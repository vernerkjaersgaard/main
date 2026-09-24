<?php
if (session_status() === PHP_SESSION_NONE)
{
    session_start();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pics.zorum.dk</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<nav class="container-fluid site-header">
<ul>
<li><strong>Pics.zorum.dk</strong></li>
</ul>
<ul>
<?php if (isset($_SESSION['user_id'])): ?>
<li><a href="projects.php">My Projects</a></li>
<?php if (!empty($_SESSION['is_admin'])): ?>
<li><a href="admin.php">Admin</a></li>
<?php endif; ?>
<li><?= htmlspecialchars($_SESSION['username']) ?></li>
<li><a href="logout.php">Log out</a></li>
<?php else: ?>
<li><a href="login.php">Log in</a></li>
<li><a href="register.php">Register</a></li>
<?php endif; ?>
</ul>
</nav>
<main class="container">