<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';

// Fixed impersonation: admin is user ID 5
if (isset($_REQUEST['impersonate'])) {
  $uid = (int)$_REQUEST['impersonate'];
  if ($uid === 5) {
    $db = new database();
    $u = $db->getUser(5);
    if ($u) {
      $_SESSION['user_id'] = (int)$u['user_id'];
      $_SESSION['user_type'] = (string)$u['user_type'];
      $_SESSION['user_email'] = (string)($u['email'] ?? '');
      $_SESSION['user_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
      header('Location: admin_dashboard.php');
      exit;
    }
  }
}

// Remove demo-role flow; using fixed IDs instead

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate()) {
    flash_set('error','Security check failed. Please retry.');
    header('Location: admin_login.php');
    exit;
  }
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $db = new database();
    $user = $db->loginUser($email, $password);
    if ($user && $user['user_type'] === 'admin') {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_type'] = 'admin';
        header('Location: admin_dashboard.php'); exit;
    } else {
        flash_set('error','Invalid admin credentials.');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin Login - TaskHive</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-5">
      <div class="card p-4 shadow">
        <h3 class="mb-4 text-center">Admin Login</h3>
        <div class="mb-3 d-flex gap-2 justify-content-center">
          <a href="admin_login.php?impersonate=5" class="btn btn-warning">Login as Admin (ID 5)</a>
          <a href="login.php?impersonate=1" class="btn btn-outline-warning">Login as Freelancer (ID 1)</a>
          <a href="login.php?impersonate=2" class="btn btn-outline-warning">Login as Client (ID 2)</a>
        </div>
        <form method="POST">
          <?= csrf_input(); ?>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <button class="btn btn-dark w-100">Login</button>
        </form>
        <?= flash_render(); ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>