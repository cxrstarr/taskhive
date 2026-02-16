<?php
session_start();
require_once __DIR__.'/database.php';
require_once __DIR__.'/flash.php';
require_once __DIR__.'/includes/csrf.php';

/*
  report_action.php
  Handles user-submitted reports for:
  - service, message, review, booking, user

  Expects POST:
    - report_type: one of service|message|review|booking|user
    - target_id:   integer id of the target
    - description: text (optional but recommended)
  - return:      URL to redirect back to (optional; defaults to index.php)

  Behavior:
    - Validates login and inputs
    - Optionally prevents duplicate spam (same reporter/type/target within 1 hour)
    - Ensures the target exists
    - Inserts a row in the reports table
    - Optionally auto-flags services/reviews/messages if those columns exist
    - Redirects back with a flash message
*/

if (empty($_SESSION['user_id'])) {
  flash_set('error','Login required.');
  header('Location: login.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate()) {
  flash_set('error','Security check failed.');
  header('Location: '.(isset($_POST['return']) ? (string)$_POST['return'] : 'index.php')); exit;
}

$allowedTypes = ['service','message','review','booking','user'];
$report_type  = $_POST['report_type'] ?? '';
$target_id    = (int)($_POST['target_id'] ?? 0);
$description  = trim($_POST['description'] ?? '');
$return       = trim($_POST['return'] ?? 'index.php');

if (!in_array($report_type, $allowedTypes, true) || $target_id <= 0) {
  flash_set('error','Invalid report.');
  header('Location: '.$return); exit;
}

// optional: limit description length
if (strlen($description) > 4000) {
  $description = substr($description, 0, 4000);
}

try {
  $db  = new database();
  $pdo = $db->opencon();

  // 1) Validate target exists
  $tableMap = [
    'service' => ['table'=>'services',  'col'=>'service_id'],
    'message' => ['table'=>'messages',  'col'=>'message_id'],
    'review'  => ['table'=>'reviews',   'col'=>'review_id'],
    'booking' => ['table'=>'bookings',  'col'=>'booking_id'],
    'user'    => ['table'=>'users',     'col'=>'user_id'],
  ];
  $tbl = $tableMap[$report_type]['table'];
  $col = $tableMap[$report_type]['col'];

  $chk = $pdo->prepare("SELECT 1 FROM {$tbl} WHERE {$col} = :id LIMIT 1");
  $chk->execute([':id'=>$target_id]);
  if (!$chk->fetchColumn()) {
    flash_set('error','Target not found.');
    header('Location: '.$return); exit;
  }

  // 2) Anti-spam: avoid duplicate report from same user on same target in last hour
  $dupe = false;
  try {
    $dupSt = $pdo->prepare("
      SELECT report_id FROM reports
      WHERE reporter_id=:u AND report_type=:t AND target_id=:i
        AND created_at >= (NOW() - INTERVAL 1 HOUR)
      LIMIT 1
    ");
    $dupSt->execute([':u'=>(int)$_SESSION['user_id'], ':t'=>$report_type, ':i'=>$target_id]);
    $dupe = (bool)$dupSt->fetchColumn();
  } catch (Throwable $e) {
    // reports table may not exist yet; ignore and continue
  }
  if ($dupe) {
    flash_set('info','You’ve already reported this recently. Our moderators will review.');
    header('Location: '.$return); exit;
  }

  // 3) Insert report (fallback to minimal columns if schema differs)
  $inserted = false;

  // Try with meta column if it exists (optional)
  $hasMeta = false;
  try {
    $c = $pdo->query("SHOW COLUMNS FROM reports LIKE 'meta'");
    $hasMeta = (bool)$c->fetch();
  } catch (Throwable $e) {}

  if ($hasMeta) {
    try {
      $ins = $pdo->prepare("
        INSERT INTO reports
          (reporter_id, report_type, target_id, description, status, created_at, meta)
        VALUES
          (:uid, :rt, :tid, :desc, 'open', NOW(), :meta)
      ");
      $meta = [
        'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'page' => $return ?: null
      ];
      $ins->execute([
        ':uid'=>(int)$_SESSION['user_id'],
        ':rt'=>$report_type,
        ':tid'=>$target_id,
        ':desc'=>$description ?: null,
        ':meta'=>json_encode($meta)
      ]);
      $inserted = true;
    } catch (Throwable $e) {
      $inserted = false;
    }
  }

  // Fallback minimal insert if prior failed or meta not present
  if (!$inserted) {
    $ins2 = $pdo->prepare("
      INSERT INTO reports
        (reporter_id, report_type, target_id, description, status, created_at)
      VALUES
        (:uid, :rt, :tid, :desc, 'open', NOW())
    ");
    $ins2->execute([
      ':uid'=>(int)$_SESSION['user_id'],
      ':rt'=>$report_type,
      ':tid'=>$target_id,
      ':desc'=>$description ?: null,
    ]);
  }

  // 4) Optional: auto-flag target for moderator queue
  if ($report_type === 'service') {
    try {
      $pdo->prepare("
        UPDATE services
        SET flagged=1,
            flagged_reason=COALESCE(NULLIF(:r,''),'User report'),
            flagged_at=NOW()
        WHERE service_id=:id LIMIT 1
      ")->execute([':r'=>$description, ':id'=>$target_id]);
    } catch (Throwable $e) {
      // flagged columns may not exist; ignore
    }
  } elseif ($report_type === 'review') {
    try {
      $pdo->prepare("
        UPDATE reviews
        SET flagged=1,
            flagged_reason=COALESCE(NULLIF(:r,''),'User report'),
            flagged_at=NOW()
        WHERE review_id=:id LIMIT 1
      ")->execute([':r'=>$description, ':id'=>$target_id]);
    } catch (Throwable $e) {
      // flagged columns may not exist; ignore
    }
  } elseif ($report_type === 'message') {
    // Optional: if you added is_flagged columns to messages as suggested
    try {
      $pdo->prepare("
        UPDATE messages
        SET is_flagged=1
        WHERE message_id=:id LIMIT 1
      ")->execute([':id'=>$target_id]);
    } catch (Throwable $e) {
      // messages.is_flagged may not exist; ignore
    }
  }

  flash_set('success','Report submitted. Our moderators will review.');
} catch (Throwable $e) {
  flash_set('error','Could not submit report.');
}

header('Location: '.$return);