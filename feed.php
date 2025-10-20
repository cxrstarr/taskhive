<?php
session_start();
require_once __DIR__ . '/database.php';

$db = new database();
$currentUser = null;
$unreadCount = 0;
$profileUrl = 'user_profile.php';
// Notifications list for navbar dropdown
$notifications = [];

if (!empty($_SESSION['user_id'])) {
    $u = $db->getUser((int)$_SESSION['user_id']);
    if ($u) {
        $currentUser = [
            'id'     => (int)$u['user_id'],
            'name'   => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? 'User'),
            'email'  => $u['email'] ?? '',
            'avatar' => $u['profile_picture'] ?: 'img/profile_icon.webp',
            'user_type' => $u['user_type'] ?? '',
        ];
        $unreadCount = (int)$db->countUnreadMessages((int)$_SESSION['user_id']);

    // Compute destination for "View Dashboard"
        $freelancerUrl = file_exists(__DIR__ . '/freelancer_dashboard.php') ? 'freelancer_dashboard.php' : 'user_profile.php';
        $clientUrl = file_exists(__DIR__ . '/client_dashboard.php')
            ? 'client_dashboard.php'
            : (file_exists(__DIR__ . '/client.php') ? 'client.php' : 'user_profile.php');
        $profileUrl = ($currentUser['user_type'] === 'freelancer') ? $freelancerUrl : $clientUrl;
        // Load notifications for current user (latest 10)
        try {
            $pdo = $db->opencon();
            $stN = $pdo->prepare("SELECT notification_id,type,data,created_at,read_at FROM notifications WHERE user_id=:u ORDER BY created_at DESC LIMIT 10");
            $stN->execute([':u'=>(int)$_SESSION['user_id']]);
            foreach ($stN->fetchAll() as $n) {
                $type = (string)$n['type'];
                $data = [];
                if (!empty($n['data'])) {
                    $d = json_decode($n['data'], true);
                    if (is_array($d)) $data = $d;
                }
                $msg = ucfirst(str_replace('_',' ', $type)) . ' update';
                if (($type === 'system' || $type === 'admin_message') && isset($data['text'])) {
                    $msg = 'System: ' . (string)$data['text'];
                } elseif ($type === 'booking_status_changed' && isset($data['status'], $data['booking_id'])) {
                    $msg = "Booking #{$data['booking_id']} status: ".ucfirst(str_replace('_',' ',$data['status']));
                } elseif ($type === 'booking_created' && isset($data['booking_id'])) {
                    $msg = "Booking #{$data['booking_id']} was created.";
                } elseif ($type === 'payment_recorded' && isset($data['amount'])) {
                    $msg = 'Payment received: \u20b1'.number_format((float)$data['amount'],2);
                } elseif ($type === 'service_rejected') {
                    $reason = isset($data['reason']) && $data['reason'] !== '' ? (string)$data['reason'] : '';
                    $title  = isset($data['title']) && $data['title'] !== '' ? (string)$data['title'] : '';
                    $msg = 'Service Approval: Rejected' . ($reason !== '' ? ' — ' . $reason : '') . ($title !== '' ? ' (' . $title . ')' : '');
                } elseif ($type === 'service_approved') {
                    $title  = isset($data['title']) && $data['title'] !== '' ? (string)$data['title'] : '';
                    $msg = 'Service Approval: Approved' . ($title !== '' ? ' — ' . $title : '');
                } elseif ($type === 'review_prompt') {
                    $title = isset($data['service_title']) ? (string)$data['service_title'] : 'your recent booking';
                    $url = isset($data['url']) ? (string)$data['url'] : ('leave_review.php?booking_id='.(int)($data['booking_id'] ?? 0));
                    $msg = 'Please leave a review for ' . $title . '.';
                }
                $notifications[] = [
                    'id' => (int)$n['notification_id'],
                    'message' => $msg,
                    'time' => date('M d, Y g:i A', strtotime($n['created_at'] ?? date('Y-m-d H:i:s'))),
                    'unread' => empty($n['read_at']),
                ];
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

// Redirect guests to login for now (adjust if you want a public feed)
if (!$currentUser) {
    header('Location: login.php');
    exit;
}

// If client clicks "Book Now" from the feed modal, create booking immediately and redirect to inbox
if ($currentUser && ($currentUser['user_type'] ?? '') === 'client' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_service_from_feed'])) {
    try {
        $viewerId = (int)($_SESSION['user_id'] ?? 0);
        $svcId = (int)($_POST['service_id'] ?? 0);
        $freelancerId = (int)($_POST['freelancer_id'] ?? 0);
        if ($viewerId <= 0 || $svcId <= 0 || $freelancerId <= 0 || $viewerId === $freelancerId) {
            header('Location: feed.php');
            exit;
        }

        $pdo = $db->opencon();
        // Validate service belongs to freelancer and is active
        $stSvc = $pdo->prepare("SELECT service_id, freelancer_id, title, description, base_price, status FROM services WHERE service_id=:sid AND freelancer_id=:fid AND status='active' LIMIT 1");
        $stSvc->execute([':sid'=>$svcId, ':fid'=>$freelancerId]);
        $svc = $stSvc->fetch();

        if ($svc && (int)$svc['freelancer_id'] === $freelancerId) {
            $unitPrice = (float)($svc['base_price'] ?? 0);
            $qty = 1;
            $totalAmount = $unitPrice * $qty;

            // Insert booking snapshotting current service details
            $stIns = $pdo->prepare("INSERT INTO bookings (
                service_id, client_id, freelancer_id, title_snapshot, description_snapshot,
                unit_price, quantity, platform_fee, total_amount, currency,
                scheduled_start, scheduled_end, status, payment_status, client_notes, created_at
            ) VALUES (
                :sid, :cid, :fid, :title, :descr, :price, :qty, 0.00, :total, 'PHP',
                NULL, NULL, 'pending', 'unpaid', :notes, NOW()
            )");
            $stIns->execute([
                ':sid'   => (int)$svc['service_id'],
                ':cid'   => $viewerId,
                ':fid'   => $freelancerId,
                ':title' => (string)$svc['title'],
                ':descr' => (string)($svc['description'] ?? ''),
                ':price' => $unitPrice,
                ':qty'   => $qty,
                ':total' => $totalAmount,
                ':notes' => 'Booked from feed',
            ]);
            $bookingId = (int)$pdo->lastInsertId();

            // Ensure a single general conversation, then post a system message
            $conversationId = $db->createOrGetGeneralConversation($viewerId, $freelancerId) ?: 0;
            if ($conversationId) {
                $body = sprintf(
                    "New booking #%d created for service '%s' (Qty: %d). Awaiting freelancer confirmation.",
                    $bookingId,
                    (string)$svc['title'],
                    $qty
                );
                try { $db->addMessage($conversationId, $viewerId, $body, 'system', $bookingId); } catch (Throwable $e) { /* ignore */ }
            }

            // Create notification for freelancer
            try {
                $notifData = json_encode(['booking_id'=>$bookingId,'service_id'=>(int)$svc['service_id'],'client_id'=>$viewerId,'qty'=>$qty], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                $stNot = $pdo->prepare("INSERT INTO notifications (user_id, type, data, created_at) VALUES (:uid, 'booking_created', :data, NOW())");
                $stNot->execute([':uid'=>$freelancerId, ':data'=>$notifData]);
            } catch (Throwable $e) { /* ignore */ }

            // Redirect straight to inbox/conversation
            $redir = 'inbox.php?user_id=' . urlencode((string)$freelancerId) . '&booking_id=' . urlencode((string)$bookingId);
            if ($conversationId) { $redir .= '&conversation_id=' . urlencode((string)$conversationId); }
            header('Location: ' . $redir);
            exit;
        }
    } catch (Throwable $e) {
        // On any error, just go back to feed
        header('Location: feed.php');
        exit;
    }
}

// If user clicks "Message" from the feed, reuse existing conversation or create the general one, then redirect
if ($currentUser && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_chat_from_feed'])) {
    try {
        $viewerId = (int)($_SESSION['user_id'] ?? 0);
        $otherId  = (int)($_POST['other_user_id'] ?? 0);
        if ($viewerId <= 0 || $otherId <= 0 || $viewerId === $otherId) {
            header('Location: feed.php');
            exit;
        }
        $conversationId = $db->createOrGetGeneralConversation($viewerId, $otherId) ?: 0;
        $redir = 'inbox.php?user_id=' . urlencode((string)$otherId);
        if ($conversationId) { $redir .= '&conversation_id=' . urlencode((string)$conversationId); }
        header('Location: ' . $redir);
        exit;
    } catch (Throwable $e) {
        header('Location: feed.php');
        exit;
    }
}

// Load services from database
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$rows = $db->listAllServices(60, 0, $search !== '' ? $search : null);

// Resolve category_id -> name map from DB (fallback safe if table missing)
$categoryNames = [];
try { $categoryNames = $db->listServiceCategoryNames(); } catch (Throwable $e) { $categoryNames = []; }

// Normalize to UI structure expected below
$services = array_map(function($s){
    $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
    $avatar = $s['profile_picture'] ?: 'img/profile_icon.webp';
    $price = '₱' . number_format((float)($s['base_price'] ?? 0), 2);
    $created = isset($s['created_at']) ? date('M d, Y', strtotime($s['created_at'])) : '';
    $cat = $s['category_name'] ?? null;
    return [
        'id' => (int)($s['service_id'] ?? 0),
        'owner_id' => (int)($s['user_id'] ?? ($s['freelancer_id'] ?? 0)),
        'user_name' => $name ?: 'Unknown',
        'user_avatar' => $avatar,
        'verified' => true,
        'title' => (string)($s['title'] ?? ''),
        'description' => (string)($s['description'] ?? ''),
        'price' => $price,
        'rating' => isset($s['avg_rating']) ? (float)$s['avg_rating'] : 0.0,
        'reviews' => isset($s['total_reviews']) ? (int)$s['total_reviews'] : 0,
        'date' => $created,
        'category' => ($cat ?: ($categoryNames[(int)($s['category_id'] ?? 0)] ?? 'Uncategorized'))
    ];
}, $rows);

// Batch-load recent reviews per service (last 2) to enrich cards and modal
$recentReviewsByService = [];
try {
    $serviceIds = array_map(fn($s) => (int)$s['id'], $services);
    // Fetch aggregates for accurate stars and review counts on cards
    $aggregates = $db->getServiceReviewAggregates($serviceIds);
    // Overlay aggregate counts/averages on $services
    foreach ($services as &$svc) {
        $sid = (int)$svc['id'];
        if (isset($aggregates[$sid])) {
            $svc['reviews'] = (int)$aggregates[$sid]['total_reviews'];
            $svc['rating']  = $svc['reviews'] > 0 ? (float)$aggregates[$sid]['avg_rating'] : 0.0;
            $svc['sum_stars'] = (float)$aggregates[$sid]['sum_stars'];
        } else {
            $svc['sum_stars'] = 0.0;
        }
    }
    unset($svc);
    // Prepare modal review snippets lazily (still batched)
    $recentReviewsByService = $db->getRecentServiceReviews($serviceIds, 2, 300);
} catch (Throwable $e) {
    $recentReviewsByService = [];
}
// Build category pills from DB categories and include 'Uncategorized' if present among services
$categoryList = array_values(array_unique(array_map('strval', array_values($categoryNames))));
// Natural case-insensitive sort
natcasesort($categoryList);
$categoryList = array_values($categoryList);
$hasUncategorized = false;
foreach ($services as $svc) {
    if (($svc['category'] ?? '') === 'Uncategorized') { $hasUncategorized = true; break; }
}
if ($hasUncategorized) { $categoryList[] = 'Uncategorized'; }
$categories = array_merge(['All'], $categoryList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Hive - Service Feed</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        /* Custom Animations */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        @keyframes slideInLeft {
            from {
                transform: translateX(-320px);
            }
            to {
                transform: translateX(0);
            }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .animate-slide-down {
            animation: slideDown 0.2s ease-out forwards;
        }
        
        .animate-fade-in-up {
            animation: fadeInUp 0.4s ease-out forwards;
        }
        
        .animate-spin-slow {
            animation: spin 20s linear infinite;
        }
        
        /* Sidebar transition */
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }
        
        .sidebar.closed {
            transform: translateX(-320px);
        }
        
        /* Card hover effects */
        .service-card {
            transition: all 0.3s ease;
        }
        
        .service-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(245, 158, 11, 0.15);
        }
        
        /* Profile dropdown */
        .profile-dropdown {
            display: none;
            opacity: 0;
        }
        
        .profile-dropdown.active {
            display: block;
            animation: slideDown 0.2s ease-out forwards;
        }
        
        /* Smooth hover transitions */
        .hover-lift {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
        }
        
        /* Sidebar active state */
        .sidebar-item.active {
            background: linear-gradient(to right, #f59e0b, #f97316);
            color: white;
        }
        
        /* Category pills */
        .category-pill {
            transition: all 0.2s ease;
        }
        
        .category-pill:hover {
            transform: scale(1.05);
        }
        
        .category-pill.active {
            background: linear-gradient(to right, #f59e0b, #f97316);
            color: white;
        }
        
        /* Hidden scrollbar for category scroll */
        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        /* Match inbox.php sidebar scrollbar behavior */
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Dropdown shared styles for notifications */
        .dropdown { display: none; opacity: 0; }
        .dropdown.active { display: block; animation: slideDown 0.2s ease-out forwards; }
    </style>

    <!-- Navbar -->
    <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-xl border-b border-amber-200/50 shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Left Section -->
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="p-2 hover:bg-amber-100 rounded-lg transition-colors">
                        <i data-lucide="menu" class="w-5 h-5 text-gray-700"></i>
                    </button>

                    <a href="index.php" class="flex items-center gap-3 cursor-pointer hover:scale-105 transition-transform">
                        <div class="relative">
                            <svg class="w-8 h-8 fill-amber-400 stroke-amber-600 stroke-2" viewBox="0 0 24 24">
                                <polygon points="12 2, 22 8.5, 22 15.5, 12 22, 2 15.5, 2 8.5"/>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="w-2 h-2 rounded-full bg-amber-600"></div>
                            </div>
                        </div>
                        <div class="hidden sm:block">
                            <h1 class="text-xl font-bold text-amber-900 tracking-tight">Task Hive</h1>
                        </div>
                    </a>
                </div>

                <!-- Right Section -->
                <div class="flex items-center gap-3">
                    <!-- Notifications -->
                    <div class="relative">
                        <button onclick="toggleNotifications()" class="relative p-2 hover:bg-amber-100 rounded-full transition-colors hover:scale-105" title="Notifications">
                            <i data-lucide="bell" class="w-5 h-5 text-gray-700"></i>
                            <span class="absolute top-1 right-1 w-2 h-2 bg-orange-500 rounded-full"></span>
                        </button>

                        <div id="notifications-dropdown" class="dropdown absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden">
                            <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border-b border-amber-200 flex items-center justify-between">
                                <h3 class="font-bold text-gray-900">Notifications</h3>
                                <button onclick="markAllAsRead()" class="text-xs text-amber-600 hover:text-amber-700 font-medium">Mark all as read</button>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item p-4 border-b border-amber-100 <?php echo $notif['unread'] ? 'bg-blue-50/50' : ''; ?>">
                                        <div class="flex items-start gap-3">
                                            <?php if ($notif['unread']): ?>
                                                <div class="w-2 h-2 bg-blue-500 rounded-full mt-2 flex-shrink-0"></div>
                                            <?php endif; ?>
                                            <div class="flex-1">
                                                <p class="text-sm text-gray-800"><?php echo htmlspecialchars($notif['message']); ?></p>
                                                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($notif['time']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!count($notifications)): ?>
                                    <div class="p-4 text-sm text-gray-600">No notifications</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <button onclick="toggleProfileDropdown()" class="flex items-center gap-3 px-3 py-2 hover:bg-amber-50 rounded-full transition-colors">
                            <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" 
                                 alt="<?php echo htmlspecialchars($currentUser['name']); ?>" 
                                 class="w-8 h-8 rounded-full border-2 border-amber-400 object-cover">
                            <span class="hidden md:block text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                            <svg id="dropdown-arrow" class="w-4 h-4 text-gray-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Dropdown Menu -->
                        <div id="profile-dropdown" class="profile-dropdown absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden">
                            <!-- User Info -->
                            <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border-b border-amber-200">
                                <div class="flex items-center gap-3">
                                    <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" 
                                         alt="<?php echo htmlspecialchars($currentUser['name']); ?>" 
                                         class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                    <div>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Menu Items -->
                            <div class="py-2">
                                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="user" class="w-5 h-5 text-amber-600"></i>
                                    <span>View Dashboard</span>
                                </a>

                                <a href="unavail.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="settings" class="w-5 h-5 text-amber-600"></i>
                                    <span>Settings</span>
                                </a>

                                <div class="my-2 border-t border-gray-200"></div>

                                <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-red-600 hover:bg-red-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="log-out" class="w-5 h-5"></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar fixed left-0 top-16 h-[calc(100vh-4rem)] w-64 bg-gradient-to-b from-gray-900 via-gray-800 to-amber-900 text-white z-40 flex flex-col shadow-2xl">
            <!-- Navigation Title -->
            <div class="px-6 py-6 border-b border-white/10">
                <h2 class="text-lg font-bold text-amber-400 tracking-wide">Navigation</h2>
            </div>

            <!-- Main Menu -->
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto hide-scrollbar">
                <a href="inbox.php" class="sidebar-item flex items-center justify-between px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all" data-name="inbox">
                    <div class="flex items-center gap-3">
                        <i data-lucide="inbox" class="w-5 h-5"></i>
                        <span class="font-medium tracking-wide">Inbox</span>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <span id="inbox-count-badge" class="px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold"><?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?></span>
                    <?php endif; ?>
                </a>

                <a href="feed.php" class="sidebar-item active flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg transition-all" data-name="browse">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Browse Services</span>
                </a>

                <a href="profile.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all" data-name="profile">
                    <i data-lucide="user" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">My Profile</span>
                </a>


                
            </nav>

            <!-- Bottom Section -->
            <div class="px-3 py-4 border-t border-white/10 space-y-1">
                <a href="unavail.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Settings</span>
                </a>
                <a href="unavail.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="help-circle" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Help & Support</span>
                </a>
            </div>

            <!-- Bee Decoration -->
            <div class="absolute bottom-4 right-4 text-2xl opacity-20">🐝</div>
        </aside>

        <!-- Main Content -->
        <main id="main-content" class="flex-1 transition-all duration-300 lg:ml-64">
            <div class="min-h-screen p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <!-- Browse Services Section -->
                    <div id="section-browse">
                    <!-- Header -->
                    <div class="mb-8">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                            <div>
                                <h1 class="text-3xl font-bold text-gray-900 mb-2">Latest Services</h1>
                                <p class="text-base text-gray-600">Discover amazing services from talented freelancers</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button class="flex items-center gap-2 px-4 py-2 border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 transition-colors">
                                    <i data-lucide="sliders-horizontal" class="w-4 h-4"></i>
                                    <span class="text-sm font-medium">Filters</span>
                                </button>
                            </div>
                        </div>

                        <!-- Search Bar -->
                        <div class="relative mb-4">
                            <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i>
                            <input 
                                type="text" 
                                id="search-input"
                                placeholder="Search services (title or description)..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                class="w-full pl-12 pr-12 h-12 bg-white border border-amber-200 rounded-lg text-base focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20 transition-all"
                                oninput="filterServices()">
                            <button id="clear-search" onclick="clearSearch()" class="hidden absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
                                ✕
                            </button>
                        </div>

                        <!-- Category Filter -->
                        <div class="flex items-center gap-2 overflow-x-auto pb-2 hide-scrollbar">
                            <?php foreach ($categories as $category): ?>
                                <button 
                                    onclick="setCategory('<?php echo htmlspecialchars($category); ?>')" 
                                    class="category-pill <?php echo $category === 'All' ? 'active' : ''; ?> px-4 py-2 rounded-full whitespace-nowrap text-sm font-medium <?php echo $category === 'All' ? '' : 'bg-white text-gray-700 border border-amber-200 hover:border-amber-400'; ?>"
                                    data-category="<?php echo htmlspecialchars($category); ?>">
                                    <?php echo htmlspecialchars($category); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Results Count -->
                    <div class="mb-4">
                        <p class="text-base text-gray-600">
                            Showing <span id="results-count" class="text-amber-600 font-semibold"><?php echo count($services); ?></span> services
                        </p>
                    </div>

                    <!-- Service Grid -->
                    <div id="service-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($services as $index => $service): ?>
                 <div class="service-card bg-white rounded-2xl shadow-md border border-amber-100 overflow-hidden" 
                                 data-id="<?php echo (int)$service['id']; ?>"
                           data-owner-id="<?php echo (int)($service['owner_id'] ?? 0); ?>"
                                 data-title="<?php echo strtolower(htmlspecialchars($service['title'])); ?>"
                                 data-title-full="<?php echo htmlspecialchars($service['title']); ?>"
                                 data-description="<?php echo strtolower(htmlspecialchars($service['description'])); ?>"
                                 data-description-full="<?php echo htmlspecialchars($service['description']); ?>"
                                 data-category="<?php echo htmlspecialchars($service['category']); ?>"
                                 data-price="<?php echo htmlspecialchars($service['price']); ?>"
                                 data-rating="<?php echo htmlspecialchars(number_format((float)$service['rating'], 1)); ?>"
                                 data-reviews="<?php echo (int)$service['reviews']; ?>"
                                 data-date="<?php echo htmlspecialchars($service['date']); ?>"
                                 data-user-name="<?php echo htmlspecialchars($service['user_name']); ?>"
                                 data-user-avatar="<?php echo htmlspecialchars($service['user_avatar']); ?>"
                                 data-recent-reviews='<?= htmlspecialchars(json_encode($recentReviewsByService[(int)$service['id']] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'
                                 style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                
                                <!-- Card Header -->
                                <div class="p-5 bg-gradient-to-br from-amber-50 to-orange-50/50 border-b border-amber-100">
                                    <div class="flex items-start justify-between mb-3">
                                        <?php if (!empty($service['owner_id'])): ?>
                                            <a href="freelancer_profile.php?id=<?php echo (int)$service['owner_id']; ?>" class="flex items-center gap-3 hover:opacity-90 transition-opacity">
                                                <img src="<?php echo htmlspecialchars($service['user_avatar']); ?>" 
                                                     alt="<?php echo htmlspecialchars($service['user_name']); ?>" 
                                                     class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                                <div>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-base font-medium text-gray-900"><?php echo htmlspecialchars($service['user_name']); ?></span>
                                                        <?php if ($service['verified']): ?>
                                                            <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                                                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex items-center gap-1 text-sm text-gray-500">
                                                        <i data-lucide="calendar" class="w-3 h-3"></i>
                                                        <span><?php echo htmlspecialchars($service['date']); ?></span>
                                                    </div>
                                                </div>
                                            </a>
                                        <?php else: ?>
                                            <div class="flex items-center gap-3">
                                                <img src="<?php echo htmlspecialchars($service['user_avatar']); ?>" 
                                                     alt="<?php echo htmlspecialchars($service['user_name']); ?>" 
                                                     class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                                <div>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-base font-medium text-gray-900"><?php echo htmlspecialchars($service['user_name']); ?></span>
                                                        <?php if ($service['verified']): ?>
                                                            <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                                                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex items-center gap-1 text-sm text-gray-500">
                                                        <i data-lucide="calendar" class="w-3 h-3"></i>
                                                        <span><?php echo htmlspecialchars($service['date']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <span class="px-2 py-1 bg-amber-100 text-amber-700 text-xs font-medium rounded-full flex items-center gap-1">
                                            <i data-lucide="tag" class="w-3 h-3"></i>
                                            <?php echo htmlspecialchars($service['category']); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Card Content -->
                                <div class="p-5">
                                    <h3 class="text-lg font-bold text-gray-900 mb-2 line-clamp-1 hover:text-amber-600 transition-colors">
                                        <?php echo htmlspecialchars($service['title']); ?>
                                    </h3>
                                    <p class="text-sm text-gray-600 mb-4 line-clamp-2 leading-relaxed">
                                        <?php echo htmlspecialchars($service['description']); ?>
                                    </p>

                                    <!-- Rating -->
                                    <div class="flex items-center gap-3 mb-4">
                                        <div class="flex items-center gap-2" aria-label="Rating: <?php echo number_format($service['rating'],1); ?> out of 5">
                                            <div class="flex">
                                                <?php 
                                                    $ratingVal = (float)$service['rating'];
                                                    $full = (int)floor($ratingVal);
                                                    $frac = $ratingVal - $full;
                                                    $hasHalf = ($frac >= 0.5) ? 1 : 0;
                                                    for ($i=1;$i<=5;$i++): 
                                                        if ($i <= $full) {
                                                            echo '<i data-lucide="star" class="w-4 h-4 text-amber-500 fill-amber-500"></i>';
                                                        } elseif ($i === $full + 1 && $hasHalf) {
                                                            echo '<i data-lucide="star-half" class="w-4 h-4 text-amber-500"></i>';
                                                        } else {
                                                            echo '<i data-lucide="star" class="w-4 h-4 text-gray-300"></i>';
                                                        }
                                                    endfor; 
                                                ?>
                                            </div>
                                            <span class="text-base font-medium text-gray-900"><?php echo number_format($service['rating'], 1); ?></span>
                                        </div>
                                        <span class="text-sm text-gray-500">(<?php echo number_format($service['reviews']); ?> reviews)</span>
                                    </div>

                                    <!-- Recent Reviews (snippets) hidden on cards; shown in modal only -->

                                    <!-- Price -->
                                    <div class="mb-4 p-3 bg-gradient-to-r from-amber-50 to-orange-50 rounded-lg border border-amber-200">
                                        <div class="text-sm text-gray-600 mb-1">Starting at</div>
                                        <div class="text-xl font-bold text-amber-600"><?php echo htmlspecialchars($service['price']); ?></div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex gap-2">
                                        <button data-action="view" class="flex-1 flex items-center justify-center gap-2 px-4 py-2 border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 hover:border-amber-400 transition-all group">
                                            <i data-lucide="eye" class="w-4 h-4 group-hover:scale-110 transition-transform"></i>
                                            <span class="text-sm font-medium">View</span>
                                        </button>
                                        <?php if (!empty($currentUser) && (int)($currentUser['id'] ?? 0) !== (int)$service['owner_id']): ?>
                                        <form method="POST" action="feed.php" class="flex-1">
                                            <input type="hidden" name="start_chat_from_feed" value="1">
                                            <input type="hidden" name="other_user_id" value="<?php echo (int)$service['owner_id']; ?>">
                                            <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 shadow-md hover:shadow-lg transition-all group">
                                                <i data-lucide="message-circle" class="w-4 h-4 group-hover:scale-110 transition-transform"></i>
                                                <span class="text-sm font-medium">Message</span>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- No Results Message -->
                    <div id="no-results" class="hidden text-center py-16">
                        <div class="text-6xl mb-4">🔍</div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">No services found</h3>
                        <p class="text-base text-gray-600">Try adjusting your search or filters</p>
                    </div>
                    </div><!-- /section-browse -->

                    <!-- Inbox Section -->
                    <div id="section-inbox" class="hidden">
                        <div class="relative bg-white border border-amber-100 rounded-2xl overflow-hidden shadow-md">
                            <div id="inbox-loader" class="absolute inset-0 hidden items-center justify-center bg-white/80 z-10">
                                <div class="flex items-center gap-2 text-gray-600">
                                    <svg class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="4" class="opacity-20"/><path d="M4 12a8 8 0 018-8" stroke-width="4" stroke-linecap="round"/></svg>
                                    <span>Loading Inbox…</span>
                                </div>
                            </div>
                            <div id="inbox-fallback" class="hidden absolute inset-0 flex items-center justify-center bg-white z-10">
                                <div class="text-center space-y-3">
                                    <div class="text-5xl">📭</div>
                                    <div class="text-gray-700">Couldn’t display inbox here.</div>
                                    <a href="conversation.php" target="_blank" class="inline-block px-4 py-2 bg-amber-500 text-white rounded-lg">Open in new tab</a>
                                </div>
                            </div>
                            <iframe 
                                id="inbox-frame" 
                                title="Inbox"
                                src="conversation.php"
                                style="width:100%; height: calc(100vh - 12rem); border:0;"
                                loading="lazy"
                            ></iframe>
                        </div>
                    </div><!-- /section-inbox -->
                </div>
            </div>
        </main>
    </div>

    <!-- Service Detail Modal -->
    <div id="service-modal" class="fixed inset-0 z-[60] hidden">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" data-modal-close></div>
        <!-- Panel wrapper -->
        <div class="relative h-full w-full flex items-center justify-center p-4">
            <div id="service-modal-panel" class="relative w-full max-w-3xl bg-white rounded-2xl shadow-2xl border border-amber-200 overflow-hidden transform transition-all duration-200 ease-out opacity-0 scale-95">
                <!-- Header -->
                <div class="p-6 bg-gradient-to-r from-amber-50 to-orange-50 border-b border-amber-200">
                    <div class="flex items-start gap-4">
                        <img id="sm-user-avatar" src="img/profile_icon.webp" alt="" class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                        <div class="flex-1">
                            <h2 id="sm-title" class="text-2xl font-bold text-gray-900">Service Title</h2>
                            <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-gray-600">
                                <span class="inline-flex items-center gap-1"><i data-lucide="user" class="w-4 h-4 text-amber-600"></i><span id="sm-user-name">User Name</span></span>
                                <span class="inline-flex items-center gap-1"><i data-lucide="calendar" class="w-4 h-4 text-amber-600"></i><span id="sm-date">Date</span></span>
                                <span class="inline-flex items-center gap-1"><i data-lucide="tag" class="w-4 h-4 text-amber-600"></i><span id="sm-category">Category</span></span>
                            </div>
                        </div>
                        <button class="p-2 rounded-lg hover:bg-amber-100 transition-colors" aria-label="Close" data-modal-close>
                            <i data-lucide="x" class="w-5 h-5 text-gray-600"></i>
                        </button>
                    </div>
                </div>

                <!-- Body -->
                <div class="p-6 space-y-6">
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="inline-flex items-center gap-3 px-3 py-1 rounded-full bg-amber-50 border border-amber-200">
                            <span id="sm-stars" class="inline-flex items-center gap-0.5" aria-hidden="true"></span>
                            <span id="sm-rating" class="font-semibold text-gray-900">0.0</span>
                            <span class="text-gray-500 text-sm">(<span id="sm-reviews">0</span> reviews)</span>
                        </div>
                        <div class="ml-auto inline-flex items-center gap-2 px-3 py-1 rounded-full bg-gradient-to-r from-amber-100 to-orange-100 border border-amber-200">
                            <span class="text-sm text-gray-600">Starting at</span>
                            <span id="sm-price" class="text-base font-bold text-amber-700">₱0.00</span>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">About this service</h3>
                        <p id="sm-description" class="text-gray-700 leading-relaxed"></p>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Recent reviews</h3>
                        <div id="sm-reviews-container" class="space-y-3">
                            <!-- Filled dynamically from card dataset -->
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-end gap-3">
                    <button id="sm-book" class="px-4 py-2 rounded-lg bg-amber-600 text-white hover:bg-amber-700 transition-colors hidden">Book Now</button>
                    <button class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 transition-colors" data-modal-close>Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden form to submit immediate booking from the feed -->
    <form id="book-from-feed-form" method="post" class="hidden">
        <input type="hidden" name="book_service_from_feed" value="1" />
        <input type="hidden" name="service_id" id="bfff-service-id" value="" />
        <input type="hidden" name="freelancer_id" id="bfff-freelancer-id" value="" />
    </form>

    <script>
    // Initialize Lucide Icons
    lucide.createIcons();

    // Session/user context for client gating
    const CURRENT_USER_ID = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
    const CURRENT_USER_TYPE = '<?php echo htmlspecialchars($currentUser['user_type'] ?? '', ENT_QUOTES); ?>';

        // Sidebar state
        let sidebarOpen = true;

        function toggleSidebar() {
            sidebarOpen = !sidebarOpen;
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            
            if (sidebarOpen) {
                sidebar.classList.remove('closed');
                mainContent.classList.add('lg:ml-64');
            } else {
                sidebar.classList.add('closed');
                mainContent.classList.remove('lg:ml-64');
            }
        }

        // Notifications dropdown
        function toggleNotifications() {
            const dropdown = document.getElementById('notifications-dropdown');
            const profileDropdown = document.getElementById('profile-dropdown');
            if (profileDropdown) profileDropdown.classList.remove('active');
            if (dropdown) dropdown.classList.toggle('active');
        }

        function markAllAsRead() {
            document.querySelectorAll('.notification-item').forEach(item => {
                item.classList.remove('bg-blue-50/50');
                const dot = item.querySelector('.bg-blue-500');
                if (dot) dot.remove();
            });
            const badge = document.querySelector('button[title="Notifications"] .absolute.top-1.right-1');
            if (badge) badge.remove();
        }

        // Profile dropdown
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profile-dropdown');
            const arrow = document.getElementById('dropdown-arrow');
            dropdown.classList.toggle('active');
            
            if (dropdown.classList.contains('active')) {
                arrow.style.transform = 'rotate(180deg)';
            } else {
                arrow.style.transform = 'rotate(0deg)';
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const profileDd = document.getElementById('profile-dropdown');
            const notifDd = document.getElementById('notifications-dropdown');
            const clickedButton = event.target.closest('button');
            if (!clickedButton || (!clickedButton.onclick && !clickedButton.getAttribute('onclick'))) {
                if (profileDd) profileDd.classList.remove('active');
                if (notifDd) notifDd.classList.remove('active');
                const arr = document.getElementById('dropdown-arrow');
                if (arr) arr.style.transform = 'rotate(0deg)';
            }
        });

    // Sidebar active state + section switcher
        function setActive(item, btn) {
            // Toggle active class on sidebar items
            document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
            if (btn) btn.classList.add('active');

            // Toggle sections
            const sections = ['browse','inbox'];
            sections.forEach(name => {
                const el = document.getElementById('section-' + name);
                if (!el) return;
                if (name === item) el.classList.remove('hidden'); else el.classList.add('hidden');
            });

            // Refresh icons
            if (window.lucide) lucide.createIcons();

            // Lazy-load conversation iframe on first open
            if (item === 'inbox') {
                const frame = document.getElementById('inbox-frame');
                const loader = document.getElementById('inbox-loader');
                if (frame && !frame.src) {
                    if (loader) loader.classList.remove('hidden');
                    frame.src = 'conversation.php';
                }
            }
        }
        // Category filter with URL syncing
        let currentCategory = 'All';

        function setCategory(category) {
            currentCategory = category;
            const pills = document.querySelectorAll('.category-pill');
            pills.forEach(pill => {
                if (pill.dataset.category === category) {
                    pill.classList.add('active');
                } else {
                    pill.classList.remove('active');
                }
            });
            // Update URL query param without reloading
            try {
                const url = new URL(window.location.href);
                if (category && category !== 'All') {
                    url.searchParams.set('category', category);
                } else {
                    url.searchParams.delete('category');
                }
                window.history.replaceState({}, '', url.toString());
            } catch (_) {}
            filterServices();
        }

        // Search and filter
        function filterServices() {
            const searchQuery = document.getElementById('search-input').value.toLowerCase();
            const clearBtn = document.getElementById('clear-search');
            const cards = document.querySelectorAll('.service-card');
            const noResults = document.getElementById('no-results');
            const grid = document.getElementById('service-grid');
            let visibleCount = 0;

            // Show/hide clear button
            if (searchQuery) {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }

            cards.forEach(card => {
                const title = card.dataset.title;
                const description = card.dataset.description;
                const category = card.dataset.category;
                
                const matchesSearch = !searchQuery || title.includes(searchQuery) || description.includes(searchQuery);
                const matchesCategory = currentCategory === 'All' || category === currentCategory;

                if (matchesSearch && matchesCategory) {
                    card.style.display = 'block';
                    card.classList.add('animate-fade-in-up');
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Update results count
            document.getElementById('results-count').textContent = visibleCount;

            // Show/hide no results message
            if (visibleCount === 0) {
                grid.style.display = 'none';
                noResults.classList.remove('hidden');
            } else {
                grid.style.display = 'grid';
                noResults.classList.add('hidden');
            }
        }

        function goSearch() {
            const input = document.getElementById('search-input');
            const q = (input?.value || '').trim();
            try {
                const url = new URL(window.location.href);
                if (q) {
                    url.searchParams.set('q', q);
                } else {
                    url.searchParams.delete('q');
                }
                // Preserve category if present; URL object already does
                window.location.href = url.toString();
            } catch (_) {
                // Fallback
                window.location.href = 'feed.php' + (q ? ('?q=' + encodeURIComponent(q)) : '');
            }
        }

        function clearSearch() {
            const input = document.getElementById('search-input');
            if (input) input.value = '';
            try {
                const url = new URL(window.location.href);
                url.searchParams.delete('q');
                window.location.href = url.toString();
            } catch (_) {
                window.location.href = 'feed.php';
            }
        }

        // Re-initialize icons after dynamic content
        document.addEventListener('DOMContentLoaded', function() {
            if (window.lucide) lucide.createIcons();
            try {
                const url = new URL(window.location.href);
                if (url.searchParams.get('tab') === 'inbox' || window.location.hash === '#inbox') {
                    const inboxBtn = document.querySelector('.sidebar [data-name="inbox"]');
                    setActive('inbox', inboxBtn);
                }
                // Initialize category from URL if provided
                const cat = url.searchParams.get('category');
                if (cat) {
                    // If the category exists among pills, activate it; else default remains 'All'
                    const pill = Array.from(document.querySelectorAll('.category-pill')).find(p => p.dataset.category === cat);
                    if (pill) setCategory(cat);
                }
                // Always apply an initial filter pass for client-side refinement
                filterServices();

                // Bind Enter key to perform server-side search
                const si = document.getElementById('search-input');
                if (si) {
                    si.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            goSearch();
                        }
                    });
                }
            } catch (_) { /* ignore */ }

            // Listen for unread count updates from conversation iframe
            window.addEventListener('message', (event) => {
                const data = event.data || {};
                if (data && data.type === 'inbox-unread-updated') {
                    const badge = document.getElementById('inbox-count-badge');
                    if (badge) {
                        const c = Number(data.count || 0);
                        badge.textContent = c > 99 ? '99+' : String(c);
                    }
                }
                if (data && data.type === 'inbox-ready') {
                    const loader = document.getElementById('inbox-loader');
                    if (loader) loader.classList.add('hidden');
                }
            });

            // Hide loader once iframe loads or reveal fallback on failure
            const frame = document.getElementById('inbox-frame');
            const loader = document.getElementById('inbox-loader');
            const fallback = document.getElementById('inbox-fallback');
            let fallbackTimer;

            function hideLoader(){ if (loader) loader.classList.add('hidden'); if (fallbackTimer) clearTimeout(fallbackTimer); }
            function showFallback(){ if (loader) loader.classList.add('hidden'); if (fallback) fallback.classList.remove('hidden'); }

            if (frame) {
                frame.addEventListener('load', hideLoader);
                // If it doesn't load within 6s, show fallback
                fallbackTimer = setTimeout(()=>{
                    try {
                        // Accessing contentDocument can throw if cross-origin; we use a timer instead
                        if (loader && !loader.classList.contains('hidden')) {
                            showFallback();
                        }
                    } catch(_) { showFallback(); }
                }, 6000);
            }

            // Service modal handlers
            const modal = document.getElementById('service-modal');
            const panel = document.getElementById('service-modal-panel');
            const closeSelectors = '[data-modal-close]';

            function openModalFromCard(card){
                if (!modal || !panel) return;
                // Populate fields
                const get = (sel)=>document.getElementById(sel);
                const setText = (id, val)=>{ const el=get(id); if (el) el.textContent = val || ''; };
                const setSrc = (id, val)=>{ const el=get(id); if (el) el.src = val || 'img/profile_icon.webp'; };

                setText('sm-title', card.getAttribute('data-title-full'));
                setText('sm-user-name', card.getAttribute('data-user-name'));
                setText('sm-date', card.getAttribute('data-date'));
                setText('sm-category', card.getAttribute('data-category'));
                setText('sm-rating', card.getAttribute('data-rating'));
                setText('sm-reviews', card.getAttribute('data-reviews'));
                setText('sm-price', card.getAttribute('data-price'));
                const desc = card.getAttribute('data-description-full') || '';
                const descEl = get('sm-description');
                if (descEl){ descEl.textContent = desc; }
                setSrc('sm-user-avatar', card.getAttribute('data-user-avatar'));

                // Populate recent reviews in modal if available
                try {
                    const reviewsData = card.getAttribute('data-recent-reviews') || '[]';
                    const arr = JSON.parse(reviewsData);
                    const box = document.getElementById('sm-reviews-container');
                    if (box) {
                        box.innerHTML = '';
                        if (Array.isArray(arr) && arr.length > 0) {
                            arr.slice(0, 5).forEach(r => {
                                const wrap = document.createElement('div');
                                wrap.className = 'p-3 bg-gray-50 border border-gray-200 rounded-lg';
                                const head = document.createElement('div');
                                head.className = 'flex items-center gap-2 text-amber-600 text-sm font-medium';
                                const rVal = Number(r.rating || 0);
                                const f = Math.floor(rVal);
                                const frac = rVal - f;
                                const half = frac >= 0.5;
                                let starHtml = '';
                                for (let i=1;i<=5;i++) {
                                    if (i <= f) {
                                        starHtml += '<i data-lucide="star" class="w-3.5 h-3.5 text-amber-500 fill-amber-500"></i>';
                                    } else if (i === f + 1 && half) {
                                        starHtml += '<i data-lucide="star-half" class="w-3.5 h-3.5 text-amber-500"></i>';
                                    } else {
                                        starHtml += '<i data-lucide="star" class="w-3.5 h-3.5 text-gray-300"></i>';
                                    }
                                }
                                head.innerHTML = '<i data-lucide="quote" class="w-4 h-4"></i>' +
                                                 '<span class="inline-flex items-center gap-1">' + starHtml + '</span>' +
                                                 '<span class="text-gray-700">·</span>' +
                                                 '<span class="text-gray-700">' + (r.reviewer_name || 'User') + '</span>';
                                wrap.appendChild(head);
                                if (r.comment) {
                                    const p = document.createElement('p');
                                    p.className = 'mt-1 text-sm text-gray-700';
                                    p.textContent = r.comment;
                                    wrap.appendChild(p);
                                }
                                box.appendChild(wrap);
                            });
                        } else {
                            const empty = document.createElement('div');
                            empty.className = 'text-sm text-gray-500';
                            empty.textContent = 'No recent reviews yet';
                            box.appendChild(empty);
                        }
                    }
                } catch (_) {}

                // Render star bar for the overall service rating in the modal
                const starsWrap = document.getElementById('sm-stars');
                const ratingText = card.getAttribute('data-rating') || '0';
                const ratingVal = Number(ratingText);
                if (starsWrap && !Number.isNaN(ratingVal)) {
                    starsWrap.innerHTML = '';
                    const full = Math.floor(ratingVal);
                    const frac = ratingVal - full;
                    const hasHalf = frac >= 0.5;
                    for (let i=1;i<=5;i++) {
                        const icon = document.createElement('i');
                        if (i <= full) {
                            icon.setAttribute('data-lucide','star');
                            icon.className = 'w-4 h-4 text-amber-500 fill-amber-500';
                        } else if (i === full + 1 && hasHalf) {
                            icon.setAttribute('data-lucide','star-half');
                            icon.className = 'w-4 h-4 text-amber-500';
                        } else {
                            icon.setAttribute('data-lucide','star');
                            icon.className = 'w-4 h-4 text-gray-300';
                        }
                        starsWrap.appendChild(icon);
                    }
                }

                // Configure Book button
                const bookBtn = document.getElementById('sm-book');
                if (bookBtn){
                    const ownerId = card.getAttribute('data-owner-id');
                    const serviceId = card.getAttribute('data-id');
                    const isOwner = String(CURRENT_USER_ID || '') === String(ownerId || '');
                    const isClient = (CURRENT_USER_TYPE || '').toLowerCase() === 'client';
                    // Only show for clients who aren't the owner and when we have ids
                    if (isClient && !isOwner && ownerId && serviceId){
                        bookBtn.classList.remove('hidden');
                        bookBtn.onclick = function(){
                            const form = document.getElementById('book-from-feed-form');
                            const svcInput = document.getElementById('bfff-service-id');
                            const frInput = document.getElementById('bfff-freelancer-id');
                            if (form && svcInput && frInput){
                                svcInput.value = String(serviceId || '');
                                frInput.value = String(ownerId || '');
                                form.submit();
                            } else {
                                // Fallback: deep-link to freelancer profile booking
                                window.location.href = 'freelancer_profile.php?id=' + encodeURIComponent(ownerId) + '&book_service_id=' + encodeURIComponent(serviceId);
                            }
                        };
                    } else {
                        bookBtn.classList.add('hidden');
                        bookBtn.onclick = null;
                    }
                }

                // Show modal with animation
                modal.classList.remove('hidden');
                requestAnimationFrame(()=>{
                    panel.classList.remove('opacity-0','scale-95');
                    panel.classList.add('opacity-100','scale-100');
                });
                // Refresh icons inside modal
                if (window.lucide) lucide.createIcons();
            }

            function closeModal(){
                if (!modal || !panel) return;
                panel.classList.add('opacity-0','scale-95');
                panel.classList.remove('opacity-100','scale-100');
                setTimeout(()=>{ modal.classList.add('hidden'); }, 150);
            }

            // Delegate clicks on view buttons
            document.body.addEventListener('click', function(e){
                const viewBtn = e.target.closest('button[data-action="view"]');
                if (viewBtn){
                    const card = viewBtn.closest('.service-card');
                    if (card) openModalFromCard(card);
                }
                const closeBtn = e.target.closest(closeSelectors);
                if (closeBtn){ closeModal(); }
            });

            // Close on Escape
            document.addEventListener('keydown', (ev)=>{
                if (ev.key === 'Escape') closeModal();
            });
        });

        // Periodically refresh inbox unread badge so it updates when messages are read
        async function refreshInboxBadge(){
            try {
                const res = await fetch('api/unread_count.php', { cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.ok) return;
                const cnt = Number(data.unreadCount || 0);
                const link = document.querySelector('.sidebar [data-name="inbox"]');
                if (!link) return;
                let badge = document.getElementById('inbox-count-badge');
                if (cnt > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.id = 'inbox-count-badge';
                        badge.className = 'px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold';
                        link.appendChild(badge);
                    }
                    badge.textContent = cnt > 99 ? '99+' : String(cnt);
                } else if (badge) {
                    badge.remove();
                }
            } catch (_) { /* ignore */ }
        }
        // Kick off initial fetch and periodic refresh
        refreshInboxBadge();
        setInterval(refreshInboxBadge, 5000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshInboxBadge(); });
    </script>

</body>
</html>