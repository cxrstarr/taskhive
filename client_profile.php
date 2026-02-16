<?php
session_start();
require_once __DIR__ . '/database.php';

// Guard: require login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new database();
$uid = (int)$_SESSION['user_id'];
$u = $db->getUser($uid);
if (!$u) {
    header('Location: login.php');
    exit;
}

// Current user (for header)
$currentUser = [
    'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'User',
    'email' => $u['email'] ?? '',
    'avatar' => ($u['profile_picture'] ?? '') ?: 'img/profile_icon.webp',
];

// Client profile details and stats
$cp = $db->getClientProfile($uid) ?: [];

// Stats: totals and money spent
$pdo = $db->opencon();

// Count completed and active bookings
$stCompleted = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE client_id=:c AND status='completed'");
$stCompleted->execute([':c'=>$uid]);
$completedCount = (int)$stCompleted->fetchColumn();

$stActive = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE client_id=:c AND status IN ('pending','accepted','in_progress','delivered')");
$stActive->execute([':c'=>$uid]);
$activeCount = (int)$stActive->fetchColumn();

$stTotal = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE client_id=:c");
$stTotal->execute([':c'=>$uid]);
$totalBookings = (int)$stTotal->fetchColumn();

// Money spent as sum of payments on this client's bookings (escrowed or released)
$stSpent = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0)
                          FROM payments p
                          JOIN bookings b ON p.booking_id=b.booking_id
                          WHERE b.client_id=:c AND p.status IN ('escrowed','released')");
$stSpent->execute([':c'=>$uid]);
$moneySpent = (float)$stSpent->fetchColumn();

// Derive verified badges heuristically
$identityVerified = !empty($u['profile_picture']);
// Payment verified if at least one payment exists
$paymentVerified = $moneySpent > 0.0;
$emailVerified = true; // email exists in DB; customize if you track verification

$client = [
    'client_id' => $uid,
    'user_id' => $uid,
    'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'Client',
    'email' => $u['email'] ?? '',
    'avatar' => ($u['profile_picture'] ?? '') ?: 'img/profile_icon.webp',
    'cover_image' => 'img/hivebg.jpg', // fallback cover
    'bio' => $u['bio'] ?? '',
    'location' => '',
    'member_since' => $u['created_at'] ?? date('Y-m-d'),
    'verified' => $identityVerified,
    'total_bookings' => $totalBookings,
    'active_services' => $activeCount,
    'completed_services' => $completedCount,
    'money_spent' => number_format($moneySpent,2,'.',''),
    'badges' => [
        'identity' => $identityVerified,
        'payment' => $paymentVerified,
        'email' => $emailVerified,
    ],
];

// Bookings list (latest first)
$rows = $db->listClientBookings($uid, 200, 0);
$bookings = [];
foreach ($rows as $r) {
    $bookings[] = [
        'id' => (int)$r['booking_id'],
        'service_name' => (string)($r['service_title'] ?? 'Service'),
        'freelancer_name' => (string)($r['freelancer_name'] ?? 'Freelancer'),
        'freelancer_avatar' => (string)($r['freelancer_picture'] ?? 'img/profile_icon.webp'),
        'status' => ucfirst(str_replace('_',' ', strtolower((string)$r['status'] ?? 'pending'))),
        'price' => '₱'.number_format((float)($r['total_amount'] ?? 0), 2),
        'date_booked' => $r['created_at'] ?? null,
        'date_completed' => $r['completed_at'] ?? null,
    ];
}

