<?php
// share_manage.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$project_id = (int)($_GET['project_id'] ?? 0);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $post_action = $_POST['share_action'] ?? '';

    if ($post_action === 'create')
    {
        $ttl_input = trim($_POST['ttl_days'] ?? '');
        $ttl_days = ($ttl_input === '') ? null : (int)$ttl_input;
        $token = bin2hex(random_bytes(32));

        $insert = $pdo->prepare("INSERT INTO tb_share_links (project_id, token, ttl_days) VALUES (?, ?, ?)");
        $insert->execute([$project_id, $token, $ttl_days]);

        log_action($pdo, $_SESSION['user_id'], 'share_link_created', $project_id, null, $ttl_days !== null ? 'expires in ' . $ttl_days . ' days' : 'no expiry');
    }
    elseif ($post_action === 'revoke')
    {
        $share_id = (int)($_POST['share_id'] ?? 0);

        $revoke = $pdo->prepare("UPDATE tb_share_links SET revoked_at = NOW() WHERE share_id = ? AND project_id = ?");
        $revoke->execute([$share_id, $project_id]);

        log_action($pdo, $_SESSION['user_id'], 'share_link_revoked', $project_id, null, 'share_id: ' . $share_id);
    }

    header('Location: share_manage.php?project_id=' . $project_id);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM tb_share_links WHERE project_id = ? ORDER BY created_at DESC");
$stmt->execute([$project_id]);
$links = $stmt->fetchAll();

require_once __DIR__ . '/header.php';

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
?>

<p><a href="collections.php?project_id=<?= $project_id ?>">&larr; Back to <?= htmlspecialchars($project['project_name']) ?></a></p>
<h1>Share Links for <?= htmlspecialchars($project['project_name']) ?></h1>

<form method="post">
    <input type="hidden" name="share_action" value="create">
    <label>Expires after how many days? (leave blank for never)
        <input type="number" name="ttl_days" min="1">
    </label>
    <button type="submit">Create New Share Link</button>
</form>

<hr>

<?php if (empty($links)): ?>
    <p>No share links created yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Link</th>
                <th>Created</th>
                <th>Expires</th>
                <th>Views</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($links as $link): ?>
                <?php
                $share_url = $base_url . '/share.php?token=' . $link['token'];
                $is_revoked = $link['revoked_at'] !== null;
                $is_expired = $link['ttl_days'] !== null &&
                    strtotime($link['created_at'] . ' +' . $link['ttl_days'] . ' days') < time();
                ?>
                <tr>
                    <td><input type="text" readonly value="<?= htmlspecialchars($share_url) ?>" style="width:100%;" onclick="this.select();"></td>
                    <td><?= htmlspecialchars($link['created_at']) ?></td>
                    <td><?= $link['ttl_days'] ? $link['ttl_days'] . ' days' : 'Never' ?></td>
                    <td><?= (int)$link['view_count'] ?></td>
                    <td>
                        <?php if ($is_revoked): ?>
                            Revoked
                        <?php elseif ($is_expired): ?>
                            Expired
                        <?php else: ?>
                            Active
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$is_revoked): ?>
                            <form method="post" onsubmit="return confirm('Revoke this link? It will stop working immediately.');" style="display:inline;">
                                <input type="hidden" name="share_action" value="revoke">
                                <input type="hidden" name="share_id" value="<?= $link['share_id'] ?>">
                                <button type="submit">Revoke</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>