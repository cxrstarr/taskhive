<?php
// Mock user data
$currentUser = [
    'name' => 'Cayte Tan',
    'email' => 'cayte.tan@taskhive.com',
    'avatar' => 'https://images.unsplash.com/photo-1581065178047-8ee15951ede6?w=200'
];

// Mock notifications
$notifications = [
    ['id' => 1, 'message' => 'New service request from John Doe', 'time' => '5 min ago', 'unread' => true],
    ['id' => 2, 'message' => 'Your service "Web Development" was approved', 'time' => '1 hour ago', 'unread' => true],
    ['id' => 3, 'message' => 'Payment received: ₱1,500.00', 'time' => '2 hours ago', 'unread' => false],
];

$unreadNotifications = count(array_filter($notifications, fn($n) => $n['unread']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & Support - Task Hive</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }

        @keyframes flyBee1 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(20vw, -15vh) rotate(10deg); }
        }

        @keyframes flyBee2 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(20vw, -15vh) rotate(-10deg); }
        }

        @keyframes flyBee3 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(20vw, -15vh) rotate(10deg); }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes orbitBee1 {
            from { transform: rotate(0deg) translateX(80px) rotate(0deg); }
            to { transform: rotate(360deg) translateX(80px) rotate(-360deg); }
        }

        @keyframes orbitBee2 {
            from { transform: rotate(0deg) translateX(80px) rotate(0deg); }
            to { transform: rotate(360deg) translateX(80px) rotate(-360deg); }
        }

        @keyframes progressBar {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 0%; }
        }

        @keyframes hexagonPulse {
            0%, 100% { opacity: 0.3; transform: scale(0.8); }
            50% { opacity: 1; transform: scale(1); }
        }
        
        .animate-slide-down { animation: slideDown 0.2s ease-out forwards; }
        .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
        
        .sidebar { 
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
        }
        .sidebar.open { transform: translateX(0); }
        
        @media (min-width: 1024px) {
            .sidebar { transform: translateX(0) !important; }
        }
        
        .dropdown { display: none; opacity: 0; }
        .dropdown.active { display: block; animation: slideDown 0.2s ease-out forwards; }
        
        .sidebar-item {
            transition: all 0.2s ease;
        }
        
        .sidebar-item.active {
            background: linear-gradient(to right, #f59e0b, #f97316);
            color: white;
        }
        
        .sidebar-item:not(.active):hover {
            background: rgba(245, 158, 11, 0.1);
            transform: translateX(4px);
        }
        
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        @media (max-width: 1024px) {
            .sidebar:not(.closed) {
                position: fixed;
                z-index: 50;
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 40;
            }
            .sidebar-overlay.active {
                display: block;
            }
        }

        .honeycomb-pattern {
            background-image: radial-gradient(circle, #f59e0b 1px, transparent 1px);
            background-size: 20px 20px;
            animation: float 6s ease-in-out infinite;
        }

        .hexagon {
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
        }

        .flying-bee {
            position: absolute;
            font-size: 2.5rem;
            pointer-events: none;
        }

        .flying-bee:nth-child(1) {
            left: 20vw;
            top: 30vh;
            animation: flyBee1 8s ease-in-out infinite;
        }

        .flying-bee:nth-child(2) {
            left: 60vw;
            top: 50vh;
            animation: flyBee2 10s ease-in-out infinite 0.5s;
        }

        .flying-bee:nth-child(3) {
            left: 80vw;
            top: 20vh;
            animation: flyBee3 12s ease-in-out infinite 1s;
        }

        .beehive-icon {
            animation: pulse 2s ease-in-out infinite;
        }

        .orbit-bee-1 {
            position: absolute;
            font-size: 1.5rem;
            animation: orbitBee1 10s linear infinite;
        }

        .orbit-bee-2 {
            position: absolute;
            font-size: 1.5rem;
            animation: orbitBee2 8s linear infinite;
        }

        .progress-fill {
            animation: progressBar 3s ease-in-out infinite;
        }

        .hexagon-item {
            animation: hexagonPulse 2s ease-in-out infinite;
        }

        .hexagon-item:nth-child(1) { animation-delay: 0s; }
        .hexagon-item:nth-child(2) { animation-delay: 0.2s; }
        .hexagon-item:nth-child(3) { animation-delay: 0.4s; }
        .hexagon-item:nth-child(4) { animation-delay: 0.6s; }
        .hexagon-item:nth-child(5) { animation-delay: 0.8s; }
        .hexagon-item:nth-child(6) { animation-delay: 1s; }

        .fade-in-up {
            animation: fadeInUp 0.8s ease-out forwards;
            opacity: 0;
        }

        .delay-200 { animation-delay: 0.2s; }
        .delay-400 { animation-delay: 0.4s; }
        .delay-600 { animation-delay: 0.6s; }
        .delay-800 { animation-delay: 0.8s; }
        .delay-1000 { animation-delay: 1s; }
        .delay-1200 { animation-delay: 1.2s; }

        .back-button {
            transition: all 0.3s ease;
        }

        .back-button:hover {
            transform: scale(1.05);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .back-button:hover .arrow-icon {
            transform: translateX(-4px);
        }

        .arrow-icon {
            transition: transform 0.3s ease;
        }

        .gradient-text {
            background: linear-gradient(to right, #d97706, #f97316, #d97706);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
                        <button onclick="toggleNotifications()" class="relative p-2 hover:bg-amber-100 rounded-full transition-colors hover:scale-105">
                            <i data-lucide="bell" class="w-5 h-5 text-gray-700"></i>
                            <?php if ($unreadNotifications > 0): ?>
                                <span class="absolute -top-1 -right-1 w-5 h-5 bg-orange-500 text-white text-xs rounded-full flex items-center justify-center font-medium">
                                    <?php echo $unreadNotifications; ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <!-- Notifications Dropdown -->
                        <div id="notifications-dropdown" class="dropdown absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden">
                            <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border-b border-amber-200">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-bold text-gray-900">Notifications</h3>
                                    <button onclick="markAllAsRead()" class="text-sm text-amber-600 hover:text-amber-700 font-medium">
                                        Mark all as read
                                    </button>
                                </div>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php foreach ($notifications as $notif): ?>
                                    <div class="p-4 border-b border-amber-100 <?php echo $notif['unread'] ? 'bg-blue-50/50' : ''; ?>">
                                        <div class="flex items-start gap-3">
                                            <?php if ($notif['unread']): ?>
                                                <div class="w-2 h-2 bg-blue-500 rounded-full mt-2 flex-shrink-0"></div>
                                            <?php endif; ?>
                                            <div class="flex-1">
                                                <p class="text-sm text-gray-900 mb-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                                <span class="text-xs text-gray-500"><?php echo htmlspecialchars($notif['time']); ?></span>
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
                            <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" 
                                 alt="<?php echo htmlspecialchars($currentUser['name']); ?>" 
                                 class="w-8 h-8 rounded-full border-2 border-amber-400 object-cover">
                            <span class="hidden md:block text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                            <svg id="dropdown-arrow" class="w-4 h-4 text-gray-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <div id="profile-dropdown" class="dropdown absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden">
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
                            <div class="py-2">
                                <a href="profile.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="user" class="w-5 h-5 text-amber-600"></i>
                                    <span>View Profile</span>
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
                <a href="inbox.php" class="sidebar-item flex items-center justify-between px-4 py-3 rounded-lg text-gray-300">
                    <div class="flex items-center gap-3">
                        <i data-lucide="inbox" class="w-5 h-5"></i>
                        <span class="font-medium tracking-wide">Inbox</span>
                    </div>
                    <span class="px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold">3</span>
                </a>

             

                <a href="feed-enhanced.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Browse Services</span>
                </a>

                <a href="profile.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="user" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Public Profile</span>
                </a>
            </nav>

            <!-- Bottom Menu -->
            <div class="px-3 py-4 border-t border-white/10 space-y-1">
                <a href="settings.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Settings</span>
                </a>

                <a href="helpsupport.php" class="sidebar-item active flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg">
                    <i data-lucide="help-circle" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Help & Support</span>
                </a>

                <a href="logout.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-red-400 hover:bg-red-500/10">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Logout</span>
                </a>
            </div>

            <!-- Bee Decoration -->
            <div class="absolute bottom-4 right-4 text-2xl opacity-20">🐝</div>
        </aside>

        <!-- Main Content -->
        <main id="main-content" class="flex-1 transition-all duration-300 lg:ml-64">
            <div class="min-h-screen bg-gradient-to-br from-amber-50 via-orange-50 to-yellow-50 relative overflow-hidden">
                <!-- Animated Honeycomb Background -->
                <div class="absolute inset-0 opacity-10 pointer-events-none">
                    <div class="absolute top-10 left-10 w-32 h-32 honeycomb-pattern"></div>
                    <div class="absolute top-40 right-20 w-40 h-40 honeycomb-pattern"></div>
                    <div class="absolute bottom-20 left-1/4 w-36 h-36 honeycomb-pattern"></div>
                    <div class="absolute bottom-40 right-1/3 w-28 h-28 honeycomb-pattern"></div>
                </div>

                <!-- Animated Flying Bees -->
                <div class="flying-bee">🐝</div>
                <div class="flying-bee">🐝</div>
                <div class="flying-bee">🐝</div>

                <!-- Under Construction Content -->
                <div class="relative z-10 flex flex-col items-center justify-center min-h-screen px-4 py-12">
                    <div class="text-center max-w-2xl">
                        
                        <!-- Animated Beehive Icon -->
                        <div class="mb-8 flex justify-center fade-in-up">
                            <div class="relative beehive-icon">
                                <div class="w-32 h-32 bg-gradient-to-br from-amber-400 to-orange-500 rounded-full flex items-center justify-center shadow-2xl">
                                    <!-- Help Circle Icon (SVG) -->
                                    <svg class="w-16 h-16 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <!-- Orbiting Bees -->
                                <div class="absolute top-0 left-0 w-full h-full flex items-center justify-center">
                                    <span class="orbit-bee-1">🐝</span>
                                </div>
                                <div class="absolute top-0 left-0 w-full h-full flex items-center justify-center">
                                    <span class="orbit-bee-2">🐝</span>
                                </div>
                            </div>
                        </div>

                        <!-- Main Heading -->
                        <h1 class="text-5xl md:text-6xl font-bold mb-6 gradient-text fade-in-up delay-200">
                            Help Center Coming Soon
                        </h1>

                        <!-- Clever Bee Pun -->
                        <p class="text-xl md:text-2xl text-amber-900 mb-4 fade-in-up delay-400">
                            Our worker bees are <span class="italic font-semibold">buzzing</span> away on this feature!
                        </p>

                        <p class="text-lg text-amber-700 mb-8 fade-in-up delay-600">
                            <span class="font-bold">BEE</span> patient while we craft something sweet for you.
                        </p>

                        <!-- Animated Progress Bar -->
                        <div class="mb-12 fade-in-up delay-800">
                            <div class="bg-white/50 backdrop-blur-sm rounded-full h-4 w-full max-w-md mx-auto overflow-hidden shadow-lg border border-amber-200">
                                <div class="progress-fill h-full bg-gradient-to-r from-amber-400 via-orange-500 to-amber-400 rounded-full"></div>
                            </div>
                            <p class="text-sm text-amber-600 mt-3">Building the perfect comb...</p>
                        </div>

                        <!-- Honeycomb Grid Animation -->
                        <div class="grid grid-cols-3 gap-3 max-w-xs mx-auto mb-12 fade-in-up delay-800">
                            <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                                <span class="text-xl">🍯</span>
                            </div>
                            <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                                <span class="text-xl">🍯</span>
                            </div>
                            <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                                <span class="text-xl">🍯</span>
                            </div>
                            <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                                <span class="text-xl">🍯</span>
                            </div>
                            <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                                <span class="text-xl">🍯</span>
                            </div>
                            <div class="hexagon-item hexagon w-16 h-16 bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center">
                                <span class="text-xl">🍯</span>
                            </div>
                        </div>

                        <!-- Back Button -->
                        <div class="fade-in-up delay-1000">
                            <a href="feed-enhanced.php" class="back-button group inline-flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-full shadow-lg">
                                <!-- Arrow Left Icon -->
                                <svg class="arrow-icon w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                </svg>
                                <span class="font-semibold">Fly Back to Hive</span>
                            </a>
                        </div>

                        <!-- Additional Info -->
                        <p class="mt-8 text-sm text-amber-600 fade-in-up delay-1200">
                            Don't worry, we never <span class="italic">drone</span> on forever! ✨
                        </p>

                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        // Notifications toggle
        function toggleNotifications() {
            const dropdown = document.getElementById('notifications-dropdown');
            const profileDropdown = document.getElementById('profile-dropdown');
            profileDropdown.classList.remove('active');
            dropdown.classList.toggle('active');
        }

        // Profile dropdown toggle
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profile-dropdown');
            const notificationsDropdown = document.getElementById('notifications-dropdown');
            const arrow = document.getElementById('dropdown-arrow');
            notificationsDropdown.classList.remove('active');
            dropdown.classList.toggle('active');
            arrow.style.transform = dropdown.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0)';
        }

        // Mark all as read
        function markAllAsRead() {
            console.log('Marking all notifications as read');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const notificationsBtn = event.target.closest('[onclick="toggleNotifications()"]');
            const profileBtn = event.target.closest('[onclick="toggleProfileDropdown()"]');
            const notificationsDropdown = document.getElementById('notifications-dropdown');
            const profileDropdown = document.getElementById('profile-dropdown');

            if (!notificationsBtn && !notificationsDropdown.contains(event.target)) {
                notificationsDropdown.classList.remove('active');
            }

            if (!profileBtn && !profileDropdown.contains(event.target)) {
                profileDropdown.classList.remove('active');
                document.getElementById('dropdown-arrow').style.transform = 'rotate(0)';
            }
        });
    </script>

</body>
</html>
