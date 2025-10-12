<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'freelancer') {
    flash_set('error','You must be logged in as a freelancer.');
    header("Location: login.php"); exit;
}

$db = new database();
$user_id = (int)$_SESSION['user_id'];

$task       = $_POST['task']      ?? '';
$service_id = (int)($_POST['service_id'] ?? 0);

if ($service_id <= 0) {
    flash_set('error','Invalid service.');
    header("Location: freelancer_profile.php"); exit;
}

if ($task === 'set_status') {
    $status = $_POST['status'] ?? '';
    $allowed = ['active','paused','archived'];
    if (!in_array($status, $allowed, true)) {
        flash_set('error','Invalid status.');
        header("Location: freelancer_profile.php"); exit;
    }
    $res = $db->updateServiceStatus($service_id, $user_id, $status);
    if ($res === true) {
        $msg = match($status) {
            'active'   => 'Service is now visible.',
            'paused'   => 'Service hidden (paused).',
            'archived' => 'Service archived (hidden).',
            default    => 'Status updated.'
        };
        flash_set('success', $msg);
    } else {
        flash_set('error', is_string($res) ? $res : 'Could not update service.');
    }
    header("Location: freelancer_profile.php"); exit;
}

if ($task === 'delete') {
    $res = $db->hardDeleteService($service_id, $user_id);
    if ($res === true) {
        flash_set('success','Service permanently deleted.');
    } else {
        flash_set('error', is_string($res) ? $res : 'Delete failed. If the service has bookings, archive it instead.');
    }
    header("Location: freelancer_profile.php"); exit;
}

flash_set('error','Unknown action.');
header("Location: freelancer_profile.php");