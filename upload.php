<?php
// upload.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

$collection_id = (int)($_GET['collection_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT c.collection_id, c.collection_name, c.storage_path, p.project_id, p.project_name
    FROM tb_collections c
    JOIN tb_projects p ON p.project_id = c.project_id
    WHERE c.collection_id = ? AND p.user_id = ?
");
$stmt->execute([$collection_id, $_SESSION['user_id']]);
$collection = $stmt->fetch();

if (!$collection)
{
    http_response_code(404);
    require_once __DIR__ . '/header.php';
    echo '<p>Collection not found.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// file_kind/extension now come from tb_allowed_filetypes via the FK,
// not a column on tb_images itself — one join gives us everything
// needed to decide how to render each grid cell.
$stmt = $pdo->prepare("
    SELECT i.stored_filename, i.original_filename, i.tag_color, i.notes, aft.file_kind, aft.extension
    FROM tb_images i
    JOIN tb_allowed_filetypes aft ON aft.filetype_id = i.filetype_id
    WHERE i.collection_id = ? AND i.status = 'complete'
    ORDER BY i.original_filename
");
$stmt->execute([$collection_id]);
$images = $stmt->fetchAll();

$tag_counts = ['red' => 0, 'green' => 0, 'blue' => 0, 'yellow' => 0, 'purple' => 0, 'none' => 0];
$note_count = 0;
foreach ($images as $image)
{
    $tag_counts[$image['tag_color']]++;

    if (!empty($image['notes']))
    {
        $note_count++;
    }
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_images WHERE collection_id = ? AND status = 'failed'");
$stmt->execute([$collection_id]);
$failed_count = $stmt->fetchColumn();

require_once __DIR__ . '/header.php';
?>

<p>
    <a href="projects.php">My Projects</a> &rarr;
    <a href="collections.php?project_id=<?= $collection['project_id'] ?>"><?= htmlspecialchars($collection['project_name']) ?></a> &rarr;
    <?= htmlspecialchars($collection['collection_name']) ?>
</p>
<h1><?= htmlspecialchars($collection['collection_name']) ?></h1>

<?php if ($failed_count > 0): ?>
    <p style="color:red;"><?= (int)$failed_count ?> image(s) previously failed to upload and were not saved.</p>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <p style="color:green;"><?= (int)$_GET['deleted'] ?> image(s) deleted.</p>
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
            'not_implemented' => 'That feature is coming soon.',
            'unknown_action' => 'Please choose a valid action.',
        ];
        echo htmlspecialchars($messages[$_GET['error']] ?? 'Something went wrong.');
        ?>
    </p>
<?php endif; ?>

<div>
    <label>Select images or files to upload
        <input type="file" id="file-input" multiple>
    </label>
    <button type="button" id="start-upload-btn">Upload</button>
</div>

<div id="upload-progress-area" style="display:none; margin-top:1rem;">
    <p id="overall-status">Preparing&hellip;</p>
    <progress id="overall-progress" value="0" max="100" style="width:100%;"></progress>
    <ul id="file-status-list" style="list-style:none; padding-left:0; max-height:280px; overflow-y:auto; font-size:0.9rem;"></ul>
</div>

<hr>

<?php if (empty($images)): ?>

    <p>No images uploaded yet.</p>

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
    <p><a href="image_list.php?collection_id=<?= $collection_id ?>">Generate copyable image list</a></p>

    <form method="post" action="gallery_action.php" id="gallery-form">
        <input type="hidden" name="collection_id" value="<?= $collection_id ?>">

        <div style="margin-bottom:1rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
            <label style="display:inline-flex; align-items:center; gap:0.5rem; width:auto;">
                <input type="checkbox" id="select-all">
                Select all
            </label>

            <select name="gallery_action" id="action-select" required>
                <option value="">Choose an action&hellip;</option>
                <option value="tag">Set color tag&hellip;</option>
                <option value="download_full">Download full size (zip)</option>
                <option value="download_medium">Download medium scaled (zip)</option>
                <option value="download_small">Download small scaled (zip)</option>
                <option value="delete">Delete ticked items</option>
            </select>

            <select name="tag_color" id="tag-color-select" style="display:none;">
                <option value="">Color&hellip;</option>
                <option value="red">🔴 Red</option>
                <option value="green">🟢 Green</option>
                <option value="blue">🔵 Blue</option>
                <option value="yellow">🟡 Yellow</option>
                <option value="purple">🟣 Purple</option>
                <option value="none">⚪ Clear tag</option>
            </select>

            <button type="submit" id="apply-btn">Apply</button>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:1rem;">
            <?php foreach ($images as $image): ?>
                <div style="position:relative;" id="f-<?= $collection_id ?>-<?= htmlspecialchars($image['stored_filename']) ?>" data-tag="<?= $image['tag_color'] ?>" data-note="<?= !empty($image['notes']) ? '1' : '0' ?>">
                    <input type="checkbox" name="ticked[]" value="<?= htmlspecialchars($image['stored_filename']) ?>"
                           class="thumb-checkbox<?= $image['tag_color'] !== 'none' ? ' tag-' . $image['tag_color'] : '' ?>"
                           style="position:absolute; top:8px; left:8px; width:20px; height:20px; z-index:1;">
                    <?php if (!empty($image['notes'])): ?>
                        <span class="has-note-badge" title="Has notes">&#9998;</span>
                    <?php endif; ?>

                    <?php if ($image['file_kind'] === 'image'): ?>
                        <a href="view.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($image['stored_filename']) ?>" target="_blank">
                            <img src="image.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($image['stored_filename']) ?>&size=thumb"
                                 alt="<?= htmlspecialchars($image['original_filename']) ?>"
                                 class="thumbnail">
                        </a>
                    <?php else: ?>
                        <a href="file_info.php?collection_id=<?= $collection_id ?>&file=<?= urlencode($image['stored_filename']) ?>" class="filebadge">
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

<script>
document.getElementById('start-upload-btn')?.addEventListener('click', async function ()
{
    const fileInput = document.getElementById('file-input');
    const files = Array.from(fileInput.files);

    if (files.length === 0)
    {
        alert('Please select at least one file first.');
        return;
    }

    this.disabled = true;
    fileInput.disabled = true;

    const progressArea = document.getElementById('upload-progress-area');
    const overallStatus = document.getElementById('overall-status');
    const overallProgress = document.getElementById('overall-progress');
    const statusList = document.getElementById('file-status-list');

    progressArea.style.display = 'block';
    overallProgress.max = files.length;
    overallProgress.value = 0;
    statusList.innerHTML = '';

    let successCount = 0;
    let failCount = 0;

    for (let i = 0; i < files.length; i++)
    {
        const file = files[i];
        const listItem = document.createElement('li');
        listItem.textContent = `${file.name} — uploading (0%)`;
        statusList.appendChild(listItem);

        overallStatus.textContent = `Uploading ${i + 1} of ${files.length}: ${file.name}`;

        try
        {
            const result = await uploadOneFile(file, listItem, <?= $collection_id ?>);

            if (result.ok)
            {
                listItem.textContent = `✅ ${result.original_filename}`;
                successCount++;
            }
            else
            {
                listItem.textContent = `❌ ${result.original_filename || file.name} — ${result.message}`;
                failCount++;
            }
        }
        catch (err)
        {
            listItem.textContent = `❌ ${file.name} — network error, upload failed.`;
            failCount++;
        }

        overallProgress.value = i + 1;
    }

    overallStatus.textContent = `Done: ${successCount} succeeded, ${failCount} failed. Reloading…`;

    setTimeout(() => window.location.reload(), 1200);
});

function uploadOneFile(file, listItem, collectionId)
{
    return new Promise((resolve, reject) =>
    {
        const xhr = new XMLHttpRequest();
        const formData = new FormData();
        formData.append('collection_id', collectionId);
        formData.append('image', file);

        xhr.upload.addEventListener('progress', function (e)
        {
            if (e.lengthComputable)
            {
                const percent = Math.round((e.loaded / e.total) * 100);
                listItem.textContent = `${file.name} — uploading (${percent}%)`;
            }
        });

        xhr.addEventListener('load', function ()
        {
            try
            {
                resolve(JSON.parse(xhr.responseText));
            }
            catch (e)
            {
                reject(e);
            }
        });

        xhr.addEventListener('error', function ()
        {
            reject(new Error('Network error'));
        });

        xhr.open('POST', 'upload_single.php');
        xhr.send(formData);
    });
}

// Select all ticks only the tiles currently visible, so a hidden
// (filtered-out) tile can never be ticked and swept into an action
// such as Delete.
document.getElementById('select-all')?.addEventListener('change', function ()
{
    const selectAllBox = this;

    document.querySelectorAll('[data-tag]').forEach(function (tile)
    {
        if (tile.style.display !== 'none')
        {
            tile.querySelector('.thumb-checkbox').checked = selectAllBox.checked;
        }
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

        const selectAll = document.getElementById('select-all');

        if (selectAll)
        {
            selectAll.checked = false;
        }
    });
});

const actionSelect = document.getElementById('action-select');
const tagColorSelect = document.getElementById('tag-color-select');

actionSelect?.addEventListener('change', function ()
{
    if (this.value === 'tag')
    {
        tagColorSelect.style.display = 'inline-block';
        tagColorSelect.required = true;
    }
    else
    {
        tagColorSelect.style.display = 'none';
        tagColorSelect.required = false;
        tagColorSelect.value = '';
    }
});

document.getElementById('gallery-form')?.addEventListener('submit', function (e)
{
    const ticked = document.querySelectorAll('.thumb-checkbox:checked').length;
    const action = actionSelect.value;

    if (ticked === 0)
    {
        alert('Please tick at least one item first.');
        e.preventDefault();
        return;
    }

    if (action === 'tag' && tagColorSelect.value === '')
    {
        alert('Please choose a color.');
        e.preventDefault();
        return;
    }

    if (action === 'delete' && !confirm(`Delete ${ticked} selected item(s)? This cannot be undone.`))
    {
        e.preventDefault();
        return;
    }

    if (action.startsWith('download_'))
    {
        document.getElementById('apply-btn').disabled = true;
        document.getElementById('apply-btn').textContent = 'Generating your download, please wait…';
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>