<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$user_id = (int)($_GET['user_id'] ?? 0);
$userDetail = $db->opencon()->query("SELECT * FROM users WHERE user_id=$user_id")->fetch();
$bookings = $db->opencon()->query("SELECT * FROM bookings WHERE client_id=$user_id OR freelancer_id=$user_id ORDER BY created_at DESC LIMIT 5")->fetchAll();
$warnings = $userDetail['warnings'] ?? 0; // Add a 'warnings' column to users table for this
$reviews = $db->opencon()->query("SELECT * FROM reviews WHERE reviewer_id=$user_id OR reviewee_id=$user_id ORDER BY created_at DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>User Detail - Admin</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_users.php" class="btn btn-sm btn-outline-light">Back to Users</a>
  </div>
</nav>
<div class="container">
  <h3>User Profile: <?= htmlspecialchars($userDetail['first_name'].' '.$userDetail['last_name']) ?> (<?= htmlspecialchars($userDetail['user_type']) ?>)</h3>
  <div>Status: <span class="badge bg-<?= $userDetail['status']=='active'?'success':'danger' ?>"><?= htmlspecialchars($userDetail['status']) ?></span></div>
  <div>Email: <?= htmlspecialchars($userDetail['email']) ?></div>
  <div>Warnings: <?= (int)$warnings ?></div>
  <hr>
  <h5>Recent Bookings</h5>
  <ul>
    <?php foreach ($bookings as $b): ?>
      <li>Booking #<?= $b['booking_id'] ?> - <?= $b['status'] ?> (₱<?= number_format($b['total_amount'],2) ?>)</li>
    <?php endforeach; ?>
  </ul>
  <h5>Recent Reviews</h5>
  <ul>
    <?php foreach ($reviews as $r): ?>
      <li><?= $r['rating'] ?>/5: <?= htmlspecialchars($r['comment']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
</body>
</html>