<?php
// view.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);
$current_file = $_GET['file'] ?? '';

$stmt = $pdo->prepare("
    SELECT c.storage_path, c.collection_name, p.project_id
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

// Handle adding a note. POST-Redirect-GET pattern: after saving, redirect
// so refreshing the page never re-submits the note. The redirect now goes
// back to the gallery grid, anchored at this image's tile, instead of
// reloading the viewer.
if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $note_text = trim($_POST['note_text'] ?? '');

    if ($note_text !== '')
    {
        $stmt = $pdo->prepare("
            SELECT image_id, notes FROM tb_images
            WHERE collection_id = ? AND stored_filename = ? AND status = 'complete'
        ");
        $stmt->execute([$collection_id, $safe_current]);
        $image_row = $stmt->fetch();

        if ($image_row)
        {
            $entry = '[' . date('Y-m-d H:i') . '] ' . $_SESSION['username'] . ': ' . $note_text;
            $existing = $image_row['notes'];
            $new_notes = ($existing === null || $existing === '') ? $entry : $existing . "\n" . $entry;

            if (strlen($new_notes) > 4000)
            {
                header('Location: view.php?collection_id=' . $collection_id . '&file=' . urlencode($safe_current) . '&note_error=too_long');
                exit;
            }

            $update = $pdo->prepare("UPDATE tb_images SET notes = ? WHERE image_id = ?");
            $update->execute([$new_notes, $image_row['image_id']]);

            log_action($pdo, $_SESSION['user_id'], 'note_added', $collection['project_id'], $collection_id, 'on ' . $safe_current);
        }
    }

    header('Location: upload.php?collection_id=' . $collection_id . '#f-' . $collection_id . '-' . $safe_current);
    exit;
}

// Prev/next navigation only walks through viewable images, skipping
// foreign files (PSD, ZIP, etc.). file_kind comes from tb_allowed_filetypes
// via the filetype_id foreign key, not from a column on tb_images.
$stmt = $pdo->prepare("
    SELECT i.stored_filename
    FROM tb_images i
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE i.collection_id = ? AND i.status = 'complete' AND aft.file_kind = 'image'
    ORDER BY i.original_filename
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

$stmt = $pdo->prepare("SELECT notes FROM tb_images WHERE collection_id = ? AND stored_filename = ?");
$stmt->execute([$collection_id, $safe_current]);
$current_notes = $stmt->fetchColumn();

require_once __DIR__ . '/header.php';
?>

<p><a href="upload.php?collection_id=<?= $collection_id ?>#f-<?= $collection_id ?>-<?= htmlspecialchars($safe_current) ?>">&larr; Back to <?= htmlspecialchars($collection['collection_name']) ?></a></p>

<p>Image <?= $current_index + 1 ?> of <?= count($images) ?></p>


<div style="text-align:center;">
    <img src="image.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=medium"
         alt="<?= htmlspecialchars($safe_current) ?>"
         class="full-image">
    <p>
        <a href="image.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=full" target="_blank">
            View full resolution
        </a>
        &nbsp;|&nbsp;
        <a href="#notes"><?= $current_notes ? '💬 View notes' : '💬 Add a note' ?></a>
    </p>
</div>

<div id="notes" style="margin-top:1.5rem;">
    <h3>Notes</h3>

    <?php if (isset($_GET['note_error']) && $_GET['note_error'] === 'too_long'): ?>
        <p style="color:red;">Notes are full (4000 character limit reached) — please continue the conversation another way.</p>
    <?php endif; ?>

    <?php if ($current_notes): ?>
        <div class="notes-box"><?= nl2br(htmlspecialchars($current_notes)) ?></div>
    <?php else: ?>
        <p><em>No notes yet.</em></p>
    <?php endif; ?>

    <form method="post" style="margin-top:0.75rem;">
        <textarea name="note_text" rows="3" placeholder="Add a note..." required></textarea>
        <button type="submit">Add Note</button>
    </form>
</div>



<p style="text-align:center; margin-top:1.5rem;">
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