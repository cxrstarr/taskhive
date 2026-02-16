<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';

/*
 * Processes a booking made by a logged-in CLIENT.
 * Redirects back with flash messages.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php"); exit;
}
if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: index.php'); exit; }

if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    flash_set('error','You must be logged in as a client to book.');
    header("Location: login.php"); exit;
}

$service_id      = (int)($_POST['service_id'] ?? 0);
$quantity        = (int)($_POST['quantity'] ?? 0);
$scheduled_start = trim($_POST['scheduled_start'] ?? '');
$scheduled_end   = trim($_POST['scheduled_end'] ?? '');
$return_slug     = trim($_POST['return_slug'] ?? ''); // so we can go back to same service page on error

if ($service_id <= 0) {
    flash_set('error','Invalid service.');
    header("Location: ".($return_slug ? 'service.php?slug='.urlencode($return_slug) : 'feed.php'));
    exit;
}

$db = new database();
$service = $db->getService($service_id);
if (!$service) {
    flash_set('error','Service not found.');
    header("Location: ".($return_slug ? 'service.php?slug='.urlencode($return_slug) : 'feed.php'));
    exit;
}

$client_id     = (int)$_SESSION['user_id'];
$freelancer_id = (int)$service['freelancer_id'];

if ($freelancer_id === $client_id) {
    flash_set('error','You cannot book your own service.');
    header("Location: service.php?slug=".urlencode($service['slug']));
    exit;
}

$minUnits = (int)$service['min_units'];
if ($quantity < $minUnits) {
    flash_set('error',"Quantity must be at least $minUnits.");
    header("Location: service.php?slug=".urlencode($service['slug']));
    exit;
}

// Parse & validate schedule times (optional)
$ss = $scheduled_start ? date('Y-m-d H:i:s', strtotime($scheduled_start)) : null;
$se = $scheduled_end ? date('Y-m-d H:i:s', strtotime($scheduled_end)) : null;
if ($ss && $se && $ss > $se) {
    flash_set('error','Scheduled end cannot be before start.');
    header("Location: service.php?slug=".urlencode($service['slug']));
    exit;
}

$booking_id = $db->createBooking($service_id, $client_id, $quantity, $ss, $se);
if (!$booking_id) {
    flash_set('error','Failed to create booking. Try again.');
    header("Location: service.php?slug=".urlencode($service['slug']));
    exit;
}

// Optional: Notification for freelancer
if (method_exists($db,'addNotification')) {
    $db->addNotification($freelancer_id,'booking_created',[
        'booking_id'=>$booking_id,
        'service_id'=>$service_id,
        'client_id'=>$client_id,
        'qty'=>$quantity
    ]);
}

// Post an automatic system message to the general conversation (single thread per pair)
try {
    $conv_id = $db->createOrGetGeneralConversation($client_id, $freelancer_id);
    if ($conv_id) {
        $msg = "New booking #$booking_id created for service '{$service['title']}' (Qty: $quantity). Awaiting freelancer confirmation.";
        $db->addMessage($conv_id, $client_id, $msg, 'system', $booking_id);
    }
} catch (Throwable $e) {
    // Non-fatal; ignore
}

flash_set('success','Booking created! Freelancer will confirm.');
header("Location: client_profile.php");

?>