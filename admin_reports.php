<?php
session_start();
require_once 'database.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }
$reports = $db->opencon()->query("SELECT * FROM reports ORDER BY created_at DESC LIMIT 50")->fetchAll(); // Assumes a "reports" table
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Reports - Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php" class="btn btn-sm btn-outline-light">Dashboard</a>
  </div>
</nav>
<div class="container">
  <h3>User Reports</h3>
  <table class="table table-bordered bg-white">
    <thead>
      <tr><th>ID</th><th>Reporter</th><th>Type</th><th>Target</th><th>Description</th><th>Status</th><th>Created</th></tr>
    </thead>
    <tbody>
      <?php foreach($reports as $r): ?>
      <tr>
        <td><?= $r['report_id'] ?></td>
        <td><?= $r['reporter_id'] ?></td>
        <td><?= $r['report_type'] ?></td>
        <td><?= $r['target_id'] ?></td>
        <td><?= htmlspecialchars($r['description']) ?></td>
        <td><?= htmlspecialchars($r['status']) ?></td>
        <td><?= $r['created_at'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>