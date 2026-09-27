<?php
// share_zip_download.php
require_once __DIR__ . '/db.php';

$batch_id = $_GET['batch'] ?? '';
$zip_token = $_GET['file'] ?? '';
$part = (int)($_GET['part'] ?? 0);
$total = (int)($_GET['total'] ?? 0);

if (!preg_match('/^[a-f0-9]{32}$/', $batch_id) || !preg_match('/^[a-f0-9]{32}$/', $zip_token))
{
    http_response_code(400);
    exit('Invalid request.');
}

$zip_path = TEMP_ZIP_PATH . '/share/' . $batch_id . '/' . $zip_token . '.zip';

if (!is_file($zip_path))
{
    http_response_code(404);
    exit('File not found or already downloaded.');
}

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