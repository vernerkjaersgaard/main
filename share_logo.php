<?php
// share_logo.php
// Serves a customer's logo to viewers of their share links. The logo lives
// outside the web root, so it can only be fetched here, with a valid token.
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, u.user_id, b.logo_filename
    FROM tb_share_links sl
    JOIN tb_projects p ON p.project_id = sl.project_id
    JOIN tb_users u ON u.user_id = p.user_id AND u.branding_enabled = 1
    JOIN tb_branding b ON b.user_id = u.user_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL AND b.logo_filename IS NOT NULL
");
$stmt->execute([$token]);
$row = $stmt->fetch();

$is_expired = $row && $row['ttl_days'] !== null &&
    strtotime($row['created_at'] . ' +' . $row['ttl_days'] . ' days') < time();

if (!$row || $is_expired || !preg_match('/^[A-Za-z0-9._-]+\z/', $row['logo_filename']))
{
    http_response_code(404);
    exit;
}

$path = STORAGE_BASE_PATH . '/branding/' . (int)$row['user_id'] . '/' . $row['logo_filename'];

if (!is_file($path))
{
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
// The page links the logo with a version number, so a replaced logo gets a
// new address and a long cache time is safe.
header('Cache-Control: private, max-age=604800');

readfile($path);
exit;