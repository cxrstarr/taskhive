<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$pdo = $db->opencon();
$q = trim($_GET['q'] ?? '');
$sql = "SELECT p.payment_id, p.booking_id, p.amount, p.method, p.payment_phase, p.status, p.paid_at, b.client_id, b.freelancer_id,
    u1.first_name AS client_first, u1.last_name AS client_last, u2.first_name AS free_first, u2.last_name AS free_last
  FROM payments p
  JOIN bookings b ON p.booking_id = b.booking_id
  JOIN users u1 ON b.client_id = u1.user_id
  JOIN users u2 ON b.freelancer_id = u2.user_id
  WHERE 1";
$params = [];
if ($q !== '') {
  $sql .= " AND (
    CONCAT(u1.first_name,' ',u1.last_name) LIKE :q OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q
    OR p.method LIKE :q OR p.payment_phase LIKE :q OR p.status LIKE :q
    OR CAST(p.payment_id AS CHAR) LIKE :q OR CAST(p.booking_id AS CHAR) LIKE :q
  )";
  $params[':q'] = "%$q%";
}
$sql .= " ORDER BY p.paid_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = $db->opencon()->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status IN ('escrowed','released')")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin - Payments</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=payments" class="btn btn-sm btn-outline-light">Dashboard Payments</a>
  </div>
</nav>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Payments</h3>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" style="max-width:360px" type="text" name="q" placeholder="Search id, booking, users, method, status" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button class="btn btn-outline-primary">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="admin_payments.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="alert alert-success mb-4">
    <strong>Total Payments (escrowed/released):</strong>
    ₱<?= number_format((float)$total,2) ?>
  </div>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>ID</th>
        <th>Booking</th>
        <th>Client</th>
        <th>Freelancer</th>
        <th>Amount</th>
        <th>Method</th>
        <th>Phase</th>
        <th>Status</th>
        <th>Paid At</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= (int)$p['payment_id'] ?></td>
        <td><?= (int)$p['booking_id'] ?></td>
        <td><?= htmlspecialchars($p['client_first'].' '.$p['client_last']) ?></td>
        <td><?= htmlspecialchars($p['free_first'].' '.$p['free_last']) ?></td>
        <td>₱<?= number_format($p['amount'],2) ?></td>
        <td><?= htmlspecialchars($p['method']) ?></td>
        <td><?= htmlspecialchars($p['payment_phase']) ?></td>
        <td><?= htmlspecialchars($p['status']) ?></td>
        <td><?= htmlspecialchars($p['paid_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?>
      <tr><td colspan="9" class="text-muted text-center">No payments found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>