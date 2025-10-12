<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__.'/database.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'not_logged_in']); exit;
}

$db       = new database();
$user_id  = (int)$_SESSION['user_id'];

try {
    $rows = $db->listConversationsWithUnread($user_id, 200, 0);
    $unreadTotal = $db->countUnreadMessages($user_id);

    $convs = [];
    foreach ($rows as $c) {
        $isClientSide = ((int)$c['client_id'] === $user_id);
        $otherName = $isClientSide
            ? trim(($c['free_first'] ?? '').' '.($c['free_last'] ?? ''))
            : trim(($c['client_first'] ?? '').' '.($c['client_last'] ?? ''));
        $otherPic = $isClientSide
            ? ($c['free_pic'] ?: 'img/client1.webp')
            : ($c['client_pic'] ?: 'img/client1.webp');

        $lastBody = $c['last_body'] ? strip_tags($c['last_body']) : '(no messages yet)';
        if (mb_strlen($lastBody) > 120) {
            $lastBody = mb_substr($lastBody, 0, 120).'…';
        }

        $convs[] = [
            'conversation_id'  => (int)$c['conversation_id'],
            'type'             => $c['conversation_type'],
            'type_label'       => $c['conversation_type'] === 'booking' ? 'Booking' : 'General',
            'other_name'       => $otherName,
            'other_pic'        => $otherPic,
            'last_message_at'  => $c['last_message_at'],
            'last_body'        => $lastBody,
            'unread_count'     => (int)$c['unread_count'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'unread_total' => (int)$unreadTotal,
        'conversations' => $convs
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'server_error']);
}