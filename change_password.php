<?php
// change_password.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$error = '';
$success = false;

$stmt = $pdo->prepare("SELECT username, email, password_hash FROM tb_users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$account = $stmt->fetch();

if (!$account)
{
    http_response_code(404);
    exit('Account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    $email_key = mb_strtolower($account['email'], 'UTF-8');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!csrf_valid($_POST['csrf_token'] ?? null))
    {
        $error = 'The form has expired. Please try again.';
    }
    elseif (login_is_blocked($pdo, $email_key, $client_ip))
    {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    }
    elseif ($current_password === '' || $new_password === '' || $new_password_confirm === '')
    {
        $error = 'All fields are required.';
    }
    elseif (!password_verify($current_password, $account['password_hash']))
    {
        login_record_failure($pdo, $email_key, $client_ip);
        log_action($pdo, $_SESSION['user_id'], 'password_change_failed');

        $error = 'Your current password is not correct.';
    }
    elseif ($new_password !== $new_password_confirm)
    {
        $error = 'The new passwords do not match.';
    }
    elseif ($new_password === $current_password)
    {
        $error = 'The new password must be different from the current one.';
    }
    elseif (($policy_error = password_policy_error($new_password, $account['username'], $account['email'])) !== null)
    {
        $error = $policy_error;
    }
    else
    {
        $update = $pdo->prepare("UPDATE tb_users SET password_hash = ? WHERE user_id = ?");
        $update->execute([password_hash($new_password, PASSWORD_DEFAULT), $_SESSION['user_id']]);

        session_regenerate_id(true);
        unset($_SESSION['password_weak']);

        log_action($pdo, $_SESSION['user_id'], 'password_changed');

        $success = true;
    }
}

require_once __DIR__ . '/header.php';
?>

<h1>Change Password</h1>

<?php if ($success): ?>

<p style="color:green;">Your password has been changed.</p>
<p><a href="index.php">Back to the start page</a></p>

<?php else: ?>

<?php if ($error): ?>
<p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
<label>Current password: <input type="password" name="current_password" autocomplete="current-password" required></label><br>
<label>New password: <input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><br>
<small>At least 12 characters. A short sentence is fine, and no special characters are needed.</small><br>
<label>Confirm new password: <input type="password" name="new_password_confirm" minlength="12" autocomplete="new-password" required></label><br>
<button type="submit">Change Password</button>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>