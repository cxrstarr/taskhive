<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: mainpage.php"); exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'freelancer') {
    flash_set('error','Unauthorized.');
    header("Location: login.php"); exit;
}

$booking_id = (int)($_POST['booking_id'] ?? 0);
$action     = $_POST['action'] ?? '';

if ($booking_id <= 0 || !in_array($action,['accept','reject'],true)) {
    flash_set('error','Invalid action.');
    header("Location: freelancer_profile.php"); exit;
}

$db = new database();
$freelancer_id = (int)$_SESSION['user_id'];
$booking = $db->getBookingForFreelancer($booking_id,$freelancer_id);

if (!$booking) {
    flash_set('error','Booking not found or not yours.');
    header("Location: freelancer_profile.php"); exit;
}

if ($booking['status'] !== 'pending') {
    flash_set('error','Booking already processed.');
    header("Location: freelancer_profile.php"); exit;
}

// Use unified handler so notifications and system messages are consistent
$res = $db->performBookingAction($booking_id, $freelancer_id, 'freelancer', $action);
if ($res === true) {
    flash_set('success','Booking '.($action==='accept'?'accepted':'rejected').'.');
} else {
    flash_set('error', is_string($res) ? $res : 'Could not update booking status.');
}

header("Location: freelancer_profile.php");