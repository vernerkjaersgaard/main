<?php
// projects.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

// Whitelists: the URL values only select a key, they are never put into the SQL.
$sort_columns = ['name' => 'project_name', 'created' => 'date_of_creation'];

$sort = $_GET['sort'] ?? 'created';

if (!array_key_exists($sort, $sort_columns))
{
    $sort = 'created';
}

// Default direction per column: names A-Z, dates newest first
$default_dir = ($sort === 'name') ? 'asc' : 'desc';
$dir = strtolower($_GET['dir'] ?? $default_dir);

if (!in_array($dir, ['asc', 'desc'], true))
{
    $dir = $default_dir;
}

function sort_link($label, $column, $current_sort, $current_dir)
{
    if ($current_sort === $column)
    {
        $next_dir = ($current_dir === 'asc') ? 'desc' : 'asc';
        $arrow = ($current_dir === 'asc') ? ' ▲' : ' ▼';
    }
    else
    {
        $next_dir = ($column === 'name') ? 'asc' : 'desc';
        $arrow = '';
    }

    return '<a href="projects.php?sort=' . $column . '&dir=' . $next_dir . '">' . htmlspecialchars($label) . $arrow . '</a>';
}
/*
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
}*/

$stmt = $pdo->prepare("SELECT project_id, project_name, date_of_creation FROM tb_projects WHERE user_id = ? ORDER BY " . $sort_columns[$sort] . " " . strtoupper($dir) . ", project_id DESC");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();

// Same measure the upload quota check uses: SUM(file_size) over this
// user's complete uploads, so this number matches what is enforced.
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
?>

<h1>Projects</h1>

<p>
<small>
Storage used: <?= format_bytes($my_usage_bytes) ?>
<?= $my_cap_mb !== null ? ' of ' . format_bytes($my_cap_mb * 1024 * 1024) : ' (unlimited)' ?>
</small>
</p>

<?php if (isset($_GET['project_deleted'])): ?>
<p style="color:green;">Project deleted.</p>
<?php endif; ?>

<p><a href="create_project.php" role="button">+ New Project</a></p>

<?php if (empty($projects)): ?>

<p>You don't have any projects yet. Click "+ New Project" above to create your first one.</p>

<?php else: ?>

<table class="zebra">
<thead>
<tr>
<th><?= sort_link('Project Name', 'name', $sort, $dir) ?></th>
<th><?= sort_link('Created', 'created', $sort, $dir) ?></th>
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