<?php
// admin.php
require_once __DIR__ . '/admin_check.php';

function format_bytes($bytes)
{
    if ($bytes === null || $bytes == 0)
    {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);

    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

$total_users = $pdo->query("SELECT COUNT(*) FROM tb_users")->fetchColumn();
$total_projects = $pdo->query("SELECT COUNT(*) FROM tb_projects")->fetchColumn();
$total_collections = $pdo->query("SELECT COUNT(*) FROM tb_collections")->fetchColumn();
$total_images_complete = $pdo->query("SELECT COUNT(*) FROM tb_images WHERE status = 'complete'")->fetchColumn();
$total_images_failed = $pdo->query("SELECT COUNT(*) FROM tb_images WHERE status = 'failed'")->fetchColumn();
$total_storage_bytes = $pdo->query("SELECT SUM(file_size) FROM tb_images WHERE status = 'complete'")->fetchColumn();

$total_share_links = $pdo->query("SELECT COUNT(*) FROM tb_share_links")->fetchColumn();

$active_share_links = $pdo->query("
    SELECT COUNT(*) FROM tb_share_links
    WHERE revoked_at IS NULL
    AND (ttl_days IS NULL OR created_at + INTERVAL ttl_days DAY > NOW())
")->fetchColumn();

$total_share_views = $pdo->query("SELECT SUM(view_count) FROM tb_share_links")->fetchColumn();

$recent_users = $pdo->query("
    SELECT username, email, date_of_creation
    FROM tb_users
    ORDER BY date_of_creation DESC
    LIMIT 10
")->fetchAll();

$recent_uploads = $pdo->query("
    SELECT i.original_filename, i.uploaded_at, c.collection_name, p.project_name
    FROM tb_images i
    JOIN tb_collections c ON c.collection_id = i.collection_id
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE i.status = 'complete'
    ORDER BY i.uploaded_at DESC, i.image_id DESC
    LIMIT 10
")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<h1>Admin Dashboard</h1>
<p><a href="admin_users.php">View all users &rarr;</a></p>
<p><a href="admin_storage.php">Disk usage by user &rarr;</a></p>

<h2>Overview</h2>
<table>
    <tbody>
        <tr><td>Users</td><td><?= (int)$total_users ?></td></tr>
        <tr><td>Projects</td><td><?= (int)$total_projects ?></td></tr>
        <tr><td>Collections</td><td><?= (int)$total_collections ?></td></tr>
        <tr><td>Images (complete)</td><td><?= (int)$total_images_complete ?></td></tr>
        <tr><td>Images (failed)</td><td><?= (int)$total_images_failed ?></td></tr>
        <tr><td>Total storage used</td><td><?= format_bytes($total_storage_bytes) ?></td></tr>
    </tbody>
</table>

<h2>Share Links</h2>
<table>
    <tbody>
        <tr><td>Total created</td><td><?= (int)$total_share_links ?></td></tr>
        <tr><td>Currently active</td><td><?= (int)$active_share_links ?></td></tr>
        <tr><td>Total views (all links)</td><td><?= (int)$total_share_views ?></td></tr>
    </tbody>
</table>

<h2>Recent Registrations</h2>
<?php if (empty($recent_users)): ?>
    <p>No users yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>Username</th><th>Email</th><th>Registered</th></tr>
        </thead>
        <tbody>
            <?php foreach ($recent_users as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td><?= htmlspecialchars($user['email']) ?></td>
                    <td><?= htmlspecialchars($user['date_of_creation']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Recent Uploads</h2>
<?php if (empty($recent_uploads)): ?>
    <p>No uploads yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>File</th><th>Project</th><th>Collection</th><th>Uploaded</th></tr>
        </thead>
        <tbody>
            <?php foreach ($recent_uploads as $upload): ?>
                <tr>
                    <td><?= htmlspecialchars($upload['original_filename']) ?></td>
                    <td><?= htmlspecialchars($upload['project_name']) ?></td>
                    <td><?= htmlspecialchars($upload['collection_name']) ?></td>
                    <td><?= htmlspecialchars($upload['uploaded_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>