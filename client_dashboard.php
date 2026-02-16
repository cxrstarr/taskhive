<?php
session_start();
require_once __DIR__ . '/includes/csp.php';
require_once 'database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once 'flash.php';

// Require logged-in client
if (empty($_SESSION['user_id']) || (($_SESSION['user_type'] ?? '') !== 'client')) {
    flash_set('error','You must be logged in as a client.');
    header('Location: login.php');
    exit;
}

$db        = new database();
$client_id = (int)$_SESSION['user_id'];
// Pagination params
$bookingsPerPage = 8; // compact list to match card width
$reviewsPerPage  = 6;
$bp = max(1, (int)($_GET['bp'] ?? 1)); // bookings page
$rp = max(1, (int)($_GET['rp'] ?? 1)); // reviews page
$boff = ($bp - 1) * $bookingsPerPage;
$roff = ($rp - 1) * $reviewsPerPage;
// Inline profile update handling
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['profile_update'])) {
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: client_dashboard.php'); exit; }
    $phone = trim($_POST['phone'] ?? '');
    $bio   = trim($_POST['bio'] ?? '');

    $fields = [];
    $fields['first_name'] = $first;
    $fields['last_name']  = $last;
    $fields['phone']      = $phone;
    $fields['bio']        = $bio;

    // Optional profile picture upload
    if (!empty($_FILES['profile_picture']) && is_array($_FILES['profile_picture']) && ($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['profile_picture']['tmp_name'];
        $size = (int)($_FILES['profile_picture']['size'] ?? 0);
        // Validate size (<= 2MB)
        if ($size > 0 && $size <= 2 * 1024 * 1024) {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $mime  = $finfo ? finfo_file($finfo, $tmp) : null;
            if ($finfo) finfo_close($finfo);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];
            if ($mime && isset($allowed[$mime])) {
                $ext = $allowed[$mime];
                $name = 'profile_'.uniqid().'.'.$ext;
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
                $dest = $uploadDir.$name;
                if (@move_uploaded_file($tmp, $dest)) {
                    $webPath = 'uploads/'.$name;
                    $fields['profile_picture'] = $webPath;
                } else {
                    flash_set('error','Failed to upload profile picture.');
                }
            } else {
                flash_set('error','Invalid image type. Please upload JPG, PNG, or WEBP.');
            }
        } else {
            flash_set('error','Image too large. Max size is 2MB.');
        }
    }

    $ok = $db->updateUserProfile($client_id, $fields);
    if ($ok) {
        flash_set('success','Profile updated successfully.');
    } else {
        flash_set('error','Could not update profile.');
    }
    header('Location: client_dashboard.php');
    exit;
}
$cp        = $db->getClientProfile($client_id);
if (!$cp) {
    flash_set('error','Client profile not found.');
    header('Location: feed.php');
    exit;
}

$displayName = trim(($cp['first_name'] ?? '').' '.($cp['last_name'] ?? '')) ?: 'Client';
$avatar = $cp['profile_picture'] ?? '';
if (!$avatar) {
    $seed = urlencode($displayName ?: ('user'.$client_id));
    $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed={$seed}";
}

$client = [
    'id' => $cp['user_id'],
    'name' => $displayName,
    'email' => $cp['email'] ?? '',
    'bio' => $cp['bio'] ?? 'No bio yet.',
    'phone' => $cp['phone'] ?? '',
    'profile_picture' => $avatar,
];

// Stats
$pdo = $db->opencon();
$totalBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE client_id=".(int)$client_id)->fetchColumn();
$activeBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE client_id=".(int)$client_id." AND status IN ('pending','accepted','in_progress','delivered')")->fetchColumn();
$completedBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE client_id=".(int)$client_id." AND status='completed'")->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*)
                     FROM bookings b
                     WHERE b.client_id=? AND b.status='completed'
                       AND NOT EXISTS (
                          SELECT 1 FROM reviews r
                          WHERE r.booking_id=b.booking_id AND r.reviewer_id=?
                       )");
