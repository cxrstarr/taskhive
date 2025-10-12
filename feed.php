<?php
session_start();
require_once __DIR__ . '/database.php';

$db = new database();
$currentUser = null;
$unreadCount = 0;
$profileUrl = 'user_profile.php';

if (!empty($_SESSION['user_id'])) {
    $u = $db->getUser((int)$_SESSION['user_id']);
    if ($u) {
        $currentUser = [
            'name'   => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? 'User'),
            'email'  => $u['email'] ?? '',
            'avatar' => $u['profile_picture'] ?: 'img/profile_icon.webp',
            'user_type' => $u['user_type'] ?? '',
        ];
        $unreadCount = (int)$db->countUnreadMessages((int)$_SESSION['user_id']);

        // Compute destination for "View Profile"
        $freelancerUrl = file_exists(__DIR__ . '/freelancer_dashboard.php') ? 'freelancer_dashboard.php' : 'user_profile.php';
        $clientUrl = file_exists(__DIR__ . '/client_dashboard.php')
            ? 'client_dashboard.php'
            : (file_exists(__DIR__ . '/client.php') ? 'client.php' : 'user_profile.php');
        $profileUrl = ($currentUser['user_type'] === 'freelancer') ? $freelancerUrl : $clientUrl;
    }
}

// Redirect guests to login for now (adjust if you want a public feed)
if (!$currentUser) {
    header('Location: login.php');
    exit;
}

// Load services from database
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$rows = $db->listAllServices(60, 0, $search !== '' ? $search : null);

