<?php
// download_results.php


require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);
$tokens = array_filter(explode(',', $_GET['tokens'] ?? ''));
?>

<h1>Your Download<?= count($tokens) > 1 ? 's are' : ' is' ?> Ready</h1>

<?php if (empty($tokens)): ?>
    <p>No files were generated.</p>
<?php else: ?>
    <ul>
        <?php foreach ($tokens as $i => $token): ?>
            <li><a href="zip_download.php?token=<?= htmlspecialchars($token) ?>">Download zip <?= $i + 1 ?> of <?= count($tokens) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p><a href="upload.php?collection_id=<?= $collection_id ?>">&larr; Back to collection</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>