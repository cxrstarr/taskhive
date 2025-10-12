<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

/* Auth guard first */
if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'freelancer') {
    flash_set('error','You must be logged in as a freelancer.');
    header("Location: login.php"); exit;
}

$user_id = (int)$_SESSION['user_id'];
$db = new database();

/* Ensure profile row exists (legacy safety) */
$db->ensureFreelancerProfile($user_id);

/* Fetch data */
$profile          = $db->getFreelancerProfile($user_id);
$services         = $db->listFreelancerServices($user_id);
$reviews          = $db->getFreelancerReviews($user_id, 50);
$pendingBookings  = $db->listFreelancerPendingBookings($user_id, 50);
$allBookings      = $db->listUserBookings($user_id,'freelancer');

/* Active bookings (accepted / in_progress / delivered) */
$activeBookings = array_filter($allBookings, function($b){
    return in_array($b['status'], ['accepted','in_progress','delivered'], true);
});

/* Unread messages (badge) */
$unreadTotal = method_exists($db,'countUnreadMessages') ? $db->countUnreadMessages($user_id) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Freelancer Profile - TaskHive</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="loginregister.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .mini-badge { font-size:.7rem; }
    .inbox-badge {
      background:#dc3545;
      font-size:.65rem;
      padding:3px 7px;
      border-radius:12px;
      font-weight:600;
    }
    .table td, .table th { vertical-align: middle; }
    .wrap-word { white-space:normal; }
    .profile-topbar {
        display:flex;
        justify-content:space-between;
        align-items:center;
        background:#fff7d6;
        border:1px solid #e9d88f;
        padding:8px 14px;
        border-radius:10px;
        margin-bottom:15px;
    }
  </style>
