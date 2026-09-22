<?php
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

$stmt = $pdo->prepare("SELECT collection_id, collection_name, date_of_creation FROM tb_collections WHERE project_id = ? ORDER BY date_of_creation DESC");
$stmt->execute([$project_id]);
$collections = $stmt->fetchAll();

require_once __DIR__ . '/header.php';
?>

<p><a href="projects.php">&larr; My Projects</a></p>
<h1><?= htmlspecialchars($project['project_name']) ?></h1>

<p><a href="create_collection.php?project_id=<?= $project_id ?>" role="button">+ New Collection</a></p>

<?php if (empty($collections)): ?>

    <p>This project doesn't have any collections yet. Click "+ New Collection" above to create the first one.</p>

<?php else: ?>

    <table>
        <thead>
            <tr>
                <th>Collection Name</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($collections as $collection): ?>
                <tr>
                    <td><a href="upload.php?collection_id=<?= (int)$collection['collection_id'] ?>"><?= htmlspecialchars($collection['collection_name']) ?></a></td>
                    <td><?= htmlspecialchars($collection['date_of_creation']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>