<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: mainpage.php"); exit;
}
if (empty($_SESSION['user_id'])) {
    flash_set('error','Login required.');
    header("Location: login.php"); exit;
}

$target_id = (int)($_POST['target_user_id'] ?? 0);
$service_id = (int)($_POST['service_id'] ?? 0); // optional, not required now

$me = (int)$_SESSION['user_id'];
if ($target_id <= 0 || $target_id === $me) {
    flash_set('error','Invalid target user.');
    header("Location: mainpage.php"); exit;
}

$db = new database();
$targetUser = $db->getUser($target_id);
if (!$targetUser) {
    flash_set('error','User not found.');
    header("Location: mainpage.php"); exit;
}

$conv_id = $db->createOrGetGeneralConversation($me, $target_id);
if (!$conv_id) {
    flash_set('error','Could not start conversation.');
    header("Location: mainpage.php"); exit;
}

flash_set('success','Conversation ready.');
// Redirect to inbox focused on this conversation and user
$redir = 'inbox.php?user_id='.urlencode((string)$target_id).'&conversation_id='.urlencode((string)$conv_id);
header('Location: '.$redir);