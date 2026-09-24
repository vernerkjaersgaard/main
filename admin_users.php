<?php
// admin_users.php
require_once __DIR__ . '/admin_check.php';
require_once __DIR__ . '/log.php';

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

        log_action($pdo, $_SESSION['user_id'], 'admin_granted', null, null, 'target user_id: ' . $target_user_id);
    }
    elseif ($post_action === 'revoke')
    {
        $update = $pdo->prepare("UPDATE tb_users SET is_admin = 0 WHERE user_id = ?");
        $update->execute([$target_user_id]);

        log_action($pdo, $_SESSION['user_id'], 'admin_revoked', null, null, 'target user_id: ' . $target_user_id);
    }
    elseif ($post_action === 'set_cap')
    {
        $cap_input = trim($_POST['storage_cap_mb'] ?? '');
        $cap_value = ($cap_input === '') ? null : (int)$cap_input;

        $update = $pdo->prepare("UPDATE tb_users SET storage_cap_mb = ? WHERE user_id = ?");
        $update->execute([$cap_value, $target_user_id]);

        log_action($pdo, $_SESSION['user_id'], 'storage_cap_set', null, null, 'target user_id: ' . $target_user_id . ', cap: ' . ($cap_value ?? 'unlimited') . ' MB');
    }
    elseif ($post_action === 'delete_user')
    {
        $confirm_username = trim($_POST['confirm_username'] ?? '');

        $stmt = $pdo->prepare("SELECT username, is_admin FROM tb_users WHERE user_id = ?");
        $stmt->execute([$target_user_id]);
        $target = $stmt->fetch();

        if (!$target)
        {
            header('Location: admin_users.php?error=user_not_found');
            exit;
        }

        if ($confirm_username !== $target['username'])
        {
            header('Location: admin_users.php?error=username_mismatch');
            exit;
        }

        if ($target['is_admin'])
        {
            $other_admins = $pdo->prepare("SELECT COUNT(*) FROM tb_users WHERE is_admin = 1 AND user_id != ?");
            $other_admins->execute([$target_user_id]);

            if ($other_admins->fetchColumn() == 0)
            {
                header('Location: admin_users.php?error=sole_admin');
                exit;
            }
        }

        // Logged BEFORE deletion, same reasoning as delete_account.php —
        // $target['username'] is only available right now, before
        // delete_user_account() removes the row it came from.
        log_action($pdo, $_SESSION['user_id'], 'admin_deleted_user', null, null, $target['username'] . ' (deleted by admin)');

        require_once __DIR__ . '/account_deletion.php';
        delete_user_account($pdo, $target_user_id);

        header('Location: admin_users.php?user_deleted=1');
        exit;
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
        u.storage_cap_mb,
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
<?php if (isset($_GET['user_deleted'])): ?>
    <p style="color:green;">User account deleted.</p>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'self_toggle'): ?>
    <p style="color:red;">You can't change your own admin status here.</p>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'username_mismatch'): ?>
    <p style="color:red;">Username confirmation didn't match — deletion cancelled.</p>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'sole_admin'): ?>
    <p style="color:red;">Can't delete the only admin account. Promote another user first.</p>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'user_not_found'): ?>
    <p style="color:red;">User not found.</p>
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
            <th>Storage Cap (MB)</th>
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
                <td>
                    <form method="post" style="display:flex; gap:0.3rem;">
                        <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                        <input type="hidden" name="toggle_action" value="set_cap">
                        <input type="number" name="storage_cap_mb" value="<?= htmlspecialchars($user['storage_cap_mb'] ?? '') ?>" placeholder="Unlimited" min="0" style="width:100px;">
                        <button type="submit">Set</button>
                    </form>
                </td>
                <td>
                    <?php if ((int)$user['user_id'] !== (int)$_SESSION['user_id']): ?>
                        <form method="post" style="display:inline;"
                            onsubmit="return confirmUserDeletion(this, '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>');">
                            <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                            <input type="hidden" name="toggle_action" value="delete_user">
                            <input type="hidden" name="confirm_username" class="confirm-username-field">
                            <button type="submit" class="secondary">Delete Account</button>
                        </form>
                    <?php else: ?>
                        <em>(you)</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
    function confirmUserDeletion(form, username)
    {
        const typed = prompt(`This will PERMANENTLY delete "${username}", all their projects, collections, and images.\n\nType the username exactly to confirm:`);

        if (typed !== username)
        {
            if (typed !== null)
            {
                alert('Username did not match. Deletion cancelled.');
            }
            return false;
        }

        form.querySelector('.confirm-username-field').value = typed;
        return true;
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>