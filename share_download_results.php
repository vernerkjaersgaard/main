<?php
// share_download_results.php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);
$batch_id = $_GET['batch'] ?? '';
$zip_tokens = array_filter(explode(',', $_GET['tokens'] ?? ''));

if (!preg_match('/^[a-f0-9]{64}$/', $token) || !preg_match('/^[a-f0-9]{32}$/', $batch_id))
{
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/header.php';
?>

<h1>Your Download<?= count($zip_tokens) > 1 ? 's are' : ' is' ?> Ready</h1>

<?php if (empty($zip_tokens)): ?>
    <p>No files were generated.</p>
<?php else: ?>
    <ul>
        <?php foreach ($zip_tokens as $i => $zip_token): ?>
            <li><a href="share_zip_download.php?batch=<?= htmlspecialchars($batch_id) ?>&file=<?= htmlspecialchars($zip_token) ?>">Download zip <?= $i + 1 ?> of <?= count($zip_tokens) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p><a href="share.php?token=<?= htmlspecialchars($token) ?>">&larr; Back to gallery</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>