// Reviews written by this client, enriched with freelancer image/name and service title
$revRows = $db->listClientWrittenReviews($uid, 30, 0);
$reviewsWritten = [];
foreach ($revRows as $rr) {
    $svcTitle = 'Service';
    $freelancerName = $rr['reviewee_name'] ?? 'Freelancer';
    $freelancerPic = $rr['reviewee_picture'] ?? 'img/profile_icon.webp';
    if (!empty($rr['booking_id'])) {
        $bx = $db->fetchBookingWithContext((int)$rr['booking_id']);
        if ($bx) {
            $svcTitle = $bx['service_title'] ?? ($bx['title_snapshot'] ?? $svcTitle);
            if (!empty($bx['freelancer_name'])) $freelancerName = $bx['freelancer_name'];
        }
    }
    $reviewsWritten[] = [
        'id' => (int)$rr['review_id'],
        'freelancer_name' => $freelancerName,
        'freelancer_avatar' => $freelancerPic,
        'service_name' => $svcTitle,
        'rating' => (int)$rr['rating'],
        'comment' => (string)($rr['comment'] ?? ''),
        'date' => date('M d, Y', strtotime($rr['created_at'] ?? date('Y-m-d'))),
    ];
}

// Notifications (latest 10)
$notifications = [];
try {
    $stN = $pdo->prepare("SELECT notification_id,type,data,created_at,read_at FROM notifications WHERE user_id=:u ORDER BY created_at DESC LIMIT 10");
    $stN->execute([':u'=>$uid]);
    foreach ($stN->fetchAll() as $n) {
        $type = (string)$n["type"];
        $msg = ucfirst(str_replace('_',' ', $type)) . ' update';
        $data = [];
        if (!empty($n['data'])) {
            $d = json_decode($n['data'], true);
            if (is_array($d)) $data = $d;
        }
        // System/admin messages: enforce "System: <text>"
        if (($type === 'system' || $type === 'admin_message') && isset($data['text'])) {
            $msg = 'System: ' . (string)$data['text'];
        } elseif ($type === 'booking_status_changed' && isset($data['status'], $data['booking_id'])) {
            $msg = "Booking #{$data['booking_id']} status: ".ucfirst(str_replace('_',' ',$data['status']));
        } elseif ($type === 'booking_created' && isset($data['booking_id'])) {
            $msg = "Booking #{$data['booking_id']} was created.";
        } elseif ($type === 'payment_recorded' && isset($data['amount'])) {
            $msg = 'Payment received: ₱'.number_format((float)$data['amount'],2);
                } elseif ($type === 'service_rejected') {
                    $reason = isset($data['reason']) && $data['reason'] !== '' ? (string)$data['reason'] : '';
                    $title  = isset($data['title']) && $data['title'] !== '' ? (string)$data['title'] : '';
                    $msg = 'Service Approval: Rejected' . ($reason !== '' ? ' — ' . $reason : '') . ($title !== '' ? ' (' . $title . ')' : '');
                } elseif ($type === 'service_approved') {
                    $title  = isset($data['title']) && $data['title'] !== '' ? (string)$data['title'] : '';
                    $msg = 'Service Approval: Approved' . ($title !== '' ? ' — ' . $title : '');
        }
        $notifications[] = [
            'id' => (int)$n['notification_id'],
            'message' => $msg,
            'time' => date('M d, Y g:i A', strtotime($n['created_at'] ?? date('Y-m-d H:i:s'))),
            'unread' => empty($n['read_at']),
        ];
    }
} catch (Throwable $e) {
    // Fallback: no notifications
}

