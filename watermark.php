<?php
// watermark.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once dirname(__DIR__) . '/watermark_functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

$user_id = (int)$_SESSION['user_id'];

// Watermarking is a permission the administrator grants, never something
// the customer can switch on for themselves.
$stmt = $pdo->prepare("SELECT watermark_enabled FROM tb_users WHERE user_id = ?");
$stmt->execute([$user_id]);

if (!$stmt->fetchColumn())
{
    http_response_code(403);
    require_once __DIR__ . '/header.php';
    echo '<h1>Watermark</h1><p>Watermarking is not enabled for your account. Please contact the administrator.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$stmt = $pdo->prepare("
    SELECT watermark_file, style, opacity, size_percent, lock_new_uploads, render_version
    FROM tb_watermarks WHERE user_id = ?
");
$stmt->execute([$user_id]);
$row = $stmt->fetch();

if (!$row)
{
    $row = [
        'watermark_file' => null, 'style' => 'center', 'opacity' => 60,
        'size_percent' => 40, 'lock_new_uploads' => 0, 'render_version' => 1,
    ];
}

$wm_dir = STORAGE_BASE_PATH . '/watermarks/' . $user_id;
$errors = [];

$form = [
    'style'            => $row['style'],
    'opacity'          => (int)$row['opacity'],
    'size_percent'     => (int)$row['size_percent'],
    'lock_new_uploads' => (int)$row['lock_new_uploads'] === 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $style    = wm_valid_style($_POST['style'] ?? '');
    $opacity  = (int)($_POST['opacity'] ?? 0);
    $size     = (int)($_POST['size_percent'] ?? 0);
    $lock_new = !empty($_POST['lock_new_uploads']);

    if ($style === null)                   { $errors[] = 'Please choose a valid style.'; }
    if ($opacity < 5 || $opacity > 100)    { $errors[] = 'The opacity must be between 5 and 100.'; }
    if ($size < 5 || $size > 100)          { $errors[] = 'The size must be between 5 and 100.'; }

    // Watermark file: checked now, processed only if everything else is fine.
    // PNG and WebP only: they can be transparent. A JPEG would give a solid box.
    $new_file = false;
    $f = $_FILES['watermark'] ?? null;

    if ($f !== null && $f['error'] !== UPLOAD_ERR_NO_FILE)
    {
        if ($f['error'] !== UPLOAD_ERR_OK)
        {
            $errors[] = 'The upload failed (error code ' . (int)$f['error'] . ').';
        }
        elseif ($f['size'] > 2 * 1024 * 1024)
        {
            $errors[] = 'The watermark file is too large (maximum 2 MB).';
        }
        else
        {
            $info = @getimagesize($f['tmp_name']);

            if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP], true))
            {
                $errors[] = 'The watermark must be a PNG or WebP image (with a transparent background).';
            }
            elseif ($info[0] * $info[1] > 25000000)
            {
                $errors[] = 'The watermark image has too many pixels.';
            }
            else
            {
                $new_file = true;
            }
        }
    }

    $file_name    = $row['watermark_file'];
    $file_changed = false;

    if ($lock_new && $file_name === null && !$new_file)
    {
        $errors[] = 'Upload a watermark file before turning on locking of new uploads.';
    }

    if (empty($errors) && $new_file)
    {
        if (!is_dir($wm_dir))
        {
            mkdir($wm_dir, 0775, true);
        }

        $tmp_target = $wm_dir . '/watermark.new.png';

        try
        {
            // Re-encoding as PNG discards anything hidden in the uploaded file
            $manager = new ImageManager(new Driver());
            $manager->read($f['tmp_name'])->scaleDown(width: 1600, height: 1600)->save($tmp_target);
            rename($tmp_target, $wm_dir . '/watermark.png');
            $file_name    = 'watermark.png';
            $file_changed = true;
        }
        catch (\Throwable $e)
        {
            @unlink($tmp_target);
            $errors[] = 'The watermark image could not be processed.';
        }
    }

    if (empty($errors))
    {
        // Cached previews are built from the file, style, opacity and size.
        // If any of them changed, the version goes up and old previews are
        // simply no longer used.
        $changed = $file_changed
            || $style !== $row['style']
            || $opacity !== (int)$row['opacity']
            || $size !== (int)$row['size_percent'];

        $version = (int)$row['render_version'] + ($changed ? 1 : 0);

        $pdo->prepare("INSERT IGNORE INTO tb_watermarks (user_id) VALUES (?)")->execute([$user_id]);

        $update = $pdo->prepare("
            UPDATE tb_watermarks
            SET watermark_file = ?, style = ?, opacity = ?, size_percent = ?,
                lock_new_uploads = ?, render_version = ?
            WHERE user_id = ?
        ");
        $update->execute([$file_name, $style, $opacity, $size, ($lock_new ? 1 : 0), $version, $user_id]);

        log_action($pdo, $user_id, 'watermark_settings_updated', null, null, null);

        header('Location: watermark.php?saved=1');
        exit;
    }

    // Errors: show the form again with what was typed
    $form = [
        'style'            => $style ?? $row['style'],
        'opacity'          => $opacity,
        'size_percent'     => $size,
        'lock_new_uploads' => $lock_new,
    ];
}

