<?php
// share_view.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? $_POST['collection_id'] ?? 0);
$current_file = $_GET['file'] ?? $_POST['file'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

// The last condition limits a collection-level link to its own collection.
// A project-level link has sl.collection_id = NULL and matches every
// collection in its project, as before.
$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, c.collection_name, sl.project_id
    FROM tb_share_links sl
    JOIN tb_collections c ON c.project_id = sl.project_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL AND c.collection_id = ?
      AND (sl.collection_id IS NULL OR sl.collection_id = c.collection_id)
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

// Handle adding a note. POST-Redirect-GET pattern, so refreshing the page
// never re-submits the note. No session here, so the customer is always
// labeled generically. After saving, the redirect goes back to the gallery
// grid, anchored at this image's tile, instead of reloading the viewer.
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
            $entry = '[' . date('Y-m-d H:i') . '] Customer: ' . $note_text;
            $existing = $image_row['notes'];
            $new_notes = ($existing === null || $existing === '') ? $entry : $existing . "\n" . $entry;

            if (strlen($new_notes) > 4000)
            {
                header('Location: share_view.php?token=' . urlencode($token) . '&collection_id=' . $collection_id . '&file=' . urlencode($safe_current) . '&note_error=too_long');
                exit;
            }

            $update = $pdo->prepare("UPDATE tb_images SET notes = ? WHERE image_id = ?");
            $update->execute([$new_notes, $image_row['image_id']]);

            log_action($pdo, null, 'share_note_added', $result['project_id'], $collection_id, 'on ' . $safe_current);
        }
    }

    header('Location: share.php?token=' . urlencode($token) . '#f-' . $collection_id . '-' . $safe_current);
    exit;
}

// Prev/next only walks through viewable images, skipping foreign files
// (PSD, ZIP, etc.). file_kind lives in tb_allowed_filetypes, reached via
// the filetype_id foreign key, not on tb_images itself.
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

// Branding: the account owner's colours and header band. Every value
// defaults to "no branding", so if the helper file is missing, or branding
// is off for this account, the page looks as it always has.
$brand_style  = '';
$brand_header = '';

$branding_file = dirname(__DIR__) . '/branding_functions.php';

if (is_file($branding_file))
{
    require_once $branding_file;

    $brand        = get_branding($pdo, $result['project_id']);
    $brand_style  = branding_style_block($brand);
    $brand_header = branding_header_html($brand, $token);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($result['collection_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<?= $brand_style ?>
</head>
<body>
<?= $brand_header ?>
<main class="container">

<p><a href="share.php?token=<?= htmlspecialchars($token) ?>#f-<?= $collection_id ?>-<?= htmlspecialchars($safe_current) ?>">&larr; Back to gallery</a></p>

<p>Image <?= $current_index + 1 ?> of <?= count($images) ?></p>

<div class="share-viewer-bg">
    <img src="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=medium"
         alt="<?= htmlspecialchars($safe_current) ?>"
         class="share-full-image">
    <p>
        <a href="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>&size=full" target="_blank">
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
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="collection_id" value="<?= $collection_id ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($safe_current) ?>">
        <textarea name="note_text" rows="3" placeholder="Add a note..." required></textarea>
        <button type="submit">Add Note</button>
    </form>
</div>

<p style="text-align:center; margin-top:1.5rem;">
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