</head>
<body>
<section class="register-section">
  <div class="register-card" style="max-width:1100px; text-align:left;">

    <div class="profile-topbar">
      <div>
        <a href="mainpage.php#feed" class="btn btn-sm btn-outline-secondary">&larr; Back to Feed</a>
      </div>
      <div class="small text-muted">
        Logged in as Freelancer
      </div>
    </div>

    <?php if ($profile): ?>
      <div class="d-flex flex-column flex-lg-row gap-4">

        <!-- Left Column -->
        <div class="card p-4 shadow-sm flex-shrink-0" style="min-width:300px;">
          <div class="text-center mb-3">
            <img src="<?= htmlspecialchars($profile['profile_picture'] ?: 'img/client1.webp'); ?>"
                 class="rounded-circle"
                 style="width:120px;height:120px;object-fit:cover;">
          </div>
          <h3 class="text-center mb-0">
            <?= htmlspecialchars($profile['first_name'].' '.$profile['last_name']); ?>
          </h3>
          <p class="text-center text-muted mb-2"><?= htmlspecialchars($profile['email']); ?></p>

          <div class="mb-3">
            <strong>Bio:</strong>
            <p class="mb-0"><?= nl2br(htmlspecialchars($profile['bio'] ?? '')); ?></p>
          </div>

          <div class="mb-3">
            <strong>Skills:</strong><br>
            <?php
              $skills = array_filter(array_map('trim', explode(',', (string)$profile['skills'])));
              if ($skills) {
                  echo '<ul class="list-unstyled mb-0">';
                  foreach ($skills as $sk) {
                      echo '<li>• '.htmlspecialchars($sk).'</li>';
                  }
                  echo '</ul>';
              } else {
                  echo '<span class="text-muted">None listed.</span>';
              }
            ?>
          </div>

          <div class="mb-3">
            <strong>Hourly Rate:</strong><br>
            <span class="badge bg-dark"><?= $profile['hourly_rate'] ? '₱'.number_format($profile['hourly_rate'],2) : 'N/A'; ?></span>
          </div>

          <div class="d-grid gap-2">
            <a href="inbox.php" class="btn btn-hive d-flex justify-content-between align-items-center">
              <span><i class="bi bi-envelope"></i> Inbox</span>
              <?php if ($unreadTotal > 0): ?>
                <span class="inbox-badge"><?= $unreadTotal; ?></span>
              <?php endif; ?>
            </a>
            <a href="manage_payment_methods.php" class="btn btn-outline-primary">Payment Methods</a>
            <a href="logout.php" class="btn btn-outline-secondary">Logout</a>
          </div>
        </div>

        <!-- Right Column -->
        <div class="flex-grow-1">

          <!-- Pending Bookings -->
            <div class="card mb-4 p-3">
              <h5 class="mb-3">Pending Bookings</h5>
              <?php if ($pendingBookings): ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead class="table-light">
                      <tr>
                        <th>ID</th>
                        <th>Service</th>
                        <th>Client</th>
                        <th>Qty</th>
                        <th>Total (₱)</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingBookings as $pb): ?>
                      <tr>
                        <td><?= (int)$pb['booking_id']; ?></td>
                        <td class="wrap-word"><?= htmlspecialchars($pb['service_title']); ?></td>
                        <td><?= htmlspecialchars($pb['client_name']); ?></td>
                        <td><?= (int)$pb['quantity']; ?></td>
                        <td><?= number_format($pb['total_amount'],2); ?></td>
                        <td>
                          <form method="POST" action="booking_update.php" class="d-inline">
                            <input type="hidden" name="booking_id" value="<?= (int)$pb['booking_id']; ?>">
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="return" value="freelancer_profile.php">
                            <button class="btn btn-success btn-sm">Accept</button>
                          </form>
                          <form method="POST" action="booking_update.php" class="d-inline ms-1">
                            <input type="hidden" name="booking_id" value="<?= (int)$pb['booking_id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="return" value="freelancer_profile.php">
                            <button class="btn btn-danger btn-sm">Reject</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p class="text-muted mb-0">No pending bookings.</p>
              <?php endif; ?>
            </div>

          <!-- Active Bookings -->
          <div class="card mb-4 p-3">
            <h5 class="mb-3">Active Bookings</h5>
            <?php if ($activeBookings): ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>ID</th>
                      <th>Status</th>
                      <th>Qty</th>
                      <th>Total (₱)</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($activeBookings as $ab): ?>
                    <tr>
                      <td><?= (int)$ab['booking_id']; ?></td>
                      <td><span class="badge bg-secondary mini-badge"><?= htmlspecialchars($ab['status']); ?></span></td>
                      <td><?= (int)$ab['quantity']; ?></td>
                      <td><?= number_format($ab['total_amount'],2); ?></td>
                      <td>
                        <?php if ($ab['status']==='accepted'): ?>
                          <form method="POST" action="booking_update.php" class="d-inline">
                            <input type="hidden" name="booking_id" value="<?= (int)$ab['booking_id']; ?>">
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="return" value="freelancer_profile.php">
                            <button class="btn btn-warning btn-sm">Start</button>
                          </form>
                        <?php elseif ($ab['status']==='in_progress'): ?>
                          <form method="POST" action="booking_update.php" class="d-inline">
                            <input type="hidden" name="booking_id" value="<?= (int)$ab['booking_id']; ?>">
                            <input type="hidden" name="action" value="deliver">
                            <input type="hidden" name="return" value="freelancer_profile.php">
                            <button class="btn btn-info btn-sm">Deliver</button>
                          </form>
                        <?php elseif ($ab['status']==='delivered'): ?>
                          <form method="POST" action="booking_update.php" class="d-inline">
                            <input type="hidden" name="booking_id" value="<?= (int)$ab['booking_id']; ?>">
                            <input type="hidden" name="action" value="complete">
                            <input type="hidden" name="return" value="freelancer_profile.php">
                            <button class="btn btn-success btn-sm">Complete</button>
                          </form>
                        <?php else: ?>
                          <span class="text-muted small">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="text-muted mb-0">No active bookings.</p>
            <?php endif; ?>
          </div>

         <!-- Posted Services -->
          <div class="card mb-4 p-3">
            <h5 class="mb-3">Posted Services</h5>
            <?php if ($services): ?>
              <div class="row g-3">
                <?php foreach ($services as $s): ?>
                  <div class="col-md-6">
                    <div class="border rounded p-2 h-100">
                      <div class="d-flex justify-content-between align-items-start">
                        <h6 class="mb-1 me-2 text-truncate" title="<?= htmlspecialchars($s['title']); ?>">
                          <?= htmlspecialchars($s['title']); ?>
                        </h6>
                        <span class="badge <?= $s['status']==='active'?'bg-success':($s['status']==='paused'?'bg-secondary':($s['status']==='draft'?'bg-warning text-dark':'bg-dark')); ?>">
                          <?= htmlspecialchars(ucfirst($s['status'])); ?>
                        </span>
                      </div>
                      <div class="small text-muted mb-2">
                        <?= htmlspecialchars(mb_substr($s['description'],0,90)); ?><?= (strlen($s['description'])>90?'...':''); ?>
                      </div>
                      <div class="d-flex justify-content-between align-items-center">
                        <div>
                          <strong>₱<?= number_format($s['base_price'],2); ?></strong>
                          (<?= htmlspecialchars($s['price_unit']); ?>)
                        </div>
                        <div class="d-flex gap-1">
                          <?php if ($s['status'] === 'draft'): ?>
                            <span class="text-muted small">Pending admin approval</span>
                            <!-- Allow archive or delete while waiting -->
                            <form method="POST" action="manage_service.php" class="d-inline"
                                  onsubmit="return confirm('Archive this draft service?');">
                              <input type="hidden" name="task" value="set_status">
                              <input type="hidden" name="service_id" value="<?= (int)$s['service_id']; ?>">
                              <input type="hidden" name="status" value="archived">
                              <button class="btn btn-sm btn-outline-warning" title="Archive">Archive</button>
                            </form>
                            <form method="POST" action="manage_service.php" class="d-inline"
                                  onsubmit="return confirm('Permanently delete this draft service?');">
                              <input type="hidden" name="task" value="delete">
                              <input type="hidden" name="service_id" value="<?= (int)$s['service_id']; ?>">
                              <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                          <?php else: ?>
                            <!-- Hide/Show toggle available after approval -->
                            <form method="POST" action="manage_service.php" class="d-inline">
                              <input type="hidden" name="task" value="set_status">
                              <input type="hidden" name="service_id" value="<?= (int)$s['service_id']; ?>">
                              <?php if ($s['status']==='active'): ?>
                                <input type="hidden" name="status" value="paused">
                                <button class="btn btn-sm btn-outline-secondary" title="Hide (pause)">Hide</button>
                              <?php else: ?>
                                <input type="hidden" name="status" value="active">
                                <button class="btn btn-sm btn-outline-success" title="Show (activate)">Show</button>
                              <?php endif; ?>
                            </form>
                            <?php if ($s['status']!=='archived'): ?>
                            <form method="POST" action="manage_service.php" class="d-inline"
                                  onsubmit="return confirm('Archive this service? It will be hidden from everyone.');">
                              <input type="hidden" name="task" value="set_status">
                              <input type="hidden" name="service_id" value="<?= (int)$s['service_id']; ?>">
                              <input type="hidden" name="status" value="archived">
                              <button class="btn btn-sm btn-outline-warning" title="Archive">Archive</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" action="manage_service.php" class="d-inline"
                                  onsubmit="return confirm('Permanently delete this service? This cannot be undone. If it has bookings, deletion will be blocked.');">
                              <input type="hidden" name="task" value="delete">
                              <input type="hidden" name="service_id" value="<?= (int)$s['service_id']; ?>">
                              <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="text-muted mb-0">No services posted yet.</p>
            <?php endif; ?>
          </div>

          <!-- Create New Service -->
          <div class="card mb-4 p-3">
            <h5 class="mb-3">Create New Service</h5>
            <form method="POST" action="post_service.php">
              <div class="mb-2">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required>
              </div>
              <div class="mb-2">
                <label class="form-label">Description</label>
                <textarea name="description" rows="2" class="form-control" required></textarea>
              </div>
              <div class="row">
                <div class="col-md-4 mb-2">
                  <label class="form-label">Base Price (PHP)</label>
                  <input type="number" step="0.01" min="0" name="base_price" class="form-control" required>
                </div>
                <div class="col-md-4 mb-2">
                  <label class="form-label">Price Unit</label>
                  <select name="price_unit" class="form-select">
                    <option value="fixed">Fixed</option>
                    <option value="hourly">Hourly</option>
                    <option value="per_unit">Per Unit</option>
                  </select>
                </div>
                <div class="col-md-4 mb-2">
                  <label class="form-label">Min Units</label>
                  <input type="number" name="min_units" value="1" min="1" class="form-control">
                </div>
              </div>
              <div class="alert alert-light border small mb-2">
                New services are submitted to admins for approval before they become visible.
              </div>
              <button class="btn btn-hive mt-2">Post Service</button>
            </form>
          </div>

          <!-- Recent Reviews -->
          <div class="card p-3">
            <h5 class="mb-3">Recent Reviews</h5>
            <?php if ($reviews): ?>
              <?php foreach ($reviews as $rv): ?>
                <div class="border-bottom mb-2 pb-2">
                  <strong><?= htmlspecialchars($rv['first_name'].' '.$rv['last_name']); ?></strong>
                  <span class="badge bg-success"><?= (int)$rv['rating']; ?>/5</span>
                  <div class="small text-muted"><?= htmlspecialchars($rv['created_at']); ?></div>
                  <div><?= nl2br(htmlspecialchars($rv['comment'] ?? '')); ?></div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="text-muted mb-0">No reviews yet.</p>
            <?php endif; ?>
          </div>

        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">Profile not found.</div>
    <?php endif; ?>
  </div>
</section>
<?= flash_render(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>