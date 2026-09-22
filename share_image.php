<?php
// share_image.php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);
$file = $_GET['file'] ?? '';
$size = $_GET['size'] ?? 'thumb';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

// Confirm the token is valid, not revoked/expired, AND that the requested
// collection actually belongs to THIS token's project — otherwise someone
// could reuse a valid token to fetch images from an unrelated project.
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

$subfolder = ($size === 'full') ? 'originals' : 'thumbs';
$path = $result['storage_path'] . '/' . $subfolder . '/' . $safe_filename;

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