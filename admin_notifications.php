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
  SELECT n.notification_id, n.user_id, n.type, n.data, n.read_at, n.created_at,
         u.first_name, u.last_name
    FROM notifications n
    JOIN users u ON n.user_id = u.user_id
    WHERE 1";
$params = [];
if ($q !== '') {
  $sql .= " AND (
    CONCAT(u.first_name,' ',u.last_name) LIKE :q
    OR n.type LIKE :q
    OR n.data LIKE :q
    OR CAST(n.notification_id AS CHAR) LIKE :q
    OR CAST(n.user_id AS CHAR) LIKE :q
  )";
  $params[':q'] = "%$q%";
}
$sql .= " ORDER BY n.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Admin - Notifications Audit</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=notifications" class="btn btn-sm btn-outline-light">Dashboard Notifications</a>
  </div>
</nav>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">System Notifications Audit</h3>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" style="max-width:360px" type="text" name="q" placeholder="Search user, type, data" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button class="btn btn-outline-primary">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="admin_notifications.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>Date</th>
        <th>User</th>
        <th>Type</th>
        <th>Data</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($notifications as $n): ?>
      <tr>
        <td><?= htmlspecialchars($n['created_at']) ?></td>
        <td><?= htmlspecialchars($n['first_name'].' '.$n['last_name']) ?></td>
        <td><?= htmlspecialchars($n['type']) ?></td>
        <td><pre><?= htmlspecialchars($n['data']) ?></pre></td>
        <td>
          <span class="badge bg-<?= $n['read_at'] ? 'success' : 'warning' ?>">
            <?= $n['read_at'] ? 'Read' : 'Unread' ?>
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$notifications): ?>
      <tr><td colspan="5" class="text-muted text-center">No notifications found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>