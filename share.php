<?php
// share.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

$token = $_GET['token'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Link not found.');
}

// scope_collection_id is NULL for a whole-project link, or the one
// collection a collection-level link is limited to.
$stmt = $pdo->prepare("
    SELECT sl.share_id, sl.ttl_days, sl.created_at,
           sl.collection_id AS scope_collection_id,
           sc.collection_name AS scope_collection_name,
           p.project_id, p.project_name, u.email AS photographer_email
    FROM tb_share_links sl
    JOIN tb_projects p ON p.project_id = sl.project_id
    JOIN tb_users u ON u.user_id = p.user_id
    LEFT JOIN tb_collections sc ON sc.collection_id = sl.collection_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL
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

$is_scoped = ($share['scope_collection_id'] !== null);

// On a collection link the page is headed by the collection name, and
// the project name is not shown to the customer at all.
$page_heading = $is_scoped ? $share['scope_collection_name'] : $share['project_name'];

$update = $pdo->prepare("UPDATE tb_share_links SET view_count = view_count + 1, last_accessed = NOW() WHERE share_id = ?");
$update->execute([$share['share_id']]);

log_action($pdo, null, 'share_view', $share['project_id'], $is_scoped ? (int)$share['scope_collection_id'] : null, null);

if ($is_scoped)
{
    $stmt = $pdo->prepare("SELECT collection_id, collection_name FROM tb_collections WHERE project_id = ? AND collection_id = ?");
    $stmt->execute([$share['project_id'], $share['scope_collection_id']]);
}
else
{
    $stmt = $pdo->prepare("SELECT collection_id, collection_name FROM tb_collections WHERE project_id = ? ORDER BY date_of_creation DESC, collection_id DESC");
    $stmt->execute([$share['project_id']]);
}

$collections = $stmt->fetchAll();

// Branding: the account owner's logo, colours, welcome text and contact
// address. Every value defaults to "no branding", so if the helper file is
// missing, or branding is off for this account, the page looks as it always has.
$brand_style   = '';
$brand_header  = '';
$brand_welcome = '';
$contact_email = $share['photographer_email'];

$branding_file = dirname(__DIR__) . '/branding_functions.php';

if (is_file($branding_file))
{
    require_once $branding_file;

    $brand         = get_branding($pdo, $share['project_id']);
    $brand_style   = branding_style_block($brand);
    $brand_header  = branding_header_html($brand, $token);
    $brand_welcome = branding_welcome_html($brand);
    $contact_email = branding_contact_email($brand, $share['photographer_email']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($page_heading) ?> &mdash; Gallery</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<?= $brand_style ?>
</head>
<body>
<?= $brand_header ?>
<main class="container">

<h1><?= htmlspecialchars($page_heading) ?></h1>

<?= $brand_welcome ?>
<p><a href="mailto:<?= htmlspecialchars($contact_email) ?>">Email the photographer</a></p>

<?php if (count($collections) > 1): ?>
    <?php
    // One tile per collection: cover picture, name and a count. The counts
    // and the cover come from the same completed files the gallery shows.
    $count_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(aft.file_kind = 'image'), 0) AS image_count,
               COALESCE(SUM(aft.file_kind <> 'image'), 0) AS file_count
        FROM tb_images i
        JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
        WHERE i.collection_id = ? AND i.status = 'complete'
    ");
    $cover_stmt = $pdo->prepare("
        SELECT i.stored_filename
        FROM tb_images i
        JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
        WHERE i.collection_id = ? AND i.status = 'complete' AND aft.file_kind = 'image'
        ORDER BY i.original_filename
        LIMIT 1
    ");

    $tiles = [];

    foreach ($collections as $collection)
    {
        $count_stmt->execute([$collection['collection_id']]);
        $counts = $count_stmt->fetch();

        $cover_stmt->execute([$collection['collection_id']]);
        $cover_file = $cover_stmt->fetchColumn();

        $n_images = (int)$counts['image_count'];
        $n_files  = (int)$counts['file_count'];

        $parts = [];

        if ($n_images > 0)
        {
            $parts[] = $n_images . ($n_images === 1 ? ' image' : ' images');
        }

        if ($n_files > 0)
        {
            $parts[] = $n_files . ($n_files === 1 ? ' file' : ' files');
        }

        $tiles[] = [
            'id'    => $collection['collection_id'],
            'name'  => $collection['collection_name'],
            'cover' => $cover_file,
            'count' => empty($parts) ? 'Nothing added yet' : implode(', ', $parts),
        ];
    }
    ?>
    <section id="collections" aria-label="Collections">
        <p class="collection-intro">This delivery contains <strong><?= count($tiles) ?> collections</strong>. Click one to jump to its pictures further down this page.</p>
        <div class="collection-tiles">
            <?php foreach ($tiles as $tile): ?>
                <a class="collection-tile" href="#collection-<?= (int)$tile['id'] ?>">
                    <div class="collection-tile-cover">
                        <?php if ($tile['cover'] !== false && $tile['cover'] !== null): ?>
                            <img src="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= (int)$tile['id'] ?>&file=<?= urlencode($tile['cover']) ?>&size=thumb" alt="" loading="lazy">
                        <?php else: ?>
                            <span aria-hidden="true">&#128196;</span>
                        <?php endif; ?>
                    </div>
                    <div class="collection-tile-body">
                        <p class="collection-tile-name"><?= htmlspecialchars($tile['name']) ?></p>
                        <p class="collection-tile-count"><?= htmlspecialchars($tile['count']) ?></p>
                        <span class="collection-tile-go">View &darr;</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <hr>
<?php endif; ?>

<?php if (isset($_GET['tagged'])): ?>
    <p style="color:green;"><?= (int)$_GET['tagged'] ?> image(s) tagged.</p>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <p style="color:red;">
        <?php
        $messages = [
            'nothing_ticked' => 'Please tick at least one image first.',
            'invalid_selection' => 'Invalid selection.',
            'invalid_color' => 'Please choose a valid color.',
            'locked_only' => 'The selected images are locked previews and cannot be downloaded yet.',
        ];
        echo htmlspecialchars($messages[$_GET['error']] ?? 'Something went wrong.');
        ?>
    </p>
<?php endif; ?>

<?php if (empty($collections)): ?>

    <p>No images have been added to this <?= $is_scoped ? 'collection' : 'project' ?> yet.</p>

<?php else: ?>

    <?php foreach ($collections as $collection): ?>
        <?php if (!$is_scoped): ?>
            <h2 id="collection-<?= $collection['collection_id'] ?>"><?= htmlspecialchars($collection['collection_name']) ?><?php if (count($collections) > 1): ?> <a class="back-to-collections" href="#collections">&uarr; All collections</a><?php endif; ?></h2>
        <?php endif; ?>

        <?php
        $stmt = $pdo->prepare("
            SELECT i.stored_filename, i.original_filename, i.tag_color, i.notes, i.watermark, aft.file_kind, aft.extension
            FROM tb_images i
            JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
            WHERE i.collection_id = ? AND i.status = 'complete'
            ORDER BY i.original_filename
        ");
        $stmt->execute([$collection['collection_id']]);
        $images = $stmt->fetchAll();

        $tag_counts = ['red' => 0, 'green' => 0, 'blue' => 0, 'yellow' => 0, 'purple' => 0, 'none' => 0];
        $note_count = 0;
        $locked_count = 0;
        foreach ($images as $image)
        {
            $tag_counts[$image['tag_color']]++;

            if (!empty($image['notes']))
            {
                $note_count++;
            }

            // A locked image is a preview only. When in doubt (a missing
            // value) it counts as locked.
            if ((int)($image['watermark'] ?? 1) !== 0)
            {
                $locked_count++;
            }
        }

        // Everything in this collection is locked: nothing can be
        // downloaded, so the download options are not offered at all.
        $all_locked = (count($images) > 0 && $locked_count === count($images));
        ?>

        <?php if (empty($images)): ?>
            <p><em>No images in this collection yet.</em></p>
        <?php else: ?>

            <div class="collection-block">

            <div class="tag-summary">
                <span class="tag-filter-label">Filter:</span>
                <?php foreach ($tag_counts as $color => $count): ?>
                    <?php if ($count > 0): ?>
                        <a href="#" class="tag-summary-item tag-filter" data-filter="<?= $color ?>">
                            <span class="tag-swatch tag-<?= $color ?>"></span>
                            <?= $count ?> <?= $color === 'none' ? 'untagged' : ucfirst($color) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($note_count > 0): ?>
                    <a href="#" class="tag-summary-item tag-filter" data-filter="notes">
                        &#9998; <?= $note_count ?> with notes
                    </a>
                <?php endif; ?>
                <a href="#" class="tag-summary-item tag-filter" data-filter="all">Show all</a>
            </div>
            <p><a href="share_image_list.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>">Generate copyable image list</a></p>
<?php if ($locked_count > 0): ?>
            <p class="lock-explanation"><small>🔒 Images marked with a lock are previews. They can be viewed but not downloaded until the photographer releases them.</small></p>
<?php endif; ?>

            <form method="post" action="share_action.php" class="tag-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="collection_id" value="<?= $collection['collection_id'] ?>">

                    <div class="action-bar">
                    <label style="display:inline-flex; align-items:center; gap:0.5rem; width:auto;">
                        <input type="checkbox" class="select-all-cb">
                        Select all
                    </label>

                    <select name="gallery_action" class="action-select" required>
                        <option value="">Choose an action&hellip;</option>
                        <option value="tag">Set color tag&hellip;</option>
<?php if (!$all_locked): ?>
                        <option value="download_full">Download full size (zip)</option>
                        <option value="download_medium">Download medium scaled (zip)</option>
                        <option value="download_small">Download small scaled (zip)</option>
<?php endif; ?>
                    </select>

                    <select name="tag_color" class="tag-color-select" style="display:none;">
                        <option value="">Color&hellip;</option>
                        <option value="red">🔴 Red</option>
                        <option value="green">🟢 Green</option>
                        <option value="blue">🔵 Blue</option>
                        <option value="yellow">🟡 Yellow</option>
                        <option value="purple">🟣 Purple</option>
                        <option value="none">⚪ Clear tag</option>
                    </select>

                <button type="submit">Apply</button>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:1rem; margin-bottom:2rem;">
                    <?php foreach ($images as $image): ?>
                        <div style="position:relative;" id="f-<?= $collection['collection_id'] ?>-<?= htmlspecialchars($image['stored_filename']) ?>" data-tag="<?= $image['tag_color'] ?>" data-note="<?= !empty($image['notes']) ? '1' : '0' ?>">
                            <input type="checkbox" name="ticked[]" value="<?= htmlspecialchars($image['stored_filename']) ?>"
                            class="thumb-checkbox<?= $image['tag_color'] !== 'none' ? ' tag-' . $image['tag_color'] : '' ?>"
                            style="position:absolute; top:8px; left:8px; width:20px; height:20px; z-index:1;">
                            <?php if (!empty($image['notes'])): ?>
                                <span class="has-note-badge" title="Has notes">&#9998;</span>
                            <?php endif; ?>
<?php if ((int)($image['watermark'] ?? 1) !== 0): ?>
                            <span class="lock-badge" title="Locked preview" style="position:absolute; bottom:8px; right:8px; z-index:1; background:rgba(0,0,0,0.65); color:#fff; border-radius:4px; padding:0 6px; font-size:0.9rem;">🔒</span>
<?php endif; ?>

                            <?php if ($image['file_kind'] === 'image'): ?>
                                <a href="share_view.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>&file=<?= urlencode($image['stored_filename']) ?>" target="_blank">
                                    <img src="share_image.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>&file=<?= urlencode($image['stored_filename']) ?>&size=thumb"
                                         alt="<?= htmlspecialchars($image['original_filename']) ?>"
                                         class="thumbnail">
                                </a>
                            <?php else: ?>
                                <a href="share_file_info.php?token=<?= htmlspecialchars($token) ?>&collection_id=<?= $collection['collection_id'] ?>&file=<?= urlencode($image['stored_filename']) ?>" class="filebadge">
                                    <span class="filebadge-ext"><?= htmlspecialchars($image['extension']) ?></span>
                                    <span class="filebadge-name"><?= htmlspecialchars($image['original_filename']) ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>

            </div>

        <?php endif; ?>
    <?php endforeach; ?>

<?php endif; ?>

<script>
// Select all ticks only the tiles currently visible, so a hidden
// (filtered-out) tile can never be ticked and swept into an action.
document.querySelectorAll('.select-all-cb').forEach(function (selectAllBox)
{
    selectAllBox.addEventListener('change', function ()
    {
        this.closest('.tag-form').querySelectorAll('[data-tag]').forEach(function (tile)
        {
            if (tile.style.display !== 'none')
            {
                tile.querySelector('.thumb-checkbox').checked = selectAllBox.checked;
            }
        });
    });
});

// Filter: click a colour, or "with notes", in the summary line to show
// only matching tiles; click it again, or "Show all", to clear. Only one
// filter is active at a time. Hidden tiles are unticked so they can't be
// submitted with the form.
document.querySelectorAll('.tag-filter').forEach(function (link)
{
    link.addEventListener('click', function (e)
    {
        e.preventDefault();

        const block = this.closest('.collection-block');
        let filter = this.dataset.filter;

        if (this.classList.contains('active'))
        {
            filter = 'all';
        }

        block.querySelectorAll('.tag-filter').forEach(function (l)
        {
            l.classList.toggle('active', filter !== 'all' && l.dataset.filter === filter);
        });

        block.querySelectorAll('[data-tag]').forEach(function (tile)
        {
            let show;

            if (filter === 'all')
            {
                show = true;
            }
            else if (filter === 'notes')
            {
                show = (tile.dataset.note === '1');
            }
            else
            {
                show = (tile.dataset.tag === filter);
            }

            tile.style.display = show ? '' : 'none';

            if (!show)
            {
                tile.querySelector('.thumb-checkbox').checked = false;
            }
        });

        const selectAll = block.querySelector('.select-all-cb');

        if (selectAll)
        {
            selectAll.checked = false;
        }
    });
});

document.querySelectorAll('.tag-form').forEach(function (form)
{
    form.addEventListener('submit', function (e)
    {
        const ticked = form.querySelectorAll('.thumb-checkbox:checked').length;
        const action = form.querySelector('.action-select').value;
        const colorSelect = form.querySelector('.tag-color-select');

        if (ticked === 0)
        {
            alert('Please tick at least one item first.');
            e.preventDefault();
            return;
        }

        if (action === 'tag' && colorSelect.value === '')
        {
            alert('Please choose a color.');
            e.preventDefault();
            return;
        }

        if (action.startsWith('download_'))
        {
            const applyBtn = form.querySelector('button[type="submit"]');
            applyBtn.disabled = true;
            applyBtn.textContent = 'Generating your download, please wait…';
        }
    });
});

document.querySelectorAll('.action-select').forEach(function (actionSelect)
{
    actionSelect.addEventListener('change', function ()
    {
        const colorSelect = this.closest('.tag-form').querySelector('.tag-color-select');
        const applyButton = this.closest('.tag-form').querySelector('.action-bar button[type="submit"]');

        // A pulsing Apply button reminds the visitor that choosing an action
        // does nothing until Apply is clicked.
        applyButton.classList.toggle('needs-apply', this.value !== '');

        if (this.value === 'tag')
        {
            colorSelect.style.display = 'inline-block';
            colorSelect.required = true;
        }
        else
        {
            colorSelect.style.display = 'none';
            colorSelect.required = false;
            colorSelect.value = '';
        }
    });
});
</script>

</main>
    <?= isset($brand) ? branding_footer_html($brand) : '' ?>
</body>
</html>