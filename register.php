<?php
// register.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailer.php';
require_once dirname(__DIR__) . '/registration_functions.php';

// Registration can be switched off in config.php. Checked before anything
// else, so neither the form nor a direct POST can create an account.
if (!registration_is_open())
{
    http_response_code(403);
    require_once __DIR__ . '/header.php';
?>
<h1>Registration is closed</h1>
<p>New accounts cannot be created at the moment.</p>
<p><a href="login.php">Back to log in</a></p>
<?= render_info_notices($pdo) ?>
<?php
    require_once __DIR__ . '/footer.php';
    exit;
}

$error = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $terms_accepted = isset($_POST['terms_accepted']);
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!csrf_valid($_POST['csrf_token'] ?? null))
    {
        $error = 'The form has expired. Please try again.';
    }
    elseif (trim($_POST['hp_contact'] ?? '') !== '')
    {
        // Honeypot: a hidden field that people never fill in. A bot gets the
        // normal "check your inbox" page, but nothing is created or sent.
        $sent = true;
    }
    elseif ($email === '' || $username === '' || $password === '' || $password_confirm === '')
    {
        $error = 'All fields are required.';
    }
    elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email, 'UTF-8') > 255)
    {
        $error = 'Please enter a valid email address.';
    }
    elseif (mb_strlen($username, 'UTF-8') > 100)
    {
        $error = 'The username can be at most 100 characters.';
    }
    elseif (!$terms_accepted)
    {
        $error = 'You must accept the Terms of Use to create an account.';
    }
    elseif ($password !== $password_confirm)
    {
        $error = 'Passwords do not match.';
    }
    elseif (($policy_error = password_policy_error($password, $username, $email)) !== null)
    {
        $error = $policy_error;
    }
    elseif (!register_request_allowed($pdo, $email, $client_ip))
    {
        // Over the limit: nothing is created or sent, and the visitor sees
        // the very same page as for a normal request.
        log_action($pdo, null, 'register_limited', null, null, 'email: ' . $email);

        $sent = true;
    }
    else
    {
        // From here on the visitor always gets the same "check your inbox"
        // page, whether the address is new, waiting for confirmation, or
        // already has an account. The page can therefore not be used to find
        // out which addresses are registered.
        $stmt = $pdo->prepare("SELECT user_id, username, email_verified_at FROM tb_users WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing && $existing['email_verified_at'] !== null)
        {
            // Confirmed account: nothing changes, the owner gets a short note.
            verification_existing_notice($email, $existing['username']);

            log_action($pdo, null, 'register_existing', null, null, 'email: ' . $email);
        }
        else
        {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $terms_path = dirname(__DIR__) . '/terms_of_use.html';
            $terms_version = is_file($terms_path) ? filemtime($terms_path) : null;

            if ($existing)
            {
                // Still unconfirmed: the new details replace the old ones, so a
                // stranger cannot block an address by registering it first.
                $update = $pdo->prepare("
                    UPDATE tb_users
                    SET username = ?, password_hash = ?, terms_accepted_at = NOW(),
                        terms_version_accepted = ?, date_of_creation = NOW()
                    WHERE user_id = ? AND email_verified_at IS NULL
                ");
                $update->execute([$username, $hash, $terms_version, $existing['user_id']]);

                $user_id = (int)$existing['user_id'];
            }
            else
            {
                try
                {
                    $insert = $pdo->prepare(
                        "INSERT INTO tb_users (email, username, password_hash, terms_accepted_at, terms_version_accepted) VALUES (?, ?, ?, NOW(), ?)"
                    );
                    $insert->execute([$email, $username, $hash, $terms_version]);

                    $user_id = (int)$pdo->lastInsertId();
                }
                catch (PDOException $e)
                {
                    // Two requests for the same address at the same moment:
                    // the other one wins, this one does nothing.
                    $user_id = 0;
                }
            }

            if ($user_id > 0)
            {
                verification_start($pdo, $user_id, $email, $username);

                log_action($pdo, null, 'register', null, null, 'email: ' . $email);
            }
        }

        $sent = true;
    }
}

require_once __DIR__ . '/header.php';
?>

<?php if ($sent): ?>

<h1>Check your inbox</h1>

<p>If the details were valid, we have sent a message with a confirmation link to the address you entered. The link is valid for 24 hours, and your account is activated when you click it.</p>
<p>Nothing arrived after a few minutes? Please check the spam folder, or <a href="resend_confirmation.php">send the link again</a>.</p>
<p><a href="login.php">Back to log in</a></p>

<?php else: ?>

<h1>Create an Account</h1>

<?php if ($error): ?>
<p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
<div aria-hidden="true" style="position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden;">
<label>Leave this field empty: <input type="text" name="hp_contact" value="" tabindex="-1" autocomplete="off"></label>
</div>
<label>Email: <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required></label><br>
<label>Username: <input type="text" name="username" maxlength="100" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required></label><br>
<label>Password: <input type="password" name="password" minlength="12" autocomplete="new-password" required></label><br>
<small>At least 12 characters. A short sentence is fine, and no special characters are needed.</small><br>
<label>Confirm Password: <input type="password" name="password_confirm" minlength="12" autocomplete="new-password" required></label><br>
<label>
<input type="checkbox" name="terms_accepted" value="1" <?= isset($_POST['terms_accepted']) ? 'checked' : '' ?> required>
    I have read and accept the <a href="terms.php" target="_blank">Terms of Use</a>
</label><br>
<button type="submit">Register</button>
</form>

<p>Already have an account? <a href="login.php">Log in</a></p>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
