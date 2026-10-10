<?php
// account_deletion.php

require_once dirname(__DIR__) . '/general_functions.php';

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

    // The tb_branding and tb_watermarks rows are removed automatically by
    // their foreign keys. The logo and watermark folders are not, so remove
    // them here, and only after the account itself is gone. The paths are
    // built from an integer id only.
    $user_id = (int)$user_id;

    if ($user_id > 0)
    {
        $branding_dir = STORAGE_BASE_PATH . '/branding/' . $user_id;

        if (is_dir($branding_dir))
        {
            delete_directory_recursive($branding_dir);
        }

        $watermark_dir = STORAGE_BASE_PATH . '/watermarks/' . $user_id;

        if (is_dir($watermark_dir))
        {
            delete_directory_recursive($watermark_dir);
        }
    }
}