<?php
session_start();
require_once 'database.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$pendingServices = $db->opencon()->query("SELECT * FROM services WHERE status='draft' OR status='paused' ORDER BY created_at DESC")->fetchAll();
$flaggedServices = $db->opencon()->query("SELECT * FROM services WHERE flagged=1 ORDER BY created_at DESC")->fetchAll(); // Add flagged column to services
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Service Approval - Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_services.php" class="btn btn-sm btn-outline-light">Back to Services</a>
  </div>
</nav>
<div class="container">
  <h3>Services Waiting Approval</h3>
  <ul>
    <?php foreach ($pendingServices as $s): ?>
      <li><?= htmlspecialchars($s['title']) ?> by <?= $s['freelancer_id'] ?> 
        <form method="POST" action="admin_service_action.php" class="d-inline">
          <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
          <button class="btn btn-sm btn-success" name="action" value="approve">Approve</button>
          <button class="btn btn-sm btn-danger" name="action" value="reject">Reject</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>

  <h3 class="mt-5">Flagged Services</h3>
  <ul>
    <?php foreach ($flaggedServices as $s): ?>
      <li><?= htmlspecialchars($s['title']) ?> by <?= $s['freelancer_id'] ?> <span class="text-danger">(Flagged)</span></li>
    <?php endforeach; ?>
  </ul>
</div>
</body>
</html>