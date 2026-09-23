<?php
// share_action.php
require_once __DIR__ . '/db.php';

$token = $_POST['token'] ?? '';
$collection_id = (int)($_POST['collection_id'] ?? 0);
$tag_color = $_POST['tag_color'] ?? '';
$ticked = $_POST['ticked'] ?? [];

$allowed_colors = ['none', 'red', 'green', 'blue', 'yellow', 'purple'];

if (!preg_match('/^[a-f0-9]{64}$/', $token))
{
    http_response_code(404);
    exit('Not found.');
}

// Confirm the token is valid AND that the collection being tagged actually
// belongs to this token's project — same join pattern used everywhere else
// in the share_* files, so a token can never be used to tag images outside
// its own project.
$stmt = $pdo->prepare("
    SELECT sl.ttl_days, sl.created_at
    FROM tb_share_links sl
    JOIN tb_collections c ON c.project_id = sl.project_id
    WHERE sl.token = ? AND sl.revoked_at IS NULL AND c.collection_id = ?
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

if (!in_array($tag_color, $allowed_colors, true))
{
    header('Location: share.php?token=' . urlencode($token) . '&error=invalid_color');
    exit;
}

if (empty($ticked) || !is_array($ticked))
{
    header('Location: share.php?token=' . urlencode($token) . '&error=nothing_ticked');
    exit;
}

// Sanitize filenames the same way every other file in this project does
$candidate_files = [];
foreach ($ticked as $filename)
{
    $safe = basename($filename);
    if ($safe !== '' && $safe === $filename)
    {
        $candidate_files[] = $safe;
    }
}

if (empty($candidate_files))
{
    header('Location: share.php?token=' . urlencode($token) . '&error=invalid_selection');
    exit;
}

// Only genuinely complete images belonging to THIS collection can be
// tagged — same cross-check pattern as gallery_action.php and share_image.php.
$placeholders = implode(',', array_fill(0, count($candidate_files), '?'));
$stmt = $pdo->prepare("
    SELECT image_id FROM tb_images
    WHERE collection_id = ? AND status = 'complete' AND stored_filename IN ($placeholders)
");
$stmt->execute(array_merge([$collection_id], $candidate_files));
$valid_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($valid_ids))
{
    header('Location: share.php?token=' . urlencode($token) . '&error=invalid_selection');
    exit;
}

$id_placeholders = implode(',', array_fill(0, count($valid_ids), '?'));
$update = $pdo->prepare("UPDATE tb_images SET tag_color = ? WHERE image_id IN ($id_placeholders)");
$update->execute(array_merge([$tag_color], $valid_ids));

header('Location: share.php?token=' . urlencode($token) . '&tagged=' . count($valid_ids));
exit;