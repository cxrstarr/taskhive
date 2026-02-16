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

    // Strict allow-list validation: only digits allowed
    $convId = filter_input(INPUT_GET, 'conversation_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $afterId = filter_input(INPUT_GET, 'after_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($convId === false || $convId === null) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_conversation_id']);
        exit;
    }
    if ($afterId === false) { // null means not provided; false means invalid
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_after_id']);
        exit;
    }
    $afterId = $afterId ?? 0;

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

    if ($afterId > 0) {
        $q = $pdo->prepare("SELECT m.*, u.first_name, u.last_name, u.profile_picture
                             FROM messages m JOIN users u ON m.sender_id=u.user_id
                             WHERE m.conversation_id=:c AND m.message_id > :mid
                             ORDER BY m.message_id ASC
                             LIMIT 200");
        $q->execute([':c'=>$convId, ':mid'=>$afterId]);
    } else {
        $q = $pdo->prepare("SELECT m.*, u.first_name, u.last_name, u.profile_picture
                             FROM messages m JOIN users u ON m.sender_id=u.user_id
                             WHERE m.conversation_id=:c
                             ORDER BY m.message_id ASC
                             LIMIT 500");
        $q->execute([':c'=>$convId]);
    }
    $rows = $q->fetchAll();

    $messages = array_map(function($m){
        return [
            'message_id' => (int)$m['message_id'],
            'conversation_id' => (int)$m['conversation_id'],
            'sender_id' => (int)$m['sender_id'],
            'body' => $m['body'],
            'message_type' => $m['message_type'],
            'attachments' => $m['attachments'] ? json_decode($m['attachments'], true) : null,
            'created_at' => $m['created_at'],
            'first_name' => $m['first_name'],
            'last_name' => $m['last_name'],
            'profile_picture' => $m['profile_picture'],
        ];
    }, $rows);

    echo json_encode(['ok'=>true,'messages'=>$messages]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','details'=>$e->getMessage()]);
}
