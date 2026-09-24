<?php
// account_deletion.php

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

function delete_user_account($pdo, $user_id)
{
    $stmt = $pdo->prepare("SELECT project_id FROM tb_projects WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $project_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($project_ids as $project_id)
    {
        $stmt = $pdo->prepare("SELECT collection_id, storage_path FROM tb_collections WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $collections = $stmt->fetchAll();

        foreach ($collections as $collection)
        {
            delete_directory_recursive($collection['storage_path']);

            $delete_collection = $pdo->prepare("DELETE FROM tb_collections WHERE collection_id = ?");
            $delete_collection->execute([$collection['collection_id']]);
        }

        $project_storage_dir = STORAGE_BASE_PATH . '/' . $project_id;

        if (is_dir($project_storage_dir))
        {
            @rmdir($project_storage_dir);
        }

        $delete_project = $pdo->prepare("DELETE FROM tb_projects WHERE project_id = ?");
        $delete_project->execute([$project_id]);
    }

    $delete_user = $pdo->prepare("DELETE FROM tb_users WHERE user_id = ?");
    $delete_user->execute([$user_id]);
}