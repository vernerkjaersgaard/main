<?php
// image_list.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT c.collection_id, c.collection_name, p.project_id, p.project_name
    FROM tb_collections c
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE c.collection_id = ? AND p.user_id = ?
");
$stmt->execute([$collection_id, $_SESSION['user_id']]);
$collection = $stmt->fetch();

if (!$collection)
{
    http_response_code(404);
    require_once __DIR__ . '/header.php';
    echo '<p>Collection not found.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$stmt = $pdo->prepare("
    SELECT original_filename, tag_color, notes
    FROM tb_images
    WHERE collection_id = ? AND status = 'complete'
    ORDER BY original_filename
");
$stmt->execute([$collection_id]);
$images = $stmt->fetchAll();

// Group by tag color, in a fixed meaningful order — 'none' (untagged) last,
// since it's the "everything else" bucket rather than a real tag choice.
// Images with notes are also collected into a separate list, shown as an
// extra section at the end; they stay in their colour group too.
$groups = ['red' => [], 'green' => [], 'blue' => [], 'yellow' => [], 'purple' => [], 'none' => []];
$with_notes = [];

foreach ($images as $image)
{
    $groups[$image['tag_color']][] = $image['original_filename'];

    if (!empty($image['notes']))
    {
        $label = ($image['tag_color'] === 'none') ? 'untagged' : $image['tag_color'];
        $with_notes[] = $image['original_filename'] . ' [' . $label . ']';
    }
}

$group_labels = [
    'red' => 'RED',
    'green' => 'GREEN',
    'blue' => 'BLUE',
    'yellow' => 'YELLOW',
    'purple' => 'PURPLE',
    'none' => 'UNTAGGED',
];

// Build the plain-text output — only groups that actually have images
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

if (!empty($with_notes))
{
    $lines[] = 'WITH NOTES (' . count($with_notes) . ', also listed above)';

    foreach ($with_notes as $entry)
    {
        $lines[] = $entry;
    }

    $lines[] = '';
}

$output_text = rtrim(implode("\n", $lines));

require_once __DIR__ . '/header.php';
?>

<p>
    <a href="projects.php">My Projects</a> &rarr;
    <a href="collections.php?project_id=<?= $collection['project_id'] ?>"><?= htmlspecialchars($collection['project_name']) ?></a> &rarr;
    <a href="upload.php?collection_id=<?= $collection_id ?>"><?= htmlspecialchars($collection['collection_name']) ?></a> &rarr;
    Image List
</p>
<h1>Image List &mdash; <?= htmlspecialchars($collection['collection_name']) ?></h1>

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

<?php require_once __DIR__ . '/footer.php'; ?>