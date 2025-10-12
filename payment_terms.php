<?php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/flash.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: mainpage.php"); exit;
}
if (empty($_SESSION['user_id'])) {
    flash_set('error','Login required.');
    header("Location: login.php"); exit;
}

$task            = $_POST['task'] ?? '';
$booking_id      = (int)($_POST['booking_id'] ?? 0);
$conversation_id = (int)($_POST['conversation_id'] ?? 0);
$user_id         = (int)$_SESSION['user_id'];
$user_type       = $_SESSION['user_type'] ?? '';

if ($booking_id <= 0) {
    flash_set('error','Invalid booking.');
    header("Location: ".($conversation_id ? "conversation.php?id=".$conversation_id : "inbox.php"));
    exit;
}

$db = new database();

switch ($task) {
    case 'propose':
        if ($user_type !== 'freelancer') {
            flash_set('error','Only freelancers can propose terms.');
            break;
        }
        $method = $_POST['method'] ?? '';
        $res = $db->proposePaymentTerms($booking_id, $user_id, $method);
        if ($res === true) flash_set('success','Payment terms proposed: '.$method);
        else flash_set('error', is_string($res) ? $res : 'Failed to propose terms.');
        break;

    case 'accept':
        if ($user_type !== 'client') {
            flash_set('error','Only clients can accept terms.');
            break;
        }
        $res = $db->acceptPaymentTerms($booking_id, $user_id);
        if ($res === true) flash_set('success','Payment terms accepted.');
        else flash_set('error', is_string($res) ? $res : 'Failed to accept terms.');
        break;

    case 'reject':
        if ($user_type !== 'client') {
            flash_set('error','Only clients can reject terms.');
            break;
        }
        $res = $db->rejectPaymentTerms($booking_id, $user_id);
        if ($res === true) flash_set('success','Payment terms rejected.');
        else flash_set('error', is_string($res) ? $res : 'Failed to reject terms.');
        break;

    default:
        flash_set('error','Unknown action.');
}

header("Location: ".($conversation_id ? "conversation.php?id=".$conversation_id : "inbox.php"));