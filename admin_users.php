<?php
// admin_users.php
require_once __DIR__ . '/admin_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $post_action = $_POST['toggle_action'] ?? '';

    // Prevent an admin from accidentally revoking their own access,
    // which could lock them out of this page with no way back in
    // short of a direct SQL fix.
    if ($target_user_id === (int)$_SESSION['user_id'])
    {
        header('Location: admin_users.php?error=self_toggle');
        exit;
    }

    if ($post_action === 'grant')
    {
        $update = $pdo->prepare("UPDATE tb_users SET is_admin = 1 WHERE user_id = ?");
        $update->execute([$target_user_id]);
    }
    elseif ($post_action === 'revoke')
    {
        $update = $pdo->prepare("UPDATE tb_users SET is_admin = 0 WHERE user_id = ?");
        $update->execute([$target_user_id]);
    }

    header('Location: admin_users.php?updated=1');
    exit;
}

$users = $pdo->query("
    SELECT
        u.user_id,
        u.username,
        u.email,
        u.is_admin,
        u.date_of_creation,
        COUNT(DISTINCT p.project_id) AS project_count,
        COUNT(DISTINCT c.collection_id) AS collection_count,
        COALESCE(SUM(CASE WHEN i.status = 'complete' THEN i.file_size ELSE 0 END), 0) AS storage_bytes,
        COALESCE(SUM(CASE WHEN i.status = 'complete' THEN 1 ELSE 0 END), 0) AS image_count
    FROM tb_users u
    LEFT JOIN tb_projects p ON p.user_id = u.user_id
    LEFT JOIN tb_collections c ON c.project_id = p.project_id
    LEFT JOIN tb_images i ON i.collection_id = c.collection_id
    GROUP BY u.user_id, u.username, u.email, u.is_admin, u.date_of_creation
    ORDER BY u.date_of_creation DESC
")->fetchAll();

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
}

require_once __DIR__ . '/header.php';
?>

<p><a href="admin.php">&larr; Admin Dashboard</a></p>
<h1>Users</h1>

<?php if (isset($_GET['updated'])): ?>
    <p style="color:green;">User updated.</p>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'self_toggle'): ?>
    <p style="color:red;">You can't change your own admin status here.</p>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th>Username</th>
            <th>Email</th>
            <th>Registered</th>
            <th>Projects</th>
            <th>Collections</th>
            <th>Images</th>
            <th>Storage</th>
            <th>Admin</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['username']) ?></td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td><?= htmlspecialchars($user['date_of_creation']) ?></td>
                <td><?= (int)$user['project_count'] ?></td>
                <td><?= (int)$user['collection_count'] ?></td>
                <td><?= (int)$user['image_count'] ?></td>
                <td><?= format_bytes($user['storage_bytes']) ?></td>
                <td><?= $user['is_admin'] ? 'Yes' : 'No' ?></td>
                <td>
                    <?php if ((int)$user['user_id'] !== (int)$_SESSION['user_id']): ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('<?= $user['is_admin'] ? 'Revoke' : 'Grant' ?> admin access for <?= htmlspecialchars($user['username'], ENT_QUOTES) ?>?');">
                            <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                            <input type="hidden" name="toggle_action" value="<?= $user['is_admin'] ? 'revoke' : 'grant' ?>">
                            <button type="submit" class="secondary">
                                <?= $user['is_admin'] ? 'Revoke admin' : 'Grant admin' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <em>(you)</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/footer.php'; ?>