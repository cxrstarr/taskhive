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
$sql = "SELECT b.booking_id, b.title_snapshot, b.status, b.total_amount, b.payment_status, b.payment_terms_status, b.created_at,
    u1.first_name AS client_first, u1.last_name AS client_last, u2.first_name AS free_first, u2.last_name AS free_last
  FROM bookings b
  JOIN users u1 ON b.client_id = u1.user_id
  JOIN users u2 ON b.freelancer_id = u2.user_id
  WHERE 1";
$params = [];
if ($q !== '') {
  $sql .= " AND (
    b.title_snapshot LIKE :q OR b.status LIKE :q OR b.payment_status LIKE :q OR b.payment_terms_status LIKE :q
    OR CONCAT(u1.first_name,' ',u1.last_name) LIKE :q OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q
    OR CAST(b.booking_id AS CHAR) LIKE :q
  )";
  $params[':q'] = "%$q%";
}
$sql .= " ORDER BY b.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin - Bookings</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=bookings" class="btn btn-sm btn-outline-light">Dashboard Bookings</a>
  </div>
</nav>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Bookings</h3>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" style="max-width:340px" type="text" name="q" placeholder="Search booking id, service, users, status" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button class="btn btn-outline-primary">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="admin_bookings.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>ID</th>
        <th>Service</th>
        <th>Client</th>
        <th>Freelancer</th>
        <th>Status</th>
        <th>Payment Status</th>
        <th>Terms</th>
        <th>Total Amount</th>
        <th>Created</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($bookings as $b): ?>
      <tr>
        <td><?= (int)$b['booking_id'] ?></td>
        <td><?= htmlspecialchars($b['title_snapshot']) ?></td>
        <td><?= htmlspecialchars($b['client_first'].' '.$b['client_last']) ?></td>
        <td><?= htmlspecialchars($b['free_first'].' '.$b['free_last']) ?></td>
        <td><?= htmlspecialchars($b['status']) ?></td>
        <td><?= htmlspecialchars($b['payment_status']) ?></td>
        <td><?= htmlspecialchars($b['payment_terms_status']) ?></td>
        <td>₱<?= number_format($b['total_amount'],2) ?></td>
        <td><?= htmlspecialchars($b['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$bookings): ?>
      <tr><td colspan="9" class="text-muted text-center">No bookings found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>