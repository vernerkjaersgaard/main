<?php
// admin_activity.php
require_once __DIR__ . '/admin_check.php';

// Only customer-facing actions, not owner-side ones — this page is
// specifically about "what are my customers doing", not a general
// activity log (tb_log also holds logins, admin actions, etc.).
$share_actions = [
    'share_view',
    'share_tag',
    'share_note_added',
    'share_download_full',
    'share_download_medium',
    'share_download_small',
];

$placeholders = implode(',', array_fill(0, count($share_actions), '?'));

$logs = $pdo->prepare("
    SELECT l.created_at, l.user_id, l.project_id, l.collection_id, l.action, l.detail, l.ip_address, p.project_name, c.collection_name
    FROM tb_log l
    LEFT JOIN tb_projects p ON p.project_id = l.project_id
    LEFT JOIN tb_collections c ON c.collection_id = l.collection_id
    WHERE l.action IN ($placeholders)
    ORDER BY l.user_id, l.project_id, l.collection_id, l.action, l.created_at DESC
    LIMIT 200
");
$logs->execute($share_actions);
$logs = $logs->fetchAll();

$action_labels = [
    'share_view' => 'Viewed gallery',
    'share_tag' => 'Tagged images',
    'share_note_added' => 'Added a note',
    'share_download_full' => 'Downloaded (full size)',
    'share_download_medium' => 'Downloaded (medium)',
    'share_download_small' => 'Downloaded (small)',
];

require_once __DIR__ . '/header.php';
?>

<p><a href="admin.php">&larr; Admin Dashboard</a></p>
<h1>Customer Activity</h1>

<p><em>Up to 200 most recent customer actions, grouped by project and collection.</em></p>

<?php if (empty($logs)): ?>
    <p>No customer activity recorded yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>When</th>
                <th>Action</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $last_project_id = null;
            $last_collection_id = null;
            ?>
            <?php foreach ($logs as $entry): ?>
                <?php if ($entry['project_id'] !== $last_project_id): ?>
                    <tr>
                        <td colspan="3" style="padding-left:0.5rem; font-weight:bold; padding-top:1rem;">
                            <?= htmlspecialchars($entry['project_name'] ?? '(deleted project)') ?>
                        </td>
                    </tr>
                    <?php $last_collection_id = null; // force the collection header to reprint too ?>
                <?php endif; ?>

                <?php if ($entry['collection_id'] !== $last_collection_id): ?>
                    <tr>
                        <td colspan="3" style="padding-left:2rem; font-style:italic; color:#555;">
                            <?= htmlspecialchars($entry['collection_name'] ?? '(deleted collection)') ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php
                $last_project_id = $entry['project_id'];
                $last_collection_id = $entry['collection_id'];
                ?>
                <tr>
                    <td style="padding-left:4rem;"><?= htmlspecialchars($entry['created_at']) ?></td>
                    <td><?= htmlspecialchars($action_labels[$entry['action']] ?? $entry['action']) ?></td>
                    <td><?= htmlspecialchars($entry['detail'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>