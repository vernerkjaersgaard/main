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
    exit('Not found.');
}

$safe_filename = basename($file);

if ($safe_filename === '' || $safe_filename !== $file)
{
    http_response_code(400);
    exit('Invalid filename.');
}

// Only genuine, complete FOREIGN files are servable here — an image row
// requesting this endpoint (wrong path, or a tampered URL) is rejected,
// just as image.php rejects anything that isn't file_kind = 'image'
// implicitly by only ever looking in originals/thumbs/medium.
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

$path = $collection['storage_path'] . '/files/' . $safe_filename;

if (!is_file($path))
{
    http_response_code(404);
    exit('File not found.');
}

log_action($pdo, $_SESSION['user_id'], 'file_download', $collection['project_id'], $collection_id, $image_row['original_filename']);

// force-download with the user's ORIGINAL filename, not the sanitized
// stored one — so a PSD they get back is named the way they expect,
// not something like "layered_design_1.psd" with an appended counter.
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $image_row['original_filename'] . '"');
header('Content-Length: ' . filesize($path));

readfile($path);
exit;