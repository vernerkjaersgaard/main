<?php
// resend_confirmation.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailer.php';
require_once dirname(__DIR__) . '/registration_functions.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $email = trim($_POST['email'] ?? '');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!csrf_valid($_POST['csrf_token'] ?? null))
    {
        $error = 'The form has expired. Please try again.';
    }
    else
    {
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
        {
            if (!register_request_allowed($pdo, $email, $client_ip))
            {
                log_action($pdo, null, 'register_limited', null, null, 'email: ' . $email);
            }
            else
            {
                $stmt = $pdo->prepare("SELECT user_id, username FROM tb_users WHERE email = ? AND email_verified_at IS NULL");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user)
                {
                    verification_start($pdo, (int)$user['user_id'], $email, $user['username']);

                    log_action($pdo, null, 'register_resend', null, null, 'email: ' . $email);
                }
            }
        }

        // Always the same message, whether or not the address has an account
        // that is waiting for confirmation, or the request was limited.
        $message = 'If that address belongs to an account that is waiting for confirmation, a new link has been sent.';
    }
}

require_once __DIR__ . '/header.php';
?>

<h1>Send the confirmation link again</h1>

<?php if ($error): ?>
<p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($message): ?>
<p style="color:green;"><?= htmlspecialchars($message) ?></p>
<?php else: ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
<label>Email: <input type="email" name="email" required></label><br>
<button type="submit">Send the link</button>
</form>
<?php endif; ?>

<p><a href="login.php">&larr; Back to Log In</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>
