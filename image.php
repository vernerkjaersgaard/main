<?php
// image.php
// The logged-in owner's own images (thumb / medium / full).
// Access check here; the serving itself is shared with share_image.php
// in image_functions.php (outside the web root).
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/image_functions.php';

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
    SELECT i.image_id
    FROM tb_images i
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE i.collection_id = ? AND i.stored_filename = ? AND i.status = 'complete' AND aft.file_kind = 'image'
");
$stmt->execute([$collection_id, $safe_filename]);

if (!$stmt->fetch())
{
    http_response_code(404);
    exit('Image not found.');
}

// The owner always gets the real image (no watermark rules on this side).
serve_image_file($pdo, $collection['storage_path'], $safe_filename, $size, (int)$_SESSION['user_id'], 'download');