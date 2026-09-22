<?php
// zip_download.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';

// Only allow the expected token shape — 32 hex characters
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

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="gallery_images.zip"');
header('Content-Length: ' . filesize($zip_path));

readfile($zip_path);

if (!connection_aborted())
{
    unlink($zip_path);
}

exit;