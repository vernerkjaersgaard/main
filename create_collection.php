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

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $collection_name = trim($_POST['collection_name'] ?? '');

    if ($collection_name === '')
    {
        $error = 'Collection name is required.';
    }
    else
    {
        $check = $pdo->prepare("SELECT collection_id FROM tb_collections WHERE project_id = ? AND collection_name = ?");
        $check->execute([$project_id, $collection_name]);

        if ($check->fetch())
        {
            $error = 'This project already has a collection with that name.';
        }
        else
        {
            // Insert first (storage_path filled in right after, once we have the new ID)
            $insert = $pdo->prepare("INSERT INTO tb_collections (project_id, collection_name, storage_path) VALUES (?, ?, '')");
            $insert->execute([$project_id, $collection_name]);
            $collection_id = $pdo->lastInsertId();

            $storage_path = STORAGE_BASE_PATH . '/' . $project_id . '/' . $collection_id;

            $update = $pdo->prepare("UPDATE tb_collections SET storage_path = ? WHERE collection_id = ?");
            $update->execute([$storage_path, $collection_id]);

            if (!is_dir($storage_path))
            {
                mkdir($storage_path, 0775, true);
            }

            header('Location: collections.php?project_id=' . $project_id);
            exit;
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<p><a href="collections.php?project_id=<?= $project_id ?>">&larr; Back to <?= htmlspecialchars($project['project_name']) ?></a></p>
<h1>New Collection</h1>

<?php if ($error): ?>
    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
    <label>Collection Name
        <input type="text" name="collection_name" value="<?= htmlspecialchars($collection_name ?? '') ?>" required>
    </label>
    <button type="submit">Create Collection</button>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>