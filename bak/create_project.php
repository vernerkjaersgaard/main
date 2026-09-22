<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $project_name = trim($_POST['project_name'] ?? '');

    if ($project_name === '')
    {
        $error = 'Project name is required.';
    }
    else
    {
        $check = $pdo->prepare("SELECT project_id FROM tb_projects WHERE user_id = ? AND project_name = ?");
        $check->execute([$_SESSION['user_id'], $project_name]);

        if ($check->fetch())
        {
            $error = 'You already have a project with that name.';
        }
        else
        {
            $insert = $pdo->prepare("INSERT INTO tb_projects (user_id, project_name) VALUES (?, ?)");
            $insert->execute([$_SESSION['user_id'], $project_name]);

            header('Location: projects.php');
            exit;
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<h1>New Project</h1>

<?php if ($error): ?>
    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
    <label>Project Name
        <input type="text" name="project_name" value="<?= htmlspecialchars($project_name ?? '') ?>" required>
    </label>
    <button type="submit">Create Project</button>
</form>

<p><a href="projects.php">&larr; Back to My Projects</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>