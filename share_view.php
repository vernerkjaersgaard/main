<?php
// share_view.php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);
$current_file = $_GET['file'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, c.collection_name, sl.project_id
    FROM tb_share_links sl
    JOIN tb_collections c ON c.project_id = sl.project_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL AND c.collection_id = ?
");
$stmt->execute([$token, $collection_id]);
$result = $stmt->fetch();

$is_expired = $result && $result['ttl_days'] !== null &&
    strtotime($result['created_at'] . ' +' . $result['ttl_days'] . ' days') < time();

if (!$result || $is_expired)
{
    http_response_code(404);
    exit('This link is invalid or has expired.');
}

$safe_current = basename($current_file);

if ($safe_current === '' || $safe_current !== $current_file)
{
    http_response_code(400);
    exit('Invalid filename.');
}

$stmt = $pdo->prepare("
    SELECT stored_filename
    FROM tb_images
    WHERE collection_id = ? AND status = 'complete'
    ORDER BY original_filename
");
$stmt->execute([$collection_id]);
$images = $stmt->fetchAll(PDO::FETCH_COLUMN);

$current_index = array_search($safe_current, $images, true);

if ($current_index === false)
{
    http_response_code(404);
    exit('Image not found.');
}

$prev_file = ($current_index > 0) ? $images[$current_index - 1] : null;
$next_file = ($current_index < count($images) - 1) ? $images[$current_index + 1] : null;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($result['collection_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">

<p><a href="share.php?token=<?= htmlspecialchars($token) ?>">&larr; Back to gallery</a></p>

<p>Image <?= $current_index + 1 ?> of <?= count($images) ?></p>

<div style="text-align:center;">
    <img src="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=medium"
         alt="<?= htmlspecialchars($safe_current) ?>"
         class="full-image">
    <p>
        <a href="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=full" target="_blank">
            View full resolution
        </a>
    </p>
</div>

<p style="text-align:center;">
    <?php if ($prev_file): ?>
        <a href="share_view.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($prev_file) ?>">&larr; Previous</a>
    <?php endif; ?>
    <?php if ($prev_file && $next_file): ?> &nbsp;|&nbsp; <?php endif; ?>
    <?php if ($next_file): ?>
        <a href="share_view.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($next_file) ?>">Next &rarr;</a>
    <?php endif; ?>
</p>

<script>
document.addEventListener('keydown', function (e)
{
    <?php if ($prev_file): ?>
    if (e.key === 'ArrowLeft')
    {
        window.location.href = 'share_view.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($prev_file) ?>';
    }
    <?php endif; ?>

    <?php if ($next_file): ?>
    if (e.key === 'ArrowRight')
    {
        window.location.href = 'share_view.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($next_file) ?>';
    }
    <?php endif; ?>
});
</script>

</main>
</body>
</html>