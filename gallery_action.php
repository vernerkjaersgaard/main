<?php
// gallery_action.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$collection_id = (int)($_POST['collection_id'] ?? 0);
$action = $_POST['gallery_action'] ?? '';
$ticked = $_POST['ticked'] ?? [];

$stmt = $pdo->prepare("
    SELECT c.storage_path, p.project_id
    FROM tb_collections c
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE c.collection_id = ? AND p.user_id = ?
");
$stmt->execute([$collection_id, $_SESSION['user_id']]);
$collection = $stmt->fetch();

if (!$collection)
{
    http_response_code(404);
    exit('Collection not found.');
}

if (empty($ticked) || !is_array($ticked))
{
    header('Location: upload.php?collection_id=' . $collection_id . '&error=nothing_ticked');
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
    header('Location: upload.php?collection_id=' . $collection_id . '&error=invalid_selection');
    exit;
}

// Now pulls file_kind/extension via tb_allowed_filetypes too — every
// action below needs to know whether each ticked item is an image or a
// foreign file, not just that it exists and is complete.
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
    header('Location: upload.php?collection_id=' . $collection_id . '&error=invalid_selection');
    exit;
}

$safe_files = array_column($valid_images, 'stored_filename');
$image_ids = array_column($valid_images, 'image_id');

// ---------------------------------------------------------------
// TAG — unchanged, applies identically regardless of file_kind
// ---------------------------------------------------------------
if ($action === 'tag')
{
    $allowed_colors = ['none', 'red', 'green', 'blue', 'yellow', 'purple'];
    $tag_color = $_POST['tag_color'] ?? '';

    if (!in_array($tag_color, $allowed_colors, true))
    {
        header('Location: upload.php?collection_id=' . $collection_id . '&error=invalid_color');
        exit;
    }

    $id_placeholders = implode(',', array_fill(0, count($image_ids), '?'));
    $update = $pdo->prepare("UPDATE tb_images SET tag_color = ? WHERE image_id IN ($id_placeholders)");
    $update->execute(array_merge([$tag_color], $image_ids));

    log_action($pdo, $_SESSION['user_id'], 'tag', $collection['project_id'], $collection_id, count($image_ids) . ' item(s) tagged ' . $tag_color);

    header('Location: upload.php?collection_id=' . $collection_id . '&tagged=' . count($image_ids));
    exit;
}

// ---------------------------------------------------------------
// DELETE — now also cleans up files/ for foreign files, not just
// originals/thumbs/medium
// ---------------------------------------------------------------
if ($action === 'delete')
{
    $originals_dir = $collection['storage_path'] . '/originals';
    $thumbs_dir    = $collection['storage_path'] . '/thumbs';
    $medium_dir    = $collection['storage_path'] . '/medium';
    $files_dir     = $collection['storage_path'] . '/files';

    $deleted_count = 0;

    foreach ($valid_images as $item)
    {
        $filename = $item['stored_filename'];

        if ($item['file_kind'] === 'image')
        {
            foreach ([$originals_dir, $thumbs_dir, $medium_dir] as $dir)
            {
                $path = $dir . '/' . $filename;
                if (is_file($path))
                {
                    unlink($path);
                }
            }
        }
        else
        {
            $path = $files_dir . '/' . $filename;
            if (is_file($path))
            {
                unlink($path);
            }
        }

        $deleted_count++;
    }

    $id_placeholders = implode(',', array_fill(0, count($image_ids), '?'));
    $delete = $pdo->prepare("DELETE FROM tb_images WHERE image_id IN ($id_placeholders)");
    $delete->execute($image_ids);

    log_action($pdo, $_SESSION['user_id'], 'delete_images', $collection['project_id'], $collection_id, $deleted_count . ' item(s) deleted');

    header('Location: upload.php?collection_id=' . $collection_id . '&deleted=' . $deleted_count);
    exit;
}

// ---------------------------------------------------------------
// DOWNLOAD (full / medium / small)
// ---------------------------------------------------------------
if (in_array($action, ['download_full', 'download_medium', 'download_small'], true))
{
    require_once __DIR__ . '/vendor/autoload.php';

    // "Don't double-zip a lone zip": if the ENTIRE ticked selection is
    // exactly one item, AND it's a foreign file with a .zip extension,
    // skip zip generation entirely and hand off straight to the
    // existing single-file download endpoint.
    if (count($valid_images) === 1 && $valid_images[0]['file_kind'] === 'file' && $valid_images[0]['extension'] === 'zip')
    {
        log_action($pdo, $_SESSION['user_id'], 'download_file', $collection['project_id'], $collection_id, $valid_images[0]['stored_filename'] . ' (already a zip, served directly)');

        header('Location: file_download.php?collection_id=' . $collection_id . '&file=' . urlencode($valid_images[0]['stored_filename']));
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $user_temp_dir = TEMP_ZIP_PATH . '/' . $user_id;

    if (!is_dir($user_temp_dir))
    {
        mkdir($user_temp_dir, 0775, true);
    }

    $originals_dir = $collection['storage_path'] . '/originals';
    $files_dir     = $collection['storage_path'] . '/files';
    $chunks = array_chunk($valid_images, 70);
    $tokens = [];

    foreach ($chunks as $chunk)
    {
        $token = bin2hex(random_bytes(16));
        $zip_path = $user_temp_dir . '/' . $token . '.zip';
        $temp_files_to_clean = [];

        $zip = new ZipArchive();
        $zip->open($zip_path, ZipArchive::CREATE);

        foreach ($chunk as $item)
        {
            $filename = $item['stored_filename'];

            // Foreign files are never resized — they're added as-is
            // regardless of which size tier was requested, since
            // "medium"/"small" has no meaning for a PSD or a ZIP.
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

        $tokens[] = $token;
    }

    $token_list = implode(',', $tokens);

    log_action($pdo, $_SESSION['user_id'], $action, $collection['project_id'], $collection_id, count($valid_images) . ' item(s)');

    header('Location: download_results.php?collection_id=' . $collection_id . '&tokens=' . urlencode($token_list));
    exit;
}

header('Location: upload.php?collection_id=' . $collection_id . '&error=unknown_action');
exit;