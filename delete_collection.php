<?php
// delete_collection.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$collection_id = (int)($_POST['collection_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT c.storage_path, c.project_id
    FROM tb_collections c
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE c.collection_id = ? AND p.user_id = ?
");
$stmt->execute([$collection_id, $_SESSION['user_id']]);
$collection = $stmt->fetch();

if (!$collection)
{
    http_response_code(404);
    exit('Collection not found.');
}

$project_id = $collection['project_id'];

function delete_directory_recursive($dir)
{
    if (!is_dir($dir))
    {
        return;
    }

    $items = scandir($dir);

    foreach ($items as $item)
    {
        if ($item === '.' || $item === '..')
        {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path))
        {
            delete_directory_recursive($path);
        }
        else
        {
            unlink($path);
        }
    }

    rmdir($dir);
}

delete_directory_recursive($collection['storage_path']);

// tb_images has ON DELETE CASCADE on its collection_id foreign key, so
// deleting the collection row below automatically removes every associated
// tb_images row too — no manual cleanup query needed here.
$delete = $pdo->prepare("DELETE FROM tb_collections WHERE collection_id = ?");
$delete->execute([$collection_id]);

header('Location: collections.php?project_id=' . $project_id . '&collection_deleted=1');
exit;