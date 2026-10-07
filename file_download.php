<?php
// file_download.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);
$file = $_GET['file'] ?? '';

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

$safe_filename = basename($file);

if ($safe_filename === '' || $safe_filename !== $file)
{
    http_response_code(400);
    exit('Invalid filename.');
}

// Confirm this is a genuine, complete, tracked file belonging to this
// collection — same source-of-truth check used throughout the project —
// and pull its original filename back so the download preserves it
// rather than exposing the sanitized stored name to the user.
$stmt = $pdo->prepare("
    SELECT original_filename
    FROM tb_images
    WHERE collection_id = ? AND stored_filename = ? AND status = 'complete'
");
$stmt->execute([$collection_id, $safe_filename]);
$image_row = $stmt->fetch();

if (!$image_row)
{
    http_response_code(404);
    exit('File not found.');
}

$path = $collection['storage_path'] . '/files/' . $safe_filename;

if (!is_file($path))
{
    http_response_code(404);
    exit('File not found.');
}

log_action($pdo, $_SESSION['user_id'], 'download_file', $collection['project_id'], $collection_id, $image_row['original_filename']);

$mime_type = mime_content_type($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime_type);
header('Content-Disposition: ' . content_disposition_attachment($image_row['original_filename']));
header('Content-Length: ' . filesize($path));

readfile($path);
exit;