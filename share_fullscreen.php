<?php
// share_fullscreen.php
//
// "Full screen view": one image at a time, nothing else on the screen.
// Tap/click the left or right edge (or swipe, or use the arrow keys) to move
// between images; tap/click the middle (or press Esc) to return to the
// overview. Uses the same share token checks and the same image endpoint
// (share_image.php, medium size) as share_view.php, so a locked image still
// shows only its watermarked preview.
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$collection_id = (int)($_GET['collection_id'] ?? 0);
$current_file = $_GET['file'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

// The last condition limits a collection-level link to its own collection.
// A project-level link has sl.collection_id = NULL and matches every
// collection in its project.
$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at, c.collection_name
    FROM tb_share_links sl
    JOIN tb_collections c ON c.project_id = sl.project_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL AND c.collection_id = ?
      AND (sl.collection_id IS NULL OR sl.collection_id = c.collection_id)
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

// Same list, same order, as the grid and as share_view.php: complete image
// files only (foreign files such as PSD or ZIP are skipped), by original name.
$stmt = $pdo->prepare("
    SELECT i.stored_filename
    FROM tb_images i
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE i.collection_id = ? AND i.status = 'complete' AND aft.file_kind = 'image'
    ORDER BY i.original_filename
");
$stmt->execute([$collection_id]);
$images = $stmt->fetchAll(PDO::FETCH_COLUMN);

$current_index = array_search($safe_current, $images, true);

if ($current_index === false)
{
    http_response_code(404);
    exit('Image not found.');
}

$prev_file = ($current_index > 0) ? $images[$current_index - 1] : null;
$next_file = ($current_index < count($images) - 1) ? $images[$current_index + 1] : null;

// Address builders. The token is 64 hex characters (checked above) and file
// names are URL-encoded, so everything is safe to place in an attribute.
$base = 'share_fullscreen.php?token=' . $token . '&collection_id=' . $collection_id . '&file=';

function fs_image_url($token, $collection_id, $file)
{
    return 'share_image.php?token=' . $token . '&collection_id=' . $collection_id . '&file=' . urlencode($file) . '&size=medium';
}

$image_url    = fs_image_url($token, $collection_id, $safe_current);
$prev_url     = ($prev_file !== null) ? $base . urlencode($prev_file) : null;
$next_url     = ($next_file !== null) ? $base . urlencode($next_file) : null;
$overview_url = 'share.php?token=' . $token . '#f-' . $collection_id . '-' . $safe_current;

$prev_image_url = ($prev_file !== null) ? fs_image_url($token, $collection_id, $prev_file) : null;
$next_image_url = ($next_file !== null) ? fs_image_url($token, $collection_id, $next_file) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#000000">
    <title><?= htmlspecialchars($result['collection_name']) ?></title>
    <style>
        html, body
        {
            margin: 0;
            padding: 0;
            height: 100%;
            background: #000;
            overflow: hidden;
        }

        .fs-stage
        {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fs-stage img
        {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
            -webkit-user-select: none;
            user-select: none;
        }

        /* Three invisible touch zones over the picture: 25% / 50% / 25%. */
        .fs-zone
        {
            position: fixed;
            top: 0;
            height: 100vh;
            height: 100dvh;
            display: block;
            z-index: 2;
            -webkit-tap-highlight-color: transparent;
            outline: none;
        }

        .fs-prev   { left: 0;   width: 25%; cursor: w-resize; }
        .fs-middle { left: 25%; width: 50%; cursor: pointer; }
        .fs-next   { left: 75%; width: 25%; cursor: e-resize; }

        /* For keyboard and screen reader users only */
        .fs-sr
        {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        /* Shown once per device, then fades away */
        .fs-hint
        {
            position: fixed;
            left: 50%;
            bottom: 1.5rem;
            transform: translateX(-50%);
            max-width: 90%;
            padding: 0.6rem 1rem;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
            font: 0.9rem/1.3 system-ui, sans-serif;
            text-align: center;
            pointer-events: none;
            z-index: 3;
            opacity: 1;
            transition: opacity 0.8s ease;
        }

        .fs-hint.fs-hint-hidden
        {
            opacity: 0;
        }
    </style>
</head>
<body>

<div class="fs-stage">
    <img src="<?= htmlspecialchars($image_url) ?>" alt="Image <?= $current_index + 1 ?> of <?= count($images) ?>" draggable="false">
</div>

<?php if ($prev_url !== null): ?>
<a class="fs-zone fs-prev" id="fs-prev" href="<?= htmlspecialchars($prev_url) ?>"><span class="fs-sr">Previous image</span></a>
<?php endif; ?>
<a class="fs-zone fs-middle" id="fs-middle" href="<?= htmlspecialchars($overview_url) ?>"><span class="fs-sr">Back to the overview</span></a>
<?php if ($next_url !== null): ?>
<a class="fs-zone fs-next" id="fs-next" href="<?= htmlspecialchars($next_url) ?>"><span class="fs-sr">Next image</span></a>
<?php endif; ?>

<div class="fs-hint fs-hint-hidden" id="fs-hint">Tap or click the sides for previous / next &middot; the middle for the overview</div>

<script>
(function ()
{
    const prevUrl     = <?= json_encode($prev_url) ?>;
    const nextUrl     = <?= json_encode($next_url) ?>;
    const overviewUrl = <?= json_encode($overview_url) ?>;

    // The next and previous pictures are fetched in the background, so the
    // change feels instant.
    <?php if ($next_image_url !== null): ?>
    new Image().src = <?= json_encode($next_image_url) ?>;
    <?php endif; ?>
    <?php if ($prev_image_url !== null): ?>
    new Image().src = <?= json_encode($prev_image_url) ?>;
    <?php endif; ?>

    // replace() instead of a normal link: the whole session is one step in
    // the browser history, so Back leaves the full screen view at once.
    function go(url)
    {
        if (url)
        {
            window.location.replace(url);
        }
    }

    // While the picture is pinch-zoomed, taps and swipes belong to the
    // zoom and must not change the image.
    function isZoomed()
    {
        return window.visualViewport && window.visualViewport.scale > 1.02;
    }

    function wire(id, url)
    {
        const el = document.getElementById(id);

        if (!el)
        {
            return;
        }

        el.addEventListener('click', function (e)
        {
            e.preventDefault();

            if (!isZoomed())
            {
                go(url);
            }
        });
    }

    wire('fs-prev', prevUrl);
    wire('fs-next', nextUrl);
    wire('fs-middle', overviewUrl);

    document.addEventListener('keydown', function (e)
    {
        if (e.key === 'ArrowLeft')
        {
            go(prevUrl);
        }
        else if (e.key === 'ArrowRight')
        {
            go(nextUrl);
        }
        else if (e.key === 'Escape')
        {
            go(overviewUrl);
        }
    });

    // Swipe: a single quick finger movement, mostly sideways.
    let startX = 0;
    let startY = 0;
    let tracking = false;

    document.addEventListener('touchstart', function (e)
    {
        tracking = (e.touches.length === 1);

        if (tracking)
        {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }
    }, { passive: true });

    document.addEventListener('touchend', function (e)
    {
        if (!tracking || e.changedTouches.length !== 1 || isZoomed())
        {
            tracking = false;
            return;
        }

        tracking = false;

        const dx = e.changedTouches[0].clientX - startX;
        const dy = e.changedTouches[0].clientY - startY;

        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5)
        {
            go(dx < 0 ? nextUrl : prevUrl);
        }
    }, { passive: true });

    // First visit on this device: show the hint for a few seconds.
    let seen = false;

    try
    {
        seen = (window.localStorage.getItem('fullscreenHintSeen') === '1');
    }
    catch (err)
    {
        seen = true;
    }

    if (!seen)
    {
        const hint = document.getElementById('fs-hint');
        hint.classList.remove('fs-hint-hidden');

        setTimeout(function ()
        {
            hint.classList.add('fs-hint-hidden');
        }, 3500);

        try
        {
            window.localStorage.setItem('fullscreenHintSeen', '1');
        }
        catch (err)
        {
        }
    }
})();
</script>

</body>
</html>
