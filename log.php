<?php
// log.php

function log_action($pdo, $user_id, $action, $project_id = null, $collection_id = null, $detail = null)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO tb_log (user_id, action, project_id, collection_id, detail, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $action, $project_id, $collection_id, $detail, $ip]);
}