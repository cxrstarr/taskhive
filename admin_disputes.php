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
$sql = "
  SELECT d.dispute_id, d.booking_id, d.raised_by_id, d.against_id, d.reason_code,
         d.description, d.status, d.resolution, d.resolved_at, d.created_at,
         u1.first_name AS raised_by_first, u1.last_name AS raised_by_last,
         u2.first_name AS against_first, u2.last_name AS against_last
    FROM disputes d
    JOIN users u1 ON d.raised_by_id = u1.user_id
    JOIN users u2 ON d.against_id = u2.user_id
    WHERE 1";
$params = [];
if ($q !== '') {
  $sql .= " AND (
    CONCAT(u1.first_name,' ',u1.last_name) LIKE :q
    OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q
    OR d.reason_code LIKE :q
    OR d.status LIKE :q
    OR d.description LIKE :q
    OR CAST(d.booking_id AS CHAR) LIKE :q
    OR CAST(d.dispute_id AS CHAR) LIKE :q
  )";
  $params[':q'] = "%$q%";
}
$sql .= " ORDER BY d.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin - Disputes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=disputes" class="btn btn-sm btn-outline-light">Dashboard Disputes</a>
  </div>
</nav>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Dispute Tracking</h3>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" style="max-width:360px" type="text" name="q" placeholder="Search booking, users, reason, status" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button class="btn btn-outline-primary">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="admin_disputes.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>Date</th>
        <th>Booking</th>
        <th>Raised By</th>
        <th>Against</th>
        <th>Reason</th>
        <th>Description</th>
        <th>Status</th>
        <th>Resolution</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($disputes as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['created_at']) ?></td>
        <td><?= (int)$d['booking_id'] ?></td>
        <td><?= htmlspecialchars($d['raised_by_first'].' '.$d['raised_by_last']) ?></td>
        <td><?= htmlspecialchars($d['against_first'].' '.$d['against_last']) ?></td>
        <td><?= htmlspecialchars($d['reason_code']) ?></td>
        <td><?= nl2br(htmlspecialchars($d['description'] ?? '')) ?></td>
        <td>
          <span class="badge bg-<?= $d['status']=='resolved'?'success':($d['status']=='open'?'warning':'secondary') ?>">
            <?= htmlspecialchars($d['status']) ?>
          </span>
        </td>
        <td><?= nl2br(htmlspecialchars($d['resolution'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$disputes): ?>
      <tr><td colspan="8" class="text-muted text-center">No disputes found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>