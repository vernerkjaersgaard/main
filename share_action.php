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

$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, sl.project_id, c.storage_path
    FROM tb_share_links sl
    JOIN tb_collections c ON c.project_id = sl.project_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL AND c.collection_id = ?
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
// reasoning as gallery_action.php.
$placeholders = implode(',', array_fill(0, count($candidate_files), '?'));
$stmt = $pdo->prepare("
    SELECT i.image_id, i.stored_filename, aft.file_kind, aft.extension
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

    // "Don't double-zip a lone zip" — identical logic to gallery_action.php,
    // just redirecting to the token-authenticated download endpoint instead.
    if (count($valid_images) === 1 && $valid_images[0]['file_kind'] === 'file' && $valid_images[0]['extension'] === 'zip')
    {
        log_action($pdo, null, 'share_download_file', $result['project_id'], $collection_id, $valid_images[0]['stored_filename'] . ' (already a zip, served directly)');

        header('Location: share_file_download.php?token=' . urlencode($token) . '&collection_id=' . $collection_id . '&file=' . urlencode($valid_images[0]['stored_filename']));
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
    $chunks = array_chunk($valid_images, 70);
    $tokens = [];

    foreach ($chunks as $chunk)
    {
        $zip_token = bin2hex(random_bytes(16));
        $zip_path = $batch_temp_dir . '/' . $zip_token . '.zip';
        $temp_files_to_clean = [];

        $zip = new ZipArchive();
        $zip->open($zip_path, ZipArchive::CREATE);

        foreach ($chunk as $item)
        {
            $filename = $item['stored_filename'];

            // Foreign files are never resized, added as-is regardless
            // of the requested size tier.
            if ($item['file_kind'] === 'file')
            {
                $source_path = $files_dir . '/' . $filename;

                if (is_file($source_path))
                {
                    $zip->addFile($source_path, $filename);
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
                $zip->addFile($source_path, $filename);
            }
            else
            {
                $target_width = ($action === 'download_medium') ? 1600 : 1080;

                $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
                $resized = $manager->read($source_path);
                $resized->scaleDown(width: $target_width);

                $tmp_resized = tempnam(sys_get_temp_dir(), 'resize_') . '.jpg';
                $resized->save($tmp_resized);

                $zip->addFile($tmp_resized, $filename);
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

    log_action($pdo, null, 'share_' . $action, $result['project_id'], $collection_id, count($valid_images) . ' item(s)');

    header('Location: share_download_results.php?token=' . urlencode($token) . '&collection_id=' . $collection_id . '&batch=' . $batch_id . '&tokens=' . urlencode($token_list));
    exit;
}

header('Location: share.php?token=' . urlencode($token) . '&error=unknown_action');
exit;