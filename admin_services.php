<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

// Only allow admin
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$pdo = $db->opencon();
$q = trim($_GET['q'] ?? '');
$sql = "SELECT s.service_id, s.title, s.description, s.base_price, s.price_unit, s.status, s.created_at, u.first_name, u.last_name
        FROM services s JOIN users u ON s.freelancer_id = u.user_id
        WHERE 1";
$params = [];
if ($q !== '') {
  $sql .= " AND (s.title LIKE :q OR CONCAT(u.first_name,' ',u.last_name) LIKE :q OR CAST(s.service_id AS CHAR) LIKE :q)";
  $params[':q'] = "%$q%";
}
$sql .= " ORDER BY s.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin - Services</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=services" class="btn btn-sm btn-outline-light">Dashboard Services</a>
  </div>
</nav>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Service Listings</h3>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" style="max-width:280px" type="text" name="q" placeholder="Search title, id, freelancer" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button class="btn btn-outline-primary">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="admin_services.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Freelancer</th>
        <th>Price</th>
        <th>Unit</th>
        <th>Status</th>
        <th>Created</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($services as $s): ?>
      <tr>
        <td><?= (int)$s['service_id'] ?></td>
        <td><?= htmlspecialchars($s['title']) ?></td>
        <td><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></td>
        <td>₱<?= number_format($s['base_price'],2) ?></td>
        <td><?= htmlspecialchars($s['price_unit']) ?></td>
        <td><?= htmlspecialchars($s['status']) ?></td>
        <td><?= htmlspecialchars($s['created_at']) ?></td>
        <td>
          <form method="POST" action="admin_service_action.php" class="d-inline">
            <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
            <button class="btn btn-sm btn-warning" name="action" value="archive">Archive</button>
            <button class="btn btn-sm btn-danger" name="action" value="delete">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$services): ?>
      <tr><td colspan="8" class="text-muted text-center">No services found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>