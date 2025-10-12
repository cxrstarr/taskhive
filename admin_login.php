<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
  <meta charset="UTF-8">
  <title>Admin Login - TaskHive</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-5">
      <div class="card p-4 shadow">
        <h3 class="mb-4 text-center">Admin Login</h3>
        <form method="POST">
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