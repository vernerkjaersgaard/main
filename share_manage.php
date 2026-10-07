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

// This project's collections. Used for the Scope dropdown, and as the
// whitelist a submitted scope is checked against, so a tampered form can
// never create a link into a collection belonging to someone else.
$stmt = $pdo->prepare("SELECT collection_id, collection_name FROM tb_collections WHERE project_id = ? ORDER BY collection_name");
$stmt->execute([$project_id]);
$project_collections = $stmt->fetchAll();
$valid_collection_ids = array_map('intval', array_column($project_collections, 'collection_id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $post_action = $_POST['share_action'] ?? '';

    if ($post_action === 'create')
    {
        $ttl_input = trim($_POST['ttl_days'] ?? '');
        $ttl_days = ($ttl_input === '') ? null : (int)$ttl_input;
        $token = bin2hex(random_bytes(32));

        // Scope: empty = the whole project (as before), otherwise one collection.
        $scope_id = (int)($_POST['collection_id'] ?? 0);
        $collection_id = null;

        if ($scope_id > 0)
        {
            if (!in_array($scope_id, $valid_collection_ids, true))
            {
                header('Location: share_manage.php?project_id=' . $project_id . '&error=bad_scope');
                exit;
            }

            $collection_id = $scope_id;
        }

        $insert = $pdo->prepare("INSERT INTO tb_share_links (project_id, collection_id, token, ttl_days) VALUES (?, ?, ?, ?)");
        $insert->execute([$project_id, $collection_id, $token, $ttl_days]);

        $detail = ($ttl_days !== null ? 'expires in ' . $ttl_days . ' days' : 'no expiry')
            . ($collection_id !== null ? '; single collection' : '; whole project');

        log_action($pdo, $_SESSION['user_id'], 'share_link_created', $project_id, $collection_id, $detail);
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

$stmt = $pdo->prepare("
    SELECT sl.*, c.collection_name
    FROM tb_share_links sl
    LEFT JOIN tb_collections c ON c.collection_id = sl.collection_id
    WHERE sl.project_id = ?
    ORDER BY sl.created_at DESC, sl.share_id DESC
");
$stmt->execute([$project_id]);
$links = $stmt->fetchAll();

// Preselect a collection when arriving from a collection's "Share" button.
$preselect = (int)($_GET['collection_id'] ?? 0);

require_once __DIR__ . '/header.php';

$base_url = BASE_URL;
?>

<p><a href="collections.php?project_id=<?= $project_id ?>">&larr; Back to <?= htmlspecialchars($project['project_name']) ?></a></p>
<h1>Share Links for <?= htmlspecialchars($project['project_name']) ?></h1>

<?php if (isset($_GET['error']) && $_GET['error'] === 'bad_scope'): ?>
    <p style="color:red;">That collection doesn't belong to this project. No link was created.</p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="share_action" value="create">

    <label>What should the link show?
        <select name="collection_id">
            <option value="">The whole project (all collections)</option>
            <?php foreach ($project_collections as $pc): ?>
                <option value="<?= (int)$pc['collection_id'] ?>" <?= ((int)$pc['collection_id'] === $preselect) ? 'selected' : '' ?>>
                    Only: <?= htmlspecialchars($pc['collection_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Expires after how many days? (leave blank for never)
        <input type="number" name="ttl_days" min="1">
    </label>
    <button type="submit">Create New Share Link</button>
</form>

<hr>

<?php if (empty($links)): ?>
    <p>No share links created yet.</p>
<?php else: ?>
    <table class="zebra">
        <thead>
            <tr>
                <th>Link</th>
                <th>Shows</th>
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
                    <td><?= $link['collection_id'] !== null ? htmlspecialchars($link['collection_name']) : 'Whole project' ?></td>
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