<?php




// auth_check.php
session_start();

if (!isset($_SESSION['user_id']))
{
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

$update = $pdo->prepare("UPDATE tb_users SET last_seen_at = NOW() WHERE user_id = ?");
$update->execute([$_SESSION['user_id']]);