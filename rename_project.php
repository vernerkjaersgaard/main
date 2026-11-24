<?php
// rename_project.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$project_id = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

$stmt = $pdo->prepare("SELECT project_id, project_name FROM tb_projects WHERE project_id = ? AND user_id = ?");
$stmt->execute([$project_id, $_SESSION['user_id']]);
$project = $stmt->fetch();

if (!$project)
{
    http_response_code(404);
    require_once __DIR__ . '/header.php';
    echo '<p>Project not found.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $new_name = trim($_POST['project_name'] ?? '');

    if ($new_name === '')
    {
        $error = 'Project name is required.';
    }
    elseif ($new_name === $project['project_name'])
    {
        // No real change — skip the DB write and duplicate-name check entirely
        header('Location: projects.php?project_renamed=1');
        exit;
    }
    else
    {
        $check = $pdo->prepare("SELECT project_id FROM tb_projects WHERE user_id = ? AND project_name = ? AND project_id != ?");
        $check->execute([$_SESSION['user_id'], $new_name, $project_id]);

        if ($check->fetch())
        {
            $error = 'You already have a project with that name.';
        }
        else
        {
            $old_name = $project['project_name'];

            $update = $pdo->prepare("UPDATE tb_projects SET project_name = ? WHERE project_id = ?");
            $update->execute([$new_name, $project_id]);

            log_action($pdo, $_SESSION['user_id'], 'rename_project', $project_id, null, $old_name . ' -> ' . $new_name);

            header('Location: projects.php?project_renamed=1');
            exit;
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<p><a href="projects.php">&larr; Back to My Projects</a></p>
<h1>Rename Project</h1>

<?php if ($error): ?>
    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="project_id" value="<?= $project_id ?>">
    <label>Project Name
        <input type="text" name="project_name" value="<?= htmlspecialchars($_POST['project_name'] ?? $project['project_name']) ?>" required>
    </label>
    <button type="submit">Save</button>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>