<?php
session_start();
require_once 'database.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }
$booking_id = (int)($_GET['booking_id'] ?? 0);
$booking = $db->opencon()->query("SELECT * FROM bookings WHERE booking_id=$booking_id")->fetch();
$dispute = $db->opencon()->query("SELECT * FROM disputes WHERE booking_id=$booking_id")->fetch();
$messages = $db->opencon()->query("SELECT * FROM messages WHERE booking_id=$booking_id ORDER BY created_at")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Booking Detail</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_bookings.php" class="btn btn-sm btn-outline-light">Back to Bookings</a>
  </div>
</nav>
<div class="container">
  <h3>Booking #<?= $booking['booking_id'] ?> (<?= htmlspecialchars($booking['status']) ?>)</h3>
  <div>Service: <?= htmlspecialchars($booking['title_snapshot']) ?></div>
  <div>Client: <?= $booking['client_id'] ?> | Freelancer: <?= $booking['freelancer_id'] ?></div>
  <div>Amount: ₱<?= number_format($booking['total_amount'],2) ?></div>
  <hr>
  <h5>Messages</h5>
  <ul>
    <?php foreach ($messages as $m): ?>
      <li><?= htmlspecialchars($m['body']) ?> <small>(<?= $m['created_at'] ?>)</small></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($dispute): ?>
    <hr>
    <h5>Dispute</h5>
    <div>Status: <?= htmlspecialchars($dispute['status']) ?></div>
    <div>Reason: <?= htmlspecialchars($dispute['reason_code']) ?></div>
    <div>Description: <?= htmlspecialchars($dispute['description']) ?></div>
    <form method="POST" action="admin_dispute_action.php">
      <input type="hidden" name="dispute_id" value="<?= (int)$dispute['dispute_id'] ?>">
      <input type="text" name="resolution" placeholder="Resolution notes" class="form-control mb-2" />
      <button class="btn btn-success" name="action" value="resolve">Resolve</button>
      <button class="btn btn-danger" name="action" value="reject">Reject</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>