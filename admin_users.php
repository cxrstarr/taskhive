<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

// Only allow admin
if (empty($_SESSION['user_id'])) {
    header('Location: admin_login.php'); exit;
}
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') {
    echo "Access denied.";
    exit;
}

$pdo = $db->opencon();
$q = trim($_GET['q'] ?? '');
$sql = "SELECT user_id, first_name, last_name, email, user_type, status, created_at FROM users WHERE 1";
$params = [];
if ($q !== '') {
  $sql .= " AND (CONCAT(first_name,' ',last_name) LIKE :q OR email LIKE :q OR user_type LIKE :q OR CAST(user_id AS CHAR) LIKE :q)";
  $params[':q'] = "%$q%";
}
$sql .= " ORDER BY created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin - Users</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=users" class="btn btn-sm btn-outline-light">Dashboard Users</a>
  </div>
</nav>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">User Management</h3>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" style="max-width:280px" type="text" name="q" placeholder="Search name, email, id, type" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button class="btn btn-outline-primary">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="admin_users.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Email</th>
        <th>Type</th>
        <th>Status</th>
        <th>Created</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= (int)$u['user_id'] ?></td>
        <td><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['user_type']) ?></td>
        <td><?= htmlspecialchars($u['status']) ?></td>
        <td><?= htmlspecialchars($u['created_at']) ?></td>
        <td>
          <form method="POST" action="admin_user_action.php" class="d-inline">
            <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
            <button class="btn btn-sm btn-danger" name="action" value="suspend">Suspend</button>
            <button class="btn btn-sm btn-warning" name="action" value="delete">Delete</button>
            <button class="btn btn-sm btn-success" name="action" value="activate">Activate</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?>
      <tr><td colspan="7" class="text-muted text-center">No users found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>