// Normalize to UI structure expected below
$services = array_map(function($s){
    $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
    $avatar = $s['profile_picture'] ?: 'img/profile_icon.webp';
    $price = '₱' . number_format((float)($s['base_price'] ?? 0), 2);
    $created = isset($s['created_at']) ? date('M d, Y', strtotime($s['created_at'])) : '';
    return [
        'id' => (int)($s['service_id'] ?? 0),
        'user_name' => $name ?: 'Unknown',
        'user_avatar' => $avatar,
        'verified' => true,
        'title' => (string)($s['title'] ?? ''),
        'description' => (string)($s['description'] ?? ''),
        'price' => $price,
        'rating' => isset($s['avg_rating']) ? (float)$s['avg_rating'] : 0.0,
        'reviews' => isset($s['total_reviews']) ? (int)$s['total_reviews'] : 0,
        'date' => $created,
        'category' => $s['category_name'] ?? 'Service'
    ];
}, $rows);
$categories = ['All', 'Education', 'Technology', 'Design', 'Video', 'Gaming', 'Childcare'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
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

    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 via-amber-50/30 to-orange-50/30">

    <!-- Navbar -->
    <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-xl border-b border-amber-200/50 shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Left Section -->
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="p-2 hover:bg-amber-100 rounded-lg transition-colors">
                        <i data-lucide="menu" class="w-5 h-5 text-gray-700"></i>
                    </button>

                    <div class="flex items-center gap-3 cursor-pointer hover:scale-105 transition-transform">
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
                    </div>
                </div>

                <!-- Right Section -->
                <div class="flex items-center gap-3">
                    <!-- Notifications -->
                    <button class="relative p-2 hover:bg-amber-100 rounded-full transition-colors hover:scale-105" title="Notifications">
                        <i data-lucide="bell" class="w-5 h-5 text-gray-700"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-orange-500 text-white text-[10px] leading-[18px] rounded-full text-center">
                                <?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?>
                            </span>
                        <?php endif; ?>
                    </button>

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
                                <a href="<?php echo htmlspecialchars($profileUrl); ?>" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
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
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <button onclick="setActive('inbox', this)" class="sidebar-item w-full flex items-center justify-between px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all" data-name="inbox">
                    <div class="flex items-center gap-3">
                        <i data-lucide="inbox" class="w-5 h-5"></i>
                        <span class="font-medium tracking-wide">Inbox</span>
                    </div>
                    <span id="inbox-count-badge" class="px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold"><?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?></span>
                </button>

                <button onclick="setActive('services', this)" class="sidebar-item w-full flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all" data-name="services">
                    <i data-lucide="briefcase" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">My Services</span>
                </button>

                <button onclick="setActive('browse', this)" class="sidebar-item active w-full flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg transition-all" data-name="browse">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Browse Services</span>
                </button>

                <button onclick="setActive('profile', this)" class="sidebar-item w-full flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all" data-name="profile">
                    <i data-lucide="user" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Public Profile</span>
                </button>

                <button onclick="setActive('favorites', this)" class="sidebar-item w-full flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all" data-name="favorites">
                    <i data-lucide="star" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Favorites</span>
                </button>
            </nav>

            <!-- Bottom Menu -->
            <div class="px-3 py-4 border-t border-white/10 space-y-1">
                <button class="w-full flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Settings</span>
                </button>

                <button class="w-full flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-amber-500/10 hover:translate-x-1 transition-all">
                    <i data-lucide="help-circle" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Help & Support</span>
                </button>

                <button class="w-full flex items-center gap-3 px-4 py-3 rounded-lg text-red-400 hover:bg-red-500/10 hover:translate-x-1 transition-all">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Logout</span>
                </button>
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
                                 data-title="<?php echo strtolower(htmlspecialchars($service['title'])); ?>"
                                 data-description="<?php echo strtolower(htmlspecialchars($service['description'])); ?>"
                                 data-category="<?php echo htmlspecialchars($service['category']); ?>"
                                 style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                
                                <!-- Card Header -->
                                <div class="p-5 bg-gradient-to-br from-amber-50 to-orange-50/50 border-b border-amber-100">
                                    <div class="flex items-start justify-between mb-3">
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
                                        <div class="flex items-center gap-1">
                                            <i data-lucide="star" class="w-4 h-4 text-amber-500 fill-amber-500"></i>
                                            <span class="text-base font-medium text-gray-900"><?php echo number_format($service['rating'], 1); ?></span>
                                        </div>
                                        <span class="text-sm text-gray-500">(<?php echo number_format($service['reviews']); ?> reviews)</span>
                                    </div>

                                    <!-- Price -->
                                    <div class="mb-4 p-3 bg-gradient-to-r from-amber-50 to-orange-50 rounded-lg border border-amber-200">
                                        <div class="text-sm text-gray-600 mb-1">Starting at</div>
                                        <div class="text-xl font-bold text-amber-600"><?php echo htmlspecialchars($service['price']); ?></div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex gap-2">
                                        <button class="flex-1 flex items-center justify-center gap-2 px-4 py-2 border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 hover:border-amber-400 transition-all group">
                                            <i data-lucide="eye" class="w-4 h-4 group-hover:scale-110 transition-transform"></i>
                                            <span class="text-sm font-medium">View</span>
                                        </button>
                                        <button class="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 shadow-md hover:shadow-lg transition-all group">
                                            <i data-lucide="message-circle" class="w-4 h-4 group-hover:scale-110 transition-transform"></i>
                                            <span class="text-sm font-medium">Message</span>
                                        </button>
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
                        <div class="relative bg-white border border-amber-100 rounded-xl overflow-hidden shadow-sm">
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
                                style="width:100%; height: calc(100vh - 10rem); border:0;"
                                loading="lazy"
                            ></iframe>
                        </div>
                    </div><!-- /section-inbox -->
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize Lucide Icons
        lucide.createIcons();

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
            const dropdown = document.getElementById('profile-dropdown');
            const profileButton = event.target.closest('button');
            
            if (!profileButton && dropdown.classList.contains('active')) {
                dropdown.classList.remove('active');
                document.getElementById('dropdown-arrow').style.transform = 'rotate(0deg)';
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
        // Category filter
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

        function clearSearch() {
            document.getElementById('search-input').value = '';
            filterServices();
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
        });
    </script>

</body>
</html>