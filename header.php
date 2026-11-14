<?php
// header.php
if (session_status() === PHP_SESSION_NONE)
{
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/general_functions.php';

?>


<?php
$on_admin_page = (strpos(basename($_SERVER['SCRIPT_NAME']), 'admin') === 0);

// The photographer's own branding (logo and name) in the header, except on admin pages.
$own_brand_name = null;
$own_brand_logo = null;

if (isset($_SESSION['user_id']) && !$on_admin_page)
{
    try
    {
        $brand_stmt = $pdo->prepare("
            SELECT b.display_name, b.logo_filename, b.logo_version
            FROM tb_users u
            JOIN tb_branding b ON b.user_id = u.user_id
            WHERE u.user_id = ? AND u.branding_enabled = 1
        ");
        $brand_stmt->execute([(int)$_SESSION['user_id']]);
        $brand_row = $brand_stmt->fetch();

        if ($brand_row)
        {
            $brand_name = trim((string)$brand_row['display_name']);
            $own_brand_name = ($brand_name === '') ? null : mb_substr($brand_name, 0, 100);

            if (preg_match('/^[A-Za-z0-9._-]+\z/', (string)$brand_row['logo_filename']))
            {
                $own_brand_logo = 'branding_logo.php?v=' . (int)$brand_row['logo_version'];
            }
        }
    }
    catch (Throwable $e)
    {
        // Branding is cosmetic: on any problem the normal header is shown.
        error_log('header.php branding query failed: ' . $e->getMessage());
    }
}
?>


<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pics.zorum.dk</title>
<link rel="stylesheet" href="assets/pico.min.css">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<nav class="container-fluid site-header<?= $on_admin_page ? ' site-header-admin' : '' ?>">
<ul>
<li><a href="index.php"><?php if ($own_brand_logo !== null): ?><img src="<?= htmlspecialchars($own_brand_logo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($own_brand_name ?? 'Logo', ENT_QUOTES, 'UTF-8') ?>" class="own-brand-logo"><?php endif; ?><?php if ($own_brand_logo === null || $own_brand_name !== null): ?><strong><?= htmlspecialchars($own_brand_name ?? 'Pics.zorum.dk') ?></strong><?php endif; ?></a><?php if ($on_admin_page): ?> <span class="admin-badge">ADMIN</span><?php endif; ?></li>
</ul>
<ul>
<?php if (isset($_SESSION['user_id'])): ?>

    <?php

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

    $days_left = function_exists('subscription_days_remaining') ? subscription_days_remaining($pdo, (int)$_SESSION['user_id']) : null;
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

    <?php if ($days_left !== null): ?>
        <li title="Days left of your subscription">
        Remaining days: <?= (int)$days_left ?>
        </li>
    <?php endif; ?>

    <li><a href="projects.php">My Projects</a></li>
    <?php if (!empty($_SESSION['is_admin'])): ?>
        <li><a href="admin.php">Admin</a></li>
    <?php endif; ?>
    <li><a href="change_password.php" title="Change password"><?= htmlspecialchars($_SESSION['username']) ?></a></li>
    <li><a href="logout.php">Log out</a></li>

<?php else: ?>

    <li><a href="login.php">Log in</a></li>
        <?php if (!function_exists('registration_is_open') || registration_is_open()): ?>
    <li><a href="register.php">Register</a></li>
    <?php endif; ?>

<?php endif; ?>
</ul>
</nav>
 
<main class="container">

<?php if (!empty($_SESSION['password_weak']) && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php'): ?>
<p class="info-notice">Your password no longer meets our requirements (at least 12 characters, not a common password). <a href="change_password.php">Please choose a new one</a>.</p>
<?php endif; ?>