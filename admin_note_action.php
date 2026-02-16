<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$admin = $db->getUser((int)$_SESSION['user_id']);
if (!$admin || $admin['user_type'] !== 'admin') { echo 'Access denied.'; exit; }

$pdo = $db->opencon();
if (!csrf_validate()) {
  flash_set('error','Security check failed.');
  header('Location: ' . (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php'));
  exit;
}
$target_type = $_POST['target_type'] ?? '';
$target_id = (int)($_POST['target_id'] ?? 0);
$note = trim($_POST['note'] ?? '');

if (!$target_type || !$target_id || $note==='') {
  flash_set('error','Missing note or target.');
  header('Location: ' . (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php'));
  exit;
}

// Create table if not exists (best-effort)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notes (
    note_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    target_type VARCHAR(32) NOT NULL,
    target_id INT NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target (target_type, target_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore */ }

try {
  $stmt = $pdo->prepare('INSERT INTO admin_notes (admin_id,target_type,target_id,note) VALUES (?,?,?,?)');
  $stmt->execute([(int)$_SESSION['user_id'], $target_type, $target_id, $note]);
  flash_set('success','Note added.');
} catch (Throwable $e) {
  flash_set('error','Failed to add note: '.$e->getMessage());
}

$back = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php';
header("Location: $back");
<?php