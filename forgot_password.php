<?php
// forgot_password.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailer.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $email = trim($_POST['email'] ?? '');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($email !== '')
    {
        if (!reset_request_allowed($pdo, $email, $client_ip))
        {
            // Over the limit: nothing is sent, and the visitor sees the very
            // same message as for a normal request (see below).
            log_action($pdo, null, 'reset_request_limited', null, null, 'email: ' . $email);
        }
        else
        {
            $stmt = $pdo->prepare("SELECT user_id, username FROM tb_users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Only proceed with generating/sending a token if the account
            // genuinely exists — but the message shown to the visitor is
            // IDENTICAL either way (see below), so this branch is invisible
            // from the outside. This is what prevents the form from being
            // usable to check which emails have accounts on the system.
            if ($user)
            {
                $token = bin2hex(random_bytes(32));

                $update = $pdo->prepare("
                    UPDATE tb_users
                    SET reset_token = ?, reset_token_expires_at = NOW() + INTERVAL 1 HOUR
                    WHERE user_id = ?
                ");
                // Only a hash of the token is stored; the real token exists only
                // in the email. A leaked database can then not be used to open
                // pending reset links.
                $update->execute([hash('sha256', $token), $user['user_id']]);

                $reset_url = BASE_URL . '/reset_password.php?token=' . $token;

                $body = "Hi " . $user['username'] . ",\n\n"
                    . "We received a request to reset your Gallery password. Click the link below to choose a new one:\n\n"
                    . $reset_url . "\n\n"
                    . "This link will expire in 1 hour. If you didn't request this, you can safely ignore this email.\n\n"
                    . "Pics.zorum.dk Gallery";

                send_email($email, 'Reset your Gallery password', $body);
            }
        }
    }

    // Always show the same message, whether or not the email existed or the
    // request was limited — deliberate, see the design note above.
    $message = 'If an account exists for that email, a password reset link has been sent.';
}

require_once __DIR__ . '/header.php';
?>

<h1>Forgot Password</h1>

<?php if ($message): ?>
<p style="color:green;"><?= htmlspecialchars($message) ?></p>
<?php else: ?>
<form method="post">
<label>Email: <input type="email" name="email" required></label><br>
<button type="submit">Send Reset Link</button>
</form>
<?php endif; ?>

<p><a href="login.php">&larr; Back to Log In</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>