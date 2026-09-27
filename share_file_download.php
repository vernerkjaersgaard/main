<?php
// share_file_download.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);
$file = $_GET['file'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, sl.project_id, c.storage_path
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
    exit('This link is invalid or has expired.');
}

$safe_filename = basename($file);

if ($safe_filename === '' || $safe_filename !== $file)
{
    http_response_code(400);
    exit('Invalid filename.');
}

$stmt = $pdo->prepare("
    SELECT original_filename FROM tb_images
    WHERE collection_id = ? AND stored_filename = ? AND status = 'complete' AND file_kind = 'file'
");
$stmt->execute([$collection_id, $safe_filename]);
$image_row = $stmt->fetch();

if (!$image_row)
{
    http_response_code(404);
    exit('File not found.');
}

$path = $result['storage_path'] . '/files/' . $safe_filename;

if (!is_file($path))
{
    http_response_code(404);
    exit('File not found.');
}

log_action($pdo, null, 'share_file_download', $result['project_id'], $collection_id, $image_row['original_filename']);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $image_row['original_filename'] . '"');
header('Content-Length: ' . filesize($path));

readfile($path);
exit;