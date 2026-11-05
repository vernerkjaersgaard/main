<?php
// confirm_email.php
// Target of the link in the confirmation e-mail.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailer.php';
require_once dirname(__DIR__) . '/registration_functions.php';

$token = (string)($_GET['token'] ?? '');

$user_id = verification_confirm($pdo, $token);

if ($user_id !== null)
{
    log_action($pdo, $user_id, 'email_confirmed');

    // Tell the owner that a new account is now active. A failing mail must
    // never spoil the page the new user sees.
    try
    {
        $stmt = $pdo->prepare("SELECT username, email FROM tb_users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $new_user = $stmt->fetch();

        if ($new_user)
        {
            send_email(
                'vk@zorum.dk',
                'New Gallery account: ' . str_replace(["\r", "\n"], ' ', $new_user['username']),
                "A new account has been confirmed on pics.zorum.dk.\n\n"
                . "Username: " . $new_user['username'] . "\n"
                . "Email: " . $new_user['email'] . "\n"
                . "Time (UTC): " . gmdate('Y-m-d H:i:s') . "\n"
                . "IP address: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n"
            );
        }
    }
    catch (Throwable $e)
    {
        error_log('new-account notice failed: ' . $e->getMessage());
    }
}
else
{
    http_response_code(400);
}

require_once __DIR__ . '/header.php';
?>

<?php if ($user_id !== null): ?>

<h1>Email address confirmed</h1>
<p>Thank you, your account is now active.</p>
<p><a href="login.php?confirmed=1">Log in</a></p>

<?php else: ?>

<h1>This link does not work</h1>
<p>The confirmation link is invalid, has expired (it is valid for 24 hours) or has already been used.</p>
<p>If you have not confirmed your address yet, you can <a href="resend_confirmation.php">send a new link</a>. If you did confirm it, simply <a href="login.php">log in</a>.</p>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
