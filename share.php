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

$update = $pdo->prepare("UPDATE tb_share_links SET view_count = view_count + 1, last_accessed = NOW() WHERE share_id = ?");
$update->execute([$share['share_id']]);

$stmt = $pdo->prepare("SELECT collection_id, collection_name FROM tb_collections WHERE project_id = ? ORDER BY date_of_creation");
$stmt->execute([$share['project_id']]);
$collections = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($share['project_name']) ?> &mdash; Gallery</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">

<h1><?= htmlspecialchars($share['project_name']) ?></h1>

<?php if (isset($_GET['tagged'])): ?>
    <p style="color:green;"><?= (int)$_GET['tagged'] ?> image(s) tagged.</p>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <p style="color:red;">
        <?php
        $messages = [
            'nothing_ticked' => 'Please tick at least one image first.',
            'invalid_selection' => 'Invalid selection.',
            'invalid_color' => 'Please choose a valid color.',
        ];
        echo htmlspecialchars($messages[$_GET['error']] ?? 'Something went wrong.');
        ?>
    </p>
<?php endif; ?>

<?php if (empty($collections)): ?>

    <p>No images have been added to this project yet.</p>

<?php else: ?>

    <?php foreach ($collections as $collection): ?>
        <h2><?= htmlspecialchars($collection['collection_name']) ?></h2>

        <?php
        $stmt = $pdo->prepare("
            SELECT stored_filename, original_filename, tag_color
            FROM tb_images
            WHERE collection_id = ? AND status = 'complete'
            ORDER BY original_filename
        ");
        $stmt->execute([$collection['collection_id']]);
        $images = $stmt->fetchAll();

        // Tally counts per color for the summary line above this collection's grid
        $tag_counts = ['red' => 0, 'green' => 0, 'blue' => 0, 'yellow' => 0, 'purple' => 0, 'none' => 0];
        foreach ($images as $image)
        {
            $tag_counts[$image['tag_color']]++;
        }
        ?>

        <?php if (empty($images)): ?>
            <p><em>No images in this collection yet.</em></p>
        <?php else: ?>

            <div class="tag-summary">
                <?php foreach ($tag_counts as $color => $count): ?>
                    <?php if ($count > 0): ?>
                        <span class="tag-summary-item">
                            <span class="tag-swatch tag-<?= $color ?>"></span>
                            <?= $count ?> <?= $color === 'none' ? 'untagged' : ucfirst($color) ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p><a href="share_image_list.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>">Generate copyable image list</a></p>

            <form method="post" action="share_action.php" class="tag-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="collection_id" value="<?= $collection['collection_id'] ?>">

                <div style="margin-bottom:1rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                    <label style="display:inline-flex; align-items:center; gap:0.5rem; width:auto;">
                        <input type="checkbox" class="select-all-cb">
                        Select all
                    </label>

                    <select name="tag_color" required>
                        <option value="">Tag selected as&hellip;</option>
                        <option value="red">🔴 Red</option>
                        <option value="green">🟢 Green</option>
                        <option value="blue">🔵 Blue</option>
                        <option value="yellow">🟡 Yellow</option>
                        <option value="purple">🟣 Purple</option>
                        <option value="none">⚪ Clear tag</option>
                    </select>

                    <button type="submit">Apply</button>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:1rem; margin-bottom:2rem;">
                    <?php foreach ($images as $image): ?>
                        <div style="position:relative;">
                            <input type="checkbox" name="ticked[]" value="<?= htmlspecialchars($image['stored_filename']) ?>"
                            class="thumb-checkbox<?= $image['tag_color'] !== 'none' ? ' tag-' . $image['tag_color'] : '' ?>"
                            style="position:absolute; top:8px; left:8px; width:20px; height:20px; z-index:1;">
                            <a href="share_view.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>&file=<?= urlencode($image['stored_filename']) ?>" target="_blank">
                                <img src="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>&file=<?= urlencode($image['stored_filename']) ?>&size=thumb"
                                     alt="<?= htmlspecialchars($image['original_filename']) ?>"
                                     class="thumbnail">
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>

        <?php endif; ?>
    <?php endforeach; ?>

<?php endif; ?>

<script>
document.querySelectorAll('.select-all-cb').forEach(function (selectAllBox)
{
    selectAllBox.addEventListener('change', function ()
    {
        this.closest('.tag-form').querySelectorAll('.thumb-checkbox').forEach(cb => cb.checked = this.checked);
    });
});

document.querySelectorAll('.tag-form').forEach(function (form)
{
    form.addEventListener('submit', function (e)
    {
        const ticked = form.querySelectorAll('.thumb-checkbox:checked').length;

        if (ticked === 0)
        {
            alert('Please tick at least one image first.');
            e.preventDefault();
        }
    });
});
</script>

</main>
</body>
</html>