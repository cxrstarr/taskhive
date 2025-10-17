<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$admin = $db->getUser((int)$_SESSION['user_id']);
if (!$admin || $admin['user_type'] !== 'admin') { echo 'Access denied.'; exit; }

$pdo = $db->opencon();
$action = $_POST['action'] ?? '';
$reason_id = (int)($_POST['reason_id'] ?? 0);
$label = trim($_POST['label'] ?? '');

// Ensure table exists
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS rejection_reasons (
    reason_id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore */ }

try {
  if ($action === 'add') {
    if ($label === '') throw new Exception('Label required');
    $stmt = $pdo->prepare('INSERT INTO rejection_reasons (label) VALUES (?)');
    $stmt->execute([$label]);
    flash_set('success','Reason saved.');
  } elseif ($action === 'delete') {
    if (!$reason_id) throw new Exception('Missing reason id');
    $stmt = $pdo->prepare('DELETE FROM rejection_reasons WHERE reason_id=? LIMIT 1');
    $stmt->execute([$reason_id]);
    flash_set('success','Reason removed.');
  } else {
    flash_set('error','Invalid action.');
  }
} catch (Throwable $e) {
  flash_set('error','Failed: '.$e->getMessage());
}

$back = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php?view=service_queue';
header("Location: $back");
<?php