$st->execute([$client_id,$client_id]);
$pendingReviews = (int)$st->fetchColumn();
$stats = [
    'total_bookings' => $totalBookings,
    'active_bookings' => $activeBookings,
    'completed_bookings' => $completedBookings,
    'pending_reviews' => $pendingReviews,
];

// Bookings (paginated)
$rows = $db->listClientBookings($client_id, $bookingsPerPage, $boff);
$bookings = array_map(function($b){
    $freelancerName = $b['freelancer_name'] ?? 'Freelancer';
    $statusMap = [
        'completed' => 'Completed',
        'cancelled' => 'Cancel',
        'pending' => 'Pending',
        'accepted' => 'Pending',
        'in_progress' => 'Pending',
        'delivered' => 'Pending',
        'rejected' => 'Cancel',
    ];
    $raw = strtolower($b['status'] ?? 'pending');
    $status = $statusMap[$raw] ?? 'Pending';
    // Prefer real freelancer picture when available
    $pic = trim($b['freelancer_picture'] ?? '');
    if ($pic) {
        if (preg_match('#^https?://#i', $pic)) {
            $avatar = $pic; // full URL
        } else {
            // Normalize Windows absolute path or other absolute FS paths to web path
            $base = basename(str_replace(['\\','/'], DIRECTORY_SEPARATOR, $pic));
            // If already looks like an uploads path, keep it
            if (preg_match('#^/?uploads/#i', $pic)) {
                $avatar = ltrim($pic, '/');
            } else {
                $avatar = 'uploads/'.$base;
            }
        }
    } else {
        $avatar = 'https://api.dicebear.com/7.x/avataaars/svg?seed='.urlencode($freelancerName);
    }
    return [
        'id' => (int)$b['booking_id'],
        'service_name' => $b['service_title'] ?? 'Service',
        'freelancer_name' => $freelancerName,
        'freelancer_avatar' => $avatar,
        'status' => $status,
        'raw_status' => $raw,
        'total' => (float)($b['total_amount'] ?? 0),
        'created_at' => $b['created_at'] ?? '',
        'has_review' => false,
    ];
}, $rows ?: []);

// Mark bookings that already have a review from this client
if ($bookings) {
    $ids = array_column($bookings,'id');
    $in  = implode(',', array_fill(0,count($ids),'?'));
    $st2 = $pdo->prepare("SELECT booking_id FROM reviews WHERE reviewer_id=? AND booking_id IN ($in)");
    $st2->execute(array_merge([$client_id], $ids));
    $done = $st2->fetchAll(PDO::FETCH_COLUMN,0);
    $doneSet = array_flip(array_map('intval',$done));
    foreach ($bookings as &$bk) { $bk['has_review'] = isset($doneSet[$bk['id']]); }
    unset($bk);
}

// Reviews written by client (paginated)
$written = $db->listClientWrittenReviews($client_id, $reviewsPerPage, $roff);
$stCnt = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE reviewer_id=?");
$stCnt->execute([$client_id]);
$totalWritten = (int)$stCnt->fetchColumn();

