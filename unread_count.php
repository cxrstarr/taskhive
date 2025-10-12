<?php
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) {
    echo json_encode(['unread'=>0]); exit;
}
require_once 'database.php';
$db = new database();
if (!method_exists($db,'countUnreadMessages')) {
    echo json_encode(['unread'=>0]); exit;
}
echo json_encode(['unread'=>$db->countUnreadMessages((int)$_SESSION['user_id'])]);

?>