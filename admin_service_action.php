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
// CSRF validation for all admin service actions
if (!csrf_validate()) {
  flash_set('error','Security check failed. Please try again.');
  $back = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_dashboard.php?view=service_queue';
  header("Location: $back");
  exit;
}
$service_id = (int)($_POST['service_id'] ?? 0);
$action = $_POST['action'] ?? '';
// Sanitize reason to avoid path-like inputs and control chars; cap length
$reason = trim((string)($_POST['reason'] ?? ''));
$reason = preg_replace('/[\\\/]/', '', $reason); // remove directory separators
$reason = preg_replace('/[\x00-\x1F\x7F]/', '', $reason); // strip control chars
$reason = mb_substr($reason, 0, 500);
$service_ids = isset($_POST['service_ids']) && is_array($_POST['service_ids']) ? array_values(array_unique(array_map('intval', $_POST['service_ids']))) : [];
// Rejects should send the service back to draft (no delete in this flow)

// Allow bulk actions with no single service_id
$allowed = ['approve','reject','archive','delete','flag','unflag','bulk_approve','bulk_reject'];
if ((!$service_id && empty($service_ids)) || !in_array($action, $allowed, true)) {
  flash_set('error', 'Invalid request.');
  // Safe redirect to same-origin path
  $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
  $target = 'admin_dashboard.php?view=services';
  if ($ref !== '') {
    $parts = @parse_url($ref);
    if ($parts && (!isset($parts['host']) || strcasecmp($parts['host'], (string)($_SERVER['HTTP_HOST'] ?? '')) === 0)) {
      $path = $parts['path'] ?? '';
      $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
      if ($path !== '' && !preg_match('/^\/{2}/', $path)) { $target = $path . $query; }
    }
  }
  header('Location: ' . $target);
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
  // Bulk actions handler
  if ($action === 'bulk_approve' || $action === 'bulk_reject') {
    if (!$service_ids) { throw new Exception('No services selected.'); }
    $pdo->beginTransaction();
    if ($action === 'bulk_approve') {
      $up = $pdo->prepare("UPDATE services SET status='active', flagged=0, flagged_reason=NULL, flagged_at=NULL WHERE service_id=? LIMIT 1");
      $sel = $pdo->prepare("SELECT freelancer_id,title FROM services WHERE service_id=? LIMIT 1");
      foreach ($service_ids as $sid) {
        $up->execute([$sid]);
        try {
          $sel->execute([$sid]);
          if ($svc = $sel->fetch()) {
            if (method_exists($db,'addNotification')) {
              $db->addNotification((int)$svc['freelancer_id'], 'service_approved', [
                'service_id'=>$sid,
                'title'=>$svc['title'],
                'status'=>'active'
              ]);
            }
          }
        } catch (Throwable $e) { /* ignore per-item */ }
        log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_approve', 'service', $sid);
      }
      $pdo->commit();
      flash_set('success', 'Selected services approved.');
    } else { // bulk_reject
      $up = $pdo->prepare("UPDATE services SET status='draft', flagged=IFNULL(flagged,0), flagged_reason=IF(?<>'',?,flagged_reason), flagged_at=NOW() WHERE service_id=? LIMIT 1");
      $sel = $pdo->prepare("SELECT freelancer_id,title FROM services WHERE service_id=? LIMIT 1");
      foreach ($service_ids as $sid) {
        $up->execute([$reason, $reason, $sid]);
        try {
          $sel->execute([$sid]);
          if ($svc = $sel->fetch()) {
            if (method_exists($db,'addNotification')) {
              $db->addNotification((int)$svc['freelancer_id'], 'service_rejected', [
                'service_id'=>$sid,
                'title'=>$svc['title'],
                'status'=>'draft',
                'reason'=>$reason ?: null
              ]);
            }
          }
        } catch (Throwable $e) { /* ignore per-item */ }
        log_admin_action($pdo, (int)$_SESSION['user_id'], 'service_reject', 'service', $sid, ['reason' => $reason]);
      }
      $pdo->commit();
      flash_set('success', 'Selected services rejected to draft.');
    }
    // Safe redirect back
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $target = 'admin_dashboard.php?view=service_queue';
    if ($ref !== '') {
      $parts = @parse_url($ref);
      if ($parts && (!isset($parts['host']) || strcasecmp($parts['host'], (string)($_SERVER['HTTP_HOST'] ?? '')) === 0)) {
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        if ($path !== '' && !preg_match('/^\/{2}/', $path)) { $target = $path . $query; }
      }
    }
    header('Location: ' . $target);
    exit;
  }

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
      // Move back to draft and mark reviewed; always set flagged_at so it drops from the approval queue
      $stmt = $pdo->prepare("UPDATE services SET status='draft', flagged=IFNULL(flagged,0), flagged_reason=IF(?<>'',?,flagged_reason), flagged_at=NOW() WHERE service_id=? LIMIT 1");
      $stmt->execute([$reason, $reason, $service_id]);
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

// Final safe redirect
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
$target = 'admin_dashboard.php?view=services';
if ($ref !== '') {
  $parts = @parse_url($ref);
  if ($parts && (!isset($parts['host']) || strcasecmp($parts['host'], (string)($_SERVER['HTTP_HOST'] ?? '')) === 0)) {
    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
    if ($path !== '' && !preg_match('/^\/{2}/', $path)) { $target = $path . $query; }
  }
}
header('Location: ' . $target);