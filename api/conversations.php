<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../database.php';

try {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $db = new database();

    $rows = $db->listConversationsWithUnread($uid, 50, 0);
    $conversations = [];
    foreach ($rows as $r) {
        $isClient = ((int)$r['client_id'] === $uid);
        $otherName = $isClient
            ? trim(($r['free_first'] ?? '') . ' ' . ($r['free_last'] ?? ''))
            : trim(($r['client_first'] ?? '') . ' ' . ($r['client_last'] ?? ''));
        $otherPic = $isClient ? ($r['free_pic'] ?? '') : ($r['client_pic'] ?? '');

        $conversations[] = [
            'conversation_id' => (int)$r['conversation_id'],
            'sender' => $otherName ?: 'Conversation',
            'sender_avatar' => $otherPic ?: 'img/profile_icon.webp',
            'category' => !empty($r['booking_id']) ? 'Booking' : 'General',
            'preview' => $r['last_body'] ?? 'No messages yet.',
            'timestamp' => $r['last_message_at'] ?? date('Y-m-d H:i:s'),
            'unread' => ((int)($r['unread_count'] ?? 0) > 0),
            'unread_count' => (int)($r['unread_count'] ?? 0),
        ];
    }

    // Optional: sort by timestamp desc in case DB doesn't
    usort($conversations, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    echo json_encode(['ok' => true, 'conversations' => $conversations]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
