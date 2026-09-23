<?php
// image.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

$collection_id = (int)($_GET['collection_id'] ?? 0);
$file = $_GET['file'] ?? '';
$size = $_GET['size'] ?? 'thumb';

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

// 'medium' is generated on-the-fly and cached to disk on first request —
// every subsequent request for the same image reuses the cached file
// instead of re-resizing. 'thumb' and 'full' are unchanged: thumb is
// already pre-generated at upload time, full serves the real original.
if ($size === 'medium')
{
    $medium_dir = $collection['storage_path'] . '/medium';
    $medium_path = $medium_dir . '/' . $safe_filename;

    if (!is_file($medium_path))
    {
        $original_path = $collection['storage_path'] . '/originals/' . $safe_filename;

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
            // If resizing fails for any reason, fall back to serving the
            // original rather than showing a broken image to the user.
            $path = $original_path;
        }
    }

    $path = $path ?? $medium_path;
}
else
{
    $subfolder = ($size === 'full') ? 'originals' : 'thumbs';
    $path = $collection['storage_path'] . '/' . $subfolder . '/' . $safe_filename;
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