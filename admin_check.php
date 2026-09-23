<?php
// admin_check.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$stmt = $pdo->prepare("SELECT is_admin FROM tb_users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || !$user['is_admin'])
{
    http_response_code(403);
    exit('Access denied.');
}