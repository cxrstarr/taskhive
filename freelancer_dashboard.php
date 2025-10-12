<?php
// Session and dependencies
session_start();
require_once 'database.php';
require_once 'flash.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    flash_set('error','Please sign in to view your dashboard.');
    header('Location: login.php');
    exit;
}

$db = new database();
$uid = (int)$_SESSION['user_id'];

// Create new service handling (freelancer side)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['new_service'])) {
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $price = trim($_POST['base_price'] ?? '');
    $unit  = strtolower(trim($_POST['price_unit'] ?? 'fixed'));
    $minU  = (int)($_POST['min_units'] ?? 1);

    if ($title === '' || $price === '' || !is_numeric($price)) {
        flash_set('error','Please provide a title and a valid base price.');
        header('Location: freelancer_dashboard.php');
        exit;
    }

    $unit = in_array($unit, ['fixed','hourly'], true) ? $unit : 'fixed';
    $minU = max(1, (int)$minU);
    $priceVal = (float)$price;

    try {
        // Use project helper to ensure slug generation and schema alignment
        $sid = $db->createService($uid, null, $title, $desc, $priceVal, $unit, $minU, 0);
        if (!$sid) throw new Exception('Create service failed');
        // Notify the freelancer that the service is pending admin approval
        if (method_exists($db,'addNotification')) {
            $db->addNotification($uid, 'service_submitted', [
                'service_id' => $sid,
                'title'      => $title,
                'status'     => 'draft',
                'message'    => 'Your service was submitted and is awaiting admin approval.'
            ]);
        }
        flash_set('success','Service submitted for approval. It will be visible once approved.');
    } catch (Throwable $e) {
        flash_set('error','Could not create service. Please try again.');
    }
    header('Location: freelancer_dashboard.php');
    exit;
}

