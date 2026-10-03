<?php
// file_info.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);
$current_file = $_GET['file'] ?? '';

$stmt = $pdo->prepare("
    SELECT c.collection_name, p.project_id
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

// Handle adding a note. Same POST-Redirect-GET pattern as view.php.
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
        $file_row = $stmt->fetch();

        if ($file_row)
        {
            $entry = '[' . date('Y-m-d H:i') . '] ' . $_SESSION['username'] . ': ' . $note_text;
            $existing = $file_row['notes'];
            $new_notes = ($existing === null || $existing === '') ? $entry : $existing . "\n" . $entry;

            if (strlen($new_notes) > 4000)
            {
                header('Location: file_info.php?collection_id=' . $collection_id . '&file=' . urlencode($safe_current) . '&note_error=too_long');
                exit;
            }

            $update = $pdo->prepare("UPDATE tb_images SET notes = ? WHERE image_id = ?");
            $update->execute([$new_notes, $file_row['image_id']]);

            log_action($pdo, $_SESSION['user_id'], 'note_added', $collection['project_id'], $collection_id, 'on ' . $safe_current);
        }
    }

    header('Location: file_info.php?collection_id=' . $collection_id . '&file=' . urlencode($safe_current));
    exit;
}

$stmt = $pdo->prepare("
    SELECT i.original_filename, i.file_size, i.notes, aft.extension
    FROM tb_images i
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE i.collection_id = ? AND i.stored_filename = ? AND i.status = 'complete'
");
$stmt->execute([$collection_id, $safe_current]);
$file_info = $stmt->fetch();

if (!$file_info)
{
    http_response_code(404);
    exit('File not found.');
}

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
}

require_once __DIR__ . '/header.php';
?>

<p><a href="upload.php?collection_id=<?= $collection_id ?>">&larr; Back to <?= htmlspecialchars($collection['collection_name']) ?></a></p>

<div style="text-align:center;">
    <div class="filebadge" style="width:140px; margin:0 auto; aspect-ratio:1/1;">
        <span class="filebadge-ext"><?= htmlspecialchars($file_info['extension']) ?></span>
    </div>
    <h2 style="margin-top:1rem;"><?= htmlspecialchars($file_info['original_filename']) ?></h2>
    <p><?= format_bytes($file_info['file_size']) ?></p>
    <p>
        <a href="file_download.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>" role="button">
            Download
        </a>
    </p>
</div>

<div style="margin-top:1.5rem;">
    <h3>Notes</h3>

    <?php if (isset($_GET['note_error']) && $_GET['note_error'] === 'too_long'): ?>
        <p style="color:red;">Notes are full (4000 character limit reached) — please continue the conversation another way.</p>
    <?php endif; ?>

    <?php if ($file_info['notes']): ?>
        <div class="notes-box"><?= nl2br(htmlspecialchars($file_info['notes'])) ?></div>
    <?php else: ?>
        <p><em>No notes yet.</em></p>
    <?php endif; ?>

    <form method="post" style="margin-top:0.75rem;">
        <textarea name="note_text" rows="3" placeholder="Add a note..." required></textarea>
        <button type="submit">Add Note</button>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>