<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    flash_set('error','Unauthorized.');
    header("Location: login.php"); exit;
}

$db = new database();
$client_id  = (int)$_SESSION['user_id'];
$booking_id = (int)($_POST['booking_id'] ?? 0);
$rating     = (int)($_POST['rating'] ?? 0);
$comment    = trim($_POST['comment'] ?? '');

if ($booking_id <=0 || $rating <1 || $rating>5) {
    flash_set('error','Invalid review data.');
    header("Location: client_dashboard.php");
    exit;
}

if ($db->leaveReviewAsClient($booking_id,$client_id,$rating,$comment)) {
    flash_set('success','Review submitted.');
} else {
    flash_set('error','Could not submit review (maybe already reviewed or status not completed).');
}
header("Location: client_dashboard.php");
exit;