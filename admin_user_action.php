<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

// Actions: suspend, delete, activate
$user_id = (int)($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
if ($user_id <= 0 || !in_array($action, ['suspend','delete','activate'], true)) {
    header('Location: admin_users.php'); exit;
}

$status = match($action){
    'suspend' => 'suspended',
    'delete'  => 'deleted',
    'activate'=> 'active'
};
$db->updateUserProfile($user_id, ['status'=>$status]);
flash_set('success','User status updated.');
header('Location: admin_users.php');