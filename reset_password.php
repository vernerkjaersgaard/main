<?php
// reset_password.php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    require_once __DIR__ . '/header.php';
    echo '<p>This reset link is invalid.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$stmt = $pdo->prepare("
    SELECT user_id FROM tb_users
    WHERE reset_token = ? AND reset_token_expires_at > NOW()
");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user)
{
    require_once __DIR__ . '/header.php';
    ?>
    <h1>Reset Password</h1>
    <p>This reset link is invalid or has expired. Please request a new one.</p>
    <p><a href="forgot_password.php">Request a new reset link</a></p>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if ($password === '' || $password_confirm === '')
    {
        $error = 'Both fields are required.';
    }
    elseif ($password !== $password_confirm)
    {
        $error = 'Passwords do not match.';
    }
    elseif (strlen($password) < 8)
    {
        $error = 'Password must be at least 8 characters.';
    }
    else
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Clear the token immediately on success — one-time use, so a
        // reused or guessed old link can never work a second time.
        $update = $pdo->prepare("
            UPDATE tb_users
            SET password_hash = ?, reset_token = NULL, reset_token_expires_at = NULL
            WHERE user_id = ?
        ");
        $update->execute([$hash, $user['user_id']]);

        $success = true;
    }
}

require_once __DIR__ . '/header.php';
?>

<h1>Reset Password</h1>

<?php if ($success): ?>

    <p style="color:green;">Your password has been reset successfully.</p>
    <p><a href="login.php">Log in with your new password</a></p>

<?php else: ?>

    <?php if ($error): ?>
        <p style="color:red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <label>New Password: <input type="password" name="password" required></label><br>
        <label>Confirm New Password: <input type="password" name="password_confirm" required></label><br>
        <button type="submit">Reset Password</button>
    </form>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>