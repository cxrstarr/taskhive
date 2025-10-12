<?php
session_start();
require_once 'database.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { exit; }

$type = $_GET['type'] ?? '';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="'.$type.'_export_'.date('Ymd_His').'.csv"');

$pdo = $db->opencon();
$cols = [];
$rows = [];

switch ($type) {
  case 'users':
    $stmt = $pdo->query("SELECT user_id,first_name,last_name,email,user_type,status,created_at FROM users");
    $cols = ['user_id','first_name','last_name','email','user_type','status','created_at'];
    break;
  case 'bookings':
    $stmt = $pdo->query("SELECT booking_id,service_id,client_id,freelancer_id,status,total_amount,created_at FROM bookings");
    $cols = ['booking_id','service_id','client_id','freelancer_id','status','total_amount','created_at'];
    break;
  case 'payments':
    $stmt = $pdo->query("SELECT payment_id,booking_id,amount,method,status,paid_at FROM payments");
    $cols = ['payment_id','booking_id','amount','method','status','paid_at'];
    break;
  default:
    echo "type must be users, bookings, or payments";
    exit;
}

echo implode(',',$cols)."\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $out = [];
    foreach ($cols as $c) $out[] = '"'.str_replace('"','""',$row[$c]).'"';
    echo implode(',',$out)."\n";
}