// Inline profile update handling (freelancer side)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['profile_update'])) {
    $first  = trim($_POST['first_name'] ?? '');
    $last   = trim($_POST['last_name'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $rate   = trim($_POST['hourly_rate'] ?? '');
    $bio    = trim($_POST['bio'] ?? '');

    $fields = [];
    if ($first !== '') $fields['first_name'] = $first;
    if ($last !== '')  $fields['last_name']  = $last;
    if ($bio !== '')   $fields['bio']        = $bio;

    // Optional profile picture upload (<= 2MB, JPG/PNG/WEBP)
    if (!empty($_FILES['profile_picture']) && is_array($_FILES['profile_picture']) && ($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['profile_picture']['tmp_name'];
        $size = (int)($_FILES['profile_picture']['size'] ?? 0);
        if ($size > 0 && $size <= 2 * 1024 * 1024) {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $mime  = $finfo ? finfo_file($finfo, $tmp) : null;
            if ($finfo) finfo_close($finfo);
            $allowed = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' ];
            if ($mime && isset($allowed[$mime])) {
                $ext = $allowed[$mime];
                $name = 'profile_'.uniqid('', true).'.'.$ext;
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

    $ok1 = true;
    if (!empty($fields)) {
        $ok1 = $db->updateUserProfile($uid, $fields) ? true : false;
    }

    // Update freelancer-specific fields
    $ok2 = true;
    try {
        $pdo = $db->opencon();
        $stU = $pdo->prepare("UPDATE freelancer_profiles SET skills=:skills, hourly_rate=:rate WHERE user_id=:uid");
        $rateVal = is_numeric($rate) ? (float)$rate : null;
        $stU->execute([
            ':skills' => $skills,
            ':rate'   => $rateVal,
            ':uid'    => $uid,
        ]);
    } catch (Throwable $e) {
        $ok2 = false;
    }

    if ($ok1 && $ok2) {
        flash_set('success','Profile updated successfully.');
    } else {
        flash_set('error','Could not update profile.');
    }
    header('Location: freelancer_dashboard.php');
    exit;
}

// Load freelancer profile; ensure this user is a freelancer
$fp = $db->getFreelancerProfile($uid);
if (!$fp) {
    flash_set('error','Freelancer dashboard is only available to freelancer accounts.');
    header('Location: feed.php');
    exit;
}

// Build user view-model for the dashboard
$displayName = trim(($fp['first_name'] ?? '') . ' ' . ($fp['last_name'] ?? '')) ?: 'Freelancer';
$avatar = $fp['profile_picture'] ?? '';
if (!$avatar) {
    // Fallback avatar (dicebear by name)
    $seed = urlencode($displayName ?: ('user'.$uid));
    $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed={$seed}";
}
$user = [
    'id' => $fp['user_id'],
    'name' => $displayName,
    'email' => $fp['email'] ?? '',
    'bio' => $fp['bio'] ?? 'No bio yet.',
    'skills' => ($fp['skills'] ?? '') !== '' ? $fp['skills'] : 'None listed',
    'hourly_rate' => (float)($fp['hourly_rate'] ?? 0),
    'profile_picture' => $avatar,
];

// Stats: pending bookings, active bookings, posted services
$pdo = $db->opencon();
// Pending
$st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE freelancer_id=:u AND status='pending'");
$st->execute([':u'=>$uid]);
$pendingBookings = (int)$st->fetchColumn();
// Active (accepted / in_progress / delivered)
$st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE freelancer_id=:u AND status IN ('accepted','in_progress','delivered')");
$st->execute([':u'=>$uid]);
$activeBookings = (int)$st->fetchColumn();
// Posted services (count all statuses; visibility on feed is handled by status='active')
$st = $pdo->prepare("SELECT COUNT(*) FROM services WHERE freelancer_id=:u");
$st->execute([':u'=>$uid]);
$postedServices = (int)$st->fetchColumn();
$stats = [
    'pending_bookings' => $pendingBookings,
    'active_bookings' => $activeBookings,
    'posted_services' => $postedServices,
];

// Pagination params (services and reviews)
$sp = isset($_GET['sp']) ? max(1, (int)$_GET['sp']) : 1; // services page
$rp = isset($_GET['rp']) ? max(1, (int)$_GET['rp']) : 1; // reviews page
$servicesPerPage = 5;
$reviewsPerPage = 5;

// Services list for this freelancer (paginated)
// Count total services
$st = $pdo->prepare("SELECT COUNT(*) FROM services WHERE freelancer_id=:u");
$st->execute([':u' => $uid]);
$servicesTotal = (int)$st->fetchColumn();
$servicesPages = max(1, (int)ceil($servicesTotal / $servicesPerPage));
if ($sp > $servicesPages) { $sp = $servicesPages; }
$servicesOffset = ($sp - 1) * $servicesPerPage;

// Fetch current page of services
try {
    $st = $pdo->prepare("SELECT service_id, title, description, base_price, price_unit, status FROM services WHERE freelancer_id=:u ORDER BY service_id DESC LIMIT :off, :lim");
    $st->bindValue(':u', $uid, PDO::PARAM_INT);
    $st->bindValue(':off', (int)$servicesOffset, PDO::PARAM_INT);
    $st->bindValue(':lim', (int)$servicesPerPage, PDO::PARAM_INT);
    $st->execute();
    $servicesRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Fallback without ORDER if needed
    $st = $pdo->prepare("SELECT service_id, title, description, base_price, price_unit, status FROM services WHERE freelancer_id=:u LIMIT :off, :lim");
    $st->bindValue(':u', $uid, PDO::PARAM_INT);
    $st->bindValue(':off', (int)$servicesOffset, PDO::PARAM_INT);
    $st->bindValue(':lim', (int)$servicesPerPage, PDO::PARAM_INT);
    $st->execute();
    $servicesRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$services = array_map(function($r){
    $priceUnit = strtolower($r['price_unit'] ?? 'fixed');
    $priceType = $priceUnit === 'hourly' ? 'Hourly' : 'Fixed';
    $status = strtolower($r['status'] ?? 'active');
    $statusLabel = match($status){
        'draft'   => 'Awaiting Approval',
        default   => ucfirst($status),
    };
    return [
        'id' => (int)$r['service_id'],
        'title' => $r['title'] ?? 'Untitled Service',
        'description' => $r['description'] ?? '',
        'price' => (float)($r['base_price'] ?? 0),
        'price_type' => $priceType,
        'status' => $statusLabel,
        'status_code' => $status,
    ];
}, $servicesRows);

// Recent reviews for this freelancer (paginated)
// Count total reviews
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE freelancer_id=:u");
    $st->execute([':u' => $uid]);
    $reviewsTotal = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $reviewsTotal = 0;
}
$reviewsPages = max(1, (int)ceil(($reviewsTotal ?: 0) / $reviewsPerPage));
if ($rp > $reviewsPages) { $rp = $reviewsPages; }
$reviewsOffset = ($rp - 1) * $reviewsPerPage;

// Fetch current page of reviews with client name and optional service name
try {
    $sql = "SELECT r.*, uc.first_name, uc.last_name, s.title AS service_name
            FROM reviews r
            LEFT JOIN users uc ON r.client_id = uc.user_id
            LEFT JOIN services s ON r.service_id = s.service_id
            WHERE r.freelancer_id = :u
            ORDER BY r.created_at DESC
            LIMIT :off, :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':u', $uid, PDO::PARAM_INT);
    $st->bindValue(':off', (int)$reviewsOffset, PDO::PARAM_INT);
    $st->bindValue(':lim', (int)$reviewsPerPage, PDO::PARAM_INT);
    $st->execute();
    $reviewRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Fallback to existing helper (may return limited set)
    $reviewRows = $db->getUserReviews($uid, $reviewsPerPage) ?: [];
    // If we cannot determine total from DB, set pages to 1 when using fallback
    if (!isset($reviewsTotal) || $reviewsTotal === 0) {
        $reviewsTotal = count($reviewRows);
        $reviewsPages = 1;
        $rp = 1;
    }
}

$reviews = [];
foreach ($reviewRows as $r) {
    $clientName = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) ?: 'Client';
    $clientAvatar = 'https://api.dicebear.com/7.x/avataaars/svg?seed='.urlencode($clientName);
    $date = '';
    if (!empty($r['created_at'])) {
        $ts = strtotime($r['created_at']);
        if ($ts) $date = date('M j, Y', $ts);
    }
    $reviews[] = [
        'client_name' => $clientName,
        'client_avatar' => $clientAvatar,
        'rating' => (int)($r['rating'] ?? 5),
        'comment' => $r['comment'] ?? ($r['body'] ?? ''),
        'date' => $date,
        'service_name' => $r['service_name'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BeeHive Dashboard - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Pagination styles aligned with client dashboard */
        .pagination {
            display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 12px;
        }
        .pagination .page-btn, .pagination .page-num {
            background: #fff7d6; color: #92400E; border: 1px solid #FCD34D; padding: 6px 12px; border-radius: 9999px; text-decoration: none; font-size: 0.875rem; display: inline-flex; align-items: center; gap: 6px; transition: all .2s ease;
        }
        .pagination .page-btn:hover, .pagination .page-num:hover { background: #FCD34D; transform: translateY(-1px); }
        .pagination .page-num.active { background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%); color: #111; border-color: transparent; font-weight: 700; }
        .pagination .disabled { opacity: .5; pointer-events: none; }

        /* Inline profile edit styles (mirror client dashboard) */
        .edit-only { display: none; }
        .profile-card.editing .edit-only { display: block; }
        .profile-card.editing .view-only { display: none; }
        .profile-edit-input, .profile-edit-textarea {
            width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; outline: none;
        }
        .profile-edit-input:focus, .profile-edit-textarea:focus { border-color: #f5b301; box-shadow: 0 0 0 3px rgba(245,179,1,0.15); }
        .name-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .profile-card .edit-actions { display: none; }
    .profile-card.editing .edit-actions { display: flex; gap: 8px; }
        .profile-card.editing .profile-edit-input, .profile-card.editing .profile-edit-textarea { pointer-events: auto; opacity: 1; }
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
                    <h1>BeeHive Dashboard</h1>
                </div>
                <button class="btn-outline" onclick="window.location.href='feed.php'">
                    Back to Feed
                </button>
            </div>
        </header>

        <div class="dashboard-grid">
            <!-- Sidebar - Profile -->
            <aside class="sidebar">
                <form class="profile-card" id="profileCard" method="POST" action="freelancer_dashboard.php" enctype="multipart/form-data">
                    <input type="hidden" name="profile_update" value="1">
                    <div class="profile-header">
                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                             alt="<?php echo htmlspecialchars($user['name']); ?>" 
                             class="profile-avatar" data-orig-src="<?php echo htmlspecialchars($user['profile_picture']); ?>">
                        
                        <h2 class="view-only"><?php echo htmlspecialchars($user['name']); ?></h2>
                        <div class="edit-only name-row">
                            <input type="text" name="first_name" class="profile-edit-input" placeholder="First name" value="<?php echo htmlspecialchars($fp['first_name'] ?? ''); ?>" disabled>
                            <input type="text" name="last_name" class="profile-edit-input" placeholder="Last name" value="<?php echo htmlspecialchars($fp['last_name'] ?? ''); ?>" disabled>
                        </div>
                        
                        <div class="profile-email">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                        <div class="edit-only" style="margin-top:8px;">
                            <label style="font-size:12px; color:#666; display:block; margin-bottom:6px;">Change profile picture</label>
                            <input type="file" name="profile_picture" accept="image/*" class="profile-edit-input" disabled onchange="previewProfileImage(event)">
                            <small style="color:#888;">Accepted: JPG, PNG, WEBP. Max 2MB.</small>
                        </div>
                    </div>

                    <div class="profile-section">
                        <p class="section-label">Bio:</p>
                        <p class="section-text view-only"><?php echo htmlspecialchars($user['bio']); ?></p>
                        <textarea class="edit-only profile-edit-textarea" name="bio" rows="4" placeholder="Tell others about you..." disabled><?php echo htmlspecialchars($fp['bio'] ?? ''); ?></textarea>
                    </div>

                    <div class="profile-section">
                        <p class="section-label">Skills:</p>
                        <p class="section-text view-only"><?php echo htmlspecialchars($user['skills']); ?></p>
                        <input class="edit-only profile-edit-input" type="text" name="skills" placeholder="e.g., PHP, Design, Marketing" value="<?php echo htmlspecialchars($fp['skills'] ?? ''); ?>" disabled>
                    </div>

                    <div class="hourly-rate">
                        <span>Hourly Rate:</span>
                        <div class="rate-value view-only">
                            <i class="fas fa-star"></i>
                            <span>₱<?php echo number_format($user['hourly_rate'], 0); ?></span>
                        </div>
                        <input class="edit-only profile-edit-input" type="number" min="0" step="1" name="hourly_rate" placeholder="Hourly rate" value="<?php echo htmlspecialchars((string)($fp['hourly_rate'] ?? '')); ?>" disabled>
                    </div>

                    <div class="profile-actions">
                        <button type="button" class="btn-primary view-only" onclick="toggleEditProfile(true)">
                            <i class="fas fa-edit"></i>
                            Edit Profile
                        </button>
                        <div class="edit-only edit-actions">
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save"></i>
                                Save
                            </button>
                            <button type="button" class="btn-outline-secondary" onclick="toggleEditProfile(false)">
                                Cancel
                            </button>
                        </div>
                        <button type="button" class="btn-outline-secondary" onclick="window.location.href='logout.php'">
                            Logout
                        </button>
                    </div>
                </form>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card stat-amber">
                        <div class="stat-info">
                            <p class="stat-label">Pending Bookings</p>
                            <p class="stat-value"><?php echo $stats['pending_bookings']; ?></p>
                        </div>
                        <i class="fas fa-clock stat-icon"></i>
                    </div>

                    <div class="stat-card stat-green">
                        <div class="stat-info">
                            <p class="stat-label">Active Bookings</p>
                            <p class="stat-value"><?php echo $stats['active_bookings']; ?></p>
                        </div>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>

                    <div class="stat-card stat-blue">
                        <div class="stat-info">
                            <p class="stat-label">Posted Services</p>
                            <p class="stat-value"><?php echo $stats['posted_services']; ?></p>
                        </div>
                        <i class="fas fa-briefcase stat-icon"></i>
                    </div>
                </div>

                <!-- Posted Services -->
                <section class="content-card">
                    <div class="section-header">
                        <h3>Posted Services</h3>
                        <button class="btn-primary" type="button" onclick="openServiceModal()">
                            <i class="fas fa-plus"></i>
                            Create New Service
                        </button>
                    </div>

                    <div class="services-list">
                        <?php foreach ($services as $service): ?>
                        <div class="service-card">
                            <div class="service-header">
                                <div class="service-title-area">
                                    <h4><?php echo htmlspecialchars($service['title']); ?></h4>
                                                                        <?php 
                                                                            $cls = 'badge-success';
                                                                            if (($service['status_code'] ?? '') === 'draft') $cls='badge-warning';
                                                                            elseif (($service['status_code'] ?? '') === 'paused') $cls='badge-info';
                                                                            elseif (($service['status_code'] ?? '') === 'archived') $cls='badge-danger';
                                                                        ?>
                                                                        <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($service['status']); ?></span>
                                </div>
                                <p class="service-description"><?php echo htmlspecialchars($service['description']); ?></p>
                            </div>

                            <div class="service-footer">
                                <p class="service-price">₱<?php echo number_format($service['price'], 2); ?> (<?php echo htmlspecialchars($service['price_type']); ?>)</p>
                                <div class="service-actions">
                                    <button class="btn-sm btn-blue" onclick="editService(<?php echo $service['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </button>
                                    <button class="btn-sm btn-amber" onclick="archiveService(<?php echo $service['id']; ?>)">
                                        <i class="fas fa-archive"></i>
                                        Archive
                                    </button>
                                    <button class="btn-sm btn-red" onclick="deleteService(<?php echo $service['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Services pagination -->
                    <div class="pagination">
                        <?php 
                            $prevSp = max(1, $sp - 1); 
                            $nextSp = min($servicesPages, $sp + 1);
                            $base = strtok($_SERVER['REQUEST_URI'],'?');
                            $qs = $_GET; unset($qs['sp']); $qs['rp'] = $rp; 
                            $slink = function($page) use ($base,$qs) { $qs['sp'] = $page; return htmlspecialchars($base.'?'.http_build_query($qs)); };
                        ?>
                        <a class="page-btn <?php echo $sp <= 1 ? 'disabled' : ''; ?>" href="<?php echo $slink($prevSp); ?>">
                            <i class="fas fa-chevron-left"></i>
                            Prev
                        </a>
                        <span class="page-num active">Page <?php echo $sp; ?> of <?php echo $servicesPages; ?></span>
                        <a class="page-btn <?php echo $sp >= $servicesPages ? 'disabled' : ''; ?>" href="<?php echo $slink($nextSp); ?>">
                            Next
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </section>

                <!-- Bookings Section -->
                <div class="bookings-grid">
                    <!-- Pending Bookings -->
                    <section class="content-card">
                        <h3>Pending Bookings</h3>
                        <div class="empty-state">
                            <i class="fas fa-clock"></i>
                            <p>No pending bookings</p>
                        </div>
                    </section>

                    <!-- Active Bookings -->
                    <section class="content-card">
                        <h3>Active Bookings</h3>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No active bookings</p>
                        </div>
                    </section>
                </div>

                <!-- Recent Reviews -->
                <section class="content-card">
                    <h3>Recent Reviews</h3>
                    <div class="reviews-list">
                        <?php foreach ($reviews as $review): ?>
                        <div class="review-card">
                            <img src="<?php echo htmlspecialchars($review['client_avatar']); ?>" 
                                 alt="<?php echo htmlspecialchars($review['client_name']); ?>" 
                                 class="review-avatar">
                            
                            <div class="review-content">
                                <div class="review-header">
                                    <h4><?php echo htmlspecialchars($review['client_name']); ?></h4>
                                    <span class="review-date"><?php echo htmlspecialchars($review['date']); ?></span>
                                </div>

                                <div class="review-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'star-filled' : 'star-empty'; ?>"></i>
                                    <?php endfor; ?>
                                    <span class="rating-text">(<?php echo $review['rating']; ?>/5)</span>
                                </div>

                                <p class="review-comment"><?php echo htmlspecialchars($review['comment']); ?></p>
                                <?php if (!empty($review['service_name'])): ?>
                                    <p class="review-service">Service: <?php echo htmlspecialchars($review['service_name']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Reviews pagination -->
                    <div class="pagination">
                        <?php 
                            $prevRp = max(1, $rp - 1); 
                            $nextRp = min($reviewsPages, $rp + 1);
                            $base = strtok($_SERVER['REQUEST_URI'],'?');
                            $qs = $_GET; unset($qs['rp']); $qs['sp'] = $sp;
                            $rlink = function($page) use ($base,$qs) { $qs['rp'] = $page; return htmlspecialchars($base.'?'.http_build_query($qs)); };
                        ?>
                        <a class="page-btn <?php echo $rp <= 1 ? 'disabled' : ''; ?>" href="<?php echo $rlink($prevRp); ?>">
                            <i class="fas fa-chevron-left"></i>
                            Prev
                        </a>
                        <span class="page-num active">Page <?php echo $rp; ?> of <?php echo $reviewsPages; ?></span>
                        <a class="page-btn <?php echo $rp >= $reviewsPages ? 'disabled' : ''; ?>" href="<?php echo $rlink($nextRp); ?>">
                            Next
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script src="dashboard.js"></script>
    <script>
    // Service Modal logic
    function openServiceModal(){
        const m = document.getElementById('serviceModal');
        if (!m) return;
        m.classList.add('open');
        // Reset form fields
        const f = document.getElementById('serviceForm');
        if (f) f.reset();
        // Defaults
        const unit = document.getElementById('price_unit');
        if (unit) unit.value = 'fixed';
        const min = document.getElementById('min_units');
        if (min) min.value = '1';
    }
    function closeServiceModal(){
        const m = document.getElementById('serviceModal');
        if (!m) return;
        m.classList.remove('open');
    }
    function validateService(e){
        const title = (document.getElementById('svc_title')||{}).value||'';
        const price = (document.getElementById('base_price')||{}).value||'';
        if (!title.trim()){
            alert('Please enter a title for your service.');
            e.preventDefault(); return false;
        }
        if (!price || isNaN(price) || Number(price) < 0){
            alert('Please enter a valid base price.');
            e.preventDefault(); return false;
        }
        return true;
    }
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
            ['first_name','last_name','skills','hourly_rate','bio','profile_picture'].forEach(n=>{
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
    </script>
    <!-- Create Service Modal -->
    <div id="serviceModal" class="modal">
        <div class="modal-content modal-service">
            <div class="modal-header-service">
                <div class="modal-header-icon">
                    <i class="fas fa-briefcase"></i>
                </div>
                <div>
                    <h3>Create New Service</h3>
                    <p class="modal-subtitle">New services are submitted to admins for approval before they become visible.</p>
                </div>
                <button class="modal-close" type="button" onclick="closeServiceModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="serviceForm" method="POST" action="freelancer_dashboard.php" onsubmit="return validateService(event)">
                <input type="hidden" name="new_service" value="1">
                <div class="form-grid-3">
                    <div class="form-full">
                        <label class="form-label">Title</label>
                        <input id="svc_title" type="text" name="title" class="form-input" placeholder="e.g., Logo Design, Plumbing Fix, Tutoring" required>
                    </div>
                    <div class="form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="4" placeholder="Describe your service, what’s included, and expectations."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">Base Price (PHP)</label>
                            <input id="base_price" type="number" name="base_price" class="form-input" min="0" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="form-col">
                            <label class="form-label">Price Unit</label>
                            <select id="price_unit" name="price_unit" class="form-input">
                                <option value="fixed" selected>Fixed</option>
                                <option value="hourly">Hourly</option>
                            </select>
                        </div>
                        <div class="form-col">
                            <label class="form-label">Min Units</label>
                            <input id="min_units" type="number" name="min_units" class="form-input" min="1" step="1" value="1">
                        </div>
                    </div>
                    <div class="hint-box">
                        New services are submitted to admins for approval before they become visible.
                    </div>
                </div>
                <div class="modal-footer-service">
                    <button type="button" class="btn-cancel" onclick="closeServiceModal()">Cancel</button>
                    <button type="submit" class="btn-submit-review">Post Service</button>
                </div>
            </form>
        </div>
    </div>
    <style>
        /* Modal base (reuse review modal patterns) */
        .modal { display:none; position:fixed; inset:0; background: rgba(0,0,0,.55); z-index:1000; align-items:center; justify-content:center; padding:16px; }
        .modal.open { display:flex; animation: fadeIn .18s ease-out; }
        @keyframes fadeIn { from { opacity:0 } to { opacity:1 } }
        .modal-content.modal-service { width:100%; max-width:720px; background:#fff; border-radius:14px; box-shadow:0 20px 50px rgba(0,0,0,.25); overflow:hidden; transform:translateY(10px); animation: slideUp .22s ease-out; }
        @keyframes slideUp { from { transform: translateY(14px); opacity:.98 } to { transform: translateY(0); opacity:1 } }
        .modal-header-service { display:flex; align-items:center; gap:12px; border-bottom:1px solid #f0f0f0; padding:16px 20px; }
        .modal-header-icon { width:44px; height:44px; background:#fff7d6; color:#f59e0b; display:grid; place-items:center; border-radius:50%; font-size:18px; }
        .modal-subtitle { color:#666; font-size:.9rem; }
        .modal-close { margin-left:auto; background:transparent; border:none; cursor:pointer; color:#666; font-size:18px; }
        .form-grid-3 { padding:18px 20px; display:flex; flex-direction:column; gap:14px; }
        .form-row { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; }
        .form-col { display:flex; flex-direction:column; gap:6px; }
        .form-full { display:flex; flex-direction:column; gap:6px; }
        .form-label { font-weight:600; color:#444; }
        .form-input, .form-textarea, select.form-input { border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; outline:none; background:#fff; }
        .form-textarea { resize:vertical; min-height:110px; }
        .form-input:focus, .form-textarea:focus, select.form-input:focus { border-color:#f5b301; box-shadow:0 0 0 3px rgba(245,179,1,0.15); }
        .hint-box { background:#fef3c7; color:#92400E; border:1px solid #FCD34D; padding:10px 12px; border-radius:10px; font-size:.9rem; }
        .modal-footer-service { display:flex; justify-content:flex-end; gap:10px; padding:0 20px 18px; }
        .btn-cancel { background:#f3f4f6; color:#333; border:1px solid #e5e7eb; padding:8px 14px; border-radius:8px; cursor:pointer; }
        .btn-cancel:hover { background:#e5e7eb; }
        .btn-submit-review { background:#f5b301; color:#111; border:none; padding:9px 16px; border-radius:8px; cursor:pointer; font-weight:600; }
        .btn-submit-review:hover { background:#e0a400; }
        @media (max-width: 640px){ .form-row { grid-template-columns:1fr; } }
    </style>
    <?php if (function_exists('flash_render')) echo flash_render(); ?>
</body>
</html>
