<?php
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'Not logged in']);
    exit;
}
$userId = (int)$_SESSION['user_id'];
$conversation_id = (int)($_GET['id'] ?? 0);
if ($conversation_id <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'Missing conversation id']);
    exit;
}
$db = new database();

// Validate access
$pdo = $db->opencon();
$stmt = $pdo->prepare("SELECT client_id,freelancer_id FROM conversations WHERE conversation_id=? LIMIT 1");
$stmt->execute([$conversation_id]);
$conv = $stmt->fetch();
if (!$conv || ($conv['client_id'] != $userId && $conv['freelancer_id'] != $userId)) {
    echo json_encode(['ok'=>false, 'error'=>'Forbidden']);
    exit;
}

/*
  Auto-mark messages as read for the polling user.
  This makes sender-side "Seen" receipts update in near real-time.
*/
$db->markConversationMessagesRead($conversation_id, $userId);

// Get latest messages (ascending for chat)
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 40; // slightly smaller for faster first paint
$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;

if ($beforeId > 0) {
    $messages = $db->getConversationMessagesBeforeId($conversation_id, $beforeId, $limit);
} else {
    // latest first
    $messages = $db->getConversationMessages($conversation_id, $limit, 0);
}
// Convert to ascending order for chat rendering convenience
$messages = array_reverse($messages);

echo json_encode([
    'ok'=>true,
    'messages'=>$messages,
    'page' => [
        'limit' => $limit,
        'before_id' => $beforeId
    ]
]);