// How many of this account's images are locked right now
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tb_images i
    JOIN tb_collections c ON c.collection_id = i.collection_id
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE p.user_id = ? AND i.watermark = 1
");
$stmt->execute([$user_id]);
$locked_count = (int)$stmt->fetchColumn();

// Preview: the real watermarking code applied to this account's most recent
// image, using the SAVED settings. It shows exactly what a customer would see.
$preview_src = '';

$stmt = $pdo->prepare("
    SELECT i.stored_filename, c.storage_path, p.project_id
    FROM tb_images i
    JOIN tb_collections c ON c.collection_id = i.collection_id
    JOIN tb_projects p ON p.project_id = c.project_id
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE p.user_id = ? AND i.status = 'complete' AND aft.file_kind = 'image'
    ORDER BY i.image_id DESC
    LIMIT 1
");
$stmt->execute([$user_id]);
$sample = $stmt->fetch();

$has_saved_file = ($row['watermark_file'] !== null);

if ($sample && $has_saved_file)
{
    $settings = wm_get_settings($pdo, $sample['project_id']);

    if ($settings !== null)
    {
        $preview_path = wm_render_preview($settings, $sample['storage_path'], $sample['stored_filename']);

        if ($preview_path !== null && is_file($preview_path))
        {
            $preview_src = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($preview_path));
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<p><a href="projects.php">&larr; My Projects</a></p>
<h1>Watermark</h1>

<p>Images you lock are shown to the people you share galleries with only as a watermarked preview. They cannot be viewed in full size or downloaded until you release them.</p>

<p><small>Images currently locked on your account: <strong><?= $locked_count ?></strong></small></p>

<?php if (isset($_GET['saved'])): ?>
    <p style="color:green;">Settings saved.</p>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <p style="color:red;"><?= htmlspecialchars($err) ?></p>
<?php endforeach; ?>

<h2>Preview</h2>

<?php if ($preview_src !== ''): ?>
    <p><img src="<?= htmlspecialchars($preview_src, ENT_QUOTES) ?>" alt="Watermark preview" style="max-width:100%; border-radius:6px;"></p>
    <p><small>Your most recent image with the saved settings. Changes you make below show here after you save.</small></p>
<?php elseif (!$has_saved_file): ?>
    <p><em>Upload a watermark file below and save, and a preview of your latest image will appear here.</em></p>
<?php elseif (!$sample): ?>
    <p><em>You have no images yet, so there is nothing to preview.</em></p>
<?php else: ?>
    <p style="color:red;">The preview could not be created. A locked image would show an error to viewers, not a clean image.</p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

    <label>Watermark file (PNG or WebP with a transparent background, up to 2 MB)
        <input type="file" name="watermark" accept="image/png,image/webp">
    </label>
    <?php if ($has_saved_file): ?>
        <p><small>A watermark file is saved. Choose a new file only if you want to replace it.</small></p>
    <?php endif; ?>

    <label>Style
        <select name="style">
            <option value="center" <?= $form['style'] === 'center' ? 'selected' : '' ?>>One watermark in the centre</option>
            <option value="tile" <?= $form['style'] === 'tile' ? 'selected' : '' ?>>Repeated across the whole image (hardest to crop out)</option>
        </select>
    </label>

    <label>Opacity: <output id="o-val"><?= (int)$form['opacity'] ?></output>%
        <input type="range" name="opacity" min="5" max="100" value="<?= (int)$form['opacity'] ?>" oninput="document.getElementById('o-val').textContent = this.value">
    </label>

    <label>Size: <output id="s-val"><?= (int)$form['size_percent'] ?></output>% of the image width (for the repeated style, each repeat is half of this)
        <input type="range" name="size_percent" min="5" max="100" value="<?= (int)$form['size_percent'] ?>" oninput="document.getElementById('s-val').textContent = this.value">
    </label>

    <label>
        <input type="checkbox" name="lock_new_uploads" value="1" <?= $form['lock_new_uploads'] ? 'checked' : '' ?>>
        Lock new uploads automatically (they start watermarked until you release them)
    </label>

    <button type="submit">Save</button>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>