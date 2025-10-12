<?php
session_start();
require_once 'database.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $toUser = (int)$_POST['user_id'];
  $text = trim($_POST['text']);
  $db->opencon()->prepare("INSERT INTO notifications (user_id,type,data) VALUES (?,?,?)")
    ->execute([$toUser, 'admin_message', json_encode(['text'=>$text])]);
  echo '<div class="alert alert-success">Notification sent!</div>';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Send Notification - Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php" class="btn btn-sm btn-outline-light">Dashboard</a>
  </div>
</nav>
<div class="container">
  <h3>Send Notification to User</h3>
  <form method="POST">
    <input type="number" name="user_id" placeholder="User ID" class="form-control mb-2" required />
    <textarea name="text" class="form-control mb-2" placeholder="Message" required></textarea>
    <button class="btn btn-primary">Send</button>
  </form>
</div>
</body>
</html>