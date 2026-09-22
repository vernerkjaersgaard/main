<?php
// gallery_action.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$collection_id = (int)($_POST['collection_id'] ?? 0);
$action = $_POST['gallery_action'] ?? '';
$ticked = $_POST['ticked'] ?? [];

// Verify this collection exists AND belongs (via its project) to the logged-in user
$stmt = $pdo->prepare("
    SELECT c.storage_path
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

// Server-side re-validation — never trust the client-side JS checks alone
if (empty($ticked) || !is_array($ticked))
{
    header('Location: upload.php?collection_id=' . $collection_id . '&error=nothing_ticked');
    exit;
}

// Sanitize every filename the same way image.php does — basename() strips
// any directory components, blocking path traversal attempts entirely.
$safe_files = [];
foreach ($ticked as $filename)
{
    $safe = basename($filename);
    if ($safe !== '' && $safe === $filename)
    {
        $safe_files[] = $safe;
    }
}

if (empty($safe_files))
{
    header('Location: upload.php?collection_id=' . $collection_id . '&error=invalid_selection');
    exit;
}

// ---------------------------------------------------------------
// DELETE
// ---------------------------------------------------------------
if ($action === 'delete')
{
    $originals_dir = $collection['storage_path'] . '/originals';
    $thumbs_dir    = $collection['storage_path'] . '/thumbs';

    $deleted_count = 0;

    foreach ($safe_files as $filename)
    {
        $original_path = $originals_dir . '/' . $filename;
        $thumb_path    = $thumbs_dir . '/' . $filename;

        $original_existed = is_file($original_path);

        if ($original_existed)
        {
            unlink($original_path);
        }

        if (is_file($thumb_path))
        {
            unlink($thumb_path);
        }

        if ($original_existed)
        {
            $deleted_count++;
        }
    }

    header('Location: upload.php?collection_id=' . $collection_id . '&deleted=' . $deleted_count);
    exit;
}

// ---------------------------------------------------------------
// DOWNLOAD (full / medium / small — all as zip files, 70 images per zip)
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

        $zip->close(); // ZipArchive reads file contents here, so temp files must still exist up to this point

        foreach ($temp_files_to_clean as $tmp_file)
        {
            unlink($tmp_file);
        }

        $tokens[] = $token;
    }

    $token_list = implode(',', $tokens);
    header('Location: download_results.php?collection_id=' . $collection_id . '&tokens=' . urlencode($token_list));
    exit;
}

// ---------------------------------------------------------------
// Fallback — unrecognized action
// ---------------------------------------------------------------
header('Location: upload.php?collection_id=' . $collection_id . '&error=unknown_action');
exit;