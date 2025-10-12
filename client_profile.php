<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    flash_set('error','You must be logged in as a client.');
    header("Location: login.php"); exit;
}

$db         = new database();
$client_id  = (int)$_SESSION['user_id'];
$profile    = $db->getClientProfile($client_id);
$bookings   = $db->listClientBookings($client_id, 200, 0);
$writtenReviews = $db->listClientWrittenReviews($client_id, 30, 0);

/* Optional unread badge */
$unreadTotal = method_exists($db,'countUnreadMessages') ? $db->countUnreadMessages($client_id) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Client Profile - TaskHive</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="loginregister.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .mini-badge { font-size:0.7rem; }
    .inbox-badge {
      background:#dc3545;
      font-size:.65rem;
      padding:3px 7px;
      border-radius:12px;
      font-weight:600;
    }
    .table td, .table th { vertical-align: middle; }
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
        Logged in as Client
      </div>
    </div>

    <?php if ($profile): ?>
      <div class="d-flex flex-column flex-lg-row gap-4">

        <!-- Left Panel -->
        <div class="card p-4 shadow-sm flex-shrink-0" style="min-width:310px;">
          <div class="text-center mb-3">
            <img src="<?= htmlspecialchars($profile['profile_picture'] ?: 'img/client1.webp'); ?>"
                 class="rounded-circle"
                 style="width:130px;height:130px;object-fit:cover;">
          </div>
          <h3 class="text-center mb-0"><?= htmlspecialchars($profile['first_name'].' '.$profile['last_name']); ?></h3>
            <p class="text-center text-muted mb-1"><?= htmlspecialchars($profile['email']); ?></p>
          <?php if ($profile['phone']): ?>
            <p class="text-center small text-secondary mb-2"><?= htmlspecialchars($profile['phone']); ?></p>
          <?php endif; ?>

          <div class="mb-3">
            <strong>Bio:</strong>
            <p class="mb-0">
              <?= $profile['bio']
                    ? nl2br(htmlspecialchars($profile['bio']))
                    : '<span class="text-muted">No bio yet.</span>'; ?>
            </p>
          </div>

          <div class="d-flex justify-content-between mb-2">
            <span class="badge text-bg-dark w-100 me-1">Bookings: <?= (int)$profile['total_bookings']; ?></span>
            <span class="badge text-bg-warning text-dark w-100 mx-1">Active: <?= (int)$profile['active_bookings']; ?></span>
            <span class="badge text-bg-info text-dark w-100 ms-1">Reviews: <?= (int)$profile['reviews_written']; ?></span>
          </div>

          <div class="d-grid gap-2 mt-3">
            <a href="inbox.php" class="btn btn-hive d-flex justify-content-between align-items-center">
              <span><i class="bi bi-envelope"></i> Inbox</span>
              <?php if ($unreadTotal > 0): ?>
                <span class="inbox-badge"><?= $unreadTotal; ?></span>
              <?php endif; ?>
            </a>
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editClientModal">Edit Profile</button>
            <a href="logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
          </div>
        </div>

        <!-- Right Panel -->
        <div class="flex-grow-1">

          <!-- Bookings -->
          <div class="card mb-4 p-3">
            <h5 class="mb-3">Your Bookings</h5>
            <?php if ($bookings): ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>#</th>
                      <th>Service</th>
                      <th>Freelancer</th>
                      <th>Status / Actions</th>
                      <th>Total (₱)</th>
                      <th>Created</th>
                      <th>Review</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($bookings as $b):
                      $canReview = in_array($b['status'],['delivered','completed'],true)
                                   && !$db->bookingHasReviewFrom($b['booking_id'],$client_id);
                  ?>
                    <tr>
                      <td><?= (int)$b['booking_id']; ?></td>
                      <td><?= htmlspecialchars($b['service_title']); ?></td>
                      <td><?= htmlspecialchars($b['freelancer_name']); ?></td>
                      <td>
                        <span class="badge bg-secondary mini-badge"><?= htmlspecialchars($b['status']); ?></span>
                        <?php if ($b['status']==='pending'): ?>
                          <form method="POST" action="booking_update.php" class="d-inline">
                            <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id']; ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="return" value="client_profile.php">
                            <button class="btn btn-outline-danger btn-sm ms-1">Cancel</button>
                          </form>
                        <?php elseif ($b['status']==='delivered'): ?>
                          <form method="POST" action="booking_update.php" class="d-inline">
                            <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id']; ?>">
                            <input type="hidden" name="action" value="approve_delivery">
                            <input type="hidden" name="return" value="client_profile.php">
                            <button class="btn btn-success btn-sm ms-1">Approve</button>
                          </form>
                        <?php endif; ?>
                      </td>
                      <td><?= number_format($b['total_amount'],2); ?></td>
                      <td class="small"><?= htmlspecialchars($b['created_at']); ?></td>
                      <td>
                        <?php if ($canReview): ?>
                          <button
                            class="btn btn-sm btn-hive"
                            data-bs-toggle="modal"
                            data-bs-target="#reviewModal"
                            data-booking="<?= (int)$b['booking_id']; ?>"
                            data-fname="<?= htmlspecialchars($b['freelancer_name']); ?>"
                          >Review</button>
                        <?php else: ?>
                          <?php if (in_array($b['status'],['delivered','completed'],true)): ?>
                            <span class="text-muted small">Done</span>
                          <?php else: ?>
                            <span class="text-muted small">N/A</span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="text-muted mb-0">No bookings yet.</p>
            <?php endif; ?>
          </div>

          <!-- Reviews Written -->
          <div class="card p-3">
            <h5 class="mb-3">Reviews You Wrote</h5>
            <?php if ($writtenReviews): ?>
              <?php foreach ($writtenReviews as $rv): ?>
                <div class="border-bottom pb-2 mb-2">
                  <strong><?= htmlspecialchars($rv['reviewee_name']); ?></strong>
                  <span class="badge bg-success"><?= (int)$rv['rating']; ?>/5</span>
                  <div class="small text-muted"><?= htmlspecialchars($rv['created_at']); ?></div>
                  <div><?= nl2br(htmlspecialchars($rv['comment'] ?? '')); ?></div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="text-muted mb-0">You haven’t written any reviews yet.</p>
            <?php endif; ?>
          </div>

        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-warning text-center">Profile not found or you are not a client.</div>
    <?php endif; ?>
  </div>
