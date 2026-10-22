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
/*
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
}*/

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

// ---------------------------------------------------------------
// Usage over time (read-only). Data comes from the two summary tables
// filled by storage_snapshot.php (nightly) and usage_add() (live).
// If the tables are missing or empty, this section says so and the
// rest of the page is unaffected.
// ---------------------------------------------------------------
$months = [];
$usage_rows = [];
$usage_error = false;

$selected_month = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selected_month))
{
    $selected_month = date('Y-m');
}

try
{
    $months = $pdo->query("
        SELECT DATE_FORMAT(snap_date, '%Y-%m') AS m FROM tb_storage_daily
        UNION
        SELECT DATE_FORMAT(usage_date, '%Y-%m') AS m FROM tb_usage_daily
        ORDER BY m DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array(date('Y-m'), $months, true))
    {
        array_unshift($months, date('Y-m'));
    }

    if (!in_array($selected_month, $months, true))
    {
        $months[] = $selected_month;
        rsort($months);
    }

    $range_start = $selected_month . '-01';
    $range_end   = date('Y-m-d', strtotime($range_start . ' +1 month'));

    $stmt = $pdo->prepare("
        SELECT user_id, AVG(bytes) AS avg_bytes, MAX(bytes) AS max_bytes
        FROM tb_storage_daily
        WHERE snap_date >= ? AND snap_date < ?
        GROUP BY user_id
    ");
    $stmt->execute([$range_start, $range_end]);
    $storage_by_user = [];

foreach ($stmt->fetchAll() as $row)
    {
        $storage_by_user[(int)$row['user_id']] = $row;
    }

    $stmt = $pdo->prepare("
        SELECT user_id,
               SUM(uploads) AS uploads, SUM(bytes_up) AS bytes_up,
               SUM(downloads) AS downloads, SUM(bytes_down) AS bytes_down,
               SUM(share_downloads) AS share_downloads, SUM(share_bytes_down) AS share_bytes_down
        FROM tb_usage_daily
        WHERE usage_date >= ? AND usage_date < ?
        GROUP BY user_id
    ");
    $stmt->execute([$range_start, $range_end]);
    $usage_by_user = [];

foreach ($stmt->fetchAll() as $row)
    {
        $usage_by_user[(int)$row['user_id']] = $row;
    }

foreach ($users as $user)
    {
        $uid = (int)$user['user_id'];
        $s = $storage_by_user[$uid] ?? null;
        $u = $usage_by_user[$uid] ?? null;

        $usage_rows[] = [
'username'        => $user['username'],
'avg_bytes'       => $s ? (float)$s['avg_bytes'] : null,
'max_bytes'       => $s ? (float)$s['max_bytes'] : null,
'uploads'         => $u ? (int)$u['uploads'] : 0,
'bytes_up'        => $u ? (int)$u['bytes_up'] : 0,
'downloads'       => $u ? (int)$u['downloads'] : 0,
'bytes_down'      => $u ? (int)$u['bytes_down'] : 0,
'share_downloads' => $u ? (int)$u['share_downloads'] : 0,
'share_bytes_down'=> $u ? (int)$u['share_bytes_down'] : 0,
        ];
    }
}
catch (Throwable $e)
{
    error_log('admin_storage usage section failed: ' . $e->getMessage());
    $usage_error = true;
}

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

<h2>Usage over time</h2>

<?php if ($usage_error): ?>
<p>The usage tables could not be read yet. Check that <code>tb_usage_daily</code> and <code>tb_storage_daily</code> exist.</p>
<?php else: ?>

<form method="get" action="admin_storage.php">
<label for="month">Month</label>
<select name="month" id="month">
<?php foreach ($months as $m): ?>
<option value="<?= htmlspecialchars($m) ?>"<?= ($m === $selected_month) ? ' selected' : '' ?>><?= htmlspecialchars($m) ?></option>
<?php endforeach; ?>
</select>
<button type="submit">Show</button>
</form>

<p><em>Stored = nightly snapshot of finished uploads (original files only, so lower than the live disk figure above, which also counts thumbnails and previews). Downloads count original files and zips; bytes are counted when a download starts.</em></p>

<table>
<thead>
<tr>
<th>Username</th>
<th>Avg stored</th>
<th>Peak stored</th>
<th>Uploaded</th>
<th>Own downloads</th>
<th>Customer downloads</th>
</tr>
</thead>
<tbody>
<?php foreach ($usage_rows as $r): ?>
<tr>
<td><?= htmlspecialchars($r['username']) ?></td>
<td><?= $r['avg_bytes'] === null ? '–' : format_bytes($r['avg_bytes']) ?></td>
<td><?= $r['max_bytes'] === null ? '–' : format_bytes($r['max_bytes']) ?></td>
<td><?= (int)$r['uploads'] ?> (<?= format_bytes($r['bytes_up']) ?>)</td>
<td><?= (int)$r['downloads'] ?> (<?= format_bytes($r['bytes_down']) ?>)</td>
<td><?= (int)$r['share_downloads'] ?> (<?= format_bytes($r['share_bytes_down']) ?>)</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>