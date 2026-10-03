<?php
// rename_collection.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$collection_id = (int)($_GET['collection_id'] ?? $_POST['collection_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT c.collection_id, c.collection_name, c.project_id, p.project_name
    FROM tb_collections c
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE c.collection_id = ? AND p.user_id = ?
");
$stmt->execute([$collection_id, $_SESSION['user_id']]);
$collection = $stmt->fetch();

if (!$collection)
{
    http_response_code(404);
    require_once __DIR__ . '/header.php';
    echo '<p>Collection not found.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$project_id = $collection['project_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $new_name = trim($_POST['collection_name'] ?? '');

    if ($new_name === '')
    {
        $error = 'Collection name is required.';
    }
    elseif ($new_name === $collection['collection_name'])
    {
        // No real change — skip the DB write and duplicate-name check entirely
        header('Location: collections.php?project_id=' . $project_id . '&collection_renamed=1');
        exit;
    }
    else
    {
        $check = $pdo->prepare("SELECT collection_id FROM tb_collections WHERE project_id = ? AND collection_name = ? AND collection_id != ?");
        $check->execute([$project_id, $new_name, $collection_id]);

        if ($check->fetch())
        {
            $error = 'This project already has a collection with that name.';
        }
        else
        {
            $old_name = $collection['collection_name'];

            $update = $pdo->prepare("UPDATE tb_collections SET collection_name = ? WHERE collection_id = ?");
            $update->execute([$new_name, $collection_id]);

            log_action($pdo, $_SESSION['user_id'], 'rename_collection', $project_id, $collection_id, $old_name . ' -> ' . $new_name);

            header('Location: collections.php?project_id=' . $project_id . '&collection_renamed=1');
            exit;
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<p><a href="collections.php?project_id=<?= $project_id ?>">&larr; Back to <?= htmlspecialchars($collection['project_name']) ?></a></p>
<h1>Rename Collection</h1>

<?php if ($error): ?>
    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="collection_id" value="<?= $collection_id ?>">
    <label>Collection Name
        <input type="text" name="collection_name" value="<?= htmlspecialchars($_POST['collection_name'] ?? $collection['collection_name']) ?>" required>
    </label>
    <button type="submit">Save</button>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>