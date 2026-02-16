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
  SELECT r.review_id, r.booking_id, r.rating, r.comment, r.reply, r.created_at,
         u1.first_name AS reviewer_first, u1.last_name AS reviewer_last,
         u2.first_name AS reviewee_first, u2.last_name AS reviewee_last
    FROM reviews r
    JOIN users u1 ON r.reviewer_id = u1.user_id
    JOIN users u2 ON r.reviewee_id = u2.user_id
    WHERE 1";
$params = [];
if ($q !== '') {
  $sql .= " AND (
    CONCAT(u1.first_name,' ',u1.last_name) LIKE :q
    OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q
    OR r.comment LIKE :q
    OR r.reply LIKE :q
    OR CAST(r.booking_id AS CHAR) LIKE :q
    OR CAST(r.rating AS CHAR) LIKE :q
  )";
  $params[':q'] = "%$q%";
}
$sql .= " ORDER BY r.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin - Reviews</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php?view=reviews" class="btn btn-sm btn-outline-light">Dashboard Reviews</a>
  </div>
</nav>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Review Moderation</h3>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" style="max-width:360px" type="text" name="q" placeholder="Search reviewer, reviewee, text, booking" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button class="btn btn-outline-primary">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="admin_reviews.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <table class="table table-bordered bg-white">
    <thead>
      <tr>
        <th>Date</th>
        <th>Booking</th>
        <th>Reviewer</th>
        <th>Reviewee</th>
        <th>Rating</th>
        <th>Comment</th>
        <th>Reply</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($reviews as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
        <td><?= (int)$r['booking_id'] ?></td>
        <td><?= htmlspecialchars($r['reviewer_first'].' '.$r['reviewer_last']) ?></td>
        <td><?= htmlspecialchars($r['reviewee_first'].' '.$r['reviewee_last']) ?></td>
        <td><span class="badge bg-success"><?= (int)$r['rating'] ?>/5</span></td>
        <td><?= nl2br(htmlspecialchars($r['comment'] ?? '')) ?></td>
        <td><?= nl2br(htmlspecialchars($r['reply'] ?? '')) ?></td>
        <td>
          <form method="POST" action="admin_review_action.php" class="d-inline">
            <?php require_once __DIR__ . '/includes/csrf.php'; echo csrf_input(); ?>
            <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
            <button class="btn btn-sm btn-danger" name="action" value="delete">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$reviews): ?>
      <tr><td colspan="8" class="text-muted text-center">No reviews found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>