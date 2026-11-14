<?php
// branding_logo.php
// Serves the LOGGED-IN photographer's own logo for the page header.
// (Customers get their logo through share_logo.php with a share token.)
// The logo lives outside the web root, so it can only be fetched here.
if (session_status() === PHP_SESSION_NONE)
{
    session_start();
}

require_once __DIR__ . '/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
session_write_close();

if ($user_id <= 0)
{
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("
    SELECT b.logo_filename
    FROM tb_users u
    JOIN tb_branding b ON b.user_id = u.user_id
    WHERE u.user_id = ? AND u.branding_enabled = 1 AND b.logo_filename IS NOT NULL
");
$stmt->execute([$user_id]);
$logo = $stmt->fetchColumn();

if ($logo === false || !preg_match('/^[A-Za-z0-9._-]+\z/', (string)$logo))
{
    http_response_code(404);
    exit;
}

$path = STORAGE_BASE_PATH . '/branding/' . $user_id . '/' . $logo;

if (!is_file($path))
{
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
// The header links the logo with a version number (?v=), so a replaced logo
// gets a new address and a long cache time is safe.
header('Cache-Control: private, max-age=604800');

readfile($path);
exit;
