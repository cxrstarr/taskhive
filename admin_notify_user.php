<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: admin_dashboard.php?view=notify_user'); exit; }
  $toUser = (int)($_POST['user_id'] ?? 0);
  $text = trim((string)($_POST['text'] ?? ''));
  if ($toUser && $text !== '') {
    $stmt = $db->opencon()->prepare("INSERT INTO notifications (user_id,type,data,created_at) VALUES (?,?,?,NOW())");
    // Use type 'system' so it renders as "System: ..." for all admins
    $stmt->execute([$toUser, 'system', json_encode(['text' => $text], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    flash_set('success', 'Notification sent!');
    header('Location: admin_dashboard.php?view=notify_user');
    exit;
  } else {
    flash_set('error', 'Please provide a valid user and message.');
    header('Location: admin_dashboard.php?view=notify_user');
    exit;
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Send Notification - Admin</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=notify_user" class="btn btn-sm btn-outline-light">Back to Dashboard</a>
  </div>
</nav>
<div class="container">
  <?= flash_render(); ?>
  <h3>Send Notification to User</h3>
  <form method="POST">
    <?= csrf_input(); ?>
    <input type="number" name="user_id" placeholder="User ID" class="form-control mb-2" required />
    <textarea name="text" class="form-control mb-2" placeholder="Message" required></textarea>
    <button class="btn btn-primary">Send</button>
  </form>
</div>
</body>
</html>