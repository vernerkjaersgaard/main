<?php
// terms.php
require_once __DIR__ . '/header.php';

$terms_path = dirname(__DIR__) . '/terms_of_use.html';
?>

<div style="max-width:700px;">
    <?php if (is_file($terms_path)): ?>
        <?= file_get_contents($terms_path) ?>
    <?php else: ?>
        <p>Terms of Use content is currently unavailable.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>