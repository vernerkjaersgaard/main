<?php
// branding.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once dirname(__DIR__) . '/branding_functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

$user_id = (int)$_SESSION['user_id'];

// Branding is a permission the administrator grants, never something the
// customer can switch on for themselves.
$stmt = $pdo->prepare("SELECT branding_enabled FROM tb_users WHERE user_id = ?");
$stmt->execute([$user_id]);

if (!$stmt->fetchColumn())
{
    http_response_code(403);
    require_once __DIR__ . '/header.php';
    echo '<h1>Branding</h1><p>Branding is not enabled for your account. Please contact the administrator.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$stmt = $pdo->prepare("
    SELECT display_name, primary_color, accent_color, welcome_text, footer_text, contact_email, logo_filename, logo_version
    FROM tb_branding WHERE user_id = ?
");
$stmt->execute([$user_id]);
$row = $stmt->fetch();

if (!$row)
{
    $row = [
        'display_name' => null, 'primary_color' => null, 'accent_color' => null,
        'welcome_text' => null, 'footer_text' => null, 'contact_email' => null,
        'logo_filename' => null, 'logo_version' => 0,
    ];
}

$branding_dir = STORAGE_BASE_PATH . '/branding/' . $user_id;
$errors = [];

// What the form shows: the saved values, or what was just posted if a
// validation error sent the form back.
$form = [
    'display_name'  => (string)$row['display_name'],
    'use_primary'   => ($row['primary_color'] !== null),
    'primary_color' => $row['primary_color'] ?? '#013950',
    'use_accent'    => ($row['accent_color'] !== null),
    'accent_color'  => $row['accent_color'] ?? '#E0A800',
    'welcome_text'  => (string)$row['welcome_text'],
    'footer_text'   => (string)$row['footer_text'],
    'contact_email' => (string)$row['contact_email'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $display_name  = trim($_POST['display_name'] ?? '');
    $welcome_text  = str_replace(["\r\n", "\r"], "\n", trim($_POST['welcome_text'] ?? ''));
    $footer_text   = str_replace(["\r\n", "\r"], "\n", trim($_POST['footer_text'] ?? ''));
    $contact_email = trim($_POST['contact_email'] ?? '');
    $use_primary   = !empty($_POST['use_primary']);
    $use_accent    = !empty($_POST['use_accent']);
    $primary       = $use_primary ? branding_valid_hex($_POST['primary_color'] ?? '') : null;
    $accent        = $use_accent  ? branding_valid_hex($_POST['accent_color'] ?? '')  : null;

    // Plain text only: control characters are removed (line breaks kept)
    $clean = preg_replace('/\p{C}/u', '', $display_name);
    if ($clean === null) { $errors[] = 'The name contains invalid characters.'; } else { $display_name = $clean; }

    $clean = preg_replace('/[^\P{C}\n]/u', '', $welcome_text);
    if ($clean === null) { $errors[] = 'The welcome text contains invalid characters.'; } else { $welcome_text = $clean; }

    $clean = preg_replace('/[^\P{C}\n]/u', '', $footer_text);
    if ($clean === null) { $errors[] = 'The footer text contains invalid characters.'; } else { $footer_text = $clean; }

    if (mb_strlen($display_name) > 100)  { $errors[] = 'The name can be at most 100 characters.'; }
    if (mb_strlen($welcome_text) > 1000) { $errors[] = 'The welcome text can be at most 1000 characters.'; }
    if (mb_strlen($footer_text) > 300)   { $errors[] = 'The footer text can be at most 300 characters.'; }
    if ($use_primary && $primary === null) { $errors[] = 'The main colour is not valid.'; }
    if ($use_accent && $accent === null)   { $errors[] = 'The accent colour is not valid.'; }

    if ($contact_email !== '' && (strlen($contact_email) > 255 || filter_var($contact_email, FILTER_VALIDATE_EMAIL) === false))
    {
        $errors[] = 'The contact email address is not valid.';
    }

    // Logo upload: checked now, processed only if everything else is fine
    $remove_logo = !empty($_POST['remove_logo']);
    $new_logo = false;
    $f = $_FILES['logo'] ?? null;

    if ($f !== null && $f['error'] !== UPLOAD_ERR_NO_FILE)
    {
        if ($f['error'] !== UPLOAD_ERR_OK)
        {
            $errors[] = 'The logo upload failed (error code ' . (int)$f['error'] . ').';
        }
        elseif ($f['size'] > 2 * 1024 * 1024)
        {
            $errors[] = 'The logo is too large (maximum 2 MB).';
        }
        else
        {
            $info = @getimagesize($f['tmp_name']);

            if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true))
            {
                $errors[] = 'The logo must be a PNG, JPEG or WebP image.';
            }
            elseif ($info[0] * $info[1] > 25000000)
            {
                $errors[] = 'The logo image has too many pixels.';
            }
            else
            {
                $new_logo = true;
            }
        }
    }

    $logo_filename = $row['logo_filename'];
    $logo_version  = (int)$row['logo_version'];

    if (empty($errors) && $new_logo)
    {
        if (!is_dir($branding_dir))
        {
            mkdir($branding_dir, 0775, true);
        }

        $tmp_target = $branding_dir . '/logo.new.png';

        try
        {
            // Re-encoding as PNG discards anything hidden in the uploaded file
            $manager = new ImageManager(new Driver());
            $manager->read($f['tmp_name'])->scaleDown(width: 600, height: 200)->save($tmp_target);
            rename($tmp_target, $branding_dir . '/logo.png');
            $logo_filename = 'logo.png';
            $logo_version++;
        }
        catch (\Throwable $e)
        {
            @unlink($tmp_target);
            $errors[] = 'The logo image could not be processed.';
        }
    }
    elseif (empty($errors) && $remove_logo && $logo_filename !== null)
    {
        @unlink($branding_dir . '/' . $logo_filename);
        $logo_filename = null;
        $logo_version++;
    }

    if (empty($errors))
    {
        $pdo->prepare("INSERT IGNORE INTO tb_branding (user_id) VALUES (?)")->execute([$user_id]);

        $update = $pdo->prepare("
            UPDATE tb_branding
            SET display_name = ?, primary_color = ?, accent_color = ?, welcome_text = ?, footer_text = ?,
                contact_email = ?, logo_filename = ?, logo_version = ?
            WHERE user_id = ?
        ");
        $update->execute([
            ($display_name === '' ? null : $display_name),
            $primary,
            $accent,
            ($welcome_text === '' ? null : $welcome_text),
            ($footer_text === '' ? null : $footer_text),
            ($contact_email === '' ? null : $contact_email),
            $logo_filename,
            $logo_version,
            $user_id,
        ]);

        log_action($pdo, $user_id, 'branding_updated', null, null, null);

        header('Location: branding.php?saved=1');
        exit;
    }

    // Errors: show the form again with what was typed
    $form = [
        'display_name'  => $display_name,
        'use_primary'   => $use_primary,
        'primary_color' => $primary ?? '#013950',
        'use_accent'    => $use_accent,
        'accent_color'  => $accent ?? '#E0A800',
        'welcome_text'  => $welcome_text,
        'footer_text'   => $footer_text,
        'contact_email' => $contact_email,
    ];
}

// Preview of the saved logo, embedded so no extra endpoint is needed
$logo_src = '';
$logo_path = $branding_dir . '/' . (string)$row['logo_filename'];

if ($row['logo_filename'] !== null && preg_match('/^[A-Za-z0-9._-]+\z/', $row['logo_filename']) && is_file($logo_path))
{
    $logo_src = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

$band = $form['use_primary'] ? $form['primary_color'] : '#2B2B2B';

require_once __DIR__ . '/header.php';
?>

<p><a href="projects.php">&larr; My Projects</a></p>
<h1>Branding</h1>

<p>This is what the people you share galleries with will see. Leave anything empty to keep the standard look.</p>

<?php if (isset($_GET['saved'])): ?>
    <p style="color:green;">Branding saved.</p>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <p style="color:red;"><?= htmlspecialchars($err) ?></p>
<?php endforeach; ?>

<h2>Preview</h2>

<div id="preview-band" style="background:<?= htmlspecialchars($band) ?>; color:<?= htmlspecialchars(branding_text_color($band)) ?>; padding:1rem 1.5rem; display:flex; align-items:center; gap:1rem; border-radius:6px;">
    <img id="preview-logo" src="<?= htmlspecialchars($logo_src, ENT_QUOTES) ?>" alt="" style="max-height:56px; width:auto; <?= $logo_src === '' ? 'display:none;' : '' ?>">
    <span id="preview-name" style="font-size:1.4rem; font-weight:bold;"><?= htmlspecialchars($form['display_name']) ?></span>
</div>
<div id="preview-welcome" style="margin:1rem 0; padding:0.75rem 1rem; border-left:4px solid <?= htmlspecialchars($band) ?>; background:rgba(255,255,255,0.55); border-radius:4px; <?= $form['welcome_text'] === '' ? 'display:none;' : '' ?>"><?= nl2br(htmlspecialchars($form['welcome_text'])) ?></div>
<div id="preview-footer" style="margin:1rem 0; padding:0.75rem 1rem; text-align:center; font-size:0.85rem; border-top:3px solid <?= htmlspecialchars($band) ?>; <?= $form['footer_text'] === '' ? 'display:none;' : '' ?>"><?= nl2br(htmlspecialchars(str_replace('{year}', date('Y'), $form['footer_text']))) ?></div>

<form method="post" enctype="multipart/form-data">

    <label>Name shown in the header (optional)
        <input type="text" name="display_name" id="f-name" maxlength="100" value="<?= htmlspecialchars($form['display_name']) ?>">
    </label>

    <label>
        <input type="checkbox" name="use_primary" id="f-use-primary" value="1" <?= $form['use_primary'] ? 'checked' : '' ?>>
        Use my own main colour (header band, buttons, links)
    </label>
    <input type="color" name="primary_color" id="f-primary" value="<?= htmlspecialchars(strtolower($form['primary_color'])) ?>">

    <label>
        <input type="checkbox" name="use_accent" id="f-use-accent" value="1" <?= $form['use_accent'] ? 'checked' : '' ?>>
        Use an accent colour (stripe under the header, link hover)
    </label>
    <input type="color" name="accent_color" id="f-accent" value="<?= htmlspecialchars(strtolower($form['accent_color'])) ?>">

    <label>Welcome text (plain text, up to 1000 characters)
        <textarea name="welcome_text" id="f-welcome" rows="4" maxlength="1000"><?= htmlspecialchars($form['welcome_text']) ?></textarea>
    </label>

    <label>Footer line shown at the bottom of every page (plain text, up to 300 characters; {year} becomes the current year)
        <textarea name="footer_text" id="f-footer" rows="2" maxlength="300" placeholder="© {year} Your Company (CVR: 12121212122), Tel: +45 12 34 56 78"><?= htmlspecialchars($form['footer_text']) ?></textarea>
    </label>

    <label>Contact email shown to viewers (optional, otherwise your login email is used)
        <input type="email" name="contact_email" maxlength="255" value="<?= htmlspecialchars($form['contact_email']) ?>">
    </label>

    <label>Logo (PNG, JPEG or WebP, up to 2 MB; a PNG with a transparent background looks best)
        <input type="file" name="logo" id="f-logo" accept="image/png,image/jpeg,image/webp">
    </label>

    <?php if ($logo_src !== ''): ?>
        <label>
            <input type="checkbox" name="remove_logo" value="1">
            Remove my current logo
        </label>
    <?php endif; ?>

    <button type="submit">Save branding</button>
</form>

<script>
// Live preview. The server validates everything again when you save.
function textColor(hex)
{
    const c = [1, 3, 5].map(i => parseInt(hex.substr(i, 2), 16) / 255)
        .map(v => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4));
    const lum = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    return (1.05 / (lum + 0.05)) >= ((lum + 0.05) / 0.05) ? '#FFFFFF' : '#000000';
}

function refreshPreview()
{
    const band = document.getElementById('f-use-primary').checked ? document.getElementById('f-primary').value : '#2B2B2B';
    const bandEl = document.getElementById('preview-band');
    bandEl.style.background = band;
    bandEl.style.color = textColor(band);

    document.getElementById('preview-name').textContent = document.getElementById('f-name').value;

    const welcome = document.getElementById('preview-welcome');
    const text = document.getElementById('f-welcome').value;
    welcome.textContent = text;
    welcome.style.whiteSpace = 'pre-line';
    welcome.style.display = text.trim() === '' ? 'none' : '';
    welcome.style.borderLeftColor = band;

    const footer = document.getElementById('preview-footer');
    const footerText = document.getElementById('f-footer').value;
    footer.textContent = footerText.replace(/\{year\}/g, new Date().getFullYear());
    footer.style.whiteSpace = 'pre-line';
    footer.style.display = footerText.trim() === '' ? 'none' : '';
    footer.style.borderTopColor = band;
}

['f-name', 'f-welcome', 'f-footer', 'f-primary', 'f-use-primary', 'f-accent', 'f-use-accent'].forEach(function (id)
{
    document.getElementById(id).addEventListener('input', refreshPreview);
    document.getElementById(id).addEventListener('change', refreshPreview);
});

document.getElementById('f-logo').addEventListener('change', function ()
{
    const file = this.files[0];
    const img = document.getElementById('preview-logo');

    if (!file)
    {
        return;
    }

    const reader = new FileReader();
    reader.onload = function (e)
    {
        img.src = e.target.result;
        img.style.display = '';
    };
    reader.readAsDataURL(file);
});

refreshPreview();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>