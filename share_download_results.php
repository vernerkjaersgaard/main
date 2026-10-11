<?php
// share_download_results.php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);
$batch_id = $_GET['batch'] ?? '';
$zip_tokens = array_filter(explode(',', $_GET['tokens'] ?? ''));
$total = count($zip_tokens);

// How many locked images were left out of this download. Display only:
// share_action.php has already left them out. Forced to a whole number.
$locked_skipped = max(0, min(100000, (int)($_GET['locked'] ?? 0)));

if (!preg_match('/^[a-f0-9]{64}$/', $token) || !preg_match('/^[a-f0-9]{32}$/', $batch_id))
{
    http_response_code(404);
    exit('Not found.');
}

// Branding: the account owner's colours and header band. This page has
// never checked the share link against the database, and still doesn't:
// the lookup below is used only to find whose branding to show. If it finds
// nothing, or anything at all goes wrong, the page simply has no branding.
$brand_style  = '';
$brand_header = '';

$branding_file = dirname(__DIR__) . '/branding_functions.php';

if (is_file($branding_file))
{
    try
    {
        $stmt = $pdo->prepare("SELECT project_id FROM tb_share_links WHERE token = ? AND revoked_at IS NULL");
        $stmt->execute([$token]);
        $project_id = $stmt->fetchColumn();

        if ($project_id !== false)
        {
            require_once $branding_file;

            $brand        = get_branding($pdo, (int)$project_id);
            $brand_style  = branding_style_block($brand);
            $brand_header = branding_header_html($brand, $token);
        }
    }
    catch (\Throwable $e)
    {
        $brand_style  = '';
        $brand_header = '';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Your download</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<?= $brand_style ?>
</head>
<body>
<?= $brand_header ?>
<main class="container">

<h1>Your Download<?= $total > 1 ? 's are' : ' is' ?> Ready</h1>

<?php if ($locked_skipped > 0): ?>
    <p><strong><?= $locked_skipped ?> locked image<?= $locked_skipped === 1 ? ' was' : 's were' ?> not included.</strong>
    Locked images are previews and cannot be downloaded until the photographer releases them.</p>
<?php endif; ?>

<?php if (empty($zip_tokens)): ?>
    <p>No files were generated.</p>
<?php else: ?>
    <ul>
    <?php foreach ($zip_tokens as $i => $zip_token): ?>
        <li><a href="share_zip_download.php?batch=<?= htmlspecialchars($batch_id) ?>&file=<?= htmlspecialchars($zip_token) ?>&part=<?= $i + 1 ?>&total=<?= $total ?>">Download zip <?= $i + 1 ?> of <?= $total ?></a></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p><a href="share.php?token=<?= htmlspecialchars($token) ?>">&larr; Back to gallery</a></p>

</main>
<?= isset($brand) ? branding_footer_html($brand) : '' ?>
</body>
</html>