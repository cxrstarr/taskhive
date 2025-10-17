<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../database.php';

try {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'not_authenticated']);
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $db = new database();

    $count = $db->countUnreadMessages($uid);
    echo json_encode(['ok'=>true,'unreadCount'=>$count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','details'=>$e->getMessage()]);
}
