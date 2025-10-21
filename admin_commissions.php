<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$stmt = $db->opencon()->query("
  SELECT c.commission_id, c.booking_id, c.percentage, c.amount, c.created_at,
         b.total_amount, b.service_id, b.client_id, b.freelancer_id,
         s.title AS service_title,
         u1.first_name AS client_first, u1.last_name AS client_last,
         u2.first_name AS free_first, u2.last_name AS free_last
    FROM commissions c
    JOIN bookings b ON c.booking_id = b.booking_id
    JOIN services s ON b.service_id = s.service_id
    JOIN users u1 ON b.client_id = u1.user_id
    JOIN users u2 ON b.freelancer_id = u2.user_id
    ORDER BY c.created_at DESC
    LIMIT 100
");
$commissions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin - Commissions</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php" class="btn btn-sm btn-outline-light">Dashboard</a>
  </div>
</nav>
<div class="container">
  <h3 class="mb-4">Commissions Earned</h3>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>Date</th>
        <th>Booking</th>
        <th>Service</th>
        <th>Client</th>
        <th>Freelancer</th>
        <th>Total Amount</th>
        <th>Commission %</th>
        <th>Commission</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($commissions as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['created_at']) ?></td>
        <td><?= (int)$c['booking_id'] ?></td>
        <td><?= htmlspecialchars($c['service_title']) ?></td>
        <td><?= htmlspecialchars($c['client_first'].' '.$c['client_last']) ?></td>
        <td><?= htmlspecialchars($c['free_first'].' '.$c['free_last']) ?></td>
        <td>₱<?= number_format($c['total_amount'],2) ?></td>
        <td><?= number_format($c['percentage'],2) ?>%</td>
        <td class="text-success fw-bold">₱<?= number_format($c['amount'],2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>