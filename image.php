<?php
// image.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);
$file = $_GET['file'] ?? '';
$size = $_GET['size'] ?? 'thumb';

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
    exit('Not found.');
}

// basename() strips any directory components — blocks path traversal
// attempts like file=../../../../etc/passwd, so only a bare filename
// within this collection's own folder can ever be requested.
$safe_filename = basename($file);

if ($safe_filename === '' || $safe_filename !== $file)
{
    http_response_code(400);
    exit('Invalid filename.');
}

$subfolder = ($size === 'full') ? 'originals' : 'thumbs';
$path = $collection['storage_path'] . '/' . $subfolder . '/' . $safe_filename;

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