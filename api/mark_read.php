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

    $convId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
    if ($convId <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'missing_parameters']);
        exit;
    }

    // Verify participant
    $pdo = $db->opencon();
    $st = $pdo->prepare("SELECT client_id,freelancer_id FROM conversations WHERE conversation_id=? LIMIT 1");
    $st->execute([$convId]);
    $conv = $st->fetch();
    if (!$conv || ($uid !== (int)$conv['client_id'] && $uid !== (int)$conv['freelancer_id'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'forbidden']);
        exit;
    }

    $ok = $db->markConversationMessagesRead($convId, $uid);
    $count = $db->countUnreadMessages($uid);

    echo json_encode(['ok'=>$ok ? true : false,'unreadCount'=>$count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','details'=>$e->getMessage()]);
}
