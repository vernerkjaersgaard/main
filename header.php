<?php
// header.php
if (session_status() === PHP_SESSION_NONE)
{
    session_start();
}

require_once __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pics.zorum.dk</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<nav class="container-fluid site-header">
<ul>
<li><strong>Pics.zorum.dk</strong></li>
</ul>
<ul>
<?php if (isset($_SESSION['user_id'])): ?>

    <?php
    if (!function_exists('format_bytes'))
    {
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
    }

    $online_count = $pdo->query("
        SELECT COUNT(*) FROM tb_users
        WHERE last_seen_at >= NOW() - INTERVAL 5 MINUTE
    ")->fetchColumn();

    $load = sys_getloadavg();
    $load1 = $load[0];

    if ($load1 < 1.0)
    {
        $load_color = 'green';
    }
    elseif ($load1 < 2.0)
    {
        $load_color = 'yellow';
    }
    else
    {
        $load_color = 'red';
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(i.file_size), 0)
        FROM tb_images i
        JOIN tb_collections c ON c.collection_id = i.collection_id
        JOIN tb_projects p ON p.project_id = c.project_id
        WHERE p.user_id = ? AND i.status = 'complete'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $my_usage_bytes = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT storage_cap_mb FROM tb_users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $my_cap_mb = $stmt->fetchColumn();
    ?>

    <li title="Users active in the last 5 minutes">
        <span class="status-dot status-green"></span><?= (int)$online_count ?> online
    </li>
    <li title="Server load">
        <span class="status-dot status-<?= $load_color ?>"></span>Server
    </li>
    <li title="Your storage usage">
        <?= format_bytes($my_usage_bytes) ?><?= $my_cap_mb !== null ? ' / ' . $my_cap_mb . ' MB' : ' (unlimited)' ?>
    </li>

    <li><a href="projects.php">My Projects</a></li>
    <?php if (!empty($_SESSION['is_admin'])): ?>
        <li><a href="admin.php">Admin</a></li>
    <?php endif; ?>
    <li><?= htmlspecialchars($_SESSION['username']) ?></li>
    <li><a href="logout.php">Log out</a></li>

<?php else: ?>

    <li><a href="login.php">Log in</a></li>
    <li><a href="register.php">Register</a></li>

<?php endif; ?>
</ul>
</nav>
<main class="container">