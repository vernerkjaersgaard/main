<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(i.file_size), 0)
        FROM tb_images i
        JOIN tb_collections c ON c.collection_id = i.collection_id
        JOIN tb_projects p ON p.project_id = c.project_id
        WHERE p.user_id = ? AND i.status = 'complete'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $my_usage_bytes = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT storage_cap_mb FROM tb_users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $my_cap_mb = $stmt->fetchColumn();

    function format_bytes($bytes)
    {
        if ($bytes == 0)
        {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare("SELECT project_id, project_name, date_of_creation FROM tb_projects WHERE user_id = ? ORDER BY date_of_creation DESC");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();
?>

<h1>Projects</h1>
<p>
    <small>
        Storage used: <?= format_bytes($my_usage_bytes) ?>
        <?= $my_cap_mb !== null ? ' of ' . $my_cap_mb . ' MB' : ' (unlimited)' ?>
    </small>
</p>
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