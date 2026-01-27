<?php
session_start();
require_once __DIR__ . '/database.php';
$db = new database();
$pdo = $db->opencon();

// Handle impersonation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)($_POST['impersonate_user_id'] ?? 0);
    $redirect = (string)($_POST['redirect'] ?? '');
    if ($uid > 0) {
        $u = $db->getUser($uid);
        if ($u) {
            $_SESSION['user_id'] = (int)$u['user_id'];
            $_SESSION['user_type'] = (string)$u['user_type'];
            $_SESSION['user_email'] = (string)($u['email'] ?? '');
            $_SESSION['user_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            if ($redirect !== '') {
                header('Location: ' . $redirect); exit;
            }
            if ($u['user_type'] === 'freelancer') {
                header('Location: freelancer_dashboard.php');
            } elseif ($u['user_type'] === 'admin') {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: client_dashboard.php');
            }
            exit;
        }
    }
}

// Fetch users list (supports optional search)
$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
    $like = '%' . $search . '%';
    $st = $pdo->prepare("SELECT user_id, first_name, last_name, email, user_type, status, created_at FROM users WHERE 
        (CONCAT(first_name,' ',last_name) LIKE :q OR email LIKE :q OR user_type LIKE :q OR CAST(user_id AS CHAR) LIKE :q)
        ORDER BY user_id ASC LIMIT 200");
    $st->bindValue(':q', $like);
    $st->execute();
} else {
    $st = $pdo->query("SELECT user_id, first_name, last_name, email, user_type, status, created_at FROM users ORDER BY user_id ASC LIMIT 200");
}
$users = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Demo: Switch User - TaskHive</title>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-amber-50 via-yellow-50 to-orange-50 min-h-screen">
  <div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-amber-900">Demo: Switch User</h1>
      <a href="index.php" class="px-4 py-2 bg-white border border-amber-300 text-amber-800 rounded-lg hover:bg-amber-50">Back to Home</a>
    </div>

    <div class="bg-white/70 backdrop-blur-sm rounded-xl border border-amber-200 shadow-sm p-4 mb-4">
      <form method="GET" class="flex gap-2 items-center">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, type, or ID" class="flex-1 px-3 py-2 border border-amber-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-300">
        <button class="px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600">Search</button>
      </form>
      <p class="text-sm text-gray-600 mt-2">Click "Use this user" to immediately impersonate without entering credentials.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($users as $u): ?>
        <div class="bg-white rounded-xl border border-amber-200 shadow-sm p-4">
          <div class="flex items-center justify-between mb-2">
            <div class="font-semibold text-gray-900">#<?= (int)$u['user_id'] ?></div>
            <span class="px-2 py-1 text-xs rounded-full border <?php 
              $t = (string)$u['user_type'];
              echo $t==='admin' ? 'border-red-300 text-red-700 bg-red-50' : ($t==='freelancer' ? 'border-blue-300 text-blue-700 bg-blue-50' : 'border-amber-300 text-amber-800 bg-amber-50');
            ?>"><?= htmlspecialchars($u['user_type']) ?></span>
          </div>
          <div class="text-gray-800 font-medium">
            <?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: '—' ?>
          </div>
          <div class="text-sm text-gray-600"><?= htmlspecialchars($u['email'] ?? '') ?></div>
          <div class="text-xs text-gray-500 mt-1">Status: <?= htmlspecialchars($u['status'] ?? 'active') ?></div>
          <form method="POST" class="mt-3">
            <input type="hidden" name="impersonate_user_id" value="<?= (int)$u['user_id'] ?>">
            <?php if (!empty($_GET['redirect'])): ?>
              <input type="hidden" name="redirect" value="<?= htmlspecialchars((string)$_GET['redirect']) ?>">
            <?php endif; ?>
            <button class="w-full px-3 py-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:shadow-md">Use this user</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!count($users)): ?>
        <div class="col-span-full bg-white rounded-xl border border-amber-200 shadow-sm p-6 text-center text-gray-700">
          No users found. Go to <a href="register.php" class="text-amber-700 underline">Register</a> to create one.
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
