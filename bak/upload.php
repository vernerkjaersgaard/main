<?php


//upload.php


require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

$collection_id = (int)($_GET['collection_id'] ?? 0);



// Verify this collection exists AND belongs (via its project) to the logged-in user
$stmt = $pdo->prepare("
    SELECT c.collection_id, c.collection_name, c.storage_path, p.project_id, p.project_name
    FROM tb_collections c
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE c.collection_id = ? AND p.user_id = ?
");
$stmt->execute([$collection_id, $_SESSION['user_id']]);
$collection = $stmt->fetch();

ini_set('max_execution_time', 300);

if (!$collection)
{
    http_response_code(404);
    require_once __DIR__ . '/header.php';
    echo '<p>Collection not found.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$originals_dir = $collection['storage_path'] . '/originals';
$thumbs_dir    = $collection['storage_path'] . '/thumbs';

if (!is_dir($originals_dir))
{
    mkdir($originals_dir, 0775, true);
}
if (!is_dir($thumbs_dir))
{
    mkdir($thumbs_dir, 0775, true);
}

$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$max_file_size = 10 * 1024 * 1024; // 10 MB


$upload_errors = [];
$upload_successes = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['images']))
{
    $file_count = count($_FILES['images']['name']);

    for ($i = 0; $i < $file_count; $i++)
    {
        $original_name = $_FILES['images']['name'][$i];
        $tmp_name = $_FILES['images']['tmp_name'][$i];
        $error_code = $_FILES['images']['error'][$i];
        $file_size = $_FILES['images']['size'][$i];

        if ($error_code === UPLOAD_ERR_NO_FILE)
        {
            continue;
        }

        if ($error_code !== UPLOAD_ERR_OK)
        {
            $upload_errors[] = htmlspecialchars($original_name) . ': upload failed (error code ' . $error_code . ').';
            continue;
        }

        if ($file_size > $max_file_size)
        {
            $upload_errors[] = htmlspecialchars($original_name) . ': file is too large (max 10 MB).';
            continue;
        }

        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowed_extensions, true))
        {
            $upload_errors[] = htmlspecialchars($original_name) . ': only JPG, PNG, GIF, and WEBP files are allowed.';
            continue;
        }

        $image_info = @getimagesize($tmp_name);

        if ($image_info === false)
        {
            $upload_errors[] = htmlspecialchars($original_name) . ': not a valid image.';
            continue;
        }

        $safe_base = str_replace(' ', '_', pathinfo($original_name, PATHINFO_FILENAME));
        $safe_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', $safe_base);
        $filename = $safe_base . '.' . $extension;
        $counter = 1;

        while (file_exists($originals_dir . '/' . $filename))
        {
            $filename = $safe_base . '_' . $counter . '.' . $extension;
            $counter++;
        }

        $destination = $originals_dir . '/' . $filename;

        if (move_uploaded_file($tmp_name, $destination))
        {
            $manager = new ImageManager(new Driver());
            $thumb = $manager->read($destination);
            $thumb->scale(width: 400);
            $thumb->save($thumbs_dir . '/' . $filename);

            $upload_successes++;
        }
        else
        {
            $upload_errors[] = htmlspecialchars($original_name) . ': failed to save.';
        }
    }
}

// List existing images (from the originals folder)
$images = [];
foreach (scandir($originals_dir) as $entry)
{
    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
    if (in_array($ext, $allowed_extensions, true))
    {
        $images[] = $entry;
    }
}
sort($images);

require_once __DIR__ . '/header.php';
?>

<p>
    <a href="projects.php">My Projects</a> &rarr;
    <a href="collections.php?project_id=<?= $collection['project_id'] ?>"><?= htmlspecialchars($collection['project_name']) ?></a> &rarr;
    <?= htmlspecialchars($collection['collection_name']) ?>
</p>
<h1><?= htmlspecialchars($collection['collection_name']) ?></h1>

<?php if (!empty($upload_errors)): ?>
    <ul style="color:red;">
        <?php foreach ($upload_errors as $err): ?>
            <li><?= $err ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($upload_successes > 0): ?>
    <p style="color:green;"><?= $upload_successes ?> image<?= $upload_successes > 1 ? 's' : '' ?> uploaded successfully.</p>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <p style="color:green;"><?= (int)$_GET['deleted'] ?> image(s) deleted.</p>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <p style="color:red;">
        <?php
        $messages = [
            'nothing_ticked' => 'Please tick at least one image first.',
            'invalid_selection' => 'Invalid selection.',
            'not_implemented' => 'That feature is coming soon.',
            'unknown_action' => 'Please choose a valid action.',
        ];
        echo htmlspecialchars($messages[$_GET['error']] ?? 'Something went wrong.');
        ?>
    </p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <label>Upload images
        <input type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
    </label>
    <button type="submit">Upload</button>
</form>

<hr>

<?php if (!empty($images)): ?>

    <form method="post" action="gallery_action.php" id="gallery-form">
        <input type="hidden" name="collection_id" value="<?= $collection_id ?>">

        <div style="margin-bottom:1rem; display:flex; gap:1rem; align-items:center;">
            <label style="display:inline-flex; align-items:center; gap:0.5rem; width:auto;">
                <input type="checkbox" id="select-all">
                Select all
            </label>

            <select name="gallery_action" required>
                <option value="">Choose an action&hellip;</option>
                <option value="download_full">Download full size images (zip)</option>
                <option value="download_medium">Download medium scaled images (zip)</option>
                <option value="download_small">Download small scaled images (zip)</option>
                <option value="delete">Delete ticked images</option>
            </select>

            <button type="submit" id="apply-btn">Apply</button>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:1rem;">
            <?php foreach ($images as $image): ?>
                <div style="position:relative;">
                    <input type="checkbox" name="ticked[]" value="<?= htmlspecialchars($image) ?>"
                           class="thumb-checkbox"
                           style="position:absolute; top:8px; left:8px; width:20px; height:20px; z-index:1;">
                    <a href="image.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($image) ?>&size=full" target="_blank">
                        <img src="image.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($image) ?>&size=thumb"
                             alt="<?= htmlspecialchars($image) ?>"
                             style="width:100%; border-radius:8px;">
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </form>

<?php endif; ?>

<script>
document.getElementById('select-all')?.addEventListener('change', function ()
{
    document.querySelectorAll('.thumb-checkbox').forEach(cb => cb.checked = this.checked);
});

document.getElementById('gallery-form')?.addEventListener('submit', function (e)
{
    const ticked = document.querySelectorAll('.thumb-checkbox:checked').length;
    const action = document.querySelector('select[name="gallery_action"]').value;

    if (ticked === 0)
    {
        alert('Please tick at least one image first.');
        e.preventDefault();
        return;
    }

    if (action === 'delete' && !confirm(`Delete ${ticked} selected image(s)? This cannot be undone.`))
    {
        e.preventDefault();
    }
});
</script>


<?php require_once __DIR__ . '/footer.php'; ?>