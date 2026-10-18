<?php
// share_action.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$token = $_POST['token'] ?? '';
$collection_id = (int)($_POST['collection_id'] ?? 0);
$action = $_POST['gallery_action'] ?? '';
$ticked = $_POST['ticked'] ?? [];

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

// The last condition limits a collection-level link to its own collection.
// A project-level link has sl.collection_id = NULL and matches every
// collection in its project, as before.
$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, sl.project_id, c.storage_path
    FROM tb_share_links sl
    JOIN tb_collections c ON c.project_id = sl.project_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL AND c.collection_id = ?
      AND (sl.collection_id IS NULL OR sl.collection_id = c.collection_id)
");
$stmt->execute([$token, $collection_id]);
$result = $stmt->fetch();

$is_expired = $result && $result['ttl_days'] !== null &&
    strtotime($result['created_at'] . ' +' . $result['ttl_days'] . ' days') < time();

if (!$result || $is_expired)
{
    http_response_code(404);
    exit('This link is invalid or has expired.');
}

if (empty($ticked) || !is_array($ticked))
{
    header('Location: share.php?token=' . urlencode($token) . '&error=nothing_ticked');
    exit;
}

$candidate_files = [];
foreach ($ticked as $filename)
{
    $safe = basename($filename);
    if ($safe !== '' && $safe === $filename)
    {
        $candidate_files[] = $safe;
    }
}

if (empty($candidate_files))
{
    header('Location: share.php?token=' . urlencode($token) . '&error=invalid_selection');
    exit;
}

