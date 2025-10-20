<?php
session_start();
require_once __DIR__ . '/database.php';

$db = new database();

// Guard: require login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];
$u = $db->getUser($uid);
$userType = strtolower($u['user_type'] ?? '');

// Current user info for header/profile
$currentUser = [
    'name'   => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'User',
    'email'  => $u['email'] ?? '',
    'avatar' => ($u['profile_picture'] ?? '') ?: 'img/profile_icon.webp',
];

// Build conversations from DB
$rows = $db->listConversationsWithUnread($uid, 50, 0);
$conversations = [];
foreach ($rows as $r) {
    $isClient = ((int)$r['client_id'] === $uid);
    $otherName = $isClient
        ? trim(($r['free_first'] ?? '') . ' ' . ($r['free_last'] ?? ''))
        : trim(($r['client_first'] ?? '') . ' ' . ($r['client_last'] ?? ''));
    $otherPic = $isClient ? ($r['free_pic'] ?? '') : ($r['client_pic'] ?? '');
    $convId   = (int)$r['conversation_id'];

    // For freelancers, check latest booking (pending/accepted) with this counterpart
    $bookingCtx = null;
    $freelancerMethods = null;
    if ($userType === 'freelancer') {
        $clientId = (int)$r['client_id'];
        $freelancerId = (int)$r['freelancer_id'];
        // Only if current user matches freelancer side
        if ($freelancerId === $uid) {
            $pb = $db->getLatestBookingBetweenUsers($clientId, $freelancerId, ['pending','accepted','in_progress','delivered']);
            if ($pb) {
                $bookingCtx = [
                    'booking_id' => (int)$pb['booking_id'],
                    'service_title' => $pb['service_title'] ?? ($pb['title_snapshot'] ?? 'Service'),
                    'total_amount' => (float)($pb['total_amount'] ?? 0),
                    'created_at' => $pb['created_at'] ?? null,
                    'status' => strtolower($pb['status'] ?? ''),
                    'payment_method' => $pb['payment_method'] ?? null,
                    'payment_terms_status' => $pb['payment_terms_status'] ?? null,
                    'downpayment_percent' => isset($pb['downpayment_percent']) ? (float)$pb['downpayment_percent'] : null,
                    'paid_upfront_amount' => isset($pb['paid_upfront_amount']) ? (float)$pb['paid_upfront_amount'] : 0,
                    'total_paid_amount' => isset($pb['total_paid_amount']) ? (float)$pb['total_paid_amount'] : 0,
                ];
            }
        }
    } elseif ($userType === 'client') {
        // For clients, also collect booking and freelancer payment methods
        $clientId = (int)$r['client_id'];
        $freelancerId = (int)$r['freelancer_id'];
        if ($clientId === $uid) {
            $pb = $db->getLatestBookingBetweenUsers($clientId, $freelancerId, ['accepted','in_progress','delivered']);
            if ($pb) {
                $bookingCtx = [
                    'booking_id' => (int)$pb['booking_id'],
                    'service_title' => $pb['service_title'] ?? ($pb['title_snapshot'] ?? 'Service'),
                    'total_amount' => (float)($pb['total_amount'] ?? 0),
                    'created_at' => $pb['created_at'] ?? null,
                    'status' => strtolower($pb['status'] ?? ''),
                    'payment_method' => $pb['payment_method'] ?? null,
                    'payment_terms_status' => $pb['payment_terms_status'] ?? null,
                    'downpayment_percent' => isset($pb['downpayment_percent']) ? (float)$pb['downpayment_percent'] : null,
                    'paid_upfront_amount' => isset($pb['paid_upfront_amount']) ? (float)$pb['paid_upfront_amount'] : 0,
                    'total_paid_amount' => isset($pb['total_paid_amount']) ? (float)$pb['total_paid_amount'] : 0,
                ];
                // Payment methods of freelancer
                try { $freelancerMethods = $db->listFreelancerPaymentMethods($freelancerId,true); } catch (Throwable $e) { $freelancerMethods = []; }
            }
        }
    }

    // Fetch latest messages (chronological for display)
    $msgsRaw = $db->getConversationMessages($convId, 50, 0); // newest first
    $msgsRaw = array_reverse($msgsRaw);
    $msgs = [];
    foreach ($msgsRaw as $m) {
        $isMe = ((int)($m['sender_id'] ?? 0) === $uid);
        $bodyStr = (string)($m['body'] ?? '');
        $mtype = strtolower((string)($m['type'] ?? ($m['message_type'] ?? ($m['meta_type'] ?? ''))));
        $isSystem = empty($m['sender_id'])
            || (!empty($m['is_system']) && (int)$m['is_system'] === 1)
            || in_array($mtype, ['system','status','payment','event'], true)
            || (strpos(ltrim($bodyStr), 'System:') === 0);

        $senderName = $isSystem
            ? 'System'
            : ($isMe ? 'You' : trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')));

        $attachments = null;
        if (!empty($m['attachments'])) {
            $decoded = json_decode($m['attachments'], true);
            if (is_array($decoded)) $attachments = $decoded;
        }
        $msgs[] = [
            'sender' => $senderName,
            'content' => $bodyStr,
            'time' => isset($m['created_at']) ? date('g:i A', strtotime($m['created_at'])) : '',
            'created_at' => $m['created_at'] ?? null,
            'attachments' => $attachments,
            'isCurrentUser' => (!$isSystem && $isMe),
            'isSystem' => $isSystem,
        ];
    }

    $conversations[$convId] = [
        'client_id' => (int)$r['client_id'],
        'freelancer_id' => (int)$r['freelancer_id'],
        'sender' => $otherName ?: 'Conversation',
        'sender_avatar' => $otherPic ?: 'img/profile_icon.webp',
        'subject' => 'Chat with ' . ($otherName ?: 'User'),
        'category' => (!empty($r['booking_id']) || $bookingCtx) ? 'Booking' : 'General',
        'preview' => $r['last_body'] ?? 'No messages yet.',
        'timestamp' => $r['last_message_at'] ?? date('Y-m-d H:i:s'),
        'unread' => ((int)($r['unread_count'] ?? 0) > 0),
        'messages' => $msgs,
        'booking_ctx' => $bookingCtx,
        'freelancer_methods' => $freelancerMethods,
    ];
}

// Unread count badge
$unreadCount = $db->countUnreadMessages($uid);

// Determine which conversation to open initially
$requestedConvId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$activeConvId = 0;
if ($requestedConvId && isset($conversations[$requestedConvId])) {
    $activeConvId = $requestedConvId;
} elseif ($requestedUserId) {
    // Find a conversation with the requested other user
    foreach ($conversations as $cid => $c) {
        $otherIsClient = ($c['client_id'] === $requestedUserId);
        $otherIsFreelancer = ($c['freelancer_id'] === $requestedUserId);
        if ($otherIsClient || $otherIsFreelancer) { $activeConvId = (int)$cid; break; }
    }
}
if (!$activeConvId && !empty($conversations)) {
    $activeConvId = (int)array_key_first($conversations);
}

// Notifications (latest 10 from DB)
$notifications = [];
try {
    $pdo = $db->opencon();
    $stN = $pdo->prepare("SELECT notification_id,type,data,created_at,read_at FROM notifications WHERE user_id=:u ORDER BY created_at DESC LIMIT 10");
    $stN->execute([':u'=>$uid]);
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
            $msg = 'Payment received: ₱'.number_format((float)$data['amount'],2);
        } elseif ($type === 'service_rejected') {
            $reason = '';
            if (isset($data['reason'])) {
                $reason = (string)$data['reason'];
            } elseif (isset($data['message'])) { // fallback if stored as message
                $reason = (string)$data['message'];
            }
            $title = '';
            if (isset($data['title'])) {
                $title = (string)$data['title'];
            } elseif (isset($data['service_title'])) {
                $title = (string)$data['service_title'];
            }
            $msg = 'Service Approval: Rejected' . ($reason !== '' ? ' — ' . $reason : '') . ($title !== '' ? " ({$title})" : '');
        } elseif ($type === 'service_approved') {
            $title = '';
            if (isset($data['title'])) {
                $title = (string)$data['title'];
            } elseif (isset($data['service_title'])) {
                $title = (string)$data['service_title'];
            }
            $msg = 'Service Approval: Approved' . ($title !== '' ? ' — ' . $title : '');
        } elseif ($type === 'review_prompt') {
            $title = isset($data['service_title']) ? (string)$data['service_title'] : 'your recent booking';
            $msg = 'Please leave a review for ' . $title . '.';
        }
        $notifications[] = [
            'id' => (int)$n['notification_id'],
            'message' => $msg,
            'time' => date('M d, Y g:i A', strtotime($n['created_at'] ?? date('Y-m-d H:i:s'))),
            'unread' => empty($n['read_at']),
        ];
    }
} catch (Throwable $e) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Hive - Inbox</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        @keyframes fadeInLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes ping {
            75%, 100% { transform: scale(2); opacity: 0; }
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
        
        .message-item { 
            transition: all 0.2s ease;
            animation: fadeInLeft 0.4s ease-out forwards;
        }
        .message-item:hover { 
            background-color: rgba(251, 191, 36, 0.08);
            transform: translateX(4px);
        }
        .message-item.active {
            background: linear-gradient(to right, rgba(251, 191, 36, 0.2), rgba(249, 115, 22, 0.2));
            border-left: 4px solid #f59e0b;
            box-shadow: 0 2px 4px rgba(245, 158, 11, 0.1);
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
        
        .unread-dot-ping {
            animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite;
        }
        
        .chat-bubble { 
            animation: fadeInUp 0.3s ease-out forwards;
            opacity: 0;
        }
        
        .filter-tab {
            transition: all 0.2s ease;
            position: relative;
        }
        
        .filter-tab:hover {
            background: rgba(251, 191, 36, 0.05);
        }
        
        .filter-tab .active-indicator {
            display: none;
            height: 2px;
            background: linear-gradient(to right, #f59e0b, #f97316);
        }
        
        .filter-tab.active .active-indicator {
            display: block;
        }
        
        .filter-tab.active {
            color: #f59e0b;
        }
        
        .star-button.starred {
            color: #f59e0b;
        }
        
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        .empty-state {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .empty-icon {
            animation: bounce 2s ease-in-out infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .send-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Collapsible client payment bar: keep header visible, hide form when collapsed */
        #client-pay-bar.collapsed #client-payment-form { display: none; }
        #client-pay-bar.collapsed { padding-bottom: 0.75rem; }

        /* Attachment preview styles */
        #attach-preview { margin-top: 0.5rem; }
        .attach-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; }
        @media (min-width: 640px) { .attach-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .attach-item { position: relative; border-radius: 0.5rem; overflow: hidden; }
        .attach-item img { width: 100%; height: 100%; object-fit: cover; cursor: zoom-in; }
        .attach-remove { position: absolute; top: 0.25rem; right: 0.25rem; background: rgba(255,255,255,0.9); border-radius: 9999px; padding: 0.25rem; line-height: 0; }
        .attach-remove:hover { background: rgba(254, 226, 226, 0.95); }
    /* Limit preview height and enable wheel scroll */
    #attach-preview .attach-grid { max-height: 220px; overflow-y: auto; padding-right: 4px; }

        /* Drag-and-drop highlight on reply compose */
        #reply-compose.dropzone-active {
            outline: 2px dashed #f59e0b;
            outline-offset: 6px;
            background-color: rgba(251, 191, 36, 0.06);
        }
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
                <a href="inbox.php" class="sidebar-item active flex items-center justify-between px-4 py-3 rounded-lg">
                    <div class="flex items-center gap-3">
                        <i data-lucide="inbox" class="w-5 h-5"></i>
                        <span class="font-medium tracking-wide">Inbox</span>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <span class="px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold"><?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?></span>
                    <?php endif; ?>
                </a>


                <a href="feed.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Browse Services</span>
                </a>

                <a href="profile.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
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

            <!-- Bee Icon -->
            <div class="absolute bottom-4 right-4 text-2xl opacity-20">🐝</div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 transition-all duration-300 lg:ml-64">
            <div class="h-[calc(100vh-4rem)] flex">
                <!-- Message List -->
                <div class="w-full lg:w-96 border-r border-amber-200 bg-white flex flex-col shadow-lg">
                    <!-- Header -->
                    <div class="p-6 border-b border-amber-200 bg-gradient-to-br from-amber-50 to-orange-50/50">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-gray-900">Inbox</h2>
                                <?php if ($unreadCount > 0): ?>
                                    <p class="text-sm text-gray-600"><?php echo $unreadCount; ?> unread message<?php echo $unreadCount > 1 ? 's' : ''; ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <button class="flex items-center gap-2 px-3 py-2 border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 hover:border-amber-400 transition-colors">
                                    <i data-lucide="filter" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Search -->
                        <div class="relative">
                            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                            <input type="text" id="search-input" placeholder="Search messages..." class="w-full pl-10 pr-4 py-2 bg-white border border-amber-200 rounded-lg text-base focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20" oninput="filterMessages()">
                        </div>
                    </div>

                    <!-- Filter Tabs -->
                    <div class="flex border-b border-amber-200 bg-white px-2">
                        <button onclick="setFilter('all')" class="filter-tab active flex-1 py-3 text-sm">
                            All
                            <div class="active-indicator absolute bottom-0 left-0 right-0"></div>
                        </button>
                        <button onclick="setFilter('unread')" class="filter-tab flex-1 py-3 text-sm">
                            Unread (<?php echo $unreadCount; ?>)
                            <div class="active-indicator absolute bottom-0 left-0 right-0"></div>
                        </button>
                    </div>

                    <!-- Message List -->
                    <div id="message-list" class="flex-1 overflow-y-auto">
                        <?php foreach ($conversations as $id => $conv): ?>
                            <div class="message-item <?php echo ((int)$id === (int)$activeConvId) ? 'active' : ''; ?> p-4 border-b border-amber-100 cursor-pointer relative group"
                                 data-id="<?php echo (int)$id; ?>"
                                 data-sender="<?php echo strtolower(htmlspecialchars($conv['sender'])); ?>"
                                 data-subject="<?php echo strtolower(htmlspecialchars($conv['subject'])); ?>"
                                 data-preview="<?php echo strtolower(htmlspecialchars($conv['preview'])); ?>"
                                 data-unread="<?php echo $conv['unread'] ? '1' : '0'; ?>"
                                 onclick="selectMessage(<?php echo (int)$id; ?>)">
                                
                                <?php if ($conv['unread']): ?>
                                    <div class="absolute top-4 left-2 w-2 h-2 bg-blue-500 rounded-full">
                                        <span class="absolute inset-0 w-2 h-2 bg-blue-500 rounded-full unread-dot-ping"></span>
                                    </div>
                                <?php endif; ?>

                                <div class="flex items-start gap-3 ml-2">
                                    <img src="<?php echo htmlspecialchars($conv['sender_avatar']); ?>" alt="<?php echo htmlspecialchars($conv['sender']); ?>" class="w-10 h-10 rounded-full border-2 border-amber-400 object-cover">

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between mb-1">
                                            <h4 class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($conv['sender']); ?></h4>
                                            <span class="conv-time text-xs text-gray-500 ml-2 whitespace-nowrap" data-ts="<?php echo htmlspecialchars($conv['timestamp']); ?>">
                                                <!-- time filled by JS -->
                                            </span>
                                        </div>

                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-xs rounded-full font-medium">
                                                <?php echo htmlspecialchars($conv['category']); ?>
                                            </span>
                                        </div>

                                        <p class="text-sm text-gray-600 line-clamp-2 leading-relaxed">
                                            <?php echo htmlspecialchars($conv['preview']); ?>
                                        </p>
                                    </div>

                                    <button class="opacity-0 group-hover:opacity-100 p-2 hover:bg-amber-100 rounded-lg transition-all">
                                        <i data-lucide="more-vertical" class="w-4 h-4 text-gray-600"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Message View -->
                <?php
                    $firstConv = null; $firstConvId = null; $hasConv = !empty($conversations);
                    if ($hasConv) { $firstConvId = (int)$activeConvId; $firstConv = $conversations[$firstConvId] ?? reset($conversations); }
                ?>
                <div id="message-view-container" class="flex-1 flex flex-col bg-white <?php echo $hasConv ? '' : 'hidden'; ?>">
                    <!-- Header -->
                    <div class="p-6 border-b border-amber-200 bg-gradient-to-br from-amber-50 to-orange-50/50">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start gap-4">
                                <img id="message-view-avatar" src="<?php echo htmlspecialchars($hasConv ? $firstConv['sender_avatar'] : 'img/profile_icon.webp'); ?>" alt="<?php echo htmlspecialchars($hasConv ? $firstConv['sender'] : ''); ?>" class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                <div>
                                    <h2 id="message-view-subject" class="text-xl font-bold text-gray-900 mb-1"><?php echo htmlspecialchars($hasConv ? $firstConv['subject'] : ''); ?></h2>
                                    <div class="flex items-center gap-3 text-sm text-gray-600">
                                        <span>From: <span id="message-view-sender"><?php echo htmlspecialchars($hasConv ? $firstConv['sender'] : ''); ?></span></span>
                                        <span>•</span>
                                        <div class="flex items-center gap-1">
                                            <i data-lucide="clock" class="w-3 h-3"></i>
                                            <span id="message-view-time" data-ts="<?php echo htmlspecialchars($hasConv ? $firstConv['timestamp'] : ''); ?>">&nbsp;</span>
                                        </div>
                                    </div>
                                    <span id="message-view-category" class="inline-block mt-2 px-3 py-1 bg-amber-100 text-amber-700 text-xs font-medium rounded-full"><?php echo htmlspecialchars($hasConv ? $firstConv['category'] : ''); ?></span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <button onclick="toggleStar()" class="star-button p-2 hover:bg-amber-100 rounded-lg transition-colors">
                                    <i data-lucide="star" class="w-4 h-4 text-gray-600"></i>
                                </button>
                                <button class="p-2 hover:bg-amber-100 rounded-lg transition-colors">
                                    <i data-lucide="archive" class="w-4 h-4 text-gray-600"></i>
                                </button>
                                <form id="delete-conv-form" method="POST" action="conversation_delete.php" class="inline">
                                    <input type="hidden" name="conversation_id" id="delete-conv-id" value="<?php echo $hasConv ? (int)$firstConvId : 0; ?>">
                                    <input type="hidden" name="return" value="inbox.php">
                                    <button type="submit" class="p-2 hover:bg-red-100 rounded-lg transition-colors" onclick="return confirm('Delete this conversation from your inbox? This won\'t affect the other user.')">
                                        <i data-lucide="trash-2" class="w-4 h-4 text-red-600"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Booking Action Bar (Freelancer only) -->
                    <?php if ($userType === 'freelancer'): ?>
                    <div id="booking-action-bar" class="hidden px-6 py-4 border-b border-amber-200 bg-amber-50/60">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <i data-lucide="calendar" class="w-5 h-5 text-amber-700"></i>
                                <div>
                                    <div class="text-amber-900 font-medium" id="bab-title">Pending booking</div>
                                    <div class="text-sm text-amber-800" id="bab-subtitle"></div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <!-- Pending: Accept / Reject -->
                                <form id="bab-accept-form" method="POST" action="booking_update.php" class="m-0 hidden">
                                    <input type="hidden" name="booking_id" id="bab-booking-id" value="">
                                    <input type="hidden" name="action" value="accept">
                                    <input type="hidden" name="return" value="inbox.php">
                                    <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">Accept</button>
                                </form>
                                <form id="bab-reject-form" method="POST" action="booking_update.php" class="m-0 hidden">
                                    <input type="hidden" name="booking_id" id="bab-booking-id-rj" value="">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="return" value="inbox.php">
                                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Reject</button>
                                </form>
                                <!-- Accepted: Start work -->
                                <form id="bab-start-form" method="POST" action="booking_update.php" class="m-0 hidden">
                                    <input type="hidden" name="booking_id" id="bab-booking-id-st" value="">
                                    <input type="hidden" name="action" value="start">
                                    <input type="hidden" name="return" value="inbox.php">
                                    <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium">Start</button>
                                </form>
                                <!-- In progress: Deliver -->
                                <form id="bab-deliver-form" method="POST" action="booking_update.php" class="m-0 hidden">
                                    <input type="hidden" name="booking_id" id="bab-booking-id-dv" value="">
                                    <input type="hidden" name="action" value="deliver">
                                    <input type="hidden" name="return" value="inbox.php">
                                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Deliver</button>
                                </form>
                                <!-- Delivered: Complete (freelancer confirms completion) -->
                                <form id="bab-complete-form" method="POST" action="booking_update.php" class="m-0 hidden">
                                    <input type="hidden" name="booking_id" id="bab-booking-id-cp" value="">
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="return" value="inbox.php">
                                    <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">Complete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Client Payment Bar (Client only) -->
                    <?php if ($userType === 'client'): ?>
                    <div id="client-pay-bar" class="hidden px-6 py-4 border-b border-amber-200 bg-amber-50/60">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-start gap-3">
                                <i data-lucide="wallet" class="w-5 h-5 text-amber-700"></i>
                                <div>
                                    <div class="text-amber-900 font-medium" id="cpb-title">Pay your freelancer</div>
                                    <div class="text-sm text-amber-800" id="cpb-subtitle"></div>
                                </div>
                            </div>
                            <div class="flex items-center">
                                <button type="button" id="cpb-toggle" class="p-2 rounded hover:bg-amber-100 text-amber-700"
                                        aria-controls="client-payment-form" aria-expanded="true" title="Hide payment panel">
                                    <span class="sr-only">Toggle payment panel</span>
                                    <i id="cpb-icon-up" data-lucide="chevron-up" class="w-5 h-5"></i>
                                    <i id="cpb-icon-down" data-lucide="chevron-down" class="w-5 h-5 hidden"></i>
                                </button>
                            </div>
                        </div>
                        <form id="client-payment-form" action="payment_actions.php" method="POST" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                            <input type="hidden" name="task" value="make_payment">
                            <input type="hidden" name="conversation_id" id="cpf-conv-id" value="">
                            <input type="hidden" name="booking_id" id="cpf-booking-id" value="">
                            <input type="hidden" name="return" value="inbox.php">
                            <input type="hidden" name="phase" id="cpf-phase" value="">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Channel</label>
                                <select name="pay_method" class="w-full border border-amber-200 rounded-md px-3 py-2">
                                    <option value="gcash">GCash</option>
                                    <option value="paymaya">PayMaya</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Amount (PHP)</label>
                                <input type="text" name="amount" id="cpf-amount" class="w-full border border-amber-200 rounded-md px-3 py-2" placeholder="0.00">
                                <div class="text-xs text-gray-500 mt-1" id="cpf-hint"></div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Freelancer receiving method</label>
                                <select name="receiver_method_id" id="cpf-method" class="w-full border border-amber-200 rounded-md px-3 py-2"></select>
                            </div>
                            <div class="md:col-span-3 flex items-center gap-3">
                                <input type="text" name="reference_code" class="flex-1 border border-amber-200 rounded-md px-3 py-2" placeholder="Reference code (optional)">
                                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium">Pay now</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Messages -->
                    <div id="message-conversation" class="flex-1 overflow-y-auto p-6 space-y-6 bg-gradient-to-br from-gray-50/50 via-amber-50/20 to-orange-50/20">
                        <?php if ($hasConv): foreach ($firstConv['messages'] as $index => $msg): ?>
                            <?php if (!empty($msg['isSystem'])): ?>
                                <div class="chat-bubble flex justify-center">
                                    <div class="max-w-2xl px-4 py-2 bg-amber-50 border border-amber-200 text-amber-900 rounded-full shadow-sm flex items-center gap-2">
                                        <i data-lucide="info" class="w-4 h-4 text-amber-600"></i>
                                        <span class="leading-relaxed text-sm"><?php echo htmlspecialchars($msg['content']); ?></span>
                                        <span class="msg-time text-xs text-amber-700/80 ml-2" data-ts="<?php echo htmlspecialchars($msg['created_at'] ?? $firstConv['timestamp']); ?>"></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="chat-bubble flex gap-4 <?php echo $msg['isCurrentUser'] ? 'flex-row-reverse' : ''; ?>">
                                    <img src="<?php echo $msg['isCurrentUser'] ? htmlspecialchars($currentUser['avatar']) : htmlspecialchars($firstConv['sender_avatar']); ?>" alt="<?php echo htmlspecialchars($msg['sender']); ?>" class="w-10 h-10 rounded-full border-2 border-amber-400 object-cover flex-shrink-0">
                                    <div class="flex-1 max-w-2xl <?php echo $msg['isCurrentUser'] ? 'flex flex-col items-end' : ''; ?>">
                                        <div class="flex items-center gap-2 mb-2 <?php echo $msg['isCurrentUser'] ? 'flex-row-reverse' : ''; ?>">
                                            <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($msg['sender']); ?></span>
                                            <span class="msg-time text-xs text-gray-500" data-ts="<?php echo htmlspecialchars($msg['created_at'] ?? $firstConv['timestamp']); ?>"></span>
                                            <?php if ($msg['isCurrentUser']): ?>
                                                <i data-lucide="check-check" class="w-4 h-4 text-blue-500"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="p-4 <?php echo $msg['isCurrentUser'] ? 'bg-gradient-to-br from-amber-500 to-orange-500 text-white' : 'bg-white border border-amber-200 text-gray-800'; ?> rounded-2xl shadow-sm hover:scale-[1.01] transition-transform">
                                            <p class="leading-relaxed"><?php echo htmlspecialchars($msg['content']); ?></p>
                                            <?php if (!empty($msg['attachments']) && is_array($msg['attachments'])): ?>
                                                <?php foreach ($msg['attachments'] as $att): ?>
                                                    <?php if (($att['type'] ?? '') === 'image'): ?>
                                                        <div class="mt-2">
                                                            <img src="<?php echo htmlspecialchars($att['url']); ?>" alt="attachment" class="chat-image max-w-xs rounded-lg border border-amber-200 cursor-zoom-in" data-full="<?php echo htmlspecialchars($att['url']); ?>">
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; endif; ?>
                    </div>


                    <!-- Reply Section -->
                    <div class="p-6 bg-white border-t border-amber-200">
                        <div class="flex items-start gap-4">
                            <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="You" class="w-10 h-10 rounded-full border-2 border-amber-400 object-cover">

                            <div class="flex-1" id="reply-compose">
                                <textarea id="reply-textarea" placeholder="Type your reply..." class="w-full min-h-24 px-4 py-3 border border-amber-200 rounded-lg text-base focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20 resize-none mb-3" oninput="checkReplyText()"></textarea>

                                <div class="flex items-center justify-between flex-wrap">
                                    <label class="flex items-center gap-2 px-4 py-2 text-gray-700 hover:bg-amber-50 rounded-lg transition-colors cursor-pointer">
                                        <i data-lucide="paperclip" class="w-4 h-4"></i>
                                        <span class="text-sm font-medium">Attach</span>
                                        <input id="image-input" type="file" accept="image/*" multiple class="hidden">
                                    </label>
                                    <span id="attach-indicator" class="ml-2 text-xs text-gray-600 hidden"></span>
                                    <div class="w-full"></div>
                                    <div id="attach-preview" class="hidden w-full">
                                        <div class="flex items-center justify-between text-xs text-gray-600 mb-1">
                                            <span id="attach-count"></span>
                                            <button type="button" id="attach-clear-all" class="px-2 py-1 rounded border border-amber-200 text-amber-700 hover:bg-amber-50">Remove all</button>
                                        </div>
                                        <div class="attach-grid"></div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <button class="flex items-center gap-2 px-4 py-2 border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 hover:border-amber-400 transition-colors">
                                            <i data-lucide="forward" class="w-4 h-4"></i>
                                            <span class="text-sm font-medium">Forward</span>
                                        </button>
                                        <button id="send-button" onclick="sendReply()" disabled class="send-button flex items-center gap-2 px-6 py-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 shadow-md hover:shadow-lg transition-all">
                                            <i data-lucide="send" class="w-4 h-4"></i>
                                            <span class="text-sm font-medium">Send Reply</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div id="empty-state" class="flex-1 <?php echo $hasConv ? 'hidden' : ''; ?> flex-col items-center justify-center bg-gradient-to-br from-amber-50/30 to-orange-50/30 p-8">
                    <div class="empty-state text-center">
                        <div class="empty-icon text-6xl mb-4">✉️</div>
                        <h3 class="text-gray-900 mb-2">No message selected</h3>
                        <p class="text-gray-600">Select a message from the list to view the conversation</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

            <!-- Lightbox Modal -->
            <div id="lightbox" class="fixed inset-0 bg-black/80 hidden items-center justify-center z-50">
                <button id="lightbox-close" class="absolute top-4 right-4 p-2 text-white/80 hover:text-white" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <img id="lightbox-img" src="" alt="attachment" class="max-h-[90vh] max-w-[90vw] rounded-lg shadow-2xl" />
            </div>

            <!-- Leave Review Modal -->
            <div id="review-modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
                <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl border border-amber-200 overflow-hidden">
                    <div class="px-5 py-4 bg-gradient-to-r from-amber-50 to-orange-50 border-b border-amber-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Leave a review</h3>
                        <button id="review-close" class="p-2 rounded hover:bg-amber-100" aria-label="Close">
                            <i data-lucide="x" class="w-5 h-5 text-gray-700"></i>
                        </button>
                    </div>
                    <form id="review-form" class="p-5 space-y-4">
                        <input type="hidden" name="booking_id" id="review-booking-id" value="" />
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
                            <div id="review-stars" class="flex items-center gap-2" role="radiogroup" aria-label="Rating">
                                <!-- JS will render 5 star buttons -->
                            </div>
                            <input type="hidden" name="rating" id="review-rating" value="0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Comment (optional)</label>
                            <textarea name="comment" id="review-comment" rows="4" class="w-full border border-amber-200 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-amber-500/30" placeholder="Share your experience..."></textarea>
                        </div>
                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" id="review-cancel" class="px-4 py-2 rounded border border-gray-300 text-gray-700 hover:bg-gray-100">Cancel</button>
                            <button type="submit" class="px-4 py-2 rounded bg-amber-600 text-white hover:bg-amber-700">Submit</button>
                        </div>
                        <p id="review-error" class="hidden text-sm text-red-600"></p>
                    </form>
                </div>
            </div>

    <script>
        lucide.createIcons();

    let sidebarOpen = false;
    let currentFilter = 'all';
    let currentMessageId = <?php echo $hasConv ? (int)$firstConvId : 0; ?>;
        let isStarred = false;

    // Conversations data from PHP
        const conversations = <?php echo json_encode($conversations); ?>;
    const currentUserAvatar = '<?php echo htmlspecialchars($currentUser['avatar']); ?>';
    const CURRENT_USER_TYPE = '<?php echo htmlspecialchars($userType); ?>';
    // Server timezone offset (e.g., +08:00) so we can interpret naive timestamps correctly
    const SERVER_TZ_OFFSET = '<?php echo date('P'); ?>';
    const messageListEl = document.getElementById('message-list');
    const imageInputEl = document.getElementById('image-input');
    const attachIndicatorEl = document.getElementById('attach-indicator');
    const attachPreviewEl = document.getElementById('attach-preview');
    const attachCountEl = document.getElementById('attach-count');
    const attachClearAllBtn = document.getElementById('attach-clear-all');
    const replyComposeEl = document.getElementById('reply-compose');
    let selectedImages = []; // [{file: File, url: string}]
    const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10MB, match backend

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

        function setFilter(filter) {
            currentFilter = filter;
            const tabs = document.querySelectorAll('.filter-tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.closest('.filter-tab').classList.add('active');
            filterMessages();
        }

        function filterMessages() {
            const searchQuery = document.getElementById('search-input').value.toLowerCase();
            const items = document.querySelectorAll('.message-item');
            
            items.forEach(item => {
                const sender = item.dataset.sender;
                const subject = item.dataset.subject;
                const preview = item.dataset.preview;
                const unread = item.dataset.unread === '1';
                
                const matchesSearch = !searchQuery || sender.includes(searchQuery) || subject.includes(searchQuery) || preview.includes(searchQuery);
                const matchesFilter = currentFilter === 'all' || (currentFilter === 'unread' && unread);
                
                if (matchesSearch && matchesFilter) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        async function selectMessage(id) {
            currentMessageId = id;
            
            // Update active state
            const items = document.querySelectorAll('.message-item');
            items.forEach(item => {
                if (parseInt(item.dataset.id) === id) {
                    item.classList.add('active');
                    // Mark as read
                    item.dataset.unread = '0';
                    const dot = item.querySelector('.absolute.top-4.left-2');
                    if (dot) dot.remove();
                } else {
                    item.classList.remove('active');
                }
            });
            
            // Load conversation content
            loadConversation(id);

            // Update delete form to target this conversation
            const delInput = document.getElementById('delete-conv-id');
            if (delInput) delInput.value = String(id);

            // Mark as read in backend
            try {
                const res = await fetch('api/mark_read.php', {
                    method: 'POST',
                    body: new URLSearchParams({ conversation_id: String(id) }),
                    cache: 'no-store',
                    headers: { 'Cache-Control': 'no-cache' }
                });
                const data = await res.json();
                if (data && data.ok) {
                    // Optionally update a global unread badge elsewhere
                }
            } catch (_) { /* ignore */ }
        }

        function renderMessageBubble(conv, msg, index) {
            const bubble = document.createElement('div');
            const isSystem = !!msg.isSystem || (!msg.isCurrentUser && String(msg.sender).toLowerCase() === 'system');
            if (isSystem) {
                bubble.className = 'chat-bubble flex justify-center';
                bubble.innerHTML = `
                    <div class="max-w-2xl px-4 py-2 bg-amber-50 border border-amber-200 text-amber-900 rounded-full shadow-sm flex items-center gap-2">
                        <i data-lucide="info" class="w-4 h-4 text-amber-600"></i>
                        <span class="leading-relaxed text-sm">${msg.content || ''}</span>
                        <span class="msg-time text-xs text-amber-700/80 ml-2" data-ts="${msg.ts || ''}"></span>
                    </div>`;
                return bubble;
            }

            bubble.className = `chat-bubble flex gap-4 ${msg.isCurrentUser ? 'flex-row-reverse' : ''}`;

            const attachmentsHtml = (msg.attachments && Array.isArray(msg.attachments))
                ? msg.attachments.map(att => {
                    if (att.type === 'image') {
                        return `<div class="mt-2"><img src="${att.url}" alt="attachment" class="chat-image max-w-xs rounded-lg border border-amber-200 cursor-zoom-in" data-full="${att.url}"></div>`;
                    }
                    return '';
                }).join('')
                : '';

            bubble.innerHTML = `
                <img src="${msg.isCurrentUser ? currentUserAvatar : conv.sender_avatar}" alt="${msg.sender}" class="w-10 h-10 rounded-full border-2 border-amber-400 object-cover flex-shrink-0">
                <div class="flex-1 max-w-2xl ${msg.isCurrentUser ? 'flex flex-col items-end' : ''}">
                    <div class="flex items-center gap-2 mb-2 ${msg.isCurrentUser ? 'flex-row-reverse' : ''}">
                        <span class="text-sm font-medium text-gray-900">${msg.sender}</span>
                        <span class="msg-time text-xs text-gray-500" data-ts="${msg.ts || ''}"></span>
                        ${msg.isCurrentUser ? '<i data-lucide="check-check" class="w-4 h-4 text-blue-500"></i>' : ''}
                    </div>
                    <div class="p-4 ${msg.isCurrentUser ? 'bg-gradient-to-br from-amber-500 to-orange-500 text-white' : 'bg-white border border-amber-200 text-gray-800'} rounded-2xl shadow-sm hover:scale-[1.01] transition-transform">
                        ${msg.content ? `<p class="leading-relaxed">${msg.content}</p>` : ''}
                        ${attachmentsHtml}
                    </div>
                </div>`;
            return bubble;
        }

        let latestMessageId = 0;

        async function loadConversation(id) {
            const conv = conversations[id];
            if (!conv) return;
            
            // Update header
            document.getElementById('message-view-avatar').src = conv.sender_avatar;
            document.getElementById('message-view-subject').textContent = conv.subject;
            document.getElementById('message-view-sender').textContent = conv.sender;
            const headerTimeEl = document.getElementById('message-view-time');
            headerTimeEl.dataset.ts = conv.timestamp;
            headerTimeEl.textContent = '';
            document.getElementById('message-view-category').textContent = conv.category;

            // Show booking action bar for pending bookings (freelancer side only)
            const bab = document.getElementById('booking-action-bar');
            if (bab && CURRENT_USER_TYPE === 'freelancer') {
                const bk = conv.booking_ctx || null;
                const titleEl = document.getElementById('bab-title');
                const subEl = document.getElementById('bab-subtitle');
                const idElA = document.getElementById('bab-booking-id');
                const idElR = document.getElementById('bab-booking-id-rj');
                const idElS = document.getElementById('bab-booking-id-st');
                const acceptForm  = document.getElementById('bab-accept-form');
                const rejectForm  = document.getElementById('bab-reject-form');
                const startForm   = document.getElementById('bab-start-form');
                const deliverForm = document.getElementById('bab-deliver-form');
                const completeForm= document.getElementById('bab-complete-form');
                const idElD = document.getElementById('bab-booking-id-dv');
                const idElC = document.getElementById('bab-booking-id-cp');
                if (bk && bk.booking_id) {
                    const amountTxt = `Amount: ₱${Number(bk.total_amount || 0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}`;
                    if (bk.status === 'pending') {
                        if (titleEl) titleEl.textContent = `Pending booking • ${bk.service_title || 'Service'}`;
                        if (subEl) subEl.textContent = amountTxt;
                        if (idElA) idElA.value = String(bk.booking_id);
                        if (idElR) idElR.value = String(bk.booking_id);
                        if (acceptForm) acceptForm.classList.remove('hidden');
                        if (rejectForm) rejectForm.classList.remove('hidden');
                        if (startForm)  startForm.classList.add('hidden');
                        if (deliverForm) deliverForm.classList.add('hidden');
                        if (completeForm) completeForm.classList.add('hidden');
                        bab.classList.remove('hidden');
                    } else if (bk.status === 'accepted') {
                        if (titleEl) titleEl.textContent = `Accepted booking • ${bk.service_title || 'Service'}`;
                        if (subEl) subEl.textContent = amountTxt;
                        if (idElS) idElS.value = String(bk.booking_id);
                        if (acceptForm) acceptForm.classList.add('hidden');
                        if (rejectForm) rejectForm.classList.add('hidden');
                        if (startForm)  startForm.classList.remove('hidden');
                        if (deliverForm) deliverForm.classList.add('hidden');
                        if (completeForm) completeForm.classList.add('hidden');
                        bab.classList.remove('hidden');
                    } else if (bk.status === 'in_progress') {
                        if (titleEl) titleEl.textContent = `In progress • ${bk.service_title || 'Service'}`;
                        if (subEl) subEl.textContent = amountTxt;
                        if (idElD) idElD.value = String(bk.booking_id);
                        if (acceptForm) acceptForm.classList.add('hidden');
                        if (rejectForm) rejectForm.classList.add('hidden');
                        if (startForm)  startForm.classList.add('hidden');
                        if (deliverForm) deliverForm.classList.remove('hidden');
                        if (completeForm) completeForm.classList.add('hidden');
                        bab.classList.remove('hidden');
                    } else if (bk.status === 'delivered') {
                        if (titleEl) titleEl.textContent = `Delivered • ${bk.service_title || 'Service'}`;
                        if (subEl) subEl.textContent = amountTxt;
                        if (idElC) idElC.value = String(bk.booking_id);
                        if (acceptForm) acceptForm.classList.add('hidden');
                        if (rejectForm) rejectForm.classList.add('hidden');
                        if (startForm)  startForm.classList.add('hidden');
                        if (deliverForm) deliverForm.classList.add('hidden');
                        if (completeForm) completeForm.classList.remove('hidden');
                        bab.classList.remove('hidden');
                    } else {
                        bab.classList.add('hidden');
                    }
                } else {
                    bab.classList.add('hidden');
                }
            }

            // Show client payment bar when there's an ongoing booking and methods available
            const cpb = document.getElementById('client-pay-bar');
            if (cpb && CURRENT_USER_TYPE === 'client') {
                const bk = conv.booking_ctx || null;
                const methods = conv.freelancer_methods || [];
                const titleEl = document.getElementById('cpb-title');
                const subEl = document.getElementById('cpb-subtitle');
                const convIdEl = document.getElementById('cpf-conv-id');
                const bidEl = document.getElementById('cpf-booking-id');
                const amtEl = document.getElementById('cpf-amount');
                const hintEl = document.getElementById('cpf-hint');
                const selEl = document.getElementById('cpf-method');
                const phaseEl = document.getElementById('cpf-phase');
                const toggleBtn = document.getElementById('cpb-toggle');
                const iconUp = document.getElementById('cpb-icon-up');
                const iconDown = document.getElementById('cpb-icon-down');

                // Compute remaining balance and only show bar if there's something left to pay
                const total = bk ? Number(bk.total_amount || 0) : 0;
                const totalPaid = bk ? Number(bk.total_paid_amount || 0) : 0;
                const remaining = Math.max(0, +(total - totalPaid).toFixed(2));

                const eligible = bk && ['accepted','in_progress','delivered'].includes(bk.status)
                    && Array.isArray(methods) && methods.length > 0
                    && remaining > 0;
                if (eligible) {
                    if (titleEl) titleEl.textContent = `Pay • ${bk.service_title || 'Service'}`;
                    if (subEl) subEl.textContent = `Total: ₱${Number(bk.total_amount || 0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})} • Remaining: ₱${remaining.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}`;
                    if (convIdEl) convIdEl.value = String(id);
                    if (bidEl) bidEl.value = String(bk.booking_id);

                    // Populate methods
                    if (selEl) {
                        selEl.innerHTML = '';
                        methods.forEach(m => {
                            const opt = document.createElement('option');
                            opt.value = String(m.method_id);
                            opt.textContent = `${m.method_type.toUpperCase()} • ${m.display_label || m.account_name || m.account_number || 'Method'}`;
                            selEl.appendChild(opt);
                        });
                    }

                    // Recommend amount based on payment terms
                    if (amtEl && hintEl) {
                        let recommended = 0;
                        const total = Number(bk.total_amount || 0);
                        const paidUp = Number(bk.paid_upfront_amount || 0);
                        const method = String(bk.payment_method || '');
                        let phase = '';
                        if (method === 'advance') {
                            recommended = Math.max(0, total - paidUp);
                            phase = 'full_advance';
                            hintEl.textContent = `Recommended: full amount ₱${Math.min(recommended, remaining).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}`;
                        } else if (method === 'downpayment') {
                            const pct = Number(bk.downpayment_percent || 50);
                            const dp = Math.round(total * (pct/100) * 100)/100;
                            if (paidUp + 0.001 < dp) {
                                recommended = Math.max(0, dp - paidUp);
                                phase = 'downpayment';
                                hintEl.textContent = `Recommended: downpayment ₱${Math.min(recommended, remaining).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            } else {
                                recommended = Math.max(0, total - paidUp);
                                phase = 'balance';
                                hintEl.textContent = `Recommended: remaining balance ₱${Math.min(recommended, remaining).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            }
                        } else if (method === 'postpaid') {
                            recommended = Math.max(0, total - Number(bk.total_paid_amount || 0));
                            phase = 'postpaid_full';
                            hintEl.textContent = `Recommended: full payment at completion ₱${Math.min(recommended, remaining).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}`;
                        } else {
                            hintEl.textContent = '';
                        }
                        // Clamp to remaining
                        recommended = Math.min(recommended, remaining);
                        if (recommended > 0 && remaining > 0) {
                            amtEl.value = recommended.toFixed(2);
                        }
                        if (phaseEl) phaseEl.value = phase;
                    }

                    cpb.classList.remove('hidden');

                    // Restore collapsed state per conversation/booking
                    if (toggleBtn && bk && bk.booking_id) {
                        toggleBtn.dataset.convId = String(id);
                        toggleBtn.dataset.bookingId = String(bk.booking_id);
                        const key = `taskhive:cpb:collapsed:${id}:${bk.booking_id}`;
                        const collapsed = localStorage.getItem(key) === '1';
                        cpb.classList.toggle('collapsed', collapsed);
                        toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                        if (iconUp && iconDown) {
                            iconUp.classList.toggle('hidden', collapsed);
                            iconDown.classList.toggle('hidden', !collapsed);
                        }
                        if (!toggleBtn._bound) {
                            toggleBtn.addEventListener('click', () => {
                                const cId = toggleBtn.dataset.convId || String(id);
                                const bId = toggleBtn.dataset.bookingId || (bk && bk.booking_id ? String(bk.booking_id) : '0');
                                const storageKey = `taskhive:cpb:collapsed:${cId}:${bId}`;
                                const nowCollapsed = !cpb.classList.contains('collapsed');
                                cpb.classList.toggle('collapsed', nowCollapsed);
                                localStorage.setItem(storageKey, nowCollapsed ? '1' : '0');
                                toggleBtn.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
                                if (iconUp && iconDown) {
                                    iconUp.classList.toggle('hidden', nowCollapsed);
                                    iconDown.classList.toggle('hidden', !nowCollapsed);
                                }
                            });
                            toggleBtn._bound = true;
                        }
                    }
                } else {
                    cpb.classList.add('hidden');
                }
            }
            
            // Update messages
            const container = document.getElementById('message-conversation');
            container.innerHTML = '';

            // Initial fetch from API to get canonical message_id and attachments
            try {
                const res = await fetch(`api/fetch_messages.php?conversation_id=${id}` , { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } });
                const data = await res.json();
                if (data && data.ok) {
                    latestMessageId = 0;
                    data.messages.forEach((m, index) => {
                        const isMe = (m.sender_id === <?php echo (int)$uid; ?>);
                        const mtype = String(m.type || m.message_type || m.meta_type || '').toLowerCase();
                        const isSystem = !m.sender_id || m.is_system === 1 || ['system','status','payment','event'].includes(mtype) || (typeof m.body === 'string' && m.body.trim().startsWith('System:'));
                        const msg = {
                            sender: isMe ? 'You' : `${m.first_name ?? ''} ${m.last_name ?? ''}`.trim(),
                            content: m.body || '',
                            ts: m.created_at,
                            isCurrentUser: isMe,
                            attachments: m.attachments || null,
                            isSystem: isSystem,
                        };
                        const bubble = renderMessageBubble(conv, msg, index);
                        container.appendChild(bubble);
                        if (m.message_id > latestMessageId) latestMessageId = m.message_id;
                    });
                }
            } catch (_) {
                // Fallback to existing conv.messages if API fails
                conv.messages.forEach((msg, index) => container.appendChild(
                    renderMessageBubble(conv, { ...msg, ts: msg.created_at || conv.timestamp }, index)
                ));
            }

            lucide.createIcons();
            
            // Scroll to bottom
            container.scrollTop = container.scrollHeight;

            // Render times now
            renderAllTimes();
            bindLightbox();
        }

        function parseTs(ts) {
            if (!ts) return null;
            if (typeof ts !== 'string') {
                const d = new Date(ts);
                return isNaN(d) ? null : d;
            }
            // Strip microseconds if present (MySQL can return .ffffff)
            let base = ts.split('.')[0];
            // Normalize 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DDTHH:MM:SS'
            base = base.replace(' ', 'T');
            // If timestamp already has timezone info (Z or ±HH:MM), keep it; else append server offset
            const hasTz = /[zZ]$/.test(ts) || /[+-]\d{2}:?\d{2}$/.test(ts);
            const iso = hasTz ? base : `${base}${SERVER_TZ_OFFSET}`;
            const d = new Date(iso);
            return isNaN(d) ? null : d;
        }

        function relativeOrShort(ts) {
            if (!ts) return '';
            const d = parseTs(ts);
            if (!d || isNaN(d)) return '';
            const now = new Date();
            const diff = (now - d) / 1000; // seconds
            if (diff < 60) return 'Just now';
            if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
            const sameDay = d.toDateString() === now.toDateString();
            if (sameDay) return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            const yesterday = new Date(now);
            yesterday.setDate(now.getDate() - 1);
            if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        function longDateTime(ts) {
            if (!ts) return '';
            const d = parseTs(ts);
            if (!d || isNaN(d)) return '';
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        function relativeForList(ts) {
            if (!ts) return '';
            const d = parseTs(ts);
            if (!d || isNaN(d)) return '';
            const now = new Date();
            const diff = (now - d) / 1000; // seconds
            if (diff < 60) return 'Just now';
            if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
            if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        function renderAllTimes() {
            // Conversation list times
            document.querySelectorAll('.conv-time').forEach(el => {
                const ts = el.dataset.ts;
                el.textContent = relativeForList(ts);
                el.title = longDateTime(ts);
            });
            // Message bubble times
            document.querySelectorAll('.msg-time').forEach(el => {
                const ts = el.dataset.ts;
                el.textContent = relativeOrShort(ts);
                el.title = longDateTime(ts);
            });
            // Header time
            const headerTime = document.getElementById('message-view-time');
            if (headerTime) {
                const ts = headerTime.dataset.ts;
                headerTime.textContent = longDateTime(ts);
            }
        }

        // Handle delete conversation via AJAX for instant UI update
        const deleteForm = document.getElementById('delete-conv-form');
        if (deleteForm && !deleteForm._bound) {
            deleteForm.addEventListener('submit', async (e) => {
                // If user canceled confirm(), browser won't submit anyway
                e.preventDefault();
                const cidInput = document.getElementById('delete-conv-id');
                const cid = cidInput ? parseInt(cidInput.value) : 0;
                if (!cid) { return; }
                try {
                    const fd = new FormData();
                    fd.append('conversation_id', String(cid));
                    fd.append('return', 'inbox.php');
                    const res = await fetch('conversation_delete.php', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
                        cache: 'no-store',
                        body: fd
                    });
                    let data = null;
                    try { data = await res.json(); } catch (_) { /* non-JSON fallback */ }
                    if (data && data.ok) {
                        // Remove from in-memory map
                        delete conversations[cid];
                        // Remove from DOM
                        const item = messageListEl.querySelector(`.message-item[data-id="${cid}"]`);
                        if (item) item.remove();

                        // If we just deleted the active conversation, switch UI state
                        if (currentMessageId === cid) {
                            currentMessageId = 0;
                            const mv = document.getElementById('message-view-container');
                            const es = document.getElementById('empty-state');
                            if (mv) mv.classList.add('hidden');
                            if (es) es.classList.remove('hidden');
                        }

                        // Update unread badge if provided
                        if (typeof data.unreadCount === 'number') {
                            const badge = document.querySelector('.sidebar .sidebar-item .px-2.py-0.5');
                            if (badge) badge.textContent = String(data.unreadCount);
                        }
                    } else if (data && data.error) {
                        alert('Failed to delete conversation: ' + String(data.error));
                        if (data.details) console.error('Delete error details:', data.details, data.file, data.line);
                    } else if (!res.ok) {
                        const txt = await res.text();
                        console.error('Delete failed HTTP '+res.status+':', txt);
                        alert('Delete failed with status ' + res.status);
                    } else {
                        // If server returned HTML (redirect), let normal submit handle it
                        deleteForm.submit();
                    }
                } catch (_) {
                    // Fallback to normal POST/redirect on error
                    deleteForm.submit();
                }
            });
            deleteForm._bound = true;
        }

        function toggleStar() {
            isStarred = !isStarred;
            const button = document.querySelector('.star-button');
            const icon = button.querySelector('i');
            
            if (isStarred) {
                button.classList.add('starred');
                icon.style.fill = '#f59e0b';
            } else {
                button.classList.remove('starred');
                icon.style.fill = 'none';
            }
        }

        function checkReplyText() {
            const textarea = document.getElementById('reply-textarea');
            const button = document.getElementById('send-button');
            const hasText = textarea.value.trim().length > 0;
            const hasImages = Array.isArray(selectedImages) ? selectedImages.length > 0 : false;
            button.disabled = !(hasText || hasImages);
        }

        async function sendReply() {
            const textarea = document.getElementById('reply-textarea');
            const text = textarea.value.trim();
            const files = Array.isArray(selectedImages) ? selectedImages.map(it => it.file) : [];
            
            if (!text && files.length === 0) return;
            
            if (!currentMessageId) return;

            // Client-side size guard to match backend
            if (files.some(f => f.size > MAX_IMAGE_SIZE)) {
                alert('One of the images is too large. Max size per image is 10MB.');
                return;
            }

            // Build form data
            const fd = new FormData();
            fd.append('conversation_id', String(currentMessageId));
            if (text) fd.append('body', text);
            files.forEach(f => fd.append('images[]', f));

            try {
                const res = await fetch('api/send_message.php', { method: 'POST', body: fd, cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } });
                const data = await res.json();
                if (!data || !data.ok) return;

                // Optimistically render the sent message
                const conv = conversations[currentMessageId];
                const container = document.getElementById('message-conversation');
                const m = data.message;
                const msg = {
                    sender: 'You',
                    content: m.body || '',
                    ts: m.created_at,
                    isCurrentUser: true,
                    attachments: m.attachments || null,
                };
                const bubble = renderMessageBubble(conv, msg);
                container.appendChild(bubble);
                lucide.createIcons();
                container.scrollTop = container.scrollHeight;
                bindLightbox();

                // Reset inputs
                textarea.value = '';
                // Clear selected preview images and file input
                clearAllSelected();
                checkReplyText();
                // Hide attach indicator
                if (attachIndicatorEl) {
                    attachIndicatorEl.classList.add('hidden');
                    attachIndicatorEl.textContent = '';
                }

                // Update latestMessageId
                if (m.message_id && m.message_id > latestMessageId) latestMessageId = m.message_id;

                // Update header and list times
                const headerTimeEl = document.getElementById('message-view-time');
                headerTimeEl.dataset.ts = m.created_at;
                const activeItem = document.querySelector('.message-item.active .conv-time');
                if (activeItem) activeItem.dataset.ts = m.created_at;
                // Update active preview immediately
                const activePreview = document.querySelector('.message-item.active p.text-sm.text-gray-600');
                if (activePreview) activePreview.textContent = m.body || 'Image';
                // Reorder list so current conversation jumps to top
                updateConversationList([
                    { conversation_id: currentMessageId, preview: m.body || 'Image', timestamp: m.created_at, unread: false }
                ]);
                renderAllTimes();
            } catch (_) {
                // Ignore errors for now
            }
        }

        // Polling for new messages and unread count
        async function pollUpdates(){
            try {
                if (currentMessageId) {
                    const res = await fetch(`api/fetch_messages.php?conversation_id=${currentMessageId}&after_id=${latestMessageId}`, { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } });
                    const data = await res.json();
                    if (data && data.ok && Array.isArray(data.messages) && data.messages.length) {
                        const conv = conversations[currentMessageId];
                        const container = document.getElementById('message-conversation');
                        let lastTs = null;
                        data.messages.forEach(m => {
                            const isMe = (m.sender_id === <?php echo (int)$uid; ?>);
                            const mtype = String(m.type || m.message_type || m.meta_type || '').toLowerCase();
                            const isSystem = !m.sender_id || m.is_system === 1 || ['system','status','payment','event'].includes(mtype) || (typeof m.body === 'string' && m.body.trim().startsWith('System:'));
                            const msg = {
                                sender: isMe ? 'You' : `${m.first_name ?? ''} ${m.last_name ?? ''}`.trim(),
                                content: m.body || '',
                                ts: m.created_at,
                                isCurrentUser: isMe,
                                attachments: m.attachments || null,
                                isSystem: isSystem,
                            };
                            container.appendChild(renderMessageBubble(conv, msg));
                            if (m.message_id > latestMessageId) latestMessageId = m.message_id;
                            lastTs = m.created_at;
                        });
                        lucide.createIcons();
                        container.scrollTop = container.scrollHeight;
                        bindLightbox();
                        // Update header and active list timestamp
                        if (lastTs) {
                            const headerTimeEl = document.getElementById('message-view-time');
                            headerTimeEl.dataset.ts = lastTs;
                            const activeItem = document.querySelector('.message-item.active .conv-time');
                            if (activeItem) activeItem.dataset.ts = lastTs;
                        }
                        renderAllTimes();
                    }
                }

                // Update unread count badge
                const unreadRes = await fetch('api/unread_count.php', { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } });
                const unreadData = await unreadRes.json();
                if (unreadData && unreadData.ok) {
                    const badge = document.querySelector('.sidebar .sidebar-item .px-2.py-0.5');
                    const val = Number(unreadData.unreadCount || 0);
                    if (badge) badge.textContent = String(val);
                }

                // Refresh conversations list (contacts) to reflect latest preview/time/order
                const convRes = await fetch('api/conversations.php', { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } });
                const convData = await convRes.json();
                if (convData && convData.ok && Array.isArray(convData.conversations)) {
                    // Remove any message items that are not in fresh list (e.g., hidden/deleted for this user)
                    const freshIds = new Set(convData.conversations.map(c => String(c.conversation_id)));
                    const items = Array.from(messageListEl.querySelectorAll('.message-item'));
                    items.forEach(item => {
                        const id = item.getAttribute('data-id');
                        if (!freshIds.has(String(id))) {
                            // Also drop from in-memory map
                            delete conversations[id];
                            if (parseInt(id) === currentMessageId) {
                                currentMessageId = 0;
                                const mv = document.getElementById('message-view-container');
                                const es = document.getElementById('empty-state');
                                if (mv) mv.classList.add('hidden');
                                if (es) es.classList.remove('hidden');
                            }
                            item.remove();
                        }
                    });
                    updateConversationList(convData.conversations);
                }
            } catch (_) { /* ignore */ }
        }

    setInterval(pollUpdates, 2000);
    // Update relative times every minute
    setInterval(renderAllTimes, 60000);

        // Close sidebar on desktop resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                sidebarOpen = false;
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebar-overlay').classList.remove('active');
            }
        });
        // Lightbox bindings
        function bindLightbox() {
            const convo = document.getElementById('message-conversation');
            const lightbox = document.getElementById('lightbox');
            const imgEl = document.getElementById('lightbox-img');
            const closeBtn = document.getElementById('lightbox-close');

            if (convo && !convo._lbBound) {
                convo.addEventListener('click', (e) => {
                    const img = e.target.closest('img.chat-image');
                    if (!img) return;
                    const src = img.getAttribute('data-full') || img.src;
                    imgEl.src = src;
                    lightbox.classList.remove('hidden');
                    lightbox.classList.add('flex');
                });
                convo._lbBound = true;
            }

            if (closeBtn && !closeBtn._lbBound) {
                closeBtn.addEventListener('click', () => {
                    lightbox.classList.add('hidden');
                    lightbox.classList.remove('flex');
                    imgEl.src = '';
                });
                closeBtn._lbBound = true;
            }

            if (lightbox && !lightbox._lbOverlayBound) {
                lightbox.addEventListener('click', (e) => {
                    if (e.target === lightbox) {
                        lightbox.classList.add('hidden');
                        lightbox.classList.remove('flex');
                        imgEl.src = '';
                    }
                });
                lightbox._lbOverlayBound = true;
            }

            if (!document._lbEscBound) {
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && !lightbox.classList.contains('hidden')) {
                        lightbox.classList.add('hidden');
                        lightbox.classList.remove('flex');
                        imgEl.src = '';
                    }
                });
                document._lbEscBound = true;
            }

            // Also enable lightbox for pre-send attachment previews
            if (attachPreviewEl && !attachPreviewEl._lbBound) {
                attachPreviewEl.addEventListener('click', (e) => {
                    const img = e.target.closest('img[data-preview-src]');
                    if (!img) return;
                    const src = img.getAttribute('data-preview-src') || img.src;
                    imgEl.src = src;
                    lightbox.classList.remove('hidden');
                    lightbox.classList.add('flex');
                });
                attachPreviewEl._lbBound = true;
            }
        }

        // Initialize icons and initial times, and load first conversation to sync times
        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            renderAllTimes();
            if (currentMessageId) {
                loadConversation(currentMessageId);
            }
            // Update send button state when an image is picked/cleared
            if (imageInputEl) {
                imageInputEl.addEventListener('change', () => {
                    const files = (imageInputEl.files && imageInputEl.files.length) ? Array.from(imageInputEl.files) : [];
                    addFilesToSelection(files);
                    // Clear the input so same file selection can trigger change again
                    imageInputEl.value = '';
                    checkReplyText();
                });
            }

            // Build star inputs for review modal
            const starWrap = document.getElementById('review-stars');
            if (starWrap && !starWrap._built) {
                for (let i = 1; i <= 5; i++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'p-1';
                    btn.dataset.value = String(i);
                    btn.innerHTML = '<i data-lucide="star" class="w-7 h-7 text-gray-300"></i>';
                    btn.addEventListener('click', () => setReviewRating(i));
                    starWrap.appendChild(btn);
                }
                starWrap._built = true;
                lucide.createIcons();
            }

            // Close handlers for review modal
            const rm = document.getElementById('review-modal');
            const rc = document.getElementById('review-close');
            const rcc = document.getElementById('review-cancel');
            [rc, rcc].forEach(el => el && el.addEventListener('click', closeReviewModal));
            if (rm && !rm._overlayBound) {
                rm.addEventListener('click', (e) => { if (e.target === rm) closeReviewModal(); });
                rm._overlayBound = true;
            }
            const rf = document.getElementById('review-form');
            if (rf && !rf._bound) {
                rf.addEventListener('submit', submitReviewViaAjax);
                rf._bound = true;
            }

            // Clear all attachments button
            if (attachClearAllBtn && !attachClearAllBtn._bound) {
                attachClearAllBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    clearAllSelected();
                });
                attachClearAllBtn._bound = true;
            }

            // Drag-and-drop support on reply area
            if (replyComposeEl && !replyComposeEl._dndBound) {
                const onDragOver = (e) => { e.preventDefault(); replyComposeEl.classList.add('dropzone-active'); };
                const onDragEnter = (e) => { e.preventDefault(); replyComposeEl.classList.add('dropzone-active'); };
                const onDragLeave = (e) => { if (e.target === replyComposeEl) replyComposeEl.classList.remove('dropzone-active'); };
                const onDrop = (e) => {
                    e.preventDefault();
                    replyComposeEl.classList.remove('dropzone-active');
                    const files = e.dataTransfer && e.dataTransfer.files ? Array.from(e.dataTransfer.files) : [];
                    if (files.length) addFilesToSelection(files);
                };
                replyComposeEl.addEventListener('dragover', onDragOver);
                replyComposeEl.addEventListener('dragenter', onDragEnter);
                replyComposeEl.addEventListener('dragleave', onDragLeave);
                replyComposeEl.addEventListener('drop', onDrop);
                replyComposeEl._dndBound = true;
            }
        });

        function openReviewModal(bookingId) {
            const rm = document.getElementById('review-modal');
            const bidEl = document.getElementById('review-booking-id');
            const rateEl = document.getElementById('review-rating');
            const cmtEl = document.getElementById('review-comment');
            const errEl = document.getElementById('review-error');
            if (!rm || !bidEl || !rateEl || !cmtEl) return;
            bidEl.value = String(bookingId || '');
            rateEl.value = '0';
            cmtEl.value = '';
            if (errEl) { errEl.classList.add('hidden'); errEl.textContent = ''; }
            setReviewRating(0);
            rm.classList.remove('hidden');
            rm.classList.add('flex');
        }

        // Attachment preview helpers
        function addFilesToSelection(files) {
            if (!files || !files.length) return;
            const dt = new DataTransfer();
            // Start with currently selected images
            selectedImages.forEach(it => dt.items.add(it.file));

            files.forEach(file => {
                // Only images and within size limit
                if (!file.type.startsWith('image/')) return;
                if (file.size > MAX_IMAGE_SIZE) {
                    alert(`Image "${file.name}" is too large. Max size is 10MB.`);
                    return;
                }
                // Deduplicate by name+size+lastModified
                const exists = selectedImages.some(it => it.file.name === file.name && it.file.size === file.size && it.file.lastModified === file.lastModified);
                if (exists) return;
                dt.items.add(file);
            });

            // Rebuild selection with fresh object URLs
            // Revoke previous URLs to avoid leaks
            selectedImages.forEach(it => URL.revokeObjectURL(it.url));
            selectedImages = Array.from(dt.files).map(f => ({ file: f, url: URL.createObjectURL(f) }));
            // Reflect into hidden input
            imageInputEl.files = dt.files;
            renderAttachPreview();
        }

        function removeSelectedAt(index) {
            index = Number(index);
            if (isNaN(index) || index < 0 || index >= selectedImages.length) return;
            // Build a new FileList without the removed index
            const dt = new DataTransfer();
            selectedImages.forEach((it, i) => { if (i !== index) dt.items.add(it.file); });
            // Revoke removed url
            try { URL.revokeObjectURL(selectedImages[index].url); } catch(_) {}
            // Recompute selectedImages with new URLs
            selectedImages.forEach((it, i) => { if (i !== index) try { URL.revokeObjectURL(it.url); } catch(_) {} });
            selectedImages = Array.from(dt.files).map(f => ({ file: f, url: URL.createObjectURL(f) }));
            imageInputEl.files = dt.files;
            renderAttachPreview();
            checkReplyText();
        }

        function clearAllSelected() {
            selectedImages.forEach(it => { try { URL.revokeObjectURL(it.url); } catch(_) {} });
            selectedImages = [];
            const dt = new DataTransfer();
            imageInputEl.files = dt.files;
            renderAttachPreview();
            if (attachIndicatorEl) { attachIndicatorEl.classList.add('hidden'); attachIndicatorEl.textContent = ''; }
            checkReplyText();
        }

        function renderAttachPreview() {
            const count = selectedImages.length;
            if (attachIndicatorEl) {
                if (count > 0) {
                    attachIndicatorEl.textContent = count === 1 ? `Attached: ${selectedImages[0].file.name}` : `Attached: ${count} images`;
                    attachIndicatorEl.classList.remove('hidden');
                } else {
                    attachIndicatorEl.classList.add('hidden');
                    attachIndicatorEl.textContent = '';
                }
            }
            if (!attachPreviewEl) return;
            const grid = attachPreviewEl.querySelector('.attach-grid');
            if (!grid) return;
            grid.innerHTML = '';
            if (count === 0) {
                attachPreviewEl.classList.add('hidden');
                if (attachCountEl) attachCountEl.textContent = '';
                return;
            }
            attachPreviewEl.classList.remove('hidden');
            if (attachCountEl) attachCountEl.textContent = count === 1 ? '1 image attached' : `${count} images attached`;
            selectedImages.forEach((it, idx) => {
                const item = document.createElement('div');
                item.className = 'attach-item';
                item.innerHTML = `
                    <img src="${it.url}" data-preview-src="${it.url}" alt="attachment preview">
                    <button type="button" class="attach-remove" aria-label="Remove image" data-index="${idx}">
                        <i data-lucide="x" class="w-4 h-4 text-red-600"></i>
                    </button>
                `;
                grid.appendChild(item);
            });
            // Bind remove handlers
            grid.querySelectorAll('.attach-remove').forEach(btn => {
                if (!btn._bound) {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const i = btn.getAttribute('data-index');
                        removeSelectedAt(i);
                    });
                    btn._bound = true;
                }
            });
            // Refresh icons
            lucide.createIcons();
        }

        function closeReviewModal() {
            const rm = document.getElementById('review-modal');
            if (!rm) return;
            rm.classList.add('hidden');
            rm.classList.remove('flex');
        }

        function setReviewRating(val) {
            const rateEl = document.getElementById('review-rating');
            const starWrap = document.getElementById('review-stars');
            if (!rateEl || !starWrap) return;
            const v = Number(val || 0);
            rateEl.value = String(v);
            const icons = starWrap.querySelectorAll('i[data-lucide="star"]');
            icons.forEach((icon, idx) => {
                const i = idx + 1;
                icon.classList.toggle('text-amber-500', i <= v);
                icon.classList.toggle('fill-amber-500', i <= v);
                icon.classList.toggle('text-gray-300', i > v);
            });
        }

        async function submitReviewViaAjax(e) {
            e.preventDefault();
            const bid = document.getElementById('review-booking-id').value;
            const rating = parseInt(document.getElementById('review-rating').value || '0');
            const comment = document.getElementById('review-comment').value || '';
            const errEl = document.getElementById('review-error');
            if (rating < 1 || rating > 5) {
                if (errEl) { errEl.textContent = 'Please select a rating from 1 to 5.'; errEl.classList.remove('hidden'); }
                return;
            }
            try {
                const fd = new FormData();
                fd.append('rating', String(rating));
                fd.append('comment', comment);
                const res = await fetch('leave_review.php?booking_id=' + encodeURIComponent(String(bid)), { method: 'POST', body: fd, cache: 'no-store' });
                if (res.redirected) {
                    closeReviewModal();
                    // Append a local thank-you system message
                    appendLocalThankYou();
                    return;
                }
                const txt = await res.text();
                if (txt && txt.toLowerCase().includes('unable to submit')) {
                    if (errEl) { errEl.textContent = 'Unable to submit review. You can only review eligible bookings once.'; errEl.classList.remove('hidden'); }
                } else {
                    closeReviewModal();
                    // Append a local thank-you system message
                    appendLocalThankYou();
                }
            } catch (err) {
                if (errEl) { errEl.textContent = 'Something went wrong. Please try again.'; errEl.classList.remove('hidden'); }
            }
        }

        function appendLocalThankYou(){
            if (!currentMessageId) return;
            const conv = conversations[currentMessageId];
            if (!conv) return;
            const container = document.getElementById('message-conversation');
            if (!container) return;
            // Format now as YYYY-MM-DD HH:MM:SS (server treats naive with SERVER_TZ_OFFSET)
            const d = new Date();
            const pad = (n) => String(n).padStart(2,'0');
            const nowTs = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
            const thankText = 'System: Thank you for your review!';
            const msg = {
                sender: 'System',
                content: thankText,
                ts: nowTs,
                isCurrentUser: false,
                isSystem: true,
            };
            const bubble = renderMessageBubble(conv, msg);
            container.appendChild(bubble);
            lucide.createIcons();
            container.scrollTop = container.scrollHeight;
            // Update header time and conversation list preview/time
            const headerTimeEl = document.getElementById('message-view-time');
            if (headerTimeEl) headerTimeEl.dataset.ts = nowTs;
            updateConversationList([
                { conversation_id: currentMessageId, preview: thankText, timestamp: nowTs, unread: false }
            ]);
            renderAllTimes();
        }

        function updateConversationList(freshConvs) {
        // Intercept clicks on "Leave a review" links inside messages to open the modal
        document.addEventListener('click', (e) => {
            const a = e.target.closest && e.target.closest('a.leave-review-link');
            if (!a) return;
            const href = a.getAttribute('href');
            try {
                const url = new URL(href, window.location.origin);
                const bid = url.searchParams.get('booking_id');
                if (bid) {
                    e.preventDefault();
                    openReviewModal(bid);
                }
            } catch (_) { /* ignore invalid URL */ }
        });

            // Build a map for quick access
            const byId = new Map();
            freshConvs.forEach(c => byId.set(String(c.conversation_id), c));

            // Update existing items and collect them for reorder
            const items = Array.from(messageListEl.querySelectorAll('.message-item'));
            items.forEach(item => {
                const id = item.getAttribute('data-id');
                const fresh = byId.get(id);
                if (!fresh) return;
                // Update preview
                const previewEl = item.querySelector('p.text-sm.text-gray-600');
                if (previewEl) previewEl.textContent = fresh.preview || 'No messages yet.';
                // Update timestamp
                const timeEl = item.querySelector('.conv-time');
                if (timeEl) timeEl.dataset.ts = fresh.timestamp;
                // Update unread indicator
                item.dataset.unread = fresh.unread ? '1' : '0';
                const dot = item.querySelector('.absolute.top-4.left-2');
                if (fresh.unread) {
                    if (!dot) {
                        const d = document.createElement('div');
                        d.className = 'absolute top-4 left-2 w-2 h-2 bg-blue-500 rounded-full';
                        const inner = document.createElement('span');
                        inner.className = 'absolute inset-0 w-2 h-2 bg-blue-500 rounded-full unread-dot-ping';
                        d.appendChild(inner);
                        item.appendChild(d);
                    }
                } else if (dot) {
                    dot.remove();
                }
            });

            // Reorder DOM by latest timestamp desc
            const sortable = items
                .map(el => ({ el, ts: el.querySelector('.conv-time')?.dataset.ts || '' }))
                .sort((a, b) => (a.ts < b.ts ? 1 : (a.ts > b.ts ? -1 : 0)));
            sortable.forEach(({ el }) => messageListEl.appendChild(el));

            renderAllTimes();
        }
    </script>

</body>
</html>
