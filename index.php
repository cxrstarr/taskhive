<?php
// Boot session and fetch current user (if any)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/database.php';
$db = new database();

// Demo role quick login (no credentials)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_role'])) {
    $pdo = $db->opencon();
    $role = $_POST['demo_role'];
    if (!in_array($role, ['client','freelancer','admin'], true)) {
        $role = 'client';
    }
    try {
        $st = $pdo->prepare("SELECT user_id, first_name, last_name, email FROM users WHERE user_type=? ORDER BY user_id ASC LIMIT 1");
        $st->execute([$role]);
        $u = $st->fetch();
        $uid = $u ? (int)$u['user_id'] : 0;
        if ($uid <= 0) {
            if ($role === 'client') {
                $uid = $db->registerClient('Demo','Client','demo_client@example.com','demo123');
            } elseif ($role === 'freelancer') {
                $uid = $db->registerFreelancer('Demo','Freelancer','demo_freelancer@example.com','demo123','',null,null,null);
            } else {
                $ins = $pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,profile_picture,created_at) VALUES ('Demo','Admin','demo_admin@example.com',NULL,?, 'admin', NULL, NOW())");
                $ins->execute([password_hash('demo123', PASSWORD_DEFAULT)]);
                $uid = (int)$pdo->lastInsertId();
            }
        }
        if ($uid && $uid > 0) {
            $_SESSION['user_id'] = $uid;
            $_SESSION['user_type'] = $role;
            $_SESSION['user_email'] = $u['email'] ?? ($role === 'client' ? 'demo_client@example.com' : ($role === 'freelancer' ? 'demo_freelancer@example.com' : 'demo_admin@example.com'));
            $_SESSION['user_name'] = (($u['first_name'] ?? 'Demo') . ' ' . ($u['last_name'] ?? ucfirst($role)));
            if ($role === 'freelancer') {
                header('Location: freelancer_dashboard.php');
            } elseif ($role === 'client') {
                header('Location: client_dashboard.php');
            } else {
                header('Location: admin_dashboard.php');
            }
            exit;
        }
    } catch (Throwable $e) {
        error_log('[DEMO_LOGIN][INDEX] ' . $e->getMessage());
    }
}
$currentUser = null;
// Navbar notifications list
$notifications = [];
if (!empty($_SESSION['user_id'])) {
    $u = $db->getUser((int)$_SESSION['user_id']);
    if ($u) {
        $currentUser = [
            'id'     => (int)$u['user_id'],                                                                                                                                                                  
            'name'   => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? 'User'),
            'email'  => $u['email'] ?? '',
            'avatar' => $u['profile_picture'] ?: 'img/profile_icon.webp',
            'type'   => $u['user_type'] ?? null,
        ];
        // Load latest 10 notifications for the logged-in user
        try {
            $pdo = $db->opencon();
            $stN = $pdo->prepare("SELECT notification_id,type,data,created_at,read_at FROM notifications WHERE user_id=:u ORDER BY created_at DESC LIMIT 10");
            $stN->execute([':u' => (int)$currentUser['id']]);
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
                    $msg = "Booking #{$data['booking_id']} status: ".ucfirst(str_replace('_',' ', $data['status']));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Hive - Find Your Buzz</title>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>                                                                                                                                                                                                                                                                                                   
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        @keyframes floatBee {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(20px, -30px) rotate(10deg); }
            75% { transform: translate(-10px, -15px) rotate(-10deg); }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes fadeInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .animate-float { animation: float 3s ease-in-out infinite; }
        .animate-float-bee { animation: floatBee 3s ease-in-out infinite; }
        .animate-slide-in { animation: slideIn 0.6s ease-out; }
        .animate-fade-in-up { animation: fadeInUp 0.6s ease-out; }
        .animate-spin-slow { animation: spin 20s linear infinite; }
        .animate-pulse-slow { animation: pulse 2s ease-in-out infinite; }
        
        .group:hover .group-hover\:translate-x-2 { transform: translateX(0.5rem); }
        .group:hover .group-hover\:scale-110 { transform: scale(1.1); }
        .group:hover .group-hover\:rotate-360 { transform: rotate(360deg); }
        
        .transition-transform { transition: transform 0.3s ease; }
        .transition-all { transition: all 0.3s ease; }
        
        .bg-honeycomb {
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='104' viewBox='0 0 60 104' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M30 0l25.98 15v30L30 60 4.02 45V15z' fill='%23f59e0b' fill-opacity='0.05' fill-rule='evenodd'/%3E%3C/svg%3E");
        }
        
        .hover-lift {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(245, 158, 11, 0.2);
        }
        
        .mobile-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .mobile-menu.active {
            max-height: 500px;
        }
        
        /* Notifications dropdown animation and visibility */
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .dropdown { display: none; opacity: 0; }
        .dropdown.active { display: block; animation: slideDown 0.2s ease-out forwards; }
    </style>
</head>
<body class="bg-gradient-to-br from-amber-50 via-yellow-50 to-orange-50">

    <!-- Navbar -->
    <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-md border-b border-amber-200/50 shadow-sm animate-slide-in">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Logo -->
                <div id="logo" class="flex items-center gap-3 cursor-pointer hover:scale-105 transition-transform">
                    <div class="relative">
                        <svg class="w-10 h-10 fill-amber-400 stroke-amber-600 stroke-2" viewBox="0 0 24 24">
                            <polygon points="12 2, 22 8.5, 22 15.5, 12 22, 2 15.5, 2 8.5"/>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center animate-spin-slow">
                            <div class="w-3 h-3 rounded-full bg-amber-600"></div>
                        </div>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-amber-900 tracking-tight">Task Hive</h1>
                        <p class="text-xs text-amber-700 -mt-1">Find Your Buzz</p>
                    </div>
                </div>

                <!-- Desktop Navigation -->
                <div class="hidden md:flex items-center gap-8">
                    <a href="feed.php" class="text-gray-700 hover:text-amber-600 transition-colors relative group">
                        Find Gigs
                        <span class="absolute -bottom-1 left-0 h-0.5 bg-amber-500 w-0 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="dashboard.php" class="text-gray-700 hover:text-amber-600 transition-colors relative group">
                        Post a Task
                        <span class="absolute -bottom-1 left-0 h-0.5 bg-amber-500 w-0 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="#how-it-works" class="text-gray-700 hover:text-amber-600 transition-colors relative group">
                        How It Works
                        <span class="absolute -bottom-1 left-0 h-0.5 bg-amber-500 w-0 group-hover:w-full transition-all duration-300"></span>
                    </a>
                </div>

                <!-- Right side: CTAs or User Profile (desktop) -->
                <div class="hidden md:flex items-center gap-4">
                    <?php if ($currentUser): ?>
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

                        <!-- Profile Dropdown Trigger -->
                        <div class="relative">
                            <button id="profile-btn" class="flex items-center gap-3 px-3 py-2 hover:bg-amber-50 rounded-full transition-colors">
                                <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-8 h-8 rounded-full border-2 border-amber-400 object-cover">
                                <span class="hidden lg:block text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                                <svg id="dropdown-arrow" class="w-4 h-4 text-gray-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <!-- Dropdown Menu -->
                            <div id="profile-dropdown" class="profile-dropdown absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden hidden">
                                <!-- User Info -->
                                <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border-b border-amber-200">
                                    <div class="flex items-center gap-3">
                                        <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
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
                    <?php else: ?>
                        <button id="btn-signin-desktop" class="px-5 py-2.5 text-amber-700 hover:text-amber-900 transition-colors hover:scale-105">
                            Sign In
                        </button>
                        <button id="btn-register-desktop" class="px-6 py-2.5 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-105">
                            Make an Account
                        </button>
                        <!-- Fixed ID quick login links -->
                        <div class="flex items-center gap-2 ms-2">
                            <a href="login.php?impersonate=1" class="px-3 py-2 text-sm bg-amber-100 text-amber-800 rounded-full border border-amber-300 hover:bg-amber-200 transition-all">Login Freelancer (ID 1)</a>
                            <a href="login.php?impersonate=2" class="px-3 py-2 text-sm bg-amber-100 text-amber-800 rounded-full border border-amber-300 hover:bg-amber-200 transition-all">Login Client (ID 2)</a>
                            <a href="admin_login.php?impersonate=5" class="px-3 py-2 text-sm bg-amber-100 text-amber-800 rounded-full border border-amber-300 hover:bg-amber-200 transition-all">Login Admin (ID 5)</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Mobile Menu Button -->
                <button id="mobile-menu-btn" class="md:hidden p-2 text-amber-700 hover:bg-amber-100 rounded-lg transition-colors">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobile-menu" class="mobile-menu md:hidden bg-white border-t border-amber-200">
            <div class="px-4 py-6 space-y-4">
                <a href="feed.php" class="block py-2 text-gray-700 hover:text-amber-600 transition-colors">Find Gigs</a>
                <a href="dashboard.php" class="block py-2 text-gray-700 hover:text-amber-600 transition-colors">Post a Task</a>
                <a href="#how-it-works" class="block py-2 text-gray-700 hover:text-amber-600 transition-colors">How It Works</a>
                <div class="pt-4 space-y-3 border-t border-amber-200">
                    <?php if ($currentUser): ?>
                        <div class="flex items-center gap-3 p-3 bg-amber-50 rounded-lg border border-amber-200">
                            <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-10 h-10 rounded-full border-2 border-amber-400 object-cover">
                            <div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                                <div class="text-xs text-gray-600"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                            </div>
                        </div>
                        <a href="dashboard.php" class="block w-full text-center py-2.5 text-amber-700 hover:bg-amber-50 rounded-lg transition-colors">View Dashboard</a>
                        <a href="settings.php" class="block w-full text-center py-2.5 text-amber-700 hover:bg-amber-50 rounded-lg transition-colors">Settings</a>
                        <a href="logout.php" class="block w-full text-center py-2.5 bg-gradient-to-r from-red-500 to-rose-500 text-white rounded-full shadow-lg">Logout</a>
                    <?php else: ?>
                        <button id="btn-signin-mobile" class="w-full py-2.5 text-amber-700 hover:bg-amber-50 rounded-lg transition-colors">Sign In</button>
                        <button id="btn-register-mobile" class="w-full py-2.5 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-full shadow-lg">Make an Account</button>
                        <div class="flex gap-2 mt-3">
                            <a href="login.php?impersonate=1" class="flex-1 text-center py-2.5 bg-amber-100 text-amber-800 rounded-lg border border-amber-300">Login Freelancer (ID 1)</a>
                            <a href="login.php?impersonate=2" class="flex-1 text-center py-2.5 bg-amber-100 text-amber-800 rounded-lg border border-amber-300">Login Client (ID 2)</a>
                            <a href="admin_login.php?impersonate=5" class="flex-1 text-center py-2.5 bg-amber-100 text-amber-800 rounded-lg border border-amber-300">Login Admin (ID 5)</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative overflow-hidden pt-20 pb-32">
        <!-- Animated Background Elements -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute w-32 h-32 opacity-10 animate-spin-slow" style="left: 10%; top: 20%;">
                <svg viewBox="0 0 100 100" class="w-full h-full fill-amber-400">
                    <polygon points="50 0, 93.3 25, 93.3 75, 50 100, 6.7 75, 6.7 25"/>
                </svg>
            </div>
            <div class="absolute w-32 h-32 opacity-10 animate-spin-slow" style="left: 80%; top: 60%; animation-duration: 25s;">
                <svg viewBox="0 0 100 100" class="w-full h-full fill-amber-400">
                    <polygon points="50 0, 93.3 25, 93.3 75, 50 100, 6.7 75, 6.7 25"/>
                </svg>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Left Content -->
                <div class="text-center lg:text-left animate-fade-in-up">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-amber-100 border border-amber-300 rounded-full mb-6">
                        <i data-lucide="sparkles" class="w-4 h-4 text-amber-600"></i>
                        <span class="text-sm text-amber-800">Join 50,000+ Freelancers</span>
                    </div>

                    <h1 class="text-5xl lg:text-6xl font-bold text-gray-900 mb-6 leading-tight">
                        Where Work
                        <span class="relative inline-block">
                            <span class="relative z-10 text-amber-600">Buzzes</span>
                            <span class="absolute inset-0 bg-amber-200 -skew-x-12"></span>
                        </span> 
                        to Life
                    </h1>

                    <p class="text-xl text-gray-600 mb-8 max-w-xl mx-auto lg:mx-0">
                        Connect with opportunities, find skilled freelancers, and build your career in the world's most vibrant gig marketplace.
                    </p>

                    <!-- Search Bar -->
                    <div class="relative max-w-2xl mx-auto lg:mx-0">
                        <div class="flex gap-2 bg-white p-2 rounded-full shadow-xl border border-amber-200 hover:shadow-2xl transition-shadow duration-300">
                            <div class="flex-1 flex items-center gap-3 px-4">
                                <i data-lucide="search" class="w-5 h-5 text-gray-400"></i>
                                <input id="search-input" type="text" placeholder="Search for gigs, skills, or freelancers..." class="flex-1 bg-transparent border-none outline-none text-gray-700 placeholder-gray-400">
                            </div>
                            <button id="search-btn" class="px-8 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-full hover:shadow-lg transition-all duration-300 hover:scale-105">
                                Search
                            </button>
                        </div>
                        
                        <div class="flex flex-wrap gap-2 mt-4 justify-center lg:justify-start">
                            <span class="text-sm text-gray-500">Popular:</span>
                            <span class="search-chip px-3 py-1 bg-white rounded-full text-sm text-amber-700 border border-amber-200 cursor-pointer hover:bg-amber-50 transition-colors" data-query="Web Design">Web Design</span>
                            <span class="search-chip px-3 py-1 bg-white rounded-full text-sm text-amber-700 border border-amber-200 cursor-pointer hover:bg-amber-50 transition-colors" data-query="Writing">Writing</span>
                            <span class="search-chip px-3 py-1 bg-white rounded-full text-sm text-amber-700 border border-amber-200 cursor-pointer hover:bg-amber-50 transition-colors" data-query="Marketing">Marketing</span>
                            <span class="search-chip px-3 py-1 bg-white rounded-full text-sm text-amber-700 border border-amber-200 cursor-pointer hover:bg-amber-50 transition-colors" data-query="Development">Development</span>
                        </div>
                    </div>
                </div>

                <!-- Right Content - Illustration -->
                <div class="relative animate-float">
                    <div class="relative bg-gradient-to-br from-amber-400 to-orange-500 rounded-3xl p-8 shadow-2xl">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-white rounded-2xl p-6 shadow-lg cursor-pointer hover:scale-110 transition-transform">
                                <div class="text-3xl mb-2">💼</div>
                                <div class="text-xs text-gray-500">Business</div>
                                <div class="text-lg font-bold text-amber-600 mt-1">2.5k+</div>
                            </div>
                            <div class="bg-white rounded-2xl p-6 shadow-lg cursor-pointer hover:scale-110 transition-transform">
                                <div class="text-3xl mb-2">🎨</div>
                                <div class="text-xs text-gray-500">Creative</div>
                                <div class="text-lg font-bold text-amber-600 mt-1">3.1k+</div>
                            </div>
                            <div class="bg-white rounded-2xl p-6 shadow-lg cursor-pointer hover:scale-110 transition-transform">
                                <div class="text-3xl mb-2">💻</div>
                                <div class="text-xs text-gray-500">Tech</div>
                                <div class="text-lg font-bold text-amber-600 mt-1">4.2k+</div>
                            </div>
                            <div class="bg-white rounded-2xl p-6 shadow-lg cursor-pointer hover:scale-110 transition-transform">
                                <div class="text-3xl mb-2">📱</div>
                                <div class="text-xs text-gray-500">Marketing</div>
                                <div class="text-lg font-bold text-amber-600 mt-1">1.8k+</div>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Bees -->
                    <div class="absolute text-2xl animate-float-bee" style="left: 20%; top: 10%;">🐝</div>
                    <div class="absolute text-2xl animate-float-bee" style="left: 50%; top: 30%; animation-delay: 0.5s;">🐝</div>
                    <div class="absolute text-2xl animate-float-bee" style="left: 80%; top: 50%; animation-delay: 1s;">🐝</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-16 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-amber-100 via-yellow-100 to-orange-100 opacity-50"></div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="text-center group">
                    <div class="inline-block group-hover:scale-110 transition-transform">
                        <div class="text-4xl lg:text-5xl font-bold text-amber-600 mb-2">
                            <span class="counter" data-target="50000">0</span>+
                        </div>
                        <div class="text-sm text-gray-600 group-hover:text-amber-700 transition-colors">Active Freelancers</div>
                    </div>
                </div>
                <div class="text-center group">
                    <div class="inline-block group-hover:scale-110 transition-transform">
                        <div class="text-4xl lg:text-5xl font-bold text-amber-600 mb-2">
                            <span class="counter" data-target="15000">0</span>+
                        </div>
                        <div class="text-sm text-gray-600 group-hover:text-amber-700 transition-colors">Projects Completed</div>
                    </div>
                </div>
                <div class="text-center group">
                    <div class="inline-block group-hover:scale-110 transition-transform">
                        <div class="text-4xl lg:text-5xl font-bold text-amber-600 mb-2">
                            <span class="counter" data-target="98">0</span>%
                        </div>
                        <div class="text-sm text-gray-600 group-hover:text-amber-700 transition-colors">Client Satisfaction</div>
                    </div>
                </div>
                <div class="text-center group">
                    <div class="inline-block group-hover:scale-110 transition-transform">
                        <div class="text-4xl lg:text-5xl font-bold text-amber-600 mb-2">
                            <span class="counter" data-target="150">0</span>+
                        </div>
                        <div class="text-sm text-gray-600 group-hover:text-amber-700 transition-colors">Countries Reached</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-24 relative overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-4xl lg:text-5xl font-bold text-gray-900 mb-4">
                    Why Choose <span class="text-amber-600">Task Hive</span>?
                </h2>
                <p class="text-xl text-gray-600 max-w-2xl mx-auto">
                    Experience the sweetest way to connect, collaborate, and succeed in the gig economy.
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="group hover-lift bg-white rounded-2xl p-8 shadow-lg border border-amber-100">
                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-blue-500 to-cyan-500 p-3 mb-6 shadow-lg group-hover:rotate-360 transition-transform duration-500">
                        <i data-lucide="shield" class="w-full h-full text-white"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Secure Payments</h3>
                    <p class="text-gray-600 leading-relaxed">Protected transactions with escrow system ensuring safety for both clients and freelancers.</p>
                </div>

                <div class="group hover-lift bg-white rounded-2xl p-8 shadow-lg border border-amber-100">
                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-amber-500 to-yellow-500 p-3 mb-6 shadow-lg group-hover:rotate-360 transition-transform duration-500">
                        <i data-lucide="zap" class="w-full h-full text-white"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Quick Matching</h3>
                    <p class="text-gray-600 leading-relaxed">AI-powered algorithm connects you with the perfect opportunities in seconds.</p>
                </div>

                <div class="group hover-lift bg-white rounded-2xl p-8 shadow-lg border border-amber-100">
                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-purple-500 to-pink-500 p-3 mb-6 shadow-lg group-hover:rotate-360 transition-transform duration-500">
                        <i data-lucide="users" class="w-full h-full text-white"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Verified Talents</h3>
                    <p class="text-gray-600 leading-relaxed">All freelancers are thoroughly vetted to ensure quality and professionalism.</p>
                </div>

                <div class="group hover-lift bg-white rounded-2xl p-8 shadow-lg border border-amber-100">
                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-green-500 to-emerald-500 p-3 mb-6 shadow-lg group-hover:rotate-360 transition-transform duration-500">
                        <i data-lucide="award" class="w-full h-full text-white"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Quality Guaranteed</h3>
                    <p class="text-gray-600 leading-relaxed">Built-in review system and milestone tracking for exceptional results.</p>
                </div>

                <div class="group hover-lift bg-white rounded-2xl p-8 shadow-lg border border-amber-100">
                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-orange-500 to-red-500 p-3 mb-6 shadow-lg group-hover:rotate-360 transition-transform duration-500">
                        <i data-lucide="dollar-sign" class="w-full h-full text-white"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Fair Pricing</h3>
                    <p class="text-gray-600 leading-relaxed">Transparent pricing with no hidden fees. Only pay when you're satisfied.</p>
                </div>

                <div class="group hover-lift bg-white rounded-2xl p-8 shadow-lg border border-amber-100">
                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-500 p-3 mb-6 shadow-lg group-hover:rotate-360 transition-transform duration-500">
                        <i data-lucide="clock" class="w-full h-full text-white"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">24/7 Support</h3>
                    <p class="text-gray-600 leading-relaxed">Round-the-clock customer support to help you whenever you need assistance.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Categories Section -->
    <section id="categories" class="py-24 bg-white relative overflow-hidden bg-honeycomb">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="text-center mb-16">
                <h2 class="text-4xl lg:text-5xl font-bold text-gray-900 mb-4">
                    Explore Popular <span class="text-amber-600">Categories</span>
                </h2>
                <p class="text-xl text-gray-600 max-w-2xl mx-auto">
                    Discover opportunities across hundreds of categories or post your own task.
                </p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="Development & IT">
                        <div class="w-12 h-12 rounded-xl bg-blue-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="code" class="w-6 h-6 text-blue-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">Development & IT</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">4,250 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>

                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="Design & Creative">
                        <div class="w-12 h-12 rounded-xl bg-purple-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="palette" class="w-6 h-6 text-purple-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">Design & Creative</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">3,180 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>

                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="Marketing & Sales">
                        <div class="w-12 h-12 rounded-xl bg-pink-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="megaphone" class="w-6 h-6 text-pink-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">Marketing & Sales</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">2,940 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>

                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="Writing & Content">
                        <div class="w-12 h-12 rounded-xl bg-green-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="file-text" class="w-6 h-6 text-green-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">Writing & Content</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">2,710 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>

                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="Video & Animation">
                        <div class="w-12 h-12 rounded-xl bg-red-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="camera" class="w-6 h-6 text-red-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">Video & Animation</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">1,890 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>

                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="Business & Consulting">
                        <div class="w-12 h-12 rounded-xl bg-indigo-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="trending-up" class="w-6 h-6 text-indigo-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">Business & Consulting</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">1,650 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>

                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="Music & Audio">
                        <div class="w-12 h-12 rounded-xl bg-orange-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="music" class="w-6 h-6 text-orange-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">Music & Audio</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">980 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>

                <div class="group cursor-pointer hover:scale-105 hover:-translate-y-2 transition-all duration-300">
                    <div class="bg-gradient-to-br from-white to-amber-50 rounded-2xl p-6 shadow-md hover:shadow-xl transition-shadow border border-amber-100 relative overflow-hidden" data-category="E-commerce">
                        <div class="w-12 h-12 rounded-xl bg-cyan-500 bg-opacity-10 flex items-center justify-center mb-4">
                            <i data-lucide="shopping-bag" class="w-6 h-6 text-cyan-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-amber-600 transition-colors">E-commerce</h3>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500">1,420 jobs</span>
                            <span class="text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity">→</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-12">
                <a href="#categories" class="inline-block px-8 py-4 bg-gradient-to-r from-amber-500 to-orange-500 text-white font-semibold rounded-full shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-105">
                    Browse All Categories
                </a>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section id="how-it-works" class="py-24 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-b from-amber-50 to-white"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="text-center mb-16">
                <h2 class="text-4xl lg:text-5xl font-bold text-gray-900 mb-4">
                    How <span class="text-amber-600">Task Hive</span> Works
                </h2>
                <p class="text-xl text-gray-600 max-w-2xl mx-auto">
                    Getting started is as easy as 1-2-3-4. Join the hive and start buzzing!
                </p>
            </div>

            <div class="relative">
                <div class="hidden lg:block absolute top-1/2 left-0 right-0 h-1 bg-gradient-to-r from-amber-200 via-amber-400 to-amber-200 -translate-y-1/2"></div>

                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8 relative z-10">
                    <div class="relative">
                        <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-all duration-300 border border-amber-100 group h-full">
                            <div class="absolute -top-4 -right-4 w-12 h-12 bg-gradient-to-br from-amber-500 to-orange-500 rounded-full flex items-center justify-center text-white font-bold shadow-lg">01</div>
                            <div class="w-16 h-16 bg-gradient-to-br from-amber-100 to-orange-100 rounded-2xl flex items-center justify-center mb-6 group-hover:from-amber-500 group-hover:to-orange-500 transition-all duration-300">
                                <i data-lucide="user-plus" class="w-8 h-8 text-amber-600 group-hover:text-white transition-colors"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Create Your Profile</h3>
                            <p class="text-gray-600 leading-relaxed">Sign up in minutes and showcase your skills or describe your project needs.</p>
                        </div>
                        <div class="hidden lg:block absolute top-1/2 -right-12 text-2xl z-20 animate-float-bee">🐝</div>
                    </div>

                    <div class="relative">
                        <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-all duration-300 border border-amber-100 group h-full">
                            <div class="absolute -top-4 -right-4 w-12 h-12 bg-gradient-to-br from-amber-500 to-orange-500 rounded-full flex items-center justify-center text-white font-bold shadow-lg">02</div>
                            <div class="w-16 h-16 bg-gradient-to-br from-amber-100 to-orange-100 rounded-2xl flex items-center justify-center mb-6 group-hover:from-amber-500 group-hover:to-orange-500 transition-all duration-300">
                                <i data-lucide="search" class="w-8 h-8 text-amber-600 group-hover:text-white transition-colors"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Find or Post Jobs</h3>
                            <p class="text-gray-600 leading-relaxed">Browse thousands of gigs or post your own task to attract top talent.</p>
                        </div>
                        <div class="hidden lg:block absolute top-1/2 -right-12 text-2xl z-20 animate-float-bee" style="animation-delay: 0.5s;">🐝</div>
                    </div>

                    <div class="relative">
                        <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-all duration-300 border border-amber-100 group h-full">
                            <div class="absolute -top-4 -right-4 w-12 h-12 bg-gradient-to-br from-amber-500 to-orange-500 rounded-full flex items-center justify-center text-white font-bold shadow-lg">03</div>
                            <div class="w-16 h-16 bg-gradient-to-br from-amber-100 to-orange-100 rounded-2xl flex items-center justify-center mb-6 group-hover:from-amber-500 group-hover:to-orange-500 transition-all duration-300">
                                <i data-lucide="handshake" class="w-8 h-8 text-amber-600 group-hover:text-white transition-colors"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Connect & Collaborate</h3>
                            <p class="text-gray-600 leading-relaxed">Match with the perfect freelancer or client and start working together.</p>
                        </div>
                        <div class="hidden lg:block absolute top-1/2 -right-12 text-2xl z-20 animate-float-bee" style="animation-delay: 1s;">🐝</div>
                    </div>

                    <div class="relative">
                        <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-all duration-300 border border-amber-100 group h-full">
                            <div class="absolute -top-4 -right-4 w-12 h-12 bg-gradient-to-br from-amber-500 to-orange-500 rounded-full flex items-center justify-center text-white font-bold shadow-lg">04</div>
                            <div class="w-16 h-16 bg-gradient-to-br from-amber-100 to-orange-100 rounded-2xl flex items-center justify-center mb-6 group-hover:from-amber-500 group-hover:to-orange-500 transition-all duration-300">
                                <i data-lucide="check-circle" class="w-8 h-8 text-amber-600 group-hover:text-white transition-colors"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-amber-600 transition-colors">Complete & Get Paid</h3>
                            <p class="text-gray-600 leading-relaxed">Deliver quality work, get paid securely, and build your reputation.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-16">
                <a href="register.php" class="inline-block px-8 py-4 bg-gradient-to-r from-amber-500 to-orange-500 text-white font-semibold rounded-full shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-105">
                    Get Started Today
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gradient-to-br from-gray-900 via-gray-800 to-amber-900 text-white relative overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <!-- Main Footer Content -->
            <div class="py-16 grid md:grid-cols-2 lg:grid-cols-6 gap-8">
                <!-- Brand Column -->
                <div class="lg:col-span-2">
                    <div class="flex items-center gap-3 mb-6 hover:scale-105 transition-transform cursor-pointer">
                        <svg class="w-10 h-10 fill-amber-400 stroke-amber-600 stroke-2" viewBox="0 0 24 24">
                            <polygon points="12 2, 22 8.5, 22 15.5, 12 22, 2 15.5, 2 8.5"/>
                        </svg>
                        <div>
                            <h3 class="text-xl font-bold text-white">Task Hive</h3>
                            <p class="text-xs text-amber-400 -mt-1">Find Your Buzz</p>
                        </div>
                    </div>
                    <p class="text-gray-400 mb-6 leading-relaxed">
                        Join thousands of freelancers and clients building successful projects together. Your next opportunity is just a click away.
                    </p>
                    
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center gap-3 text-gray-400 hover:text-amber-400 transition-colors cursor-pointer">
                            <i data-lucide="mail" class="w-4 h-4"></i>
                            <span>hello@taskhive.com</span>
                        </div>
                        <div class="flex items-center gap-3 text-gray-400 hover:text-amber-400 transition-colors cursor-pointer">
                            <i data-lucide="phone" class="w-4 h-4"></i>
                            <span>+1 (555) 123-4567</span>
                        </div>
                        <div class="flex items-center gap-3 text-gray-400 hover:text-amber-400 transition-colors cursor-pointer">
                            <i data-lucide="map-pin" class="w-4 h-4"></i>
                            <span>San Francisco, CA</span>
                        </div>
                    </div>
                </div>

                <!-- Link Columns -->
                <div>
                    <h4 class="text-lg font-bold text-white mb-4">For Freelancers</h4>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Find Work</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Create Profile</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">How to Succeed</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Success Stories</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="text-lg font-bold text-white mb-4">For Clients</h4>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Post a Job</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Find Talent</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Enterprise Solutions</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Pricing Plans</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="text-lg font-bold text-white mb-4">Resources</h4>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Help Center</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Blog</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Community</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Guides & Tutorials</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="text-lg font-bold text-white mb-4">Company</h4>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">About Us</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Careers</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Press</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Contact Us</a></li>
                    </ul>
                </div>
            </div>

            <!-- Newsletter Section -->
            <div class="py-8 border-t border-gray-700">
                <div class="grid md:grid-cols-2 gap-8 items-center">
                    <div>
                        <h3 class="text-xl font-bold text-white mb-2">Stay in the Loop</h3>
                        <p class="text-gray-400">
                            Get the latest gigs, tips, and opportunities delivered to your inbox.
                        </p>
                    </div>
                    <div>
                        <div class="flex gap-2">
                            <input type="email" placeholder="Enter your email" class="flex-1 px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-amber-500 transition-colors">
                            <button class="px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white font-semibold rounded-lg hover:shadow-lg transition-all duration-300 hover:scale-105">
                                Subscribe
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="py-8 border-t border-gray-700">
                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                    <p class="text-gray-400 text-sm">© 2025 Task Hive. All rights reserved.</p>

                    <div class="flex items-center gap-4">
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center text-gray-400 hover:text-white hover:bg-gradient-to-br hover:from-amber-500 hover:to-orange-500 transition-all duration-300 hover:scale-110">
                            <i data-lucide="facebook" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center text-gray-400 hover:text-white hover:bg-gradient-to-br hover:from-amber-500 hover:to-orange-500 transition-all duration-300 hover:scale-110">
                            <i data-lucide="twitter" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center text-gray-400 hover:text-white hover:bg-gradient-to-br hover:from-amber-500 hover:to-orange-500 transition-all duration-300 hover:scale-110">
                            <i data-lucide="instagram" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center text-gray-400 hover:text-white hover:bg-gradient-to-br hover:from-amber-500 hover:to-orange-500 transition-all duration-300 hover:scale-110">
                            <i data-lucide="linkedin" class="w-5 h-5"></i>
                        </a>
                    </div>

                    <div class="flex items-center gap-6 text-sm">
                        <a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Privacy Policy</a>
                        <a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Terms of Service</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="absolute bottom-10 right-10 text-4xl animate-float-bee">🐝</div>
    </footer>

    <script>
        // Initialize Lucide Icons
        lucide.createIcons();

        // Mobile Menu Toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');

        mobileMenuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('active');
        });

        // Counter Animation
        const counters = document.querySelectorAll('.counter');
        
        const observerOptions = {
            threshold: 0.5,
            rootMargin: '0px'
        };

        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const counter = entry.target;
                    const target = parseInt(counter.getAttribute('data-target'));
                    const duration = 2000; // 2 seconds
                    const increment = target / (duration / 16); // 60fps
                    let current = 0;

                    const updateCounter = () => {
                        current += increment;
                        if (current < target) {
                            counter.textContent = Math.floor(current).toLocaleString();
                            requestAnimationFrame(updateCounter);
                        } else {
                            counter.textContent = target.toLocaleString();
                        }
                    };

                    updateCounter();
                    counterObserver.unobserve(counter);
                }
            });
        }, observerOptions);

        counters.forEach(counter => {
            counterObserver.observe(counter);
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add scroll animation to elements
        const observeElements = document.querySelectorAll('.hover-lift, .group');
        
        const fadeInObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });

        observeElements.forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            fadeInObserver.observe(el);
        });

        // Make logo clickable to go home
        document.getElementById('logo')?.addEventListener('click', () => {
            window.location.href = 'index.php';
        });

        // CTA button navigation (desktop + mobile)
        document.getElementById('btn-signin-desktop')?.addEventListener('click', () => {
            window.location.href = 'login.php';
        });
        document.getElementById('btn-register-desktop')?.addEventListener('click', () => {
            window.location.href = 'register.php';
        });
        document.getElementById('btn-signin-mobile')?.addEventListener('click', () => {
            window.location.href = 'login.php';
        });
        document.getElementById('btn-register-mobile')?.addEventListener('click', () => {
            window.location.href = 'register.php';
        });

        // Search behavior
        const searchInput = document.getElementById('search-input');
        const goSearch = () => {
            const q = (searchInput?.value || '').trim();
            const url = 'feed.php' + (q ? ('?q=' + encodeURIComponent(q)) : '');
            window.location.href = url;
        };
        document.getElementById('search-btn')?.addEventListener('click', goSearch);
        searchInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                goSearch();
            }
        });
        document.querySelectorAll('.search-chip').forEach((chip) => {
            chip.addEventListener('click', () => {
                const q = chip.getAttribute('data-query') || chip.textContent.trim();
                window.location.href = 'feed.php?q=' + encodeURIComponent(q);
            });
        });

        // Category cards -> navigate with category filter
        document.querySelectorAll('[data-category]').forEach((card) => {
            card.addEventListener('click', () => {
                const cat = card.getAttribute('data-category') || '';
                window.location.href = 'feed.php?category=' + encodeURIComponent(cat);
            });
        });

        // Notifications dropdown (desktop)
        function toggleNotifications() {
            const dd = document.getElementById('notifications-dropdown');
            const pd = document.getElementById('profile-dropdown');
            if (pd && !pd.classList.contains('hidden')) pd.classList.add('hidden');
            if (dd) dd.classList.toggle('active');
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

        // Profile dropdown (if present)
        const profileBtn = document.getElementById('profile-btn');
        const profileDropdown = document.getElementById('profile-dropdown');
        const dropdownArrow = document.getElementById('dropdown-arrow');
        if (profileBtn && profileDropdown) {
            const toggleProfileDropdown = () => {
                const isHidden = profileDropdown.classList.contains('hidden');
                if (isHidden) {
                    profileDropdown.classList.remove('hidden');
                    dropdownArrow && (dropdownArrow.style.transform = 'rotate(180deg)');
                } else {
                    profileDropdown.classList.add('hidden');
                    dropdownArrow && (dropdownArrow.style.transform = 'rotate(0deg)');
                }
            };
            profileBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleProfileDropdown();
            });
            document.addEventListener('click', (e) => {
                // Close notifications when clicking outside
                const notifDd = document.getElementById('notifications-dropdown');
                if (notifDd && notifDd.classList.contains('active') && !e.target.closest('#notifications-dropdown') && !e.target.closest('button[title="Notifications"]')) {
                    notifDd.classList.remove('active');
                }
                if (!profileDropdown.classList.contains('hidden')) {
                    // Close when clicking outside
                    if (!e.target.closest('#profile-btn') && !e.target.closest('#profile-dropdown')) {
                        profileDropdown.classList.add('hidden');
                        dropdownArrow && (dropdownArrow.style.transform = 'rotate(0deg)');
                    }
                }
            });
        }
    </script>

</body>
</html>
