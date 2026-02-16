<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$pdo = $db->opencon();
// CSRF validation
if (!csrf_validate()) {
  flash_set('error','Security check failed.');
  $back = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php?view=reviews';
  header("Location: $back");
  exit;
}
$review_id = (int)($_POST['review_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');

if ($review_id <= 0 || !in_array($action, ['delete','approve','flag','unflag'], true)) {
  flash_set('error', 'Invalid request.');
  header('Location: ' . (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php?view=reviews'));
  exit;
}

function log_admin_action($pdo, $adminId, $action, $targetType, $targetId, $changes = null) {
  // Optional audit log if table exists
  try {
    $stmt = $pdo->prepare("INSERT INTO admin_action_logs (admin_id, action, target_type, target_id, changes, ip, user_agent) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
      $adminId, $action, $targetType, $targetId,
      $changes ? json_encode($changes) : null,
      $_SERVER['REMOTE_ADDR'] ?? null,
      $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
  } catch (Throwable $e) { /* ignore if table missing */ }
}

try {
  switch ($action) {
    case 'delete':
      $stmt = $pdo->prepare("DELETE FROM reviews WHERE review_id=? LIMIT 1");
      $stmt->execute([$review_id]);
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'review_delete', 'review', $review_id);
      flash_set('success', 'Review deleted.');
      break;

    case 'approve': // keep the review, remove the flag
    case 'unflag':
      // Requires reviews.flagged columns (run migration provided earlier)
      $stmt = $pdo->prepare("UPDATE reviews SET flagged=0, flagged_reason=NULL, flagged_at=NULL WHERE review_id=? LIMIT 1");
      $stmt->execute([$review_id]);
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'review_unflag', 'review', $review_id);
      flash_set('success', 'Review approved and unflagged.');
      break;

    case 'flag': // optional: flag a review with a reason
      $stmt = $pdo->prepare("UPDATE reviews SET flagged=1, flagged_reason=?, flagged_at=NOW() WHERE review_id=? LIMIT 1");
      $stmt->execute([$reason ?: null, $review_id]);
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'review_flag', 'review', $review_id, ['reason' => $reason]);
      flash_set('success', 'Review flagged.');
      break;
  }
} catch (Throwable $e) {
  flash_set('error', 'Action failed: '.$e->getMessage());
}

$back = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php?view=reviews';
header("Location: $back");