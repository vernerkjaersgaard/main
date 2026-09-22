<?php
session_start();

if (isset($_SESSION['user_id']))
{
    header('Location: projects.php');
    exit;
}

require_once __DIR__ . '/header.php';
?>

<h1>Welcome to the Gallery</h1>
<p>Please log in or create an account to continue.</p>

<p>
    <a href="login.php" role="button">Log In</a>
    <a href="register.php" role="button" class="secondary">Create Account</a>
</p>

<?php require_once __DIR__ . '/footer.php'; ?>