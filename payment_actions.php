<?php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/flash.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId   = (int)$_SESSION['user_id'];
$userType = $_SESSION['user_type'] ?? 'client';

$db  = new database();
$pdo = $db->opencon();

$task = $_POST['task'] ?? '';

if ($task === 'make_payment') {
    $conversation_id   = (int)($_POST['conversation_id'] ?? 0);
    $booking_id        = (int)($_POST['booking_id'] ?? 0);
    $phase             = $_POST['phase'] ?? '';
    $paymentMethod     = $_POST['pay_method'] ?? '';
    $receiverMethodId  = isset($_POST['receiver_method_id']) && $_POST['receiver_method_id'] !== '' ? (int)$_POST['receiver_method_id'] : null;
    $reference_code    = trim($_POST['reference_code'] ?? '');

    // Normalize amount (strip commas/spaces)
    $rawAmount = $_POST['amount'] ?? '0';
    $amount    = (float)str_replace([',',' '], '', $rawAmount);

    // Validate booking and role
    $booking = $db->fetchBookingWithContext($booking_id);
    if (!$booking) {
        flash_set('error','Booking not found.');
        header('Location: conversation.php?id='.$conversation_id);
        exit;
    }
    if ((int)$booking['client_id'] !== $userId) {
        flash_set('error','Only the booking client can pay.');
        header('Location: conversation.php?id='.$conversation_id);
        exit;
    }
    if (!in_array($paymentMethod, ['gcash','paymaya'], true)) {
        flash_set('error','Choose a valid method (GCash/PayMaya).');
        header('Location: conversation.php?id='.$conversation_id);
        exit;
    }

    // Optional payer details structure
    $payerDetails = [
        'channel' => $paymentMethod,
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];

    $res = $db->recordPayment(
        $booking_id,
        $userId,
        $amount,
        $phase,
        $paymentMethod,
        $payerDetails,
        $reference_code ?: null,
        true,                 // OTP verified (simulated)
        $receiverMethodId     // which freelancer receiving method was used
    );

    if ($res === true) {
        flash_set('success','Payment recorded.');
    } else {
        flash_set('error', is_string($res) ? $res : 'Payment failed.');
    }
    header('Location: conversation.php?id='.$conversation_id);
    exit;
}

// Fallback
header('Location: inbox.php');
exit;