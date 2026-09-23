<?php
// view.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);
$current_file = $_GET['file'] ?? '';

$stmt = $pdo->prepare("
    SELECT c.storage_path, c.collection_name
    FROM tb_collections c
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE c.collection_id = ? AND p.user_id = ?
");
$stmt->execute([$collection_id, $_SESSION['user_id']]);
$collection = $stmt->fetch();

if (!$collection)
{
    http_response_code(404);
    exit('Collection not found.');
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

require_once __DIR__ . '/header.php';
?>

<p><a href="upload.php?collection_id=<?= $collection_id ?>">&larr; Back to <?= htmlspecialchars($collection['collection_name']) ?></a></p>

<p>Image <?= $current_index + 1 ?> of <?= count($images) ?></p>

<div style="text-align:center;">
    <img src="image.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=medium"
         alt="<?= htmlspecialchars($safe_current) ?>"
         class="full-image">
    <p>
        <a href="image.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=full" target="_blank">
            View full resolution
        </a>
    </p>
</div>

<p style="text-align:center;">
    <?php if ($prev_file): ?>
        <a href="view.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($prev_file) ?>">&larr; Previous</a>
    <?php endif; ?>
    <?php if ($prev_file && $next_file): ?> &nbsp;|&nbsp; <?php endif; ?>
    <?php if ($next_file): ?>
        <a href="view.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($next_file) ?>">Next &rarr;</a>
    <?php endif; ?>
</p>

<script>
document.addEventListener('keydown', function (e)
{
    <?php if ($prev_file): ?>
    if (e.key === 'ArrowLeft')
    {
        window.location.href = 'view.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($prev_file) ?>';
    }
    <?php endif; ?>

    <?php if ($next_file): ?>
    if (e.key === 'ArrowRight')
    {
        window.location.href = 'view.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($next_file) ?>';
    }
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>