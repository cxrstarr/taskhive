<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

$db = new database();

$currentUser = null;
$unreadTotal = 0;
if (!empty($_SESSION['user_id'])) {
    $currentUser = $db->getUser((int)$_SESSION['user_id']);
    if ($currentUser && method_exists($db,'countUnreadMessages')) {
        $unreadTotal = $db->countUnreadMessages((int)$currentUser['user_id']);
    }
}

// Search & pagination
$search  = isset($_GET['q']) ? trim($_GET['q']) : '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
$offset  = ($page - 1) * $perPage;

if (!method_exists($db,'listAllServices') || !method_exists($db,'countAllServices')) {
    die('Your database.php needs listAllServices() + countAllServices().');
}

$services = $db->listAllServices($perPage, $offset, $search);
$total    = $db->countAllServices($search);
$pages    = max(1, (int)ceil($total / $perPage));

function navActive($needle) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (stripos($uri,$needle)!==false) ? ' active' : '';
}

$isFreelancer = $currentUser && $currentUser['user_type']==='freelancer';
$isClient     = $currentUser && $currentUser['user_type']==='client';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>TaskHive 🐝 - Main Page</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="mainpage.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .sidebar { display:flex; flex-direction:column; }
    .sidebar .brand-mini { font-weight:600; font-size:1.05rem; letter-spacing:.5px; }
    .sidebar .nav-link { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:0.9rem; padding:8px 12px; }
    .sidebar .nav-link i { width:18px; }
    .sidebar .nav-link.active, .sidebar .nav-link:hover { background:#1f2326; color:#fff; }
    .unread-badge { background:#dc3545; font-size:0.65rem; padding:3px 7px; border-radius:12px; font-weight:600; }
    .sidebar-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1019; display:none; }
    .sidebar-open .sidebar-overlay { display:block; }
    .sidebar-open .sidebar { transform:translateX(0); }
    @media (max-width: 991.98px) {
      .sidebar { position:fixed; top:0; left:0; bottom:0; width:240px; padding-top:60px; background:#121416;
                 transform:translateX(-260px); transition:transform .25s; border-right:1px solid #222; z-index:1020; }
      body.has-sidebar .main-wrapper { margin-left:0; }
    }
    .top-navbar .btn-toggle-sidebar { border:none; background:transparent; color:#ffca28; font-size:1.35rem; line-height:1; padding:4px 8px; }
    .feed-empty { border:1px dashed #bbb; background:#fffdfa; }
    .service-card .card-title { font-size:1rem; line-height:1.2; }
    .btn-hive { background:#ffca28; color:#333; font-weight:600; border:none; }
    .btn-hive:hover { background:#ffa726; color:#fff; }
    .service-card a.text-decoration-none { color:#333; }
    .service-card a.text-decoration-none:hover { text-decoration:underline; }
    form.inline-message-btn { margin:0; }
  </style>
</head>
<body class="<?= $currentUser ? 'has-sidebar' : '' ?>">

<!-- Top Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark top-navbar position-sticky top-0 w-100" style="z-index:1030;">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <?php if ($currentUser): ?>
        <button class="btn-toggle-sidebar d-lg-none" id="sidebarToggle" aria-label="Toggle sidebar">
          <i class="bi bi-list"></i>
        </button>
      <?php endif; ?>
      <a class="navbar-brand fw-bold text-warning" href="mainpage.php">TaskHive 🐝</a>
    </div>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item"><a class="nav-link<?= navActive('#feed'); ?>" href="#feed">Feed</a></li>
        <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
        <?php if ($currentUser):
              $profileLink = $isFreelancer ? 'freelancer_profile.php' : 'client_profile.php';
              $pic         = $currentUser['profile_picture'] ?: 'img/client1.webp';
              $fullName    = $currentUser['first_name'].' '.$currentUser['last_name'];
        ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" data-bs-toggle="dropdown">
              <img src="<?= htmlspecialchars($pic); ?>" alt="Profile" class="rounded-circle me-2" style="width:32px;height:32px;object-fit:cover;">
              <span class="d-inline-block" style="max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= htmlspecialchars($fullName); ?>
              </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= $profileLink; ?>">View Dashboard</a></li>
              <li><a class="dropdown-item" href="user_profile.php?id=<?= (int)$currentUser['user_id']; ?>">Public Profile</a></li>
              <li><a class="dropdown-item" href="logout.php">Logout</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
          <li class="nav-item"><a class="nav-link" href="register.php">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<?php if ($currentUser): ?>
<!-- Sidebar & overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar">
  <div class="px-3 pb-2 d-none d-lg-block">
    <div class="brand-mini text-warning">Navigation</div>
  </div>
  <ul class="nav flex-column px-2 pb-4 mt-2">

    <li class="nav-item mb-1">
      <a href="inbox.php" class="nav-link text-light<?= navActive('inbox.php'); ?>">
        <span><i class="bi bi-envelope me-2"></i>Inbox</span>
        <?php if ($unreadTotal > 0): ?>
          <span class="unread-badge"><?= $unreadTotal; ?></span>
        <?php endif; ?>
      </a>
    </li>

    <?php if ($isClient): ?>
      <li class="nav-item mb-1">
        <a href="client_profile.php" class="nav-link text-light<?= navActive('client_profile.php'); ?>">
          <span><i class="bi bi-briefcase me-2"></i>My Bookings</span>
        </a>
      </li>
    <?php elseif ($isFreelancer): ?>
      <li class="nav-item mb-1">
        <a href="freelancer_profile.php" class="nav-link text-light<?= navActive('freelancer_profile.php'); ?>">
          <span><i class="bi bi-hammer me-2"></i>My Services</span>
        </a>
      </li>
    <?php endif; ?>

    <li class="nav-item mb-1">
      <a href="#feed" class="nav-link text-light">
        <span><i class="bi bi-grid me-2"></i>Browse Services</span>
      </a>
    </li>

    <li class="nav-item mb-1">
      <a href="user_profile.php?id=<?= $currentUser['user_id']; ?>" class="nav-link text-light">
        <span><i class="bi bi-person-circle me-2"></i>Public Profile</span>
      </a>
    </li>

    <li class="nav-item mt-3 mb-1">
      <a href="logout.php" class="nav-link text-light">
        <span><i class="bi bi-box-arrow-right me-2"></i>Logout</span>
      </a>
    </li>
  </ul>
</aside>
<?php endif; ?>

<main class="main-wrapper">
  <section id="feed" class="py-4">
    <div class="container-fluid container-max">
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
        <h2 class="mb-0 fw-bold">Latest Services</h2>

        <form method="GET" action="#feed" class="d-flex service-search gap-2 w-100 w-md-auto">
          <input type="text"
                 name="q"
                 value="<?= htmlspecialchars($search); ?>"
                 class="form-control"
                 placeholder="Search services (title or description)...">
          <button class="btn btn-hive">Search</button>
          <?php if ($search): ?>
            <a href="mainpage.php#feed" class="btn btn-outline-secondary">Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="row g-4">
        <?php if ($services): ?>
          <?php foreach($services as $svc):
             $freelancerName = $svc['first_name'].' '.$svc['last_name'];
             $pic            = $svc['profile_picture'] ?: 'img/client1.webp';
             $rawDesc        = strip_tags($svc['description']);
             $short          = mb_substr($rawDesc,0,140) . (mb_strlen($rawDesc)>140 ? '...' : '');
             $priceTxt       = '₱'.number_format($svc['base_price'],2);
             if ($svc['price_unit']==='hourly')     $priceTxt.='/hr';
             elseif ($svc['price_unit']==='per_unit') $priceTxt.='/unit';
             $detail         = 'service.php?slug='.urlencode($svc['slug']);
             $showMessageBtn = $currentUser && $currentUser['user_id'] != $svc['freelancer_id'];
          ?>
          <div class="col-sm-6 col-lg-4 d-flex">
            <div class="card service-card flex-fill shadow-sm h-100">
              <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center mb-3">
                  <img src="<?= htmlspecialchars($pic); ?>" alt="User" class="rounded-circle me-2" style="width:48px;height:48px;object-fit:cover;">
                  <div>
                    <strong class="d-block small text-truncate" style="max-width:160px;">
                      <a href="user_profile.php?id=<?= (int)$svc['freelancer_id']; ?>" class="text-decoration-none">
                        <?= htmlspecialchars($freelancerName); ?>
                      </a>
                    </strong>
                    <small class="text-muted"><?= htmlspecialchars(date('M d, Y', strtotime($svc['created_at']))); ?></small>
                  </div>
                </div>
                <h5 class="card-title mb-1 text-truncate" title="<?= htmlspecialchars($svc['title']); ?>">
                  <?= htmlspecialchars($svc['title']); ?>
                </h5>
                <p class="text-muted small flex-grow-1 mb-2" style="white-space:pre-wrap;"><?= htmlspecialchars($short); ?></p>
                <div class="d-flex justify-content-between align-items-center mt-2">
                  <span class="badge bg-warning text-dark px-3 py-2"><?= htmlspecialchars($priceTxt); ?></span>
                  <div class="btn-group">
                    <a href="<?= htmlspecialchars($detail); ?>" class="btn btn-sm btn-hive">View</a>
                    <?php if ($showMessageBtn): ?>
                      <form method="POST" action="start_conversation.php" class="inline-message-btn">
                        <input type="hidden" name="target_user_id" value="<?= (int)$svc['freelancer_id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Message</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-12">
            <div class="alert feed-empty text-center mb-0">
              No services found.
            </div>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($pages > 1): ?>
        <nav class="mt-4">
          <ul class="pagination justify-content-center">
            <?php
              $base = 'mainpage.php'.($search ? '?q='.urlencode($search).'&' : '?');
              echo '<li class="page-item'.($page==1?' disabled':'').'"><a class="page-link" href="'.$base.'page='.(max(1,$page-1)).'#feed">&laquo;</a></li>';
              for ($p=1;$p<=$pages;$p++) {
                  $active = $p==$page ? ' active':'';
                  echo '<li class="page-item'.$active.'"><a class="page-link" href="'.$base.'page='.$p.'#feed">'.$p.'</a></li>';
              }
              echo '<li class="page-item'.($page==$pages?' disabled':'').'"><a class="page-link" href="'.$base.'page='.(min($pages,$page+1)).'#feed">&raquo;</a></li>';
            ?>
          </ul>
        </nav>
      <?php endif; ?>

    </div>
  </section>
</main>

<footer class="footer bg-dark text-light py-3 mt-auto">
  <div class="container text-center">
    <small>&copy; 2025 TaskHive. All rights reserved.</small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const body = document.body;
const toggleBtn = document.getElementById('sidebarToggle');
const overlay = document.getElementById('sidebarOverlay');

function openSidebar(){ body.classList.add('sidebar-open'); }
function closeSidebar(){ body.classList.remove('sidebar-open'); }

if (toggleBtn) {
  toggleBtn.addEventListener('click', e => {
    e.preventDefault();
    body.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
  });
}
if (overlay) {
  overlay.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
}

/*
  Real-time unread badge (sidebar Inbox)
  - Polls unread_count.php every 4s (paused when tab hidden, resumed when visible)
  - Updates/creates the red unread badge without reloading the page
  - Also updates the page title with (N) when there are unread messages
*/
(function(){
  const INBOX_SELECTOR = '.sidebar a.nav-link[href="inbox.php"]';
  const POLL_MS_VISIBLE = 4000;
  const POLL_MS_HIDDEN  = 12000;

  let timer = null;
  let lastUnread = 0;

  function setTitleCount(n){
    const base = 'TaskHive 🐝';
    if (n > 0) {
      if (!document.title.startsWith('(')) {
        document.title = `(${n}) ${document.title}`;
      } else {
        document.title = document.title.replace(/^\(\d+\)\s*/, `(${n}) `);
      }
    } else {
      document.title = base + ' - Main Page';
    }
  }

  function updateBadge(count){
    const link = document.querySelector(INBOX_SELECTOR);
    if (!link) return;

    let badge = link.querySelector('.unread-badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'unread-badge';
        link.appendChild(badge);
      }
      badge.textContent = count;
    } else if (badge) {
      badge.remove();
    }
  }

  async function pollOnce(){
    try {
      const controller = new AbortController();
      const t = setTimeout(() => controller.abort(), 7000); // safety timeout
      const res = await fetch('unread_count.php', { cache: 'no-store', signal: controller.signal });
      clearTimeout(t);
      if (!res.ok) return;
      const data = await res.json();
      const count = Number(data && data.unread || 0);

      if (count !== lastUnread) {
        updateBadge(count);
        setTitleCount(count);
        lastUnread = count;
      }
    } catch(e) {
      // silent
    }
  }

  function schedule(){
    clearInterval(timer);
    const delay = document.hidden ? POLL_MS_HIDDEN : POLL_MS_VISIBLE;
    timer = setInterval(pollOnce, delay);
  }

  // Initialize from server-rendered value if present
  (function bootstrapLast(){
    const link = document.querySelector(INBOX_SELECTOR);
    const badge = link ? link.querySelector('.unread-badge') : null;
    lastUnread = badge ? Number(badge.textContent || 0) : 0;
    setTitleCount(lastUnread);
  })();

  pollOnce();
  schedule();
  document.addEventListener('visibilitychange', schedule);
})();
</script>
<?= flash_render(); ?>
</body>
</html>