<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare("SELECT project_id, project_name, date_of_creation FROM tb_projects WHERE user_id = ? ORDER BY date_of_creation DESC");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();
?>

<h1>My Projects</h1>

<p><a href="create_project.php" role="button">+ New Project</a></p>

<?php if (empty($projects)): ?>

    <p>You don't have any projects yet. Click "+ New Project" above to create your first one.</p>

<?php else: ?>

    <table>
        <thead>
            <tr>
                <th>Project Name</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($projects as $project): ?>
                <tr>
                    <td><a href="collections.php?project_id=<?= (int)$project['project_id'] ?>"><?= htmlspecialchars($project['project_name']) ?></a></td>
                    <td><?= htmlspecialchars($project['date_of_creation']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>