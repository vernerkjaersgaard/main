<?php
require_once __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $username === '' || $password === '') {
        $error = 'All fields are required.';
    } else {
        $check = $pdo->prepare("SELECT user_id FROM tb_users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare(
                "INSERT INTO tb_users (email, username, password_hash) VALUES (?, ?, ?)"
            );
            $insert->execute([$email, $username, $hash]);

            header('Location: login.php?registered=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">    
    <title>Register</title></head>
<body>
    <main class="container">
    <h1>Create an Account</h1>
    <?php if ($error): ?>
        <p style="color:red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>Email: <input type="email" name="email" required></label><br>
        <label>Username: <input type="text" name="username" required></label><br>
        <label>Password: <input type="password" name="password" required></label><br>
        <button type="submit">Register</button>
    </form>
    <p>Already have an account? <a href="login.php">Log in</a></p>
    </main>
</body>
</html>