// Total pages
$bookingsTotalPages = max(1, (int)ceil($totalBookings / $bookingsPerPage));
$reviewsTotalPages  = max(1, (int)ceil($totalWritten / $reviewsPerPage));
$reviews_written = array_map(function($r){
    $name = $r['reviewee_name'] ?? 'Freelancer';
    $pic  = trim($r['reviewee_picture'] ?? '');
    if ($pic) {
        if (preg_match('#^https?://#i', $pic)) {
            $avatar = $pic;
        } else {
            $base = basename(str_replace(['\\','/'], DIRECTORY_SEPARATOR, $pic));
            if (preg_match('#^/?uploads/#i', $pic)) {
                $avatar = ltrim($pic, '/');
            } else {
                $avatar = 'uploads/'.$base;
            }
        }
    } else {
        $avatar = 'https://api.dicebear.com/7.x/avataaars/svg?seed='.urlencode($name);
    }
    return [
        'id' => (int)$r['review_id'],
        'freelancer_name' => $name,
        'freelancer_avatar' => $avatar,
        'service_name' => $r['service_name'] ?? '',
        'rating' => (int)($r['rating'] ?? 5),
        'comment' => $r['comment'] ?? '',
        'created_at' => $r['created_at'] ?? '',
    ];
}, $written ?: []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>BeeHive Client Dashboard - <?php echo htmlspecialchars($client['name']); ?></title>
        <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
            /* Minimal modal + rating styles to ensure the Review popup works */
            .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1000; align-items: center; justify-content: center; padding: 16px; }
            .modal.open { display: flex; }
            .modal-content { width: 100%; max-width: 620px; background: #fff; border-radius: 14px; box-shadow: 0 20px 50px rgba(0,0,0,.25); overflow: hidden; }
            .modal-review { padding: 20px; }
            .modal-header-review { display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #f0f0f0; padding-bottom: 12px; }
            .modal-header-icon { width: 44px; height: 44px; background: #fff7d6; color: #f59e0b; display: grid; place-items: center; border-radius: 50%; font-size: 18px; }
            .modal-close { margin-left: auto; background: transparent; border: none; cursor: pointer; color: #666; font-size: 18px; }
            .review-modal-info { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 16px; padding: 14px 0; }
            .review-modal-service .info-label { font-weight: 600; color: #555; margin-right: 6px; }
            .review-modal-freelancer { display: flex; align-items: center; gap: 12px; }
            .modal-avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: #fafafa; }
            .info-value, .info-value-block { font-weight: 600; color: #222; }
            .form-group-modal { margin: 14px 0; }
            .star-rating-container { display: flex; align-items: center; gap: 10px; }
            .star-rating .fa-star { font-size: 24px; color: #d7d7d7; cursor: pointer; transition: color .15s ease; }
            .star-rating .fa-star.star-filled { color: #f5b301; }
            .rating-description { color: #666; font-size: 14px; }
            .review-textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; resize: vertical; min-height: 120px; outline: none; }
            .review-textarea:focus { border-color: #f5b301; box-shadow: 0 0 0 3px rgba(245,179,1,0.15); }
            .char-count { display: block; margin-top: 6px; font-size: 12px; color: #888; text-align: right; }
            .modal-footer-review { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
            .btn-cancel { background: #f3f4f6; color: #333; border: 1px solid #e5e7eb; padding: 8px 14px; border-radius: 8px; cursor: pointer; }
            .btn-cancel:hover { background: #e5e7eb; }
            .btn-submit-review { background: #f5b301; color: #111; border: none; padding: 9px 16px; border-radius: 8px; cursor: pointer; font-weight: 600; }
            .btn-submit-review:hover { background: #e0a400; }
            .text-muted { color: #8b8b8b; }
            .btn-sm.btn-warning { background: #f59e0b; color: #111; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; }
            .btn-sm.btn-warning:hover { background: #d98706; }
            /* Inline profile edit toggles */
            .edit-only { display: none; }
            .profile-card.editing .edit-only { display: block; }
            .profile-card.editing .view-only { display: none; }
            .profile-edit-input, .profile-edit-textarea {
                width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; outline: none;
            }
            .profile-edit-input:focus, .profile-edit-textarea:focus { border-color: #f5b301; box-shadow: 0 0 0 3px rgba(245,179,1,0.15); }
            .name-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
            /* Show actions as flex only when editing */
            .profile-card.editing .edit-actions { display: flex; gap: 8px; }
            /* Ensure inputs are interactable in edit mode */
            .profile-card.editing .profile-edit-input,
            .profile-card.editing .profile-edit-textarea { pointer-events: auto; opacity: 1; }

            /* Pagination styles */
            .pagination {
                display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 12px;
            }
            .pagination .page-btn, .pagination .page-num {
                background: #fff7d6; color: #92400E; border: 1px solid #FCD34D; padding: 6px 12px; border-radius: 9999px; text-decoration: none; font-size: 0.875rem; display: inline-flex; align-items: center; gap: 6px; transition: all .2s ease;
            }
            .pagination .page-btn:hover, .pagination .page-num:hover { background: #FCD34D; transform: translateY(-1px); }
            .pagination .page-num.active { background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%); color: #111; border-color: transparent; font-weight: 700; }
            .pagination .disabled { opacity: .5; pointer-events: none; }
        </style>
</head>
<body class="honeycomb-bg">
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="logo">
                        <span class="bee-icon">🐝</span>
                    </div>
                    <h1>BeeHive Client Dashboard</h1>
                </div>
                <a class="btn-outline" href="feed.php">Back to Feed</a>
            </div>
        </header>

        <div class="dashboard-grid">
            <!-- Sidebar - Profile -->
            <aside class="sidebar">
                <form class="profile-card" id="profileCard" method="POST" action="client_dashboard.php" enctype="multipart/form-data">
                    <input type="hidden" name="profile_update" value="1">
                    <div class="profile-header">
                    <img src="<?php echo htmlspecialchars($client['profile_picture']); ?>" 
                        alt="<?php echo htmlspecialchars($client['name']); ?>" 
                        class="profile-avatar" data-orig-src="<?php echo htmlspecialchars($client['profile_picture']); ?>">
                        
                        <h2 class="view-only"><?php echo htmlspecialchars($client['name']); ?></h2>
                        <div class="edit-only name-row">
                            <input type="text" name="first_name" class="profile-edit-input" placeholder="First name" value="<?php echo htmlspecialchars($cp['first_name'] ?? ''); ?>" disabled>
                            <input type="text" name="last_name" class="profile-edit-input" placeholder="Last name" value="<?php echo htmlspecialchars($cp['last_name'] ?? ''); ?>" disabled>
                        </div>
                        <?= csrf_input(); ?>
                        
                        <div class="profile-email">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($client['email']); ?></span>
                        </div>
                        <div class="edit-only" style="margin-top:8px;">
                            <label style="font-size:12px; color:#666; display:block; margin-bottom:6px;">Change profile picture</label>
                            <input type="file" name="profile_picture" accept="image/*" class="profile-edit-input" disabled>
                            <small style="color:#888;">Accepted: JPG, PNG, WEBP. Max 2MB.</small>
                        </div>
                    </div>

                    <div class="profile-section">
                        <p class="section-label">Phone:</p>
                        <p class="section-text view-only"><?php echo htmlspecialchars($client['phone']); ?></p>
                        <input class="edit-only profile-edit-input" type="text" name="phone" placeholder="Phone" value="<?php echo htmlspecialchars($cp['phone'] ?? ''); ?>" disabled>
                    </div>

                    <div class="profile-section">
                        <p class="section-label">Bio:</p>
                        <p class="section-text view-only"><?php echo htmlspecialchars($client['bio']); ?></p>
                        <textarea class="edit-only profile-edit-textarea" name="bio" rows="4" placeholder="Tell others about you..." disabled><?php echo htmlspecialchars($cp['bio'] ?? ''); ?></textarea>
                    </div>

                    <div class="profile-actions">
                        <button type="button" class="btn-primary view-only" id="btn-edit-profile">
                            <i class="fas fa-edit"></i>
                            Edit Profile
                        </button>
                        <div class="edit-only edit-actions">
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save"></i>
                                Save
                            </button>
                            <button type="button" class="btn-outline-secondary" id="btn-cancel-profile">
                                Cancel
                            </button>
                        </div>
                        <a class="btn-outline-secondary" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </form>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Stats -->
                <div class="stats-grid stats-grid-4">
                    <div class="stat-card stat-blue">
                        <div class="stat-info">
                            <p class="stat-label">Total Bookings</p>
                            <p class="stat-value"><?php echo $stats['total_bookings']; ?></p>
                        </div>
                        <i class="fas fa-shopping-cart stat-icon"></i>
                    </div>

                    <div class="stat-card stat-green">
                        <div class="stat-info">
                            <p class="stat-label">Active Bookings</p>
                            <p class="stat-value"><?php echo $stats['active_bookings']; ?></p>
                        </div>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>

                    <div class="stat-card stat-amber">
                        <div class="stat-info">
                            <p class="stat-label">Completed</p>
                            <p class="stat-value"><?php echo $stats['completed_bookings']; ?></p>
                        </div>
                        <i class="fas fa-trophy stat-icon"></i>
                    </div>

                    <div class="stat-card stat-purple">
                        <div class="stat-info">
                            <p class="stat-label">Pending Reviews</p>
                            <p class="stat-value"><?php echo $stats['pending_reviews']; ?></p>
                        </div>
                        <i class="fas fa-star stat-icon"></i>
                    </div>
                </div>

                <!-- Your Bookings -->
                <section class="content-card">
                    <div class="section-header">
                        <h3>Your Bookings</h3>
                        <a class="btn-primary" href="feed.php"><i class="fas fa-plus"></i> Book a Service</a>
                    </div>

                    <div class="bookings-table-wrapper">
                        <table class="bookings-table">
                            <thead>
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
                                <?php $count = 1; foreach ($bookings as $booking): ?>
                                <tr>
                                    <td><?php echo $count++; ?></td>
                                    <td>
                                        <div class="service-cell">
                                            <span class="service-name"><?php echo htmlspecialchars($booking['service_name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="freelancer-cell">
                                    <img src="<?php echo htmlspecialchars($booking['freelancer_avatar']); ?>" 
                                        alt="<?php echo htmlspecialchars($booking['freelancer_name']); ?>" 
                                        class="freelancer-avatar-sm"
                                        data-fallback-src="img/profile_icon.webp">
                                            <span><?php echo htmlspecialchars($booking['freelancer_name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($booking['status'] === 'Completed'): ?>
                                            <span class="badge badge-success">Completed</span>
                                        <?php elseif ($booking['status'] === 'Cancel'): ?>
                                            <span class="badge badge-danger">Cancelled</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="total-cell">₱<?php echo number_format($booking['total'], 2); ?></td>
                                    <td class="date-cell"><?php echo date('Y-m-d', strtotime($booking['created_at'])); ?><br>
                                        <span class="time-text"><?php echo date('H:i:s', strtotime($booking['created_at'])); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($booking['has_review']): ?>
                                            <span class="text-muted">Done</span>
                                        <?php elseif (in_array($booking['raw_status'], ['delivered','completed'])): ?>
                                            <button
                                                class="btn-sm btn-warning"
                                                type="button"
                                                data-open-review="1"
                                                data-booking-id="<?php echo (int)$booking['id']; ?>"
                                                data-service-name="<?php echo htmlspecialchars($booking['service_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-freelancer-name="<?php echo htmlspecialchars($booking['freelancer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-avatar="<?php echo htmlspecialchars($booking['freelancer_avatar'], ENT_QUOTES, 'UTF-8'); ?>"
                                            >
                                                Review
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Bookings Pagination -->
                    <div class="pagination">
                        <?php 
                        $prevBp = max(1, $bp-1); $nextBp = min($bookingsTotalPages, $bp+1);
                        $base = strtok($_SERVER['REQUEST_URI'],'?');
                        $qs = $_GET; unset($qs['bp']); $qs['rp'] = $rp; 
                        $link = function($page) use ($base,$qs) { $qs['bp']=$page; return htmlspecialchars($base.'?'.http_build_query($qs)); };
                        ?>
                        <a class="page-btn <?php echo $bp<=1?'disabled':''; ?>" href="<?php echo $link($prevBp); ?>">
                            <i class="fas fa-arrow-left"></i> Prev
                        </a>
                        <span class="page-num active">Page <?php echo $bp; ?> of <?php echo $bookingsTotalPages; ?></span>
                        <a class="page-btn <?php echo $bp>=$bookingsTotalPages?'disabled':''; ?>" href="<?php echo $link($nextBp); ?>">
                            Next <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </section>

                <!-- Reviews You Wrote -->
                <section class="content-card">
                    <h3>Reviews You Wrote</h3>
                    
                    <?php if (empty($reviews_written)): ?>
                        <div class="empty-state">
                            <i class="fas fa-star"></i>
                            <p>You haven't written any reviews yet</p>
                        </div>
                    <?php else: ?>
                        <div class="reviews-list">
                            <?php foreach ($reviews_written as $review): ?>
                            <div class="review-card">
                          <img src="<?php echo htmlspecialchars($review['freelancer_avatar']); ?>" 
                              alt="<?php echo htmlspecialchars($review['freelancer_name']); ?>" 
                              class="review-avatar"
                              data-fallback-src="img/profile_icon.webp">
                                
                                <div class="review-content">
                                    <div class="review-header">
                                        <div>
                                            <h4><?php echo htmlspecialchars($review['freelancer_name']); ?></h4>
                                            <p class="review-service-name"><?php echo htmlspecialchars($review['service_name']); ?></p>
                                        </div>
                                        <span class="review-date"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                                    </div>

                                    <div class="review-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'star-filled' : 'star-empty'; ?>"></i>
                                        <?php endfor; ?>
                                        <span class="rating-text">(<?php echo $review['rating']; ?>/5)</span>
                                    </div>

                                    <p class="review-comment"><?php echo htmlspecialchars($review['comment']); ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <!-- Reviews Pagination -->
                    <div class="pagination">
                        <?php 
                        $prevRp = max(1, $rp-1); $nextRp = min($reviewsTotalPages, $rp+1);
                        $base = strtok($_SERVER['REQUEST_URI'],'?');
                        $qs = $_GET; unset($qs['rp']); $qs['bp'] = $bp;
                        $rlink = function($page) use ($base,$qs) { $qs['rp']=$page; return htmlspecialchars($base.'?'.http_build_query($qs)); };
                        ?>
                        <a class="page-btn <?php echo $rp<=1?'disabled':''; ?>" href="<?php echo $rlink($prevRp); ?>">
                            <i class="fas fa-arrow-left"></i> Prev
                        </a>
                        <span class="page-num active">Page <?php echo $rp; ?> of <?php echo $reviewsTotalPages; ?></span>
                        <a class="page-btn <?php echo $rp>=$reviewsTotalPages?'disabled':''; ?>" href="<?php echo $rlink($nextRp); ?>">
                            Next <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <!-- Review Modal (iframe mode using leave_review.php) -->
    <div id="reviewIframeModal" class="modal" aria-hidden="true">
        <div class="modal-content" style="max-width:800px; width:100%;">
            <div class="modal-header-review" style="padding: 14px 20px; border-bottom: 1px solid #f0f0f0; display:flex; align-items:center; gap:12px;">
                <div class="modal-header-icon"><i class="fas fa-star"></i></div>
                <div>
                    <h3 style="margin:0;">Write a Review</h3>
                    <p class="modal-subtitle">Share your experience with this service</p>
                </div>
                <button class="modal-close" type="button" id="btn-close-review-modal" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <iframe id="reviewIframe" src="about:blank" style="display:block; width:100%; height:560px; border:0;" title="Leave a review"></iframe>
        </div>
    </div>

    <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
    // Expose a map for quick lookup (kept for potential future use)
    window.BOOKINGS_BY_ID = <?php echo json_encode(array_reduce($bookings, function($acc,$b){
        $acc[$b['id']] = [
            'service_name' => $b['service_name'],
            'freelancer_name' => $b['freelancer_name'],
            'freelancer_avatar' => $b['freelancer_avatar']
        ];
        return $acc;
    }, []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    // Open the review modal as an iframe to leave_review.php (modal mode)
    function openReviewModalFromButton(btn){
        const bid   = btn.getAttribute('data-booking-id') || '';
        const redirect = window.location.href;
        const url = 'leave_review.php?booking_id=' + encodeURIComponent(bid) + '&modal=1&redirect=' + encodeURIComponent(redirect);
        const iframe = document.getElementById('reviewIframe');
        if (iframe) iframe.src = url;
        const modal = document.getElementById('reviewIframeModal');
        if (modal) modal.classList.add('open');
    }
    function closeReviewIframeModal(){
        const modal = document.getElementById('reviewIframeModal');
        if (modal) modal.classList.remove('open');
        const iframe = document.getElementById('reviewIframe');
        if (iframe) iframe.src = 'about:blank';
    }
    // Listen for messages from the iframe (review submitted/cancel)
    window.addEventListener('message', function(ev){
        const d = ev && ev.data ? ev.data : null;
        if (!d || typeof d !== 'object') return;
        if (d.type === 'review_submitted') {
            // Close modal and refresh to reflect state change
            closeReviewIframeModal();
            // Refresh the page to update bookings and stats
            window.location.reload();
        } else if (d.type === 'review_cancel') {
            closeReviewIframeModal();
        }
    });
    function toggleEditProfile(edit){
        const form = document.getElementById('profileCard');
        if (!form) return;
        if (edit) {
            form.classList.add('editing');
            // Enable inputs
            form.querySelectorAll('.edit-only input, .edit-only textarea').forEach(el=>{
                el.disabled = false; el.removeAttribute('disabled');
                el.readOnly = false; el.removeAttribute('readonly');
            });
            ['first_name','last_name','phone','bio','profile_picture'].forEach(n=>{
                const el = form.querySelector(`[name="${n}"]`);
                if (el) {
                    el.disabled = false; el.removeAttribute('disabled');
                    el.readOnly = false; el.removeAttribute('readonly');
                }
            });
            const first = form.querySelector('input[name="first_name"]');
            if (first) first.focus();
        } else {
            // Reset unsaved changes and exit edit mode
            if (typeof form.reset === 'function') form.reset();
            // Disable inputs again
            form.querySelectorAll('.edit-only input, .edit-only textarea').forEach(el=>{
                el.disabled = true; el.setAttribute('disabled','disabled');
                el.readOnly = true; el.setAttribute('readonly','readonly');
            });
            // Clear selected file and restore avatar preview
            const file = form.querySelector('input[name="profile_picture"]');
            if (file) file.value = '';
            const img = form.querySelector('.profile-avatar');
            if (img && img.dataset && img.dataset.origSrc) img.src = img.dataset.origSrc;
            form.classList.remove('editing');
        }
    }

    function previewProfileImage(e){
        const file = e.target && e.target.files ? e.target.files[0] : null;
        if (!file) return;
        const img = document.querySelector('#profileCard .profile-avatar');
        if (!img) return;
        const url = URL.createObjectURL(file);
        img.src = url;
    }

    // Bind UI events without inline handlers (keeps CSP free of unsafe-inline)
    document.addEventListener('DOMContentLoaded', function(){
        document.getElementById('btn-edit-profile')?.addEventListener('click', function(){ toggleEditProfile(true); });
        document.getElementById('btn-cancel-profile')?.addEventListener('click', function(){ toggleEditProfile(false); });
        document.getElementById('btn-close-review-modal')?.addEventListener('click', function(){ closeReviewIframeModal(); });

        document.querySelectorAll('[data-open-review="1"]').forEach((btn) => {
            btn.addEventListener('click', function(){ openReviewModalFromButton(btn); });
        });

        const fileInput = document.querySelector('#profileCard input[name="profile_picture"]');
        fileInput?.addEventListener('change', previewProfileImage);

        document.querySelectorAll('img[data-fallback-src]').forEach((img) => {
            img.addEventListener('error', function(){
                const fb = img.getAttribute('data-fallback-src');
                if (fb && img.getAttribute('src') !== fb) img.setAttribute('src', fb);
            });
        });
    });
    </script>
    <?php if (function_exists('flash_render')) echo flash_render(); ?>
</body>
</html>
