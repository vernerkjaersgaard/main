<?php
// zip_download.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$part = (int)($_GET['part'] ?? 0);
$total = (int)($_GET['total'] ?? 0);

if (!preg_match('/^[a-f0-9]{32}$/', $token))
{
    http_response_code(400);
    exit('Invalid token.');
}

$zip_path = TEMP_ZIP_PATH . '/' . $_SESSION['user_id'] . '/' . $token . '.zip';

if (!is_file($zip_path))
{
    http_response_code(404);
    exit('File not found or already downloaded.');
}

// Numbered filename when part/total are present and valid, otherwise a
// safe generic fallback — so a manually-typed or malformed URL still
// downloads correctly rather than failing on a missing parameter.
if ($part > 0 && $total > 0 && $part <= $total)
{
    $download_filename = 'gallery_images_' . $part . '_of_' . $total . '.zip';
}
else
{
    $download_filename = 'gallery_images.zip';
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $download_filename . '"');
header('Content-Length: ' . filesize($zip_path));

readfile($zip_path);

if (!connection_aborted())
{
    unlink($zip_path);
}

exit;