// Now pulls file_kind/extension via tb_allowed_filetypes too — same
// reasoning as gallery_action.php. i.watermark tells which images are
// locked previews (1) and which are released (0).
$placeholders = implode(',', array_fill(0, count($candidate_files), '?'));
$stmt = $pdo->prepare("
    SELECT i.image_id, i.stored_filename, i.original_filename, i.watermark, aft.file_kind, aft.extension
    FROM tb_images i
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE i.collection_id = ? AND i.status = 'complete' AND i.stored_filename IN ($placeholders)
");
$stmt->execute(array_merge([$collection_id], $candidate_files));
$valid_images = $stmt->fetchAll();

if (empty($valid_images))
{
    header('Location: share.php?token=' . urlencode($token) . '&error=invalid_selection');
    exit;
}

$safe_files = array_column($valid_images, 'stored_filename');
$image_ids = array_column($valid_images, 'image_id');

// ---------------------------------------------------------------
// TAG
// Locked images can be tagged: that is how a customer picks what to buy.
// ---------------------------------------------------------------
if ($action === 'tag')
{
    $allowed_colors = ['none', 'red', 'green', 'blue', 'yellow', 'purple'];
    $tag_color = $_POST['tag_color'] ?? '';

    if (!in_array($tag_color, $allowed_colors, true))
    {
        header('Location: share.php?token=' . urlencode($token) . '&error=invalid_color');
        exit;
    }

    $id_placeholders = implode(',', array_fill(0, count($image_ids), '?'));
    $update = $pdo->prepare("UPDATE tb_images SET tag_color = ? WHERE image_id IN ($id_placeholders)");
    $update->execute(array_merge([$tag_color], $image_ids));

    log_action($pdo, null, 'share_tag', $result['project_id'], $collection_id, count($image_ids) . ' item(s) tagged ' . $tag_color);

    header('Location: share.php?token=' . urlencode($token) . '&tagged=' . count($image_ids));
    exit;
}

// ---------------------------------------------------------------
// DOWNLOAD (full / medium / small)
// ---------------------------------------------------------------
if (in_array($action, ['download_full', 'download_medium', 'download_small'], true))
{
    require_once __DIR__ . '/vendor/autoload.php';

    // Locked images never go into a download. This is decided here, from
    // the database, whatever the browser sent. When in doubt (a missing
    // value) an image counts as locked.
    $downloadable = [];
    $locked_skipped = 0;

    foreach ($valid_images as $item)
    {
        if ((int)($item['watermark'] ?? 1) === 0)
        {
            $downloadable[] = $item;
        }
        else
        {
            $locked_skipped++;
        }
    }

    // Nothing released in the selection: no zip is built at all.
    if (empty($downloadable))
    {
        log_action($pdo, null, 'share_download_blocked', $result['project_id'], $collection_id, $locked_skipped . ' locked item(s) requested');

        header('Location: share.php?token=' . urlencode($token) . '&error=locked_only');
        exit;
    }

    // "Don't double-zip a lone zip" — identical logic to gallery_action.php,
    // just redirecting to the token-authenticated download endpoint instead.
    // Only when the customer selected exactly that one released file.
    if (count($valid_images) === 1 && count($downloadable) === 1 && $downloadable[0]['file_kind'] === 'file' && $downloadable[0]['extension'] === 'zip')
    {
        log_action($pdo, null, 'share_download_file', $result['project_id'], $collection_id, $downloadable[0]['stored_filename'] . ' (already a zip, served directly)');

        header('Location: share_file_download.php?token=' . urlencode($token) . '&collection_id=' . $collection_id . '&file=' . urlencode($downloadable[0]['stored_filename']));
        exit;
    }

    // No logged-in user here, so temp zips are grouped by a fresh random
    // folder per download batch rather than by user_id.
    $batch_id = bin2hex(random_bytes(16));
    $batch_temp_dir = TEMP_ZIP_PATH . '/share/' . $batch_id;

    if (!is_dir($batch_temp_dir))
    {
        mkdir($batch_temp_dir, 0775, true);
    }

    $originals_dir = $result['storage_path'] . '/originals';
    $files_dir     = $result['storage_path'] . '/files';
    $chunks = array_chunk($downloadable, 70);
    $tokens = [];

        foreach ($chunks as $chunk)
    {
        $zip_token = bin2hex(random_bytes(16));
        $zip_path = $batch_temp_dir . '/' . $zip_token . '.zip';
        $temp_files_to_clean = [];
        $used_names = []; // CHANGED: names already used inside this zip

        $zip = new ZipArchive();
        $zip->open($zip_path, ZipArchive::CREATE);

        foreach ($chunk as $item)
        {
            $filename = $item['stored_filename'];

            // CHANGED: the name inside the zip is the original file name
            // (made safe and unique). The file is still read from disk
            // under its stored name.
            $entry_name = unique_zip_entry_name($item['original_filename'], $filename, $used_names);

            // Foreign files are never resized, added as-is regardless
            // of the requested size tier.
            if ($item['file_kind'] === 'file')
            {
                $source_path = $files_dir . '/' . $filename;

                if (is_file($source_path))
                {
                    $zip->addFile($source_path, $entry_name); // CHANGED
                }

                continue;
            }

            $source_path = $originals_dir . '/' . $filename;

            if (!is_file($source_path))
            {
                continue;
            }

            if ($action === 'download_full')
            {
                $zip->addFile($source_path, $entry_name); // CHANGED
            }
            else
            {
                $target_width = ($action === 'download_medium') ? 1600 : 1080;

                $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
                $resized = $manager->read($source_path);
                $resized->scaleDown(width: $target_width);

                $tmp_resized = tempnam(sys_get_temp_dir(), 'resize_') . '.jpg';
                $resized->save($tmp_resized);

                $zip->addFile($tmp_resized, $entry_name); // CHANGED
                $temp_files_to_clean[] = $tmp_resized;
            }
        }

        $zip->close();

        foreach ($temp_files_to_clean as $tmp_file)
        {
            unlink($tmp_file);
        }

        $tokens[] = $zip_token;
    }

    $token_list = implode(',', $tokens);

    log_action($pdo, null, 'share_' . $action, $result['project_id'], $collection_id,
        count($downloadable) . ' item(s)' . ($locked_skipped > 0 ? ', ' . $locked_skipped . ' locked skipped' : ''));

    // The skipped count is added only when there is one, so the address is
    // exactly what it was before for an ordinary download.
    header('Location: share_download_results.php?token=' . urlencode($token) . '&collection_id=' . $collection_id . '&batch=' . $batch_id . '&tokens=' . urlencode($token_list)
        . ($locked_skipped > 0 ? '&locked=' . $locked_skipped : ''));
    exit;
}

header('Location: share.php?token=' . urlencode($token) . '&error=unknown_action');
exit;