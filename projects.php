<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare("SELECT project_id, project_name, date_of_creation FROM tb_projects WHERE user_id = ? ORDER BY date_of_creation DESC");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();
?>

<h1>Projects</h1>
<?php if (isset($_GET['project_deleted'])): ?>
    <p style="color:green;">Project deleted.</p>
<?php endif; ?>

<p><a href="create_project.php" role="button">+ New Project</a></p>

<?php if (empty($projects)): ?>

    <p>You don't have any projects yet. Click "+ New Project" above to create your first one.</p>

<?php else: ?>

    <table>
    <thead>
        <tr>
            <th>Project Name</th>
            <th>Created</th>
            <th>Share</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($projects as $project): ?>
            <tr>
                <td><a href="collections.php?project_id=<?= (int)$project['project_id'] ?>"><?= htmlspecialchars($project['project_name']) ?></a></td>
                <td><?= htmlspecialchars($project['date_of_creation']) ?></td>
                <td><a href="share_manage.php?project_id=<?= (int)$project['project_id'] ?>">Share</a></td>
                <td>
                    <form method="post" action="delete_project.php" onsubmit="return confirm('Delete the ENTIRE project &quot;<?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?>&quot;, including ALL its collections and images? This cannot be undone.');" style="display:inline;">
                        <input type="hidden" name="project_id" value="<?= (int)$project['project_id'] ?>">
                        <button type="submit" class="secondary">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>
<hr>
<p><small><a href="delete_account.php">Delete my account</a></small></p>
<?php require_once __DIR__ . '/footer.php'; ?>