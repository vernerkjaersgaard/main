<?php
// share_image_list.php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, c.collection_name
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

$stmt = $pdo->prepare("
    SELECT original_filename, tag_color
    FROM tb_images
    WHERE collection_id = ? AND status = 'complete'
    ORDER BY original_filename
");
$stmt->execute([$collection_id]);
$images = $stmt->fetchAll();

$groups = ['red' => [], 'green' => [], 'blue' => [], 'yellow' => [], 'purple' => [], 'none' => []];
foreach ($images as $image)
{
    $groups[$image['tag_color']][] = $image['original_filename'];
}

$group_labels = [
    'red' => 'RED',
    'green' => 'GREEN',
    'blue' => 'BLUE',
    'yellow' => 'YELLOW',
    'purple' => 'PURPLE',
    'none' => 'UNTAGGED',
];

$lines = [];
foreach ($groups as $color => $filenames)
{
    if (empty($filenames))
    {
        continue;
    }

    $lines[] = $group_labels[$color] . ' (' . count($filenames) . ')';
    foreach ($filenames as $filename)
    {
        $lines[] = $filename;
    }
    $lines[] = '';
}

$output_text = rtrim(implode("\n", $lines));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Image List &mdash; <?= htmlspecialchars($result['collection_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">

<p><a href="share.php?token=<?= htmlspecialchars($token) ?>">&larr; Back to gallery</a></p>
<h1>Image List &mdash; <?= htmlspecialchars($result['collection_name']) ?></h1>

<?php if (empty($images)): ?>

    <p>No images in this collection yet.</p>

<?php else: ?>

    <p>
        <button type="button" id="copy-btn">Copy to clipboard</button>
        <span id="copy-confirm" style="display:none; color:green;">Copied!</span>
    </p>

    <textarea id="image-list-output" readonly rows="20" style="width:100%; font-family:monospace;"><?= htmlspecialchars($output_text) ?></textarea>

<?php endif; ?>

<script>
document.getElementById('copy-btn')?.addEventListener('click', function ()
{
    const textarea = document.getElementById('image-list-output');
    textarea.select();

    navigator.clipboard.writeText(textarea.value).then(function ()
    {
        const confirm = document.getElementById('copy-confirm');
        confirm.style.display = 'inline';
        setTimeout(() => confirm.style.display = 'none', 1500);
    });
});
</script>

</main>
</body>
</html>