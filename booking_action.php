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

$newStatus = $action === 'accept' ? 'accepted' : 'rejected';
if ($db->updateBookingStatus($booking_id,$newStatus)) {

    // Add notification for client
    if (method_exists($db,'addNotification')) {
        $db->addNotification((int)$booking['client_id'],'booking_status_changed',[
            'booking_id'=>$booking_id,
            'status'=>$newStatus
        ]);
    }

    // Add message to the general conversation (single thread per pair)
    try {
        $conv_id = $db->createOrGetGeneralConversation((int)$booking['client_id'], $freelancer_id);
        if ($conv_id) {
            $msg = "Freelancer has ".($action==='accept'?'ACCEPTED':'REJECTED')." booking #$booking_id.";
            $db->addMessage((int)$conv_id,$freelancer_id,$msg,'system',$booking_id);
        }
    } catch (Throwable $e) {}

    flash_set('success','Booking '.$newStatus.'.');
} else {
    flash_set('error','Could not update booking status.');
}

header("Location: freelancer_profile.php");