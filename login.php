<?php
// login.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $email_key = mb_strtolower($email, 'UTF-8');

    if (login_is_blocked($pdo, $email_key, $client_ip))
    {
        // While blocked the password is not even checked, and the message is
        // the same whether or not the email belongs to an account.
        log_action($pdo, null, 'login_blocked', null, null, 'email: ' . $email);

        http_response_code(429);
        $error = 'Too many failed attempts. Please wait 15 minutes and try again, or use "Forgot your password?".';
    }
    else
    {
        $stmt = $pdo->prepare("SELECT user_id, username, password_hash, is_admin FROM tb_users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash']))
        {
            // New session id at login, so an id known before login is useless afterwards.
            session_regenerate_id(true);

            // Upgrade the stored hash if PHP's default algorithm or cost has moved on.
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT))
            {
                $rehash = $pdo->prepare("UPDATE tb_users SET password_hash = ? WHERE user_id = ?");
                $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
            }

            login_clear_failures($pdo, $email_key);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];

            // At this moment the plain password is known: if it does not meet
            // the current rules, remember that so a banner can ask for a new one.
            if (password_policy_error($password, $user['username'], $email) !== null)
            {
                $_SESSION['password_weak'] = 1;
            }

            log_action($pdo, $user['user_id'], 'login');

            header('Location: index.php');
            exit;
        }
        else
        {
            login_record_failure($pdo, $email_key, $client_ip);

            log_action($pdo, null, 'login_failed', null, null, 'email: ' . $email);

            $error = 'Invalid email or password.';
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<h1>Log In</h1>

<?php if (!empty($_GET['registered'])): ?>
<p style="color:green;">Account created — please log in.</p>
<?php endif; ?>
<?php if ($error): ?>
<p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
<label>Email: <input type="email" name="email" autocomplete="username" required></label><br>
<label>Password: <input type="password" name="password" autocomplete="current-password" required></label><br>
<p><a href="forgot_password.php">Forgot your password?</a></p>
<button type="submit">Log In</button>
</form>

<?php if (registration_is_open()): /* CHANGED: link only shown while registration is open */ ?>
<p>Don't have an account? <a href="register.php">Register</a></p>
<?php endif; ?>

<?= render_info_notices($pdo) /* CHANGED: notices from tb_info, just above the footer */ ?>

<?php require_once __DIR__ . '/footer.php'; ?>