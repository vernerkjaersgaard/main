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

$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, c.storage_path
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
    exit('Not found.');
}

$safe_filename = basename($file);

if ($safe_filename === '' || $safe_filename !== $file)
{
    http_response_code(400);
    exit('Invalid filename.');
}

$stmt = $pdo->prepare("
    SELECT image_id FROM tb_images
    WHERE collection_id = ? AND stored_filename = ? AND status = 'complete'
");
$stmt->execute([$collection_id, $safe_filename]);

if (!$stmt->fetch())
{
    http_response_code(404);
    exit('Image not found.');
}

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