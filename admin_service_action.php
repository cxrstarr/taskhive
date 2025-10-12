<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }

$pdo = $db->opencon();
$service_id = (int)($_POST['service_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');

if ($service_id <= 0 || !in_array($action, ['approve','reject','archive','delete','flag','unflag'], true)) {
  flash_set('error', 'Invalid request.');
  header('Location: ' . (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php?view=services'));
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
    case 'approve':
      // Approve and make active; also clear flags if any
      $stmt = $pdo->prepare("UPDATE services SET status='active', flagged=0, flagged_reason=NULL, flagged_at=NULL WHERE service_id=? LIMIT 1");
      $stmt->execute([$service_id]);
      // Notify freelancer about approval
      try {
        $row = $pdo->prepare("SELECT freelancer_id,title FROM services WHERE service_id=? LIMIT 1");
        $row->execute([$service_id]);
        if ($svc = $row->fetch()) {
          if (method_exists($db,'addNotification')) {
            $db->addNotification((int)$svc['freelancer_id'], 'service_approved', [
              'service_id'=>$service_id,
              'title'=>$svc['title'],
              'status'=>'active'
            ]);
          }
        }
      } catch (Throwable $e) { /* ignore */ }
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_approve', 'service', $service_id);
      flash_set('success', 'Service approved.');
      break;

    case 'reject':
      // Move back to draft so freelancer cannot self-activate; keep optional reason in flagged fields if provided
      $stmt = $pdo->prepare("UPDATE services SET status='draft', flagged=IFNULL(flagged,0), flagged_reason=IF(?<>'',?,flagged_reason), flagged_at=IF(?<>'',NOW(),flagged_at) WHERE service_id=? LIMIT 1");
      $stmt->execute([$reason, $reason, $reason, $service_id]);
      // Notify freelancer about rejection/returned to draft
      try {
        $row = $pdo->prepare("SELECT freelancer_id,title FROM services WHERE service_id=? LIMIT 1");
        $row->execute([$service_id]);
        if ($svc = $row->fetch()) {
          if (method_exists($db,'addNotification')) {
            $db->addNotification((int)$svc['freelancer_id'], 'service_rejected', [
              'service_id'=>$service_id,
              'title'=>$svc['title'],
              'status'=>'draft',
              'reason'=>$reason ?: null
            ]);
          }
        }
      } catch (Throwable $e) { /* ignore */ }
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_reject', 'service', $service_id, ['reason' => $reason]);
      flash_set('success', 'Service rejected and returned to draft.');
      break;

    case 'archive':
      $stmt = $pdo->prepare("UPDATE services SET status='archived' WHERE service_id=? LIMIT 1");
      $stmt->execute([$service_id]);
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_archive', 'service', $service_id);
      flash_set('success', 'Service archived.');
      break;

    case 'delete':
      $stmt = $pdo->prepare("DELETE FROM services WHERE service_id=? LIMIT 1");
      $stmt->execute([$service_id]);
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_delete', 'service', $service_id);
      flash_set('success', 'Service deleted.');
      break;

    case 'flag':
      // Requires services.flagged columns (migration below)
      $stmt = $pdo->prepare("UPDATE services SET flagged=1, flagged_reason=?, flagged_at=NOW() WHERE service_id=? LIMIT 1");
      $stmt->execute([$reason ?: null, $service_id]);
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_flag', 'service', $service_id, ['reason' => $reason]);
      flash_set('success', 'Service flagged.');
      break;

    case 'unflag':
      $stmt = $pdo->prepare("UPDATE services SET flagged=0, flagged_reason=NULL, flagged_at=NULL WHERE service_id=? LIMIT 1");
      $stmt->execute([$service_id]);
      log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_unflag', 'service', $service_id);
      flash_set('success', 'Service unflagged.');
      break;
  }
} catch (Throwable $e) {
  flash_set('error', 'Action failed: '.$e->getMessage());
}

$back = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php?view=services';
header("Location: $back");