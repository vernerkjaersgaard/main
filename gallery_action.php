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

$placeholders = implode(',', array_fill(0, count($candidate_files), '?'));
$stmt = $pdo->prepare("
    SELECT image_id, stored_filename
    FROM tb_images
    WHERE collection_id = ? AND status = 'complete' AND stored_filename IN ($placeholders)
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
// TAG
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

    log_action($pdo, $_SESSION['user_id'], 'tag', $collection['project_id'], $collection_id, count($image_ids) . ' image(s) tagged ' . $tag_color);

    header('Location: upload.php?collection_id=' . $collection_id . '&tagged=' . count($image_ids));
    exit;
}

// ---------------------------------------------------------------
// DELETE
// ---------------------------------------------------------------
if ($action === 'delete')
{
    $originals_dir = $collection['storage_path'] . '/originals';
    $thumbs_dir    = $collection['storage_path'] . '/thumbs';
    $medium_dir    = $collection['storage_path'] . '/medium';

    $deleted_count = 0;

    foreach ($safe_files as $filename)
    {
        $original_path = $originals_dir . '/' . $filename;
        $thumb_path    = $thumbs_dir . '/' . $filename;
        $medium_path   = $medium_dir . '/' . $filename;

        if (is_file($original_path))
        {
            unlink($original_path);
        }

        if (is_file($thumb_path))
        {
            unlink($thumb_path);
        }

        if (is_file($medium_path))
        {
            unlink($medium_path);
        }

        $deleted_count++;
    }

    $id_placeholders = implode(',', array_fill(0, count($image_ids), '?'));
    $delete = $pdo->prepare("DELETE FROM tb_images WHERE image_id IN ($id_placeholders)");
    $delete->execute($image_ids);

    log_action($pdo, $_SESSION['user_id'], 'delete_images', $collection['project_id'], $collection_id, $deleted_count . ' image(s) deleted');

    header('Location: upload.php?collection_id=' . $collection_id . '&deleted=' . $deleted_count);
    exit;
}

// ---------------------------------------------------------------
// DOWNLOAD (full / medium / small — zip files, 70 images per zip)
// ---------------------------------------------------------------
if (in_array($action, ['download_full', 'download_medium', 'download_small'], true))
{
    require_once __DIR__ . '/vendor/autoload.php';

    $user_id = $_SESSION['user_id'];
    $user_temp_dir = TEMP_ZIP_PATH . '/' . $user_id;

    if (!is_dir($user_temp_dir))
    {
        mkdir($user_temp_dir, 0775, true);
    }

    $originals_dir = $collection['storage_path'] . '/originals';
    $chunks = array_chunk($safe_files, 70);
    $tokens = [];

    foreach ($chunks as $chunk)
    {
        $token = bin2hex(random_bytes(16));
        $zip_path = $user_temp_dir . '/' . $token . '.zip';
        $temp_files_to_clean = [];

        $zip = new ZipArchive();
        $zip->open($zip_path, ZipArchive::CREATE);

        foreach ($chunk as $filename)
        {
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

    log_action($pdo, $_SESSION['user_id'], $action, $collection['project_id'], $collection_id, count($safe_files) . ' image(s)');

    header('Location: download_results.php?collection_id=' . $collection_id . '&tokens=' . urlencode($token_list));
    exit;
}

header('Location: upload.php?collection_id=' . $collection_id . '&error=unknown_action');
exit;