<?php
// share_image.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);
$file = $_GET['file'] ?? '';
$size = $_GET['size'] ?? 'thumb';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

// sl.project_id is fetched so a locked image's watermark settings can be
// found below.
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
    exit('Not found.');
}

$safe_filename = basename($file);

if ($safe_filename === '' || $safe_filename !== $file)
{
    http_response_code(400);
    exit('Invalid filename.');
}

// i.watermark is fetched with the same query that confirms the image exists.
$stmt = $pdo->prepare("
    SELECT i.image_id, i.watermark
    FROM tb_images i
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE i.collection_id = ? AND i.stored_filename = ? AND i.status = 'complete' AND aft.file_kind = 'image'
");
$stmt->execute([$collection_id, $safe_filename]);
$image = $stmt->fetch();

if (!$image)
{
    http_response_code(404);
    exit('Image not found.');
}

// ---------------------------------------------------------------
// LOCKED (watermarked) images. When in doubt, an image counts as locked.
//
// A locked image is only ever shown as a watermarked preview:
//  - thumbnails are served as usual (by design, they carry no watermark)
//  - medium is served as the watermarked preview, from its own cache
//    folder, and NEVER from the shared medium/ cache
//  - full is refused
// If the watermarked preview cannot be produced for ANY reason, the
// answer is an error, never the clean image.
// ---------------------------------------------------------------
$locked = ((int)($image['watermark'] ?? 1) !== 0);

if ($locked && $size === 'medium')
{
    $preview = null;
    $helper  = dirname(__DIR__) . '/watermark_functions.php';

    if (is_file($helper))
    {
        require_once $helper;

        $settings = wm_get_settings($pdo, $result['project_id']);

        if ($settings !== null)
        {
            $preview = wm_render_preview($settings, $result['storage_path'], $safe_filename);
        }
    }

    if ($preview === null || !is_file($preview))
    {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit('Preview unavailable.');
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($preview));
    // Never cached, so an image you release shows clean at once.
    header('Cache-Control: no-store');

    readfile($preview);
    exit;
}

if ($locked && $size === 'full')
{
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('This image is a locked preview.');
}

// ---------------------------------------------------------------
// Everything below is unchanged: unlocked images, and thumbnails.
// ---------------------------------------------------------------

// Same on-the-fly generate-and-cache logic as image.php — a customer
// viewing a shared gallery benefits from the same faster medium-size
// loading, and reuses the SAME cached file on disk if the logged-in
// owner has already viewed this image (both routes write to the same
// storage_path/medium/ folder).
if ($size === 'medium')
{
    $medium_dir = $result['storage_path'] . '/medium';
    $medium_path = $medium_dir . '/' . $safe_filename;

    if (!is_file($medium_path))
    {
        $original_path = $result['storage_path'] . '/originals/' . $safe_filename;

        if (!is_file($original_path))
        {
            http_response_code(404);
            exit('Image not found.');
        }

        if (!is_dir($medium_dir))
        {
            mkdir($medium_dir, 0775, true);
        }

        try
        {
            $manager = new ImageManager(new Driver());
            $resized = $manager->read($original_path);
            $resized->scaleDown(width: 1600);
            $resized->save($medium_path);
        }
        catch (\Throwable $e)
        {
            $path = $original_path;
        }
    }

    $path = $path ?? $medium_path;
}
else
{
    $subfolder = ($size === 'full') ? 'originals' : 'thumbs';
    $path = $result['storage_path'] . '/' . $subfolder . '/' . $safe_filename;
}

if (!is_file($path))
{
    http_response_code(404);
    exit('Image not found.');
}

$mime_type = mime_content_type($path);

if (!str_starts_with($mime_type, 'image/'))
{
    http_response_code(400);
    exit('Not an image.');
}

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');

readfile($path);
exit;