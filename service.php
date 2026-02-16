<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_GET['slug'])) {
    flash_set('error','Service not found.');
  header("Location: index.php"); exit;
}

$db  = new database();
$pdo = $db->opencon();

$stmt = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.profile_picture, u.user_id AS freelancer_user_id
    FROM services s
    JOIN users u ON s.freelancer_id = u.user_id
    WHERE s.slug = :slug AND s.status='active'
    LIMIT 1
");
$stmt->execute([':slug'=>$_GET['slug']]);
$svc = $stmt->fetch();

if (!$svc) {
    flash_set('error','Service not found or inactive.');
  header("Location: index.php"); exit;
}

$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = $db->getUser((int)$_SESSION['user_id']);
}

$isOwner  = $currentUser && $currentUser['user_id'] == $svc['freelancer_user_id'];
$isClient = $currentUser && $currentUser['user_type'] === 'client';

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($svc['title']); ?> - TaskHive</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
    .price-badge { font-size:0.9rem; }
    .avatar-sm { width:64px;height:64px;object-fit:cover; }
    .service-actions .btn { margin-right:4px; }
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
  <a href="index.php" class="navbar-brand fw-bold text-warning">TaskHive 🐝</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav ms-auto">
  <li class="nav-item"><a class="nav-link" href="feed.php">Feed</a></li>
        <?php if ($currentUser): 
          $dashboard = $currentUser['user_type']==='freelancer' ? 'freelancer_profile.php' : 'client_profile.php';
        ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
              <?= htmlspecialchars($currentUser['first_name'].' '.$currentUser['last_name']); ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="dashboard.php">View Dashboard</a></li>
              <li><a class="dropdown-item" href="settings.php">Settings</a></li>
              <li><a class="dropdown-item" href="logout.php">Logout</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
          <li class="nav-item"><a class="nav-link" href="register.php">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-5">
  <a href="feed.php" class="btn btn-sm btn-secondary mb-4">&larr; Back</a>
  <div class="card shadow-sm p-4">
    <div class="d-flex align-items-center mb-3">
      <img src="<?= htmlspecialchars($svc['profile_picture'] ?: 'img/client1.webp'); ?>" class="rounded-circle me-3 avatar-sm" alt="avatar">
      <div>
        <h3 class="mb-0"><?= htmlspecialchars($svc['title']); ?></h3>
        <small class="text-muted">
          By
          <a href="user_profile.php?id=<?= (int)$svc['freelancer_user_id']; ?>" class="text-decoration-none">
            <?= htmlspecialchars($svc['first_name'].' '.$svc['last_name']); ?>
          </a>
          &middot; <?= htmlspecialchars(date('M d, Y', strtotime($svc['created_at']))); ?>
        </small>
      </div>
    </div>

    <p class="mb-3"><?= nl2br(htmlspecialchars($svc['description'])); ?></p>

    <p class="mb-4">
      <span class="badge bg-warning text-dark price-badge">
        Price: ₱<?= number_format((float)$svc['base_price'],2); ?>
        <?php
          if ($svc['price_unit']==='hourly') echo '/hr';
          elseif ($svc['price_unit']==='per_unit') echo '/unit';
        ?>
      </span>
      <span class="ms-2 text-muted small">Minimum units: <?= (int)$svc['min_units']; ?></span>
    </p>

    <div class="d-flex flex-wrap gap-2 service-actions">
      <?php if (!$currentUser): ?>
        <a href="login.php" class="btn btn-hive">Login to Book</a>
        <a href="login.php" class="btn btn-outline-primary btn-sm">Login to Message</a>
      <?php else: ?>
        <?php if ($isOwner): ?>
          <div class="alert alert-info mb-0 py-1 px-2">You own this service.</div>
        <?php else: ?>
          <?php if ($isClient): ?>
            <button class="btn btn-hive" data-bs-toggle="modal" data-bs-target="#bookModal">Book / Hire</button>
          <?php endif; ?>
          <!-- Message (any user type) -->
          <form action="start_conversation.php" method="POST" class="d-inline">
            <?php require_once __DIR__ . '/includes/csrf.php'; echo csrf_input(); ?>
            <input type="hidden" name="target_user_id" value="<?= (int)$svc['freelancer_user_id']; ?>">
            <input type="hidden" name="service_id" value="<?= (int)$svc['service_id']; ?>">
            <button class="btn btn-outline-primary btn-sm" type="submit">Message</button>
          </form>
          <!-- Report Service -->
          <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#reportServiceModal">Report</button>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php if ($isClient && !$isOwner): ?>
<!-- Booking Modal -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="book_service.php">
      <?php require_once __DIR__ . '/includes/csrf.php'; echo csrf_input(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Book: <?= htmlspecialchars($svc['title']); ?></h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="service_id" value="<?= (int)$svc['service_id']; ?>">
        <input type="hidden" name="return_slug" value="<?= htmlspecialchars($svc['slug']); ?>">
        <div class="mb-3">
          <label class="form-label">Quantity / Units</label>
          <input type="number" name="quantity"
                 value="<?= (int)$svc['min_units']; ?>"
                 min="<?= (int)$svc['min_units']; ?>"
                 class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Scheduled Start (optional)</label>
          <input type="datetime-local" name="scheduled_start" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Scheduled End (optional)</label>
          <input type="datetime-local" name="scheduled_end" class="form-control">
        </div>
        <div class="alert alert-light border small">
          Platform fee (estimate) is 10% and appears in your booking summary.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-hive" type="submit">Confirm Booking</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($currentUser && !$isOwner): ?>
<!-- Report Service Modal -->
<div class="modal fade" id="reportServiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="report_action.php">
      <?php require_once __DIR__ . '/includes/csrf.php'; echo csrf_input(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Report Service</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="report_type" value="service">
        <input type="hidden" name="target_id" value="<?= (int)$svc['service_id'] ?>">
        <input type="hidden" name="return" value="<?= 'service.php?slug='.urlencode($svc['slug']) ?>">
        <label class="form-label">Describe the issue</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Reason / details..." required></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger">Submit Report</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?= flash_render(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>