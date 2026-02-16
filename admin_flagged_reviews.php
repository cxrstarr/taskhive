<?php
session_start();
require_once 'database.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }
$flaggedReviews = $db->opencon()->query("SELECT * FROM reviews WHERE flagged=1 ORDER BY created_at DESC")->fetchAll(); // Add flagged column
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Flagged Reviews - Admin</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_reviews.php" class="btn btn-sm btn-outline-light">Back to Reviews</a>
  </div>
</nav>
<div class="container">
  <h3>Flagged Reviews</h3>
  <ul>
    <?php foreach ($flaggedReviews as $r): ?>
      <li>
        <?= htmlspecialchars($r['rating']) ?>/5: <?= htmlspecialchars($r['comment']) ?>
        <form method="POST" action="admin_review_action.php" class="d-inline">
          <?php require_once __DIR__ . '/includes/csrf.php'; echo csrf_input(); ?>
          <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
          <button class="btn btn-sm btn-danger" name="action" value="delete">Delete</button>
          <button class="btn btn-sm btn-success" name="action" value="approve">Keep</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
</body>
</html>