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
    // Redirect to own freelancer public profile
    header('Location: freelancer_profile.php?id=' . urlencode((string)$uid));
    exit;
}

// Default: client profile
header('Location: client_profile.php');
exit;
 
