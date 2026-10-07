<?php
// collections.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$project_id = (int)($_GET['project_id'] ?? 0);

// Verify this project exists AND belongs to the logged-in user
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

// Whitelist of allowed sort options, mapped to their actual ORDER BY clause.
// Never build ORDER BY from raw user input directly — even though this is
// a dropdown (not free text), a whitelist means a tampered/unexpected
// value can only ever fall back to the safe default, never reach SQL.
$sort_options = [
    'newest'  => 'date_of_creation DESC',
    'oldest'  => 'date_of_creation ASC',
    'name_az' => 'collection_name ASC',
    'name_za' => 'collection_name DESC',
];

$sort = $_GET['sort'] ?? 'newest';

if (!array_key_exists($sort, $sort_options))
{
    $sort = 'newest';
}

$stmt = $pdo->prepare("SELECT collection_id, collection_name, date_of_creation FROM tb_collections WHERE project_id = ? ORDER BY " . $sort_options[$sort]);
$stmt->execute([$project_id]);
$collections = $stmt->fetchAll();

require_once __DIR__ . '/header.php';
?>

<p><a href="projects.php">&larr; My Projects</a></p>
<h1><?= htmlspecialchars($project['project_name']) ?></h1>
<?php if (isset($_GET['collection_deleted'])): ?>
<p style="color:green;">Collection deleted.</p>
<?php endif; ?>
<?php if (isset($_GET['collection_renamed'])): ?>
<p style="color:green;">Collection renamed.</p>
<?php endif; ?>

<p><a href="create_collection.php?project_id=<?= $project_id ?>" role="button">+ New Collection</a></p>

<?php if (empty($collections)): ?>

<p>This project doesn't have any collections yet. Click "+ New Collection" above to create the first one.</p>

<?php else: ?>

<form method="get" style="margin-bottom:1rem;">
<input type="hidden" name="project_id" value="<?= $project_id ?>">
<label style="display:inline-flex; align-items:center; gap:0.5rem; width:auto;">
        Sort by:
<select name="sort" onchange="this.form.submit()">
<option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
<option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
<option value="name_az" <?= $sort === 'name_az' ? 'selected' : '' ?>>Name (A&ndash;Z)</option>
<option value="name_za" <?= $sort === 'name_za' ? 'selected' : '' ?>>Name (Z&ndash;A)</option>
</select>
</label>
</form>

<table class="zebra">
<thead>
<tr>
<th>Collection Name</th>
<th>Created</th>
<th></th>
<th></th>
<th></th>
</tr>
</thead>
<tbody>
<?php foreach ($collections as $collection): ?>
<tr>
<td><a href="upload.php?collection_id=<?= (int)$collection['collection_id'] ?>"><?= htmlspecialchars($collection['collection_name']) ?></a></td>
<td><?= htmlspecialchars($collection['date_of_creation']) ?></td>
<td>
<a href="rename_collection.php?collection_id=<?= (int)$collection['collection_id'] ?>" role="button" class="secondary">Rename</a>
</td>
<td>
<a href="share_manage.php?project_id=<?= $project_id ?>&collection_id=<?= (int)$collection['collection_id'] ?>" role="button" class="secondary">Share</a>
</td>
<td>
<form method="post" action="delete_collection.php" onsubmit="return confirm('Delete the collection &quot;<?= htmlspecialchars($collection['collection_name'], ENT_QUOTES) ?>&quot; and all its images? This cannot be undone.');" style="display:inline;">
<input type="hidden" name="collection_id" value="<?= (int)$collection['collection_id'] ?>">
<button type="submit" class="secondary">Delete</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>