</section>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="edit_client_profile.php" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title">Edit Profile</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <?php if ($profile): ?>
          <div class="mb-3 text-center">
            <img src="<?= htmlspecialchars($profile['profile_picture'] ?: 'img/client1.webp'); ?>"
                 class="rounded-circle mb-2"
                 style="width:90px;height:90px;object-fit:cover;">
            <input type="file" class="form-control" name="profile_picture" accept="image/*">
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($profile['phone'] ?? ''); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Bio</label>
            <textarea name="bio" rows="3" class="form-control"><?= htmlspecialchars($profile['bio'] ?? ''); ?></textarea>
          </div>
        <?php else: ?>
          <p class="text-muted">Profile data unavailable.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-hive" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="leave_review_client.php">
      <div class="modal-header">
        <h5 class="modal-title">Leave a Review</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="booking_id" id="reviewBookingId">
        <div class="mb-3">
          <label class="form-label">Freelancer</label>
          <input type="text" id="reviewFreelancerName" class="form-control" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">Rating (1-5)</label>
          <input type="number" name="rating" min="1" max="5" value="5" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Comment (optional)</label>
          <textarea name="comment" rows="3" class="form-control"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-hive" type="submit">Submit Review</button>
      </div>
    </form>
  </div>
</div>

<?= flash_render(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const reviewModal = document.getElementById('reviewModal');
if (reviewModal) {
  reviewModal.addEventListener('show.bs.modal', event => {
     const button = event.relatedTarget;
     if (!button) return;
     document.getElementById('reviewBookingId').value = button.getAttribute('data-booking');
     document.getElementById('reviewFreelancerName').value = button.getAttribute('data-fname');
  });
}
</script>
</body>
</html>