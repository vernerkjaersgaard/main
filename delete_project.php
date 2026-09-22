<?php
// delete_project.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$project_id = (int)($_POST['project_id'] ?? 0);

// Verify this project exists AND belongs to the logged-in user
$stmt = $pdo->prepare("SELECT project_id FROM tb_projects WHERE project_id = ? AND user_id = ?");
$stmt->execute([$project_id, $_SESSION['user_id']]);
$project = $stmt->fetch();

if (!$project)
{
    http_response_code(404);
    exit('Project not found.');
}

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

// Delete every collection's files, then its DB row, before touching the project itself
$stmt = $pdo->prepare("SELECT collection_id, storage_path FROM tb_collections WHERE project_id = ?");
$stmt->execute([$project_id]);
$collections = $stmt->fetchAll();

foreach ($collections as $collection)
{
    delete_directory_recursive($collection['storage_path']);

    $delete_collection = $pdo->prepare("DELETE FROM tb_collections WHERE collection_id = ?");
    $delete_collection->execute([$collection['collection_id']]);
}

// Remove the now-empty project-level storage folder itself
$project_storage_dir = STORAGE_BASE_PATH . '/' . $project_id;

if (is_dir($project_storage_dir))
{
    @rmdir($project_storage_dir);
}

// tb_share_links has ON DELETE CASCADE, so those rows clean up automatically
// once the project row itself is deleted below — no manual step needed here.
$delete_project = $pdo->prepare("DELETE FROM tb_projects WHERE project_id = ?");
$delete_project->execute([$project_id]);

header('Location: projects.php?project_deleted=1');
exit;