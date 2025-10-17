<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$admin = $db->getUser((int)$_SESSION['user_id']);
if (!$admin || $admin['user_type'] !== 'admin') { echo 'Access denied.'; exit; }

$pdo = $db->opencon();
$target = $_POST['target'] ?? '';

try {
  if ($target === 'admin_notes') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notes (
      note_id INT AUTO_INCREMENT PRIMARY KEY,
      admin_id INT NOT NULL,
      target_type VARCHAR(32) NOT NULL,
      target_id INT NOT NULL,
      note TEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_target (target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    flash_set('success','admin_notes table is ready.');
  } elseif ($target === 'rejection_reasons') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rejection_reasons (
      reason_id INT AUTO_INCREMENT PRIMARY KEY,
      label VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // Seed defaults if empty
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM rejection_reasons")->fetchColumn();
    if ($cnt === 0) {
      $stmt = $pdo->prepare('INSERT INTO rejection_reasons (label) VALUES (?), (?), (?), (?)');
      $stmt->execute(['Incomplete details','Pricing unclear or misleading','Prohibited content or service','Low quality description']);
    }
    flash_set('success','rejection_reasons table is ready.');
  } else {
    flash_set('error','Unknown target.');
  }
} catch (Throwable $e) {
  flash_set('error','Failed: '.$e->getMessage());
}

header('Location: admin_dashboard.php?view=system');
<?php