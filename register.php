<?php
// register.php
require_once __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $terms_accepted = isset($_POST['terms_accepted']);

if ($email === '' || $username === '' || $password === '' || $password_confirm === '')
    {
        $error = 'All fields are required.';
    }
elseif (!$terms_accepted)
    {
        $error = 'You must accept the Terms of Use to create an account.';
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
        $check = $pdo->prepare("SELECT user_id FROM tb_users WHERE email = ?");
        $check->execute([$email]);

if ($check->fetch())
        {
            $error = 'An account with that email already exists.';
        }
else
        {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $terms_path = dirname(__DIR__) . '/terms_of_use.html';
            $terms_version = is_file($terms_path) ? filemtime($terms_path) : null;

            $insert = $pdo->prepare(
"INSERT INTO tb_users (email, username, password_hash, terms_accepted_at, terms_version_accepted) VALUES (?, ?, ?, NOW(), ?)"
            );
            $insert->execute([$email, $username, $hash, $terms_version]);

            header('Location: login.php?registered=1');
exit;
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<h1>Create an Account</h1>

<?php if ($error): ?>
<p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
<label>Email: <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required></label><br>
<label>Username: <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required></label><br>
<label>Password: <input type="password" name="password" required></label><br>
<label>Confirm Password: <input type="password" name="password_confirm" required></label><br>
<label>
    <input type="checkbox" name="terms_accepted" value="1" <?= isset($_POST['terms_accepted']) ? 'checked' : '' ?> required>
    I have read and accept the <a href="terms.php" target="_blank">Terms of Use</a>
</label><br>
<button type="submit">Register</button>
</form>

<p>Already have an account? <a href="login.php">Log in</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>