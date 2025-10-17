<?php
session_start();
require_once __DIR__.'/database.php';
require_once __DIR__.'/flash.php';

// Detect if client expects JSON (XHR/fetch)
$accept = isset($_SERVER['HTTP_ACCEPT']) ? (string)$_SERVER['HTTP_ACCEPT'] : '';
$xrw    = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) : '';
$wantsJson = ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
        exit;
    }
    header('Location: inbox.php');
    exit;
}

if (empty($_SESSION['user_id'])) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'not_authenticated']);
        exit;
    }
    flash_set('error','Please log in to continue.');
    header('Location: login.php');
    exit;
}

$conversation_id = (int)($_POST['conversation_id'] ?? 0);
$return = trim($_POST['return'] ?? 'inbox.php');
if ($conversation_id <= 0) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_conversation']);
        exit;
    }
    flash_set('error','Invalid conversation.');
    header('Location: '.$return);
    exit;
}

try {
    $db = new database();
    $uid = (int)$_SESSION['user_id'];
    $res = $db->hideConversationForUser($conversation_id, $uid);
    if ($wantsJson) {
        header('Content-Type: application/json');
        if ($res === true) {
            echo json_encode(['ok'=>true, 'conversation_id'=>$conversation_id, 'unreadCount'=>$db->countUnreadMessages($uid)]);
        } else {
            echo json_encode(['ok'=>false,'error'=> is_string($res)?$res:'hide_failed']);
        }
        exit;
    }
    if ($res === true) {
        flash_set('success','Conversation deleted from your inbox.');
    } else {
        flash_set('error', is_string($res) ? $res : 'Could not delete conversation.');
    }
} catch (Throwable $e) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'ok'=>false,
            'error'=>'server_error',
            'details'=>$e->getMessage(),
            'file'=>$e->getFile(),
            'line'=>$e->getLine()
        ]);
        exit;
    }
    flash_set('error','Error: '.$e->getMessage());
}

header('Location: '.$return);
exit;
