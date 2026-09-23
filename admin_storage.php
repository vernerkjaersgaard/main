<?php
// admin_storage.php
require_once __DIR__ . '/admin_check.php';

function get_directory_size($dir)
{
    if (!is_dir($dir))
    {
        return 0;
    }

    $size = 0;
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
            $size += get_directory_size($path);
        }
        else
        {
            $size += filesize($path);
        }
    }

    return $size;
}

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

// Get every user, and every project_id they own — a user's total disk
// usage is the sum of their individual project folders on disk, which
// each hold every collection's originals/thumbs/medium subfolders.
$users = $pdo->query("SELECT user_id, username, email FROM tb_users ORDER BY username")->fetchAll();

$storage_results = [];
$grand_total = 0;

foreach ($users as $user)
{
    $stmt = $pdo->prepare("SELECT project_id FROM tb_projects WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
    $project_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $user_total = 0;
    foreach ($project_ids as $project_id)
    {
        $user_total += get_directory_size(STORAGE_BASE_PATH . '/' . $project_id);
    }

    $storage_results[] = [
        'username' => $user['username'],
        'email' => $user['email'],
        'project_count' => count($project_ids),
        'bytes' => $user_total,
    ];

    $grand_total += $user_total;
}

// Largest usage first — the whole point of this page is spotting who's
// actually consuming the most space, so that's the natural default sort.
usort($storage_results, fn($a, $b) => $b['bytes'] <=> $a['bytes']);

require_once __DIR__ . '/header.php';
?>

<p><a href="admin.php">&larr; Admin Dashboard</a></p>
<h1>Disk Usage by User</h1>

<p><strong>Total across all users:</strong> <?= format_bytes($grand_total) ?></p>

<p><em>Note: this walks the actual filesystem (originals, thumbnails, and cached medium-size images all included), so it may take a moment to load on a large collection of files — this is a genuine, real-time calculation, not a cached or estimated figure.</em></p>

<table>
    <thead>
        <tr>
            <th>Username</th>
            <th>Email</th>
            <th>Projects</th>
            <th>Disk Usage</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($storage_results as $result): ?>
            <tr>
                <td><?= htmlspecialchars($result['username']) ?></td>
                <td><?= htmlspecialchars($result['email']) ?></td>
                <td><?= (int)$result['project_count'] ?></td>
                <td><?= format_bytes($result['bytes']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/footer.php'; ?>