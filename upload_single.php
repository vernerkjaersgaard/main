<?php
// upload_single.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

header('Content-Type: application/json');

$collection_id = (int)($_POST['collection_id'] ?? 0);

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
    echo json_encode(['ok' => false, 'message' => 'Collection not found.']);
    exit;
}

// Enforce per-user storage quota, if one is set (NULL = unlimited).
$stmt = $pdo->prepare("SELECT storage_cap_mb FROM tb_users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$cap_mb = $stmt->fetchColumn();

if ($cap_mb !== null)
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(i.file_size), 0)
        FROM tb_images i
        JOIN tb_collections c ON c.collection_id = i.collection_id
        JOIN tb_projects p ON p.project_id = c.project_id
        WHERE p.user_id = ? AND i.status = 'complete'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $current_usage_bytes = $stmt->fetchColumn();

    $cap_bytes = $cap_mb * 1024 * 1024;
    $incoming_size = $_FILES['image']['size'] ?? 0;

    if ($current_usage_bytes + $incoming_size > $cap_bytes)
    {
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'original_filename' => $_FILES['image']['name'] ?? '',
            'message' => 'Storage quota exceeded. You have used ' . round($current_usage_bytes / 1024 / 1024, 1) . ' MB of your ' . $cap_mb . ' MB limit.',
        ]);
        exit;
    }
}

if (!isset($_FILES['image']))
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No file received.']);
    exit;
}

$originals_dir = $collection['storage_path'] . '/originals';
$thumbs_dir    = $collection['storage_path'] . '/thumbs';
$files_dir     = $collection['storage_path'] . '/files';

if (!is_dir($originals_dir))
{
    mkdir($originals_dir, 0775, true);
}
if (!is_dir($thumbs_dir))
{
    mkdir($thumbs_dir, 0775, true);
}
// Note: files_dir is created on demand below, only if this upload is
// actually a foreign file — no point creating an empty folder for
// collections that only ever receive images.

$max_file_size = 80 * 1024 * 1024; // 80 MB, applies to both images and foreign files

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

// Single source of truth for "is this extension allowed, and what kind is
// it" — replaces the old hardcoded $allowed_extensions array entirely.
$stmt = $pdo->prepare("SELECT filetype_id, file_kind FROM tb_allowed_filetypes WHERE extension = ? AND is_active = 1");
$stmt->execute([$extension]);
$filetype = $stmt->fetch();

if (!$filetype)
{
    respond_error($original_name, 'File type ".' . htmlspecialchars($extension) . '" is not allowed.');
}

$filetype_id = $filetype['filetype_id'];
$file_kind = $filetype['file_kind'];

// Collision-safe stored filename — same logic regardless of file kind
$safe_base = str_replace(' ', '_', pathinfo($original_name, PATHINFO_FILENAME));
$safe_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', $safe_base);
$stored_filename = $safe_base . '.' . $extension;
$counter = 1;

// Check both possible destination folders for a collision, since an image
// and a foreign file could theoretically share the same sanitized base name
while (file_exists($originals_dir . '/' . $stored_filename) || file_exists($files_dir . '/' . $stored_filename))
{
    $stored_filename = $safe_base . '_' . $counter . '.' . $extension;
    $counter++;
}

$insert = $pdo->prepare("
    INSERT INTO tb_images (collection_id, original_filename, stored_filename, file_size, status, filetype_id)
    VALUES (?, ?, ?, ?, 'pending', ?)
");
$insert->execute([$collection_id, $original_name, $stored_filename, $file['size'], $filetype_id]);
$image_id = $pdo->lastInsertId();

// ---------------------------------------------------------------
// IMAGE pipeline — unchanged from before: validate as a real image,
// move into originals/, generate a thumbnail.
// ---------------------------------------------------------------
if ($file_kind === 'image')
{
    $image_info = @getimagesize($file['tmp_name']);

    if ($image_info === false)
    {
        $update = $pdo->prepare("UPDATE tb_images SET status = 'failed' WHERE image_id = ?");
        $update->execute([$image_id]);
        respond_error($original_name, 'Not a valid image.');
    }

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

        log_action($pdo, $_SESSION['user_id'], 'upload', $collection['project_id'], $collection_id, $original_name);

        echo json_encode(['ok' => true, 'original_filename' => $original_name, 'stored_filename' => $stored_filename]);
        exit;
    }
    catch (\Throwable $e)
    {
        $update = $pdo->prepare("UPDATE tb_images SET status = 'failed' WHERE image_id = ?");
        $update->execute([$image_id]);
        respond_error($original_name, 'Thumbnail generation failed.');
    }
}

// ---------------------------------------------------------------
// FOREIGN FILE pipeline — no image validation, no thumbnail. Just
// move it into its own files/ subfolder, as-is.
// ---------------------------------------------------------------
if (!is_dir($files_dir))
{
    mkdir($files_dir, 0775, true);
}

$destination = $files_dir . '/' . $stored_filename;

if (!move_uploaded_file($file['tmp_name'], $destination))
{
    $update = $pdo->prepare("UPDATE tb_images SET status = 'failed' WHERE image_id = ?");
    $update->execute([$image_id]);
    respond_error($original_name, 'Failed to save file.');
}

$update = $pdo->prepare("UPDATE tb_images SET status = 'complete' WHERE image_id = ?");
$update->execute([$image_id]);

log_action($pdo, $_SESSION['user_id'], 'upload_file', $collection['project_id'], $collection_id, $original_name);

echo json_encode(['ok' => true, 'original_filename' => $original_name, 'stored_filename' => $stored_filename]);
exit;