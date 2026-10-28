<?php
// branding_terms.php
// The photographer's own "Terms of use" text, shown to the people they share
// galleries with. Plain text only. Every saved change becomes a new version.
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once dirname(__DIR__) . '/branding_functions.php';

$user_id      = (int)$_SESSION['user_id'];
$max_length   = 10000;   // characters
$max_versions = 200;     // older versions beyond this are removed

// Same permission as the Branding page: granted by the administrator,
// never something the customer can switch on for themselves.
$stmt = $pdo->prepare("SELECT branding_enabled FROM tb_users WHERE user_id = ?");
$stmt->execute([$user_id]);

if (!$stmt->fetchColumn())
{
    http_response_code(403);
    require_once __DIR__ . '/header.php';
    echo '<h1>Terms of use</h1><p>Branding is not enabled for your account. Please contact the administrator.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// The version in force is the newest one.
$stmt = $pdo->prepare("
    SELECT version_id, terms_text, created_at
    FROM tb_terms_versions
    WHERE user_id = ?
    ORDER BY version_id DESC
    LIMIT 1
");
$stmt->execute([$user_id]);
$current = $stmt->fetch();
$current_text = $current ? (string)$current['terms_text'] : '';

$errors = [];
$form_text = $current_text;

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $posted = (string)($_POST['terms_text'] ?? '');
    $form_text = $posted;   // if something is wrong, the form shows what was typed

    if (!csrf_valid($_POST['csrf_token'] ?? null))
    {
        $errors[] = 'The form has expired. Please try again.';
    }
    elseif (!mb_check_encoding($posted, 'UTF-8'))
    {
        $errors[] = 'The text contains invalid characters.';
    }
    else
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $posted));

        // Plain text only: control characters are removed (line breaks kept)
        $clean = preg_replace('/[^\P{C}\n]/u', '', $text);

        if ($clean === null)
        {
            $errors[] = 'The text contains invalid characters.';
        }
        else
        {
            // More than one blank line in a row adds nothing
            $collapsed = preg_replace("/\n{3,}/", "\n\n", $clean);
            $text = ($collapsed === null) ? $clean : $collapsed;
        }

        if (empty($errors) && mb_strlen($text) > $max_length)
        {
            $errors[] = 'The text can be at most ' . number_format($max_length) . ' characters (it has ' . number_format(mb_strlen($text)) . ').';
        }

        if (empty($errors))
        {
            if ($text === $current_text)
            {
                header('Location: branding_terms.php?nochange=1');
                exit;
            }

            // A new version is added; nothing is overwritten.
            $insert = $pdo->prepare("INSERT INTO tb_terms_versions (user_id, terms_text) VALUES (?, ?)");
            $insert->execute([$user_id, $text]);

            // Keep only the newest versions.
            $prune = $pdo->prepare("
                DELETE FROM tb_terms_versions
                WHERE user_id = ?
                  AND version_id NOT IN (
                      SELECT version_id FROM (
                          SELECT version_id FROM tb_terms_versions
                          WHERE user_id = ?
                          ORDER BY version_id DESC
                          LIMIT " . (int)$max_versions . "
                      ) AS newest
                  )
            ");
            $prune->execute([$user_id, $user_id]);

            log_action($pdo, $user_id, 'terms_updated', null, null, $text === '' ? 'removed' : null);

            header('Location: branding_terms.php?saved=1');
            exit;
        }
    }
}

