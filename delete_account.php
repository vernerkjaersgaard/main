<?php
// delete_account.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT password_hash, is_admin FROM tb_users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();

    if (!$current_user || !password_verify($password, $current_user['password_hash']))
    {
        $error = 'Incorrect password.';
    }
    elseif ($current_user['is_admin'])
    {
        // Refuse to let the sole admin delete themselves — that would
        // leave admin_check.php locking everyone out of every admin page
        // permanently, recoverable only via direct SQL. Mirrors the same
        // self-lockout protection already built into admin_users.php.
        $other_admins = $pdo->prepare("SELECT COUNT(*) FROM tb_users WHERE is_admin = 1 AND user_id != ?");
        $other_admins->execute([$_SESSION['user_id']]);

        if ($other_admins->fetchColumn() == 0)
        {
            $error = "You're the only admin on this system. Promote another user to admin first, or contact the site administrator, before deleting this account.";
        }
    }

    if ($error === '')
    {
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

        $stmt = $pdo->prepare("SELECT project_id FROM tb_projects WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $project_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($project_ids as $project_id)
        {
            // Same pattern as delete_project.php: clear every collection's
            // files + row first (tb_images cascades automatically via
            // ON DELETE CASCADE), then the project's own folder + row.
            // tb_share_links cascades automatically too, once the project
            // row itself is deleted below.
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
        $delete_user->execute([$_SESSION['user_id']]);

        session_destroy();

        require_once __DIR__ . '/header.php';
        ?>
        <h1>Account Deleted</h1>
        <p>Your account and all associated projects, collections, and images have been permanently deleted.</p>
        <p><a href="index.php">Return to the homepage</a></p>
        <?php
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

// Show a summary of what's about to be destroyed, so the warning is
// concrete rather than abstract.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_projects WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$project_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tb_images i
    JOIN tb_collections c ON c.collection_id = i.collection_id
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE p.user_id = ? AND i.status = 'complete'
");
$stmt->execute([$_SESSION['user_id']]);
$image_count = $stmt->fetchColumn();

require_once __DIR__ . '/header.php';
?>

<h1>Delete Account</h1>

<p style="color:red;"><strong>Warning:</strong> this permanently deletes your account, all <?= (int)$project_count ?> project(s), and all <?= (int)$image_count ?> image(s) they contain — including any active share links. This action cannot be undone.</p>

<?php if ($error): ?>
    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
    <label>Enter your password to confirm:
        <input type="password" name="password" required>
    </label>
    <button type="submit" class="secondary">Permanently Delete My Account</button>
</form>

<p><a href="projects.php">&larr; Cancel and go back</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>