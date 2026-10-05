<?php
// share_file_info.php
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

// Handle adding a note. Same pattern as share_view.php. No session, so
// token/collection_id/file travel as hidden POST fields, and the author
// is always labeled generically as "Customer". After saving, the redirect
// goes back to the gallery grid, anchored at this file's tile.
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
            $entry = '[' . date('Y-m-d H:i') . '] Customer: ' . $note_text;
            $existing = $file_row['notes'];
            $new_notes = ($existing === null || $existing === '') ? $entry : $existing . "\n" . $entry;

            if (strlen($new_notes) > 4000)
            {
                header('Location: share_file_info.php?token=' . urlencode($token) . '&collection_id=' . $collection_id . '&file=' . urlencode($safe_current) . '&note_error=too_long');
                exit;
            }

            $update = $pdo->prepare("UPDATE tb_images SET notes = ? WHERE image_id = ?");
            $update->execute([$new_notes, $file_row['image_id']]);

            log_action($pdo, null, 'share_note_added', $result['project_id'], $collection_id, 'on ' . $safe_current);
        }
    }

    header('Location: share.php?token=' . urlencode($token) . '#f-' . $collection_id . '-' . $safe_current);
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
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($file_info['original_filename']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<main class="container">

<p><a href="share.php?token=<?= htmlspecialchars($token) ?>#f-<?= $collection_id ?>-<?= htmlspecialchars($safe_current) ?>">&larr; Back to gallery</a></p>

<div style="text-align:center;">
    <div class="filebadge" style="width:140px; margin:0 auto; aspect-ratio:1/1;">
        <span class="filebadge-ext"><?= htmlspecialchars($file_info['extension']) ?></span>
    </div>
    <h2 style="margin-top:1rem;"><?= htmlspecialchars($file_info['original_filename']) ?></h2>
    <p><?= format_bytes($file_info['file_size']) ?></p>
    <p>
        <a href="share_file_download.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection_id ?>&file=<?= urlencode($safe_current) ?>" role="button">
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
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="collection_id" value="<?= $collection_id ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($safe_current) ?>">
        <textarea name="note_text" rows="3" placeholder="Add a note..." required></textarea>
        <button type="submit">Add Note</button>
    </form>
</div>

</main>
</body>
</html>