// The last few versions, for the history list
$stmt = $pdo->prepare("
    SELECT version_id, created_at, CHAR_LENGTH(terms_text) AS chars
    FROM tb_terms_versions
    WHERE user_id = ?
    ORDER BY version_id DESC
    LIMIT 10
");
$stmt->execute([$user_id]);
$history = $stmt->fetchAll();

// A starting point the photographer can insert and then rewrite. Nothing is
// saved until "Save terms" is clicked.
$example_text = "TERMS OF USE FOR THESE IMAGES\n\n"
    . "1. Copyright\n"
    . "All images in this gallery are protected by copyright and remain the property of [photographer / company name].\n\n"
    . "2. What you may do\n"
    . "You may use the images you have received for [personal use / the purposes we agreed, for example your own website and social media]. Please credit [name] where reasonable.\n\n"
    . "3. What you may not do\n"
    . "Without our written permission you may not sell or license the images, pass them on to others for commercial use, or edit them in a way that changes their meaning.\n\n"
    . "4. Availability\n"
    . "This gallery link is available for [30] days. Please download the images you want to keep before it expires.\n\n"
    . "5. Questions\n"
    . "If you are unsure whether a use is covered, please contact us at [email address] before using the image.\n\n"
    . "This is an example only. Replace everything in [brackets] and adjust the text to your own agreement with your customer.";

require_once __DIR__ . '/header.php';
?>

<p><a href="branding.php">&larr; Branding</a></p>
<h1>Terms of use for your customers</h1>

<p>Write the terms that apply when the people you share galleries with use your images, for example usage rights, credit and how long the link is available. Customers see a "Terms of use" link next to "Email the photographer" on their gallery page.</p>

<p><small>You are responsible for this text. It is shown in addition to, and cannot override, the Terms of Use of this service. This page gives no legal advice; if in doubt, ask a lawyer. Plain text only: a blank line starts a new paragraph, and no formatting or links are possible.</small></p>

<?php if (isset($_GET['saved'])): ?>
    <p style="color:green;"><?= $current_text === '' ? 'Terms removed. Customers no longer see a Terms of use link.' : 'Terms saved as a new version.' ?></p>
<?php endif; ?>
<?php if (isset($_GET['nochange'])): ?>
    <p>No changes to save.</p>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <p style="color:red;"><?= htmlspecialchars($err) ?></p>
<?php endforeach; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <label>Your terms of use (plain text, up to <?= number_format($max_length) ?> characters; leave empty to publish no terms)
        <textarea name="terms_text" id="f-terms" rows="22" maxlength="<?= (int)$max_length ?>"><?= htmlspecialchars($form_text) ?></textarea>
    </label>
    <p><small><span id="char-count">0</span> / <?= number_format($max_length) ?> characters</small></p>

    <button type="submit">Save terms</button>
    <button type="button" id="insert-example" class="secondary">Insert example text</button>
</form>

<?php if ($current_text !== ''): ?>
    <h2>As your customers see it</h2>
    <?= branding_terms_html($current_text) ?>
<?php endif; ?>

<?php if (!empty($history)): ?>
    <h2>Saved versions</h2>
    <table>
        <thead>
            <tr><th>Version</th><th>Saved</th><th>Length</th></tr>
        </thead>
        <tbody>
        <?php foreach ($history as $index => $version): ?>
            <tr>
                <td>#<?= (int)$version['version_id'] ?><?= $index === 0 ? ' (in force)' : '' ?></td>
                <td><?= htmlspecialchars($version['created_at']) ?></td>
                <td><?= (int)$version['chars'] === 0 ? 'empty (terms removed)' : number_format((int)$version['chars']) . ' characters' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><small>Every version is kept, up to the latest <?= (int)$max_versions ?>.</small></p>
<?php endif; ?>

<script>
const termsBox = document.getElementById('f-terms');
const charCount = document.getElementById('char-count');

function updateCount()
{
    charCount.textContent = termsBox.value.length.toLocaleString();
}

termsBox.addEventListener('input', updateCount);
updateCount();

document.getElementById('insert-example').addEventListener('click', function ()
{
    if (termsBox.value.trim() !== '' && !confirm('Replace the text in the box with the example text? Nothing is saved until you click "Save terms".'))
    {
        return;
    }

    termsBox.value = <?= json_encode($example_text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    updateCount();
    termsBox.focus();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
