<?php
session_start();
require_once __DIR__.'/database.php';
require_once __DIR__.'/flash.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$service_id = (int)($_GET['service_id'] ?? 0);
if ($service_id <= 0) {
  flash_set('error','Missing service id.');
  header('Location: admin_dashboard.php?view=service_queue'); exit;
}

$pdo = $db->opencon();

// detect optional flag columns on services
$hasFlagCols = false;
try {
  $chk = $pdo->query("SHOW COLUMNS FROM services LIKE 'flagged'");
  $hasFlagCols = (bool)$chk->fetch();
} catch (Throwable $e) {}

$stmt = $pdo->prepare("
  SELECT s.*,
         u.user_id AS freelancer_user_id, u.first_name, u.last_name, u.email, u.profile_picture
  FROM services s
  JOIN users u ON u.user_id = s.freelancer_id
  WHERE s.service_id = :sid
  LIMIT 1
");
$stmt->execute([':sid'=>$service_id]);
$svc = $stmt->fetch();

if (!$svc) {
  flash_set('error','Service not found.');
  header('Location: admin_dashboard.php?view=service_queue'); exit;
}

// service media (if any)
$media = [];
try {
  $m = $pdo->prepare("SELECT media_id,url,media_type,position FROM service_media WHERE service_id=? ORDER BY position, media_id LIMIT 24");
  $m->execute([$service_id]);
  $media = $m->fetchAll();
} catch (Throwable $e) {}

// bookings info
$bookingCount = (int)$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE service_id=?")
                         ->execute([$service_id]) ? (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE service_id={$service_id}")->fetchColumn() : 0;
$recentBookings = [];
try {
  $bs = $pdo->prepare("SELECT booking_id,status,total_amount,created_at FROM bookings WHERE service_id=? ORDER BY created_at DESC LIMIT 10");
  $bs->execute([$service_id]);
  $recentBookings = $bs->fetchAll();
} catch (Throwable $e) {}

// reports (if table exists)
$reports = [];
$hasReports = true;
try {
  $rs = $pdo->prepare("SELECT report_id,reporter_id,description,status,created_at FROM reports WHERE report_type='service' AND target_id=? ORDER BY created_at DESC LIMIT 10");
  $rs->execute([$service_id]);
  $reports = $rs->fetchAll();
} catch (Throwable $e) { $hasReports = false; }

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$status = $svc['status'] ?? 'active';
$flagged = $hasFlagCols ? (int)($svc['flagged'] ?? 0) : 0;
$flagReason = $hasFlagCols ? ($svc['flagged_reason'] ?? null) : null;
$flagAt = $hasFlagCols ? ($svc['flagged_at'] ?? null) : null;
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Admin • Service #<?= (int)$service_id ?> Inspect</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .meta-badge { font-size: .82rem; }
    .thumb { width: 110px; height: 80px; object-fit: cover; border-radius: 8px; border:1px solid #e6e6ea; }
    .grid { display:flex; flex-wrap:wrap; gap:10px; }
    .desc { white-space: pre-wrap; }
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <span class="navbar-brand">TaskHive 🐝 Admin</span>
    <div class="d-flex gap-2">
      <a href="admin_dashboard.php?view=service_queue" class="btn btn-sm btn-outline-light">Back to Service Approval</a>
      <a href="admin_dashboard.php?view=services" class="btn btn-sm btn-outline-light">All Services</a>
    </div>
  </div>
</nav>

<div class="container">
  <?= flash_render(); ?>

  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start">
        <div class="me-3">
          <h4 class="mb-1">#<?= (int)$svc['service_id'] ?> • <?= h($svc['title']) ?></h4>
          <div class="small text-muted">Slug: <?= h($svc['slug']) ?> • Created: <?= h($svc['created_at']) ?> • Updated: <?= h($svc['updated_at']) ?></div>
        </div>
        <div class="text-end">
          <span class="badge bg-<?= $status==='active'?'success':($status==='paused'?'secondary':($status==='draft'?'warning text-dark':'dark')) ?> meta-badge"><?= h(ucfirst($status)) ?></span>
          <?php if ($hasFlagCols): ?>
            <?php if ($flagged): ?>
              <span class="badge bg-danger meta-badge">Flagged</span>
            <?php else: ?>
              <span class="badge bg-light text-dark border meta-badge">Not flagged</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <hr>

      <div class="row g-3">
        <div class="col-md-8">
          <h6>Description</h6>
          <div class="desc border rounded p-2 bg-white"><?= nl2br(h($svc['description'] ?? '')) ?></div>
        </div>
        <div class="col-md-4">
          <h6>Meta</h6>
          <ul class="list-group">
            <li class="list-group-item d-flex justify-content-between align-items-center">
              Price
              <span class="badge bg-warning text-dark">₱<?= number_format((float)$svc['base_price'], 2) ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              Unit
              <span class="badge bg-light text-dark border"><?= h($svc['price_unit']) ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              Min Units
              <span class="badge bg-light text-dark border"><?= (int)$svc['min_units'] ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              Bookings
              <span class="badge bg-info text-dark"><?= (int)$bookingCount ?></span>
            </li>
            <?php if ($hasFlagCols): ?>
            <li class="list-group-item">
              <div class="small text-muted mb-1">Flag reason:</div>
              <div><?= $flagReason ? h($flagReason) : '<span class="text-muted">—</span>' ?></div>
              <?php if ($flagAt): ?>
                <div class="small text-muted mt-1">Flagged at: <?= h($flagAt) ?></div>
              <?php endif; ?>
            </li>
            <?php endif; ?>
          </ul>
        </div>
      </div>

      <hr>

      <div class="row g-3">
        <div class="col-md-6">
          <h6>Freelancer</h6>
          <div class="d-flex align-items-center">
            <img src="<?= h($svc['profile_picture'] ?: 'img/client1.webp') ?>" class="rounded me-2" style="width:48px;height:48px;object-fit:cover;">
            <div>
              <div><strong><?= h($svc['first_name'].' '.$svc['last_name']) ?></strong></div>
              <div class="small text-muted"><?= h($svc['email']) ?> • ID <?= (int)$svc['freelancer_user_id'] ?></div>
              <a class="btn btn-sm btn-outline-primary mt-1" href="user_profile.php?id=<?= (int)$svc['freelancer_user_id'] ?>" target="_blank">Open Public Profile</a>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <h6>Media</h6>
          <?php if ($media): ?>
            <div class="grid">
              <?php foreach ($media as $m): ?>
                <a href="<?= h($m['url']) ?>" target="_blank" rel="noopener">
                  <img class="thumb" src="<?= h($m['url']) ?>" alt="media">
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted">No media uploaded.</div>
          <?php endif; ?>
        </div>
      </div>

      <hr>

      <div class="row g-3">
        <div class="col-md-6">
          <h6>Recent Bookings</h6>
          <?php if ($recentBookings): ?>
            <ul class="list-group">
              <?php foreach ($recentBookings as $b): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <span>#<?= (int)$b['booking_id'] ?> • <span class="badge bg-secondary"><?= h($b['status']) ?></span></span>
                  <span>₱<?= number_format((float)$b['total_amount'],2) ?> &nbsp; <span class="text-muted small"><?= h($b['created_at']) ?></span></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">No bookings.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <h6>Recent Reports<?= $hasReports ? '' : ' (reports table not found)' ?></h6>
          <?php if ($reports): ?>
            <ul class="list-group">
              <?php foreach ($reports as $r): ?>
                <li class="list-group-item">
                  <div class="d-flex justify-content-between">
                    <strong>#<?= (int)$r['report_id'] ?></strong>
                    <span class="badge bg-<?= $r['status']==='open'?'warning text-dark':'secondary' ?>"><?= h($r['status'] ?? 'open') ?></span>
                  </div>
                  <div class="small text-muted">Reporter: <?= (int)$r['reporter_id'] ?> • <?= h($r['created_at']) ?></div>
                  <div><?= nl2br(h($r['description'] ?? '')) ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">No reports.</div>
          <?php endif; ?>
        </div>
      </div>

      <hr>

      <h6>Admin Actions</h6>
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <form method="POST" action="admin_service_action.php" class="d-inline">
          <input type="hidden" name="service_id" value="<?= (int)$service_id ?>">
          <button class="btn btn-success" name="action" value="approve">Approve</button>
        </form>

        <form method="POST" action="admin_service_action.php" class="d-inline">
          <input type="hidden" name="service_id" value="<?= (int)$service_id ?>">
          <div class="input-group">
            <input type="text" name="reason" class="form-control form-control-sm" placeholder="Rejection reason (optional)">
            <button class="btn btn-outline-danger" name="action" value="reject">Reject to Draft</button>
          </div>
        </form>

        <?php if ($hasFlagCols): ?>
        <form method="POST" action="admin_service_action.php" class="d-inline">
          <input type="hidden" name="service_id" value="<?= (int)$service_id ?>">
          <div class="input-group">
            <input type="text" name="reason" class="form-control form-control-sm" placeholder="Flag reason (optional)">
            <button class="btn btn-outline-warning" name="action" value="flag">Flag</button>
          </div>
        </form>
        <form method="POST" action="admin_service_action.php" class="d-inline">
          <input type="hidden" name="service_id" value="<?= (int)$service_id ?>">
          <button class="btn btn-outline-secondary" name="action" value="unflag">Unflag</button>
        </form>
        <?php endif; ?>

        <form method="POST" action="admin_service_action.php" class="d-inline" onsubmit="return confirm('Archive this service?');">
          <input type="hidden" name="service_id" value="<?= (int)$service_id ?>">
          <button class="btn btn-outline-dark" name="action" value="archive">Archive</button>
        </form>

        <form method="POST" action="admin_service_action.php" class="d-inline" onsubmit="return confirm('Delete this service permanently? This cannot be undone.');">
          <input type="hidden" name="service_id" value="<?= (int)$service_id ?>">
          <button class="btn btn-outline-danger" name="action" value="delete">Delete</button>
        </form>
      </div>

    </div>
  </div>

  <div class="mb-4">
    <a class="btn btn-outline-secondary" href="admin_dashboard.php?view=service_queue">&larr; Back to Approval Queue</a>
  </div>
</div>
<?= flash_render(); ?>
</body>
</html>