<?php
// share_terms.php
// The photographer's terms of use, shown to the people they share galleries
// with. Reached only with a valid share token, like every other share page.
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/branding_functions.php';

$token = $_GET['token'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

$stmt = $pdo->prepare("
    SELECT ttl_days, created_at, project_id
    FROM tb_share_links
    WHERE token = ? AND revoked_at IS NULL
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

$terms = get_terms($pdo, $share['project_id']);

if ($terms === null)
{
    http_response_code(404);
    exit('No terms of use have been published.');
}

// Same branding as the rest of the share pages.
$brand        = get_branding($pdo, $share['project_id']);
$brand_style  = branding_style_block($brand);
$brand_header = branding_header_html($brand, $token);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Terms of use &mdash; Gallery</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<?= $brand_style ?>
</head>
<body>
<?= $brand_header ?>
<main class="container">

<p><a href="share.php?token=<?= htmlspecialchars($token) ?>">&larr; Back to gallery</a></p>

<h1>Terms of use</h1>

<?= branding_terms_html($terms['text']) ?>

<p><small>Last updated <?= htmlspecialchars(substr($terms['created_at'], 0, 10)) ?></small></p>

</main>
    <?= branding_footer_html($brand) ?>
</body>
</html>
