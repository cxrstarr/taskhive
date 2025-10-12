<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: mainpage.php"); exit;
}
if (empty($_SESSION['user_id']) || empty($_SESSION['user_type'])) {
    flash_set('error','Login required.');
    header("Location: login.php"); exit;
}

$booking_id = (int)($_POST['booking_id'] ?? 0);
$action     = trim($_POST['action'] ?? '');
$return     = trim($_POST['return'] ?? ''); // optional redirect override

if ($booking_id <= 0 || !$action) {
    flash_set('error','Invalid booking action data.');
    header("Location: mainpage.php"); exit;
}

$db = new database();
$actor_id   = (int)$_SESSION['user_id'];
$actor_role = $_SESSION['user_type']; // 'client' or 'freelancer'

if (!in_array($actor_role,['client','freelancer'],true)) {
    flash_set('error','Unauthorized role.');
    header("Location: mainpage.php"); exit;
}

$result = $db->performBookingAction($booking_id,$actor_id,$actor_role,$action);
if ($result === true) {
    flash_set('success','Action processed: '.$action);
} else {
    flash_set('error', $result);
}

// Decide where to send back
if ($return) {
    header("Location: ".$return);
} else {
    if ($actor_role==='client') {
        header("Location: client_profile.php");
    } else {
        header("Location: freelancer_profile.php");
    }
}

?>