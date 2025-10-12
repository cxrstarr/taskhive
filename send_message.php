<?php
session_start();

// Ensure logged in
if (!isset($_SESSION['user_id'])) {
    // Try TaskHive/login.php first, else fall back to root login.php
    $loginPath = 'login.php';
    if (!file_exists(__DIR__ . '/login.php') && file_exists(dirname(__DIR__) . '/login.php')) {
        $loginPath = '../login.php';
    }
    header('Location: ' . $loginPath);
    exit();
}

// Resolve database.php whether under TaskHive/ or at project root
$__dbPath = __DIR__ . '/database.php';
if (!file_exists($__dbPath)) {
    $__dbPath = dirname(__DIR__) . '/database.php';
}
require_once $__dbPath;
$db = new database();
$pdo = $db->opencon();

$userId = (int)$_SESSION['user_id'];
$conversationId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
$body = isset($_POST['body']) ? trim((string)$_POST['body']) : '';

if ($conversationId <= 0) {
    header('Location: conversation.php');
    exit();
}

// Verify the user belongs to this conversation (use distinct placeholders to avoid HY093)
$chk = $pdo->prepare("SELECT conversation_id FROM conversations WHERE conversation_id=:id AND (client_id=:uc OR freelancer_id=:uf) LIMIT 1");
$chk->execute([':id'=>$conversationId, ':uc'=>$userId, ':uf'=>$userId]);
if (!$chk->fetch()) {
    header('Location: conversation.php');
    exit();
}

// Handle image uploads (optional multiple)
$attachments = [];
if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
    $count = count($_FILES['images']['name']);
    $uploadBase = dirname(__DIR__) . '/uploads/'; // project root uploads
    if (!is_dir($uploadBase)) {@mkdir($uploadBase, 0777, true);}    
    for ($i=0; $i<$count; $i++) {
        $name = $_FILES['images']['name'][$i] ?? '';
        $tmp  = $_FILES['images']['tmp_name'][$i] ?? '';
        $err  = (int)($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        // Allow only common image extensions
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'])) continue;
        $safe = preg_replace('/[^a-zA-Z0-9._-]/','_', pathinfo($name, PATHINFO_FILENAME));
        $destName = $safe . '_' . uniqid('', true) . '.' . $ext;
        $destPath = $uploadBase . $destName;
        if (move_uploaded_file($tmp, $destPath)) {
            // Store relative path used by UI normalizer
            $attachments[] = 'uploads/' . $destName;
        }
    }
}

// Convert attachments array to JSON if any
$attachmentsJson = !empty($attachments) ? json_encode($attachments) : null;

// Insert message using existing DB helper (adds timestamp)
// addMessage(conversation_id, sender_id, body, message_type, booking_id, attachments)
$db->addMessage($conversationId, $userId, $body, !empty($attachments) ? 'media' : 'text', null, $attachments);

// Redirect back to the appropriate conversation page (inside feed iframe or standalone)
$returnTo = isset($_POST['return_to']) ? trim((string)$_POST['return_to']) : '';
$target = 'conversation.php';
if ($returnTo === 'conversation.php') {
    $target = 'conversation.php';
}
header('Location: ' . $target . '?id=' . $conversationId . '#bottom');
exit();
