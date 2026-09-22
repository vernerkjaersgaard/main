<?php
// share.php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Link not found.');
}

$stmt = $pdo->prepare("
    SELECT sl.share_id, sl.ttl_days, sl.created_at, p.project_id, p.project_name
    FROM tb_share_links sl
    JOIN tb_projects p ON p.project_id = sl.project_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL
");
$stmt->execute([$token]);
$share = $stmt->fetch();

$is_expired = $share && $share['ttl_days'] !== null &&
    strtotime($share['created_at'] . ' +' . $share['ttl_days'] . ' days') < time();

if (!$share || $is_expired)
{
    http_response_code(404);
    exit('This link is invalid or has expired.');
}

// Track usage
$update = $pdo->prepare("UPDATE tb_share_links SET view_count = view_count + 1, last_accessed = NOW() WHERE share_id = ?");
$update->execute([$share['share_id']]);

$stmt = $pdo->prepare("SELECT collection_id, collection_name, storage_path FROM tb_collections WHERE project_id = ? ORDER BY date_of_creation");
$stmt->execute([$share['project_id']]);
$collections = $stmt->fetchAll();

$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($share['project_name']) ?> &mdash; Gallery</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
</head>
<body>
<main class="container">

<h1><?= htmlspecialchars($share['project_name']) ?></h1>

<?php if (empty($collections)): ?>

    <p>No images have been added to this project yet.</p>

<?php else: ?>

    <?php foreach ($collections as $collection): ?>
        <h2><?= htmlspecialchars($collection['collection_name']) ?></h2>

        <?php
        $originals_dir = $collection['storage_path'] . '/originals';
        $images = [];

        if (is_dir($originals_dir))
        {
            foreach (scandir($originals_dir) as $entry)
            {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_extensions, true))
                {
                    $images[] = $entry;
                }
            }
            sort($images);
        }
        ?>

        <?php if (empty($images)): ?>
            <p><em>No images in this collection yet.</em></p>
        <?php else: ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:1rem; margin-bottom:2rem;">
                <?php foreach ($images as $image): ?>
                    <a href="share_view.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>&file=<?= urlencode($image) ?>" target="_blank">
                        <img src="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>&file=<?= urlencode($image) ?>&size=thumb"
                             alt="<?= htmlspecialchars($image) ?>"
                             class="thumbnail">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

<?php endif; ?>

</main>
</body>
</html>