// Unread inbox messages count for sidebar badge
$unreadCount = 0;
try { $unreadCount = (int)$db->countUnreadMessages($uid); } catch (Throwable $e) { $unreadCount = 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Hive - <?php echo htmlspecialchars($client['name']); ?> Profile</title>
    
    <!-- Tailwind CSS CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.1/tailwind.min.css">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .5; }
        }
        
        .sidebar { 
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
        }
        .sidebar.open { transform: translateX(0); }
        
        @media (min-width: 1024px) {
            .sidebar { transform: translateX(0) !important; }
        }
        
        @media (max-width: 1024px) {
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 30;
                top: 4rem;
            }
            .sidebar-overlay.active {
                display: block;
            }
        }
        
        .dropdown { display: none; opacity: 0; }
        .dropdown.active { display: block; animation: slideDown 0.2s ease-out forwards; }
        
        .sidebar-item {
            transition: all 0.2s ease;
        }
        
        .sidebar-item.active {
            background: linear-gradient(to right, #f59e0b, #f97316);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.3);
        }
        
        .sidebar-item:not(.active):hover {
            background: rgba(245, 158, 11, 0.1);
            transform: translateX(4px);
        }
        
        .notification-badge {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        .notification-item {
            transition: all 0.2s ease;
        }
        
        .notification-item:hover {
            background: rgba(245, 158, 11, 0.1);
        }
        
        .fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .scale-in {
            animation: scaleIn 0.4s ease-out forwards;
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(245, 158, 11, 0.15);
        }
        
        .booking-row {
            transition: all 0.2s ease;
        }
        
        .booking-row:hover {
            background: rgba(251, 191, 36, 0.05);
            transform: translateX(2px);
        }
        
        .review-card {
            transition: all 0.3s ease;
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .review-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(245, 158, 11, 0.15);
        }
        
        .tab-button {
            transition: all 0.2s ease;
            position: relative;
        }
        
        .tab-button.active {
            color: #f59e0b;
        }
        
        .tab-button .tab-indicator {
            display: none;
            height: 3px;
            background: linear-gradient(to right, #f59e0b, #f97316);
            border-radius: 999px;
        }
        
        .tab-button.active .tab-indicator {
            display: block;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-completed {
            background: #dcfce7;
            color: #15803d;
        }
        
        .status-ongoing {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 via-amber-50/30 to-orange-50/30">

    <!-- Sidebar Overlay (Mobile) -->
    <div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>

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
                        <button onclick="toggleNotifications()" class="relative p-2 hover:bg-amber-100 rounded-full transition-colors">
                            <i data-lucide="bell" class="w-5 h-5 text-gray-700"></i>
                            <span class="notification-badge absolute top-1 right-1 w-2 h-2 bg-orange-500 rounded-full"></span>
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
                            </div>
                        </div>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <button onclick="toggleProfileDropdown()" class="flex items-center gap-3 px-3 py-2 hover:bg-amber-50 rounded-full transition-colors">
                            <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-8 h-8 rounded-full border-2 border-amber-400 object-cover">
                            <span class="hidden md:block text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                            <svg id="dropdown-arrow" class="w-4 h-4 text-gray-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <div id="profile-dropdown" class="dropdown absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden">
                            <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border-b border-amber-200">
                                <div class="flex items-center gap-3">
                                    <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                    <div>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="py-2">
                                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="user" class="w-5 h-5 text-amber-600"></i>
                                    <span>View Dashboard</span>
                                </a>
                                
                                <a href="settings.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
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
                <a href="inbox.php" class="sidebar-item flex items-center justify-between px-4 py-3 rounded-lg text-gray-300" data-name="inbox">
                    <div class="flex items-center gap-3">
                        <i data-lucide="inbox" class="w-5 h-5"></i>
                        <span class="font-medium tracking-wide">Inbox</span>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <span id="inbox-count-badge" class="px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold"><?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?></span>
                    <?php endif; ?>
                </a>

                <a href="feed.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Browse Services</span>
                </a>

                <a href="profile.php" class="sidebar-item active flex items-center gap-3 px-4 py-3 rounded-lg">
                    <i data-lucide="user" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">My Profile</span>
                </a>


                <div class="my-2 border-t border-white/10"></div>
            </nav>

            <!-- Bottom Section -->
            <div class="px-3 py-4 border-t border-white/10 space-y-1">
                <a href="settings.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Settings</span>
                </a>
                <a href="helpsupport.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="help-circle" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Help & Support</span>
                </a>
                <a href="logout.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-red-400 hover:bg-red-500/10">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Logout</span>
                </a>
            </div>

            <!-- Bee Icon -->
            <div class="absolute bottom-4 right-4 text-2xl opacity-20">🐝</div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 transition-all duration-300 lg:ml-64">
            <div class="min-h-screen">
                <!-- Cover Image -->
                <div class="relative h-48 bg-gradient-to-br from-amber-400 to-orange-500 overflow-hidden">
                    <img src="<?php echo htmlspecialchars($client['cover_image']); ?>" alt="Cover" class="w-full h-full object-cover opacity-40">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent"></div>
                </div>

                <!-- Profile Header -->
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="relative -mt-16 mb-8">
                        <div class="bg-white rounded-2xl shadow-xl border border-amber-200 p-6">
                            <div class="flex flex-col md:flex-row gap-6 items-start md:items-center">
                                <!-- Avatar -->
                                <div class="relative">
                                    <img src="<?php echo htmlspecialchars($client['avatar']); ?>" alt="<?php echo htmlspecialchars($client['name']); ?>" class="w-32 h-32 rounded-full border-4 border-white shadow-lg object-cover">
                                    <?php if (!empty($client['verified'])): ?>
                                        <div class="absolute bottom-2 right-2 bg-gradient-to-br from-amber-500 to-orange-500 rounded-full p-2 shadow-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-white"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Info -->
                                <div class="flex-1">
                                    <div class="flex items-start justify-between flex-wrap gap-4">
                                        <div>
                                            <div class="flex items-center gap-2 mb-1">
                                                <h1 class="text-gray-900"><?php echo htmlspecialchars($client['name']); ?></h1>
                                                <?php if ($client['verified']): ?>
                                                    <span class="flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-medium rounded-full">
                                                        <i data-lucide="shield-check" class="w-3 h-3"></i>
                                                        Verified Client
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center gap-4 text-sm text-gray-600 mb-2">
                                                <div class="flex items-center gap-1">
                                                    <i data-lucide="mail" class="w-4 h-4 text-amber-600"></i>
                                                    <span><?php echo htmlspecialchars($client['email']); ?></span>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <i data-lucide="map-pin" class="w-4 h-4 text-amber-600"></i>
                                                    <span><?php echo htmlspecialchars($client['location']); ?></span>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1 text-sm text-gray-600">
                                                <i data-lucide="calendar" class="w-4 h-4 text-amber-600"></i>
                                                <span>Member since <?php echo date('M Y', strtotime($client['member_since'])); ?></span>
                                            </div>
                                        </div>

                                        <button onclick="editProfile()" class="flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 shadow-md hover:shadow-lg transition-all">
                                            <i data-lucide="edit" class="w-5 h-5"></i>
                                            <span class="font-medium">Edit Profile</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Stats -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 pt-6 border-t border-amber-200">
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-blue-50 to-blue-100/50 rounded-lg border border-blue-200">
                                    <div class="flex items-center justify-center mb-2">
                                        <i data-lucide="shopping-bag" class="w-6 h-6 text-blue-600"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo number_format($client['total_bookings']); ?></div>
                                    <div class="text-sm text-gray-600">Total Bookings</div>
                                </div>
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-orange-50 to-orange-100/50 rounded-lg border border-orange-200">
                                    <div class="flex items-center justify-center mb-2">
                                        <i data-lucide="loader" class="w-6 h-6 text-orange-600"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo number_format($client['active_services']); ?></div>
                                    <div class="text-sm text-gray-600">Active Services</div>
                                </div>
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-green-50 to-green-100/50 rounded-lg border border-green-200">
                                    <div class="flex items-center justify-center mb-2">
                                        <i data-lucide="check-circle" class="w-6 h-6 text-green-600"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo number_format($client['completed_services']); ?></div>
                                    <div class="text-sm text-gray-600">Completed</div>
                                </div>
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-purple-50 to-purple-100/50 rounded-lg border border-purple-200">
                                    <div class="flex items-center justify-center mb-2">
                                        <i data-lucide="wallet" class="w-6 h-6 text-purple-600"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-gray-900 mb-1">₱<?php echo number_format($client['money_spent'], 2); ?></div>
                                    <div class="text-sm text-gray-600">Money Spent</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content Grid -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                        <!-- Left Column (Profile Info) -->
                        <div class="space-y-6">
                            <!-- About -->
                            <div class="bg-white rounded-2xl shadow-lg border border-amber-200 p-6">
                                <div class="flex items-center gap-2 mb-4">
                                    <i data-lucide="user-circle" class="w-5 h-5 text-amber-600"></i>
                                    <h3 class="text-gray-900">About Me</h3>
                                </div>
                                <p class="text-gray-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($client['bio'])); ?></p>
                            </div>

                            <!-- Trust & Safety -->
                            <div class="bg-white rounded-2xl shadow-lg border border-amber-200 p-6">
                                <div class="flex items-center gap-2 mb-4">
                                    <i data-lucide="shield-check" class="w-5 h-5 text-green-600"></i>
                                    <h3 class="text-gray-900">Trust & Safety</h3>
                                </div>
                                <div class="space-y-3">
                                    <div class="flex items-center gap-3 opacity-100 <?php echo !empty($client['badges']['identity']) ? '' : 'opacity-50'; ?>">
                                        <div class="p-2 bg-green-100 rounded-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i>
                                        </div>
                                        <span class="text-sm text-gray-700">Identity Verified</span>
                                    </div>
                                    <div class="flex items-center gap-3 <?php echo !empty($client['badges']['payment']) ? '' : 'opacity-50'; ?>">
                                        <div class="p-2 bg-green-100 rounded-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i>
                                        </div>
                                        <span class="text-sm text-gray-700">Payment Verified</span>
                                    </div>
                                    <div class="flex items-center gap-3 <?php echo !empty($client['badges']['email']) ? '' : 'opacity-50'; ?>">
                                        <div class="p-2 bg-green-100 rounded-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i>
                                        </div>
                                        <span class="text-sm text-gray-700">Email Verified</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column (Bookings & Reviews) -->
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Tabs -->
                            <div class="bg-white rounded-2xl shadow-lg border border-amber-200">
                                <div class="flex border-b border-amber-200">
                                    <button onclick="switchTab('bookings')" class="tab-button active flex-1 px-6 py-4 text-sm font-medium hover:bg-amber-50 transition-colors">
                                        My Bookings
                                        <div class="tab-indicator mt-2"></div>
                                    </button>
                                    <button onclick="switchTab('reviews')" class="tab-button flex-1 px-6 py-4 text-sm font-medium text-gray-600 hover:bg-amber-50 transition-colors">
                                        Reviews Written
                                        <div class="tab-indicator mt-2"></div>
                                    </button>
                                </div>

                                <!-- Tab Content -->
                                <div class="p-6">
                                    <!-- Bookings Tab -->
                                    <div id="bookings-tab" class="tab-content">
                                        <div class="overflow-x-auto">
                                            <table class="w-full">
                                                <thead>
                                                    <tr class="border-b border-amber-200">
                                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">#</th>
                                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Service</th>
                                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Freelancer</th>
                                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Status</th>
                                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Price</th>
                                                        <th class="text-left py-3 px-4 text-sm font-medium text-gray-700">Date</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($bookings as $index => $booking): ?>
                                                        <tr class="booking-row border-b border-amber-100">
                                                            <td class="py-4 px-4 text-sm text-gray-900"><?php echo $index + 1; ?></td>
                                                            <td class="py-4 px-4">
                                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                                                            </td>
                                                            <td class="py-4 px-4">
                                                                <div class="flex items-center gap-2">
                                                                    <img src="<?php echo htmlspecialchars($booking['freelancer_avatar']); ?>" alt="<?php echo htmlspecialchars($booking['freelancer_name']); ?>" class="w-8 h-8 rounded-full border-2 border-amber-400 object-cover">
                                                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($booking['freelancer_name']); ?></span>
                                                                </div>
                                                            </td>
                                                            <td class="py-4 px-4">
                                                                <span class="status-badge status-<?php echo strtolower($booking['status']); ?>">
                                                                    <?php echo htmlspecialchars($booking['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td class="py-4 px-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($booking['price']); ?></td>
                                                            <td class="py-4 px-4 text-sm text-gray-600">
                                                                <?php echo date('M d, Y', strtotime($booking['date_booked'])); ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Reviews Tab -->
                                    <div id="reviews-tab" class="tab-content hidden">
                                        <div class="space-y-4">
                                            <?php foreach ($reviewsWritten as $index => $review): ?>
                                                <div class="review-card p-6 bg-white rounded-xl border border-amber-200" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                                    <div class="flex items-start gap-4 mb-4">
                                                        <img src="<?php echo htmlspecialchars($review['freelancer_avatar']); ?>" alt="<?php echo htmlspecialchars($review['freelancer_name']); ?>" class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                                        <div class="flex-1">
                                                            <div class="flex items-center justify-between mb-1">
                                                                <h5 class="font-medium text-gray-900">Review for <?php echo htmlspecialchars($review['freelancer_name']); ?></h5>
                                                                <span class="text-xs text-gray-500"><?php echo htmlspecialchars($review['date']); ?></span>
                                                            </div>
                                                            <div class="flex items-center gap-2 mb-1">
                                                                <div class="flex">
                                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                        <i data-lucide="star" class="w-4 h-4 <?php echo $i <= $review['rating'] ? 'fill-amber-400 text-amber-400' : 'text-gray-300'; ?>"></i>
                                                                    <?php endfor; ?>
                                                                </div>
                                                                <span class="text-sm font-medium text-gray-900"><?php echo number_format($review['rating'], 1); ?></span>
                                                            </div>
                                                            <p class="text-xs text-amber-600 font-medium">Service: <?php echo htmlspecialchars($review['service_name']); ?></p>
                                                        </div>
                                                    </div>
                                                    <p class="text-gray-700 leading-relaxed"><?php echo htmlspecialchars($review['comment']); ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        lucide.createIcons();

        let sidebarOpen = false;
        let currentTab = 'bookings';

        function toggleSidebar() {
            sidebarOpen = !sidebarOpen;
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            
            if (sidebarOpen) {
                sidebar.classList.add('open');
                overlay.classList.add('active');
            } else {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            }
        }

        function toggleNotifications() {
            const dropdown = document.getElementById('notifications-dropdown');
            const profileDropdown = document.getElementById('profile-dropdown');
            
            profileDropdown.classList.remove('active');
            dropdown.classList.toggle('active');
        }

        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profile-dropdown');
            const notifDropdown = document.getElementById('notifications-dropdown');
            const arrow = document.getElementById('dropdown-arrow');
            
            notifDropdown.classList.remove('active');
            dropdown.classList.toggle('active');
            arrow.style.transform = dropdown.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0deg)';
        }

        document.addEventListener('click', function(event) {
            const profileDropdown = document.getElementById('profile-dropdown');
            const notifDropdown = document.getElementById('notifications-dropdown');
            const clickedButton = event.target.closest('button');
            
            if (!clickedButton || (!clickedButton.onclick && !clickedButton.getAttribute('onclick'))) {
                profileDropdown.classList.remove('active');
                notifDropdown.classList.remove('active');
                document.getElementById('dropdown-arrow').style.transform = 'rotate(0deg)';
            }
        });

        function markAllAsRead() {
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.classList.remove('bg-blue-50/50');
                const dot = item.querySelector('.bg-blue-500');
                if (dot) dot.remove();
            });
            
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.remove();
        }

        function switchTab(tab) {
            currentTab = tab;
            
            // Update tab buttons
            const tabs = document.querySelectorAll('.tab-button');
            tabs.forEach(t => {
                t.classList.remove('active');
                t.classList.add('text-gray-600');
            });
            event.target.closest('.tab-button').classList.add('active');
            event.target.closest('.tab-button').classList.remove('text-gray-600');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById(tab + '-tab').classList.remove('hidden');
            
            // Reinitialize icons
            setTimeout(() => lucide.createIcons(), 100);
        }

        function editProfile() {
            window.location.href = 'edit_profile.php';
        }

        // Close sidebar on desktop resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                sidebarOpen = false;
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebar-overlay').classList.remove('active');
            }
        });

        // Initialize icons
        setTimeout(() => lucide.createIcons(), 100);

        // Live-refresh unread inbox badge (only if logged in)
        const CURRENT_USER_ID = <?php echo (int)$uid; ?>;
        async function refreshUnreadBadge(){
            if (!CURRENT_USER_ID) return;
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
        refreshUnreadBadge();
        setInterval(refreshUnreadBadge, 5000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshUnreadBadge(); });
    </script>

</body>
</html>
