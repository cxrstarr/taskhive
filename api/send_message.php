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

    // Validate input (strict digits only)
    $convId = filter_input(INPUT_POST, 'conversation_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $body = isset($_POST['body']) ? trim((string)$_POST['body']) : '';
    if (($convId === false || $convId === null) && empty($_FILES['image']) && empty($_FILES['images'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_conversation_id']);
        exit;
    }
    $convId = $convId ?? 0;

    // Verify the user is a participant in the conversation
    $pdo = $db->opencon();
    $st = $pdo->prepare("SELECT conversation_id, client_id, freelancer_id FROM conversations WHERE conversation_id=? LIMIT 1");
    $st->execute([$convId]);
    $conv = $st->fetch();
    if (!$conv || ($uid !== (int)$conv['client_id'] && $uid !== (int)$conv['freelancer_id'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'forbidden']);
        exit;
    }

    // Handle optional images upload (single 'image' or multiple 'images[]')
    $attachments = [];
    $type = 'text';

    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    $maxSize = 10 * 1024 * 1024; // 10MB

    // Helper to ensure folder exists
    $dir = realpath(__DIR__ . '/../img/uploads');
    if (!$dir) {
        $dirPath = __DIR__ . '/../img/uploads';
        if (!is_dir($dirPath)) @mkdir($dirPath, 0775, true);
        $dir = realpath($dirPath);
    }
    $msgDirPath = $dir . DIRECTORY_SEPARATOR . 'messages';
    if (!is_dir($msgDirPath)) @mkdir($msgDirPath, 0775, true);

    // Legacy single file field
    if (!empty($_FILES['image']) && isset($_FILES['image']['error']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $files = [
            [
                'tmp_name' => $_FILES['image']['tmp_name'],
                'name' => $_FILES['image']['name'],
                'size' => (int)$_FILES['image']['size'],
                'error' => (int)$_FILES['image']['error'],
                'type' => $_FILES['image']['type'] ?? null,
            ]
        ];
    } else if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
        // Multiple files field images[]
        $files = [];
        $count = count($_FILES['images']['name']);
        for ($i=0; $i<$count; $i++) {
            if ((int)$_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                $files[] = [
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'name' => $_FILES['images']['name'][$i],
                    'size' => (int)$_FILES['images']['size'][$i],
                    'error' => (int)$_FILES['images']['error'][$i],
                    'type' => $_FILES['images']['type'][$i] ?? null,
                ];
            }
        }
    } else {
        $files = [];
    }

    foreach ($files as $f) {
        $tmp = $f['tmp_name'];
        $name = $f['name'];
        $size = (int)$f['size'];
        if ($size > $maxSize) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'file_too_large']);
            exit;
        }
        $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : ($f['type'] ?? '');
        if (!in_array($mime, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'unsupported_type']);
            exit;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg');
        $basename = 'msg_' . uniqid('', true) . '.' . $ext;
        $destFs = $msgDirPath . DIRECTORY_SEPARATOR . $basename;
        if (!move_uploaded_file($tmp, $destFs)) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'upload_failed']);
            exit;
        }
        $webPath = 'img/uploads/messages/' . $basename;
        $attachments[] = ['type'=>'image','url'=>$webPath];
    }

    if (empty($attachments)) {
        $attachments = null;
    } else if ($body === '') {
        $type = 'image';
    }

    if ($body === '' && !$attachments) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'empty_message']);
        exit;
    }

    // Insert message
    $mid = $db->addMessage($convId, $uid, $body, $type, null, $attachments);
    if (!$mid) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'db_insert_failed']);
        exit;
    }

    // Fetch the newly created message with user info
    $st = $pdo->prepare("SELECT m.*, u.first_name, u.last_name, u.profile_picture
                         FROM messages m JOIN users u ON m.sender_id=u.user_id
                         WHERE m.message_id=? LIMIT 1");
    $st->execute([$mid]);
    $row = $st->fetch();

    echo json_encode([
        'ok' => true,
        'message' => [
            'message_id' => (int)$row['message_id'],
            'conversation_id' => (int)$row['conversation_id'],
            'sender_id' => (int)$row['sender_id'],
            'body' => $row['body'],
            'message_type' => $row['message_type'],
            'attachments' => $row['attachments'] ? json_decode($row['attachments'], true) : null,
            'created_at' => $row['created_at'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'profile_picture' => $row['profile_picture'],
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','details'=>$e->getMessage()]);
}
