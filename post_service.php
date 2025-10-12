<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_type']??'') !== 'freelancer') {
    flash_set('error','Not authorized.');
    header("Location: login.php"); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: freelancer_profile.php"); exit;
}

$title = trim($_POST['title']??'');
$description = trim($_POST['description']??'');
$base_price = (float)($_POST['base_price']??0);
$price_unit = $_POST['price_unit'] ?? 'fixed';
$min_units = (int)($_POST['min_units'] ?? 1);

if (!$title || !$description || $base_price <= 0) {
    flash_set('error','Fill all required service fields.');
    header("Location: freelancer_profile.php"); exit;
}

$db = new database();
$service_id = $db->createService((int)$_SESSION['user_id'], null, $title, $description, $base_price, $price_unit, $min_units);
if ($service_id) {
    // Put new services into 'draft' so they appear in admin approval queue
    try {
        $pdo = $db->opencon();
        $pdo->prepare("UPDATE services SET status='draft' WHERE service_id=? LIMIT 1")->execute([$service_id]);
    } catch (Throwable $e) {
        // Non-fatal; if it fails, service may default to 'active' by schema default
    }
    // Notify freelancer that service is awaiting admin approval
    if (method_exists($db,'addNotification')) {
        $db->addNotification((int)$_SESSION['user_id'],'service_submitted',[
            'service_id'=>$service_id,
            'title'=>$title,
            'status'=>'draft',
            'message'=>'Your service was submitted and is awaiting admin approval.'
        ]);
    }
    flash_set('success','Service submitted for admin approval.');
} else {
    flash_set('error','Failed to post service.');
}
header("Location: freelancer_profile.php");