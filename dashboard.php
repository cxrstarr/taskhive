<?php
session_start();
require_once __DIR__ . '/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new database();
$uid = (int)$_SESSION['user_id'];
$u = $db->getUser($uid);
if (!$u) {
    header('Location: login.php');
    exit;
}

$type = strtolower((string)($u['user_type'] ?? ''));
if ($type === 'freelancer') {
    $target = file_exists(__DIR__.'/freelancer_dashboard.php') ? 'freelancer_dashboard.php' : 'freelancer_profile.php?id='.(int)$uid;
    header('Location: '.$target);
    exit;
}

// default to client dashboard
$target = file_exists(__DIR__.'/client_dashboard.php') ? 'client_dashboard.php' : 'client_profile.php';
header('Location: '.$target);
exit;