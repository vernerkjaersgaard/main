<?php
// upload_single.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

header('Content-Type: application/json');

$collection_id = (int)($_POST['collection_id'] ?? 0);

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
    echo json_encode(['ok' => false, 'message' => 'Collection not found.']);
    exit;
}

if (!isset($_FILES['image']))
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No file received.']);
    exit;
}

$originals_dir = $collection['storage_path'] . '/originals';
$thumbs_dir    = $collection['storage_path'] . '/thumbs';

if (!is_dir($originals_dir))
{
    mkdir($originals_dir, 0775, true);
}
if (!is_dir($thumbs_dir))
{
    mkdir($thumbs_dir, 0775, true);
}

$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$max_file_size = 80 * 1024 * 1024; // 80 MB — this is now a genuinely
                                    // meaningful ceiling again, since each
                                    // request carries only ONE file.

$file = $_FILES['image'];
$original_name = $file['name'];

function respond_error($original_name, $message)
{
    echo json_encode(['ok' => false, 'original_filename' => $original_name, 'message' => $message]);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK)
{
    respond_error($original_name, 'Upload failed (error code ' . $file['error'] . ').');
}

if ($file['size'] > $max_file_size)
{
    respond_error($original_name, 'File is too large (max 80 MB).');
}

$extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

if (!in_array($extension, $allowed_extensions, true))
{
    respond_error($original_name, 'Only JPG, PNG, GIF, and WEBP files are allowed.');
}

$image_info = @getimagesize($file['tmp_name']);

if ($image_info === false)
{
    respond_error($original_name, 'Not a valid image.');
}

$safe_base = str_replace(' ', '_', pathinfo($original_name, PATHINFO_FILENAME));
$safe_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', $safe_base);
$stored_filename = $safe_base . '.' . $extension;
$counter = 1;

while (file_exists($originals_dir . '/' . $stored_filename))
{
    $stored_filename = $safe_base . '_' . $counter . '.' . $extension;
    $counter++;
}

$insert = $pdo->prepare("
    INSERT INTO tb_images (collection_id, original_filename, stored_filename, file_size, status)
    VALUES (?, ?, ?, ?, 'pending')
");
$insert->execute([$collection_id, $original_name, $stored_filename, $file['size']]);
$image_id = $pdo->lastInsertId();

$destination = $originals_dir . '/' . $stored_filename;

if (!move_uploaded_file($file['tmp_name'], $destination))
{
    $update = $pdo->prepare("UPDATE tb_images SET status = 'failed' WHERE image_id = ?");
    $update->execute([$image_id]);
    respond_error($original_name, 'Failed to save file.');
}

try
{
    $manager = new ImageManager(new Driver());
    $thumb = $manager->read($destination);
    $thumb->scale(width: 400);
    $thumb->save($thumbs_dir . '/' . $stored_filename);

    $update = $pdo->prepare("UPDATE tb_images SET status = 'complete' WHERE image_id = ?");
    $update->execute([$image_id]);

    echo json_encode(['ok' => true, 'original_filename' => $original_name, 'stored_filename' => $stored_filename]);
    exit;
}
catch (\Throwable $e)
{
    $update = $pdo->prepare("UPDATE tb_images SET status = 'failed' WHERE image_id = ?");
    $update->execute([$image_id]);
    respond_error($original_name, 'Thumbnail generation failed.');
}