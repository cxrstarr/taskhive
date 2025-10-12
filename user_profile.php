<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_GET['id'])) {
    flash_set('error','Profile not found.');
    header("Location: mainpage.php"); exit;
}
$db = new database();
$viewer_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$profile_id = (int)$_GET['id'];

$public = $db->getPublicProfile($profile_id);
if (!$public) {
    flash_set('error','User profile not found.');
    header("Location: mainpage.php"); exit;
}

$isSelf = $viewer_id === $profile_id;
$canMessage = $viewer_id && !$isSelf;

$skills = [];
if (($public['user_type'] ?? '') === 'freelancer' && !empty($public['skills'])) {
    $skills = array_filter(array_map('trim', explode(',', $public['skills'])));
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($public['first_name'].' '.$public['last_name']); ?> - Profile</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { background:#fafafa; }
    .profile-wrapper { max-width:1100px; margin:auto; }
    .avatar { width:140px;height:140px;object-fit:cover; }
  </style>
</head>
<body>
<div class="container py-4 profile-wrapper">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="mainpage.php#feed" class="btn btn-sm btn-outline-secondary">&larr; Back to Feed</a>
    <div class="d-flex gap-2">
      <?php if ($canMessage): ?>
        <form method="POST" action="start_conversation.php" class="d-inline">
          <input type="hidden" name="target_user_id" value="<?= (int)$public['user_id']; ?>">
          <button class="btn btn-sm btn-primary">Message</button>
        </form>
      <?php endif; ?>
      <?php if ($viewer_id && !$isSelf): ?>
        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reportUserModal">Report User</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <div class="d-flex flex-column flex-md-row gap-4">
        <div class="text-center">
          <img src="<?= htmlspecialchars($public['profile_picture'] ?: 'img/client1.webp'); ?>" class="rounded-circle avatar mb-2" alt="avatar">
          <h4 class="mb-0"><?= htmlspecialchars($public['first_name'].' '.$public['last_name']); ?></h4>
          <div class="text-muted small mb-2"><?= htmlspecialchars(ucfirst($public['user_type'])); ?></div>
          <div>
            <span class="badge bg-warning text-dark">Rating: <?= number_format((float)$public['avg_rating'],2); ?> (<?= (int)$public['total_reviews']; ?>)</span>
          </div>
        </div>
        <div class="flex-grow-1">
          <h5>Bio</h5>
          <p class="mb-3"><?= $public['bio'] ? nl2br(htmlspecialchars($public['bio'])) : '<span class="text-muted">No bio.</span>'; ?></p>

          <?php if ($public['user_type']==='freelancer'): ?>
            <h6>Skills</h6>
            <?php if ($skills): ?>
              <p>
                <?php foreach($skills as $sk): ?>
                  <span class="badge bg-light text-dark border"><?= htmlspecialchars($sk); ?></span>
                <?php endforeach; ?>
              </p>
              <?php if (!empty($public['hourly_rate'])): ?>
                <div class="mb-2"><strong>Hourly Rate:</strong> ₱<?= number_format((float)$public['hourly_rate'],2); ?></div>
              <?php endif; ?>
            <?php else: ?>
              <p class="text-muted">No skills listed.</p>
            <?php endif; ?>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

  <?php if ($public['user_type']==='freelancer'): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="mb-3">Services</h5>
        <?php if (!empty($public['services'])): ?>
          <div class="row g-3">
            <?php foreach($public['services'] as $svc): ?>
              <div class="col-md-4">
                <div class="border rounded p-2 h-100">
                  <strong class="d-block text-truncate" title="<?= htmlspecialchars($svc['title']); ?>">
                    <?= htmlspecialchars($svc['title']); ?>
                  </strong>
                  <div class="small text-muted mb-1">
                    <?= htmlspecialchars(mb_substr(strip_tags($svc['description']),0,90)); ?><?= (strlen($svc['description'])>90?'...':''); ?>
                  </div>
                  <span class="badge bg-warning text-dark">₱<?= number_format((float)$svc['base_price'],2); ?><?= $svc['price_unit']==='hourly'?'/hr':($svc['price_unit']==='per_unit'?'/unit':''); ?></span>
                  <a href="service.php?slug=<?= urlencode($svc['slug']); ?>" class="btn btn-sm btn-outline-primary mt-2 w-100">View</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">No services posted.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="mb-3">Reviews</h5>
      <?php if (!empty($public['reviews'])): ?>
        <?php foreach($public['reviews'] as $rv): ?>
          <div class="border-bottom mb-2 pb-2">
            <strong><?= htmlspecialchars($rv['first_name'].' '.$rv['last_name']); ?></strong>
            <span class="badge bg-success"><?= (int)$rv['rating']; ?>/5</span>
            <div class="small text-muted"><?= htmlspecialchars($rv['created_at']); ?></div>
            <div class="mb-1"><?= nl2br(htmlspecialchars($rv['comment'] ?? '')); ?></div>
            <?php if ($viewer_id): ?>
              <form method="POST" action="report_action.php" class="d-inline" onsubmit="return confirm('Report this review to moderators?');">
                <input type="hidden" name="report_type" value="review">
                <input type="hidden" name="target_id" value="<?= (int)$rv['review_id'] ?>">
                <input type="hidden" name="return" value="<?= 'user_profile.php?id='.(int)$public['user_id'] ?>">
                <input type="hidden" name="description" value="<?= htmlspecialchars('Reported on profile '.$public['user_id'], ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn btn-link btn-sm text-danger p-0">Report</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="text-muted mb-0">No reviews yet.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($viewer_id && !$isSelf): ?>
<!-- Report User Modal -->
<div class="modal fade" id="reportUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="report_action.php">
      <div class="modal-header">
        <h5 class="modal-title">Report User</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="report_type" value="user">
        <input type="hidden" name="target_id" value="<?= (int)$public['user_id'] ?>">
        <input type="hidden" name="return" value="<?= 'user_profile.php?id='.(int)$public['user_id'] ?>">
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