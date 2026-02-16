<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';

if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$currentUser = $db->getUser((int)$_SESSION['user_id']);
if (!$currentUser || $currentUser['user_type'] !== 'admin') { echo "Access denied."; exit; }

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = $db->opencon();
$view = $_GET['view'] ?? 'overview';

// Helper to mark active link
function active($current, $name){ return $current === $name ? 'active' : ''; }

// Small counters used across views
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalServices = (int)$pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
$totalBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$totalPaymentsCount = (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();

// Counts for moderation queues
$pendingApprovalsCount = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE (status='paused' OR (status='draft' AND flagged_at IS NULL))")->fetchColumn();
$rejectedServicesCount = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE status='draft' AND flagged_at IS NOT NULL")->fetchColumn();

// Lightweight AJAX endpoint for booking detail modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'booking_detail') {
  $bid = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
  $booking = null;
  if ($bid) {
    $sql = "SELECT b.*, 
                   u1.first_name AS client_first, u1.last_name AS client_last,
                   u2.first_name AS free_first, u2.last_name AS free_last
            FROM bookings b
            JOIN users u1 ON b.client_id=u1.user_id
            JOIN users u2 ON b.freelancer_id=u2.user_id
            WHERE b.booking_id=$bid";
    $booking = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
  }
  $dispute = $bid ? $pdo->query("SELECT * FROM disputes WHERE booking_id=$bid")->fetch(PDO::FETCH_ASSOC) : null;
  $messages = $bid ? $pdo->query("SELECT body,created_at FROM messages WHERE booking_id=$bid ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC) : [];
  $payments = $bid ? $pdo->query("SELECT payment_id,amount,method,payment_phase,status,paid_at FROM payments WHERE booking_id=$bid ORDER BY paid_at DESC, payment_id DESC")->fetchAll(PDO::FETCH_ASSOC) : [];
  // Admin notes (best-effort)
  $notes = [];
  try { $notes = $bid ? $pdo->query("SELECT n.note_id,n.note,n.created_at,u.first_name,u.last_name FROM admin_notes n JOIN users u ON u.user_id=n.admin_id WHERE n.target_type='booking' AND n.target_id=$bid ORDER BY n.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) : []; } catch (Throwable $e) {}
  header('Content-Type: text/html; charset=UTF-8');
  if (!$booking) {
    echo '<div class="text-muted">Booking not found.</div>';
    exit;
  }
  // Header
  echo '<div class="d-flex justify-content-between align-items-start mb-2">';
  echo   '<div>';
  echo     '<div class="mb-1"><strong>Booking:</strong> #'.(int)$booking['booking_id'].' • <span class="badge bg-info">'.h($booking['status'])."</span></div>";
  echo     '<div><strong>Title:</strong> '.h($booking['title_snapshot']).'</div>';
  echo     '<div class="text-muted small">Created: '.h($booking['created_at']).'</div>';
  echo   '</div>';
  echo   '<div class="text-end">';
  echo     '<div><span class="text-muted">Payment:</span> '.h($booking['payment_status']).'</div>';
  echo     '<div><span class="text-muted">Terms:</span> '.h($booking['payment_terms_status']).'</div>';
  echo   '</div>';
  echo '</div>';

  // Parties
  echo '<div class="mb-2">';
  echo   '<strong>Client:</strong> '.h(($booking['client_first'] ?? '').' '.($booking['client_last'] ?? '')). ' (ID '.(int)$booking['client_id'].')';
  echo   ' • <strong>Freelancer:</strong> '.h(($booking['free_first'] ?? '').' '.($booking['free_last'] ?? '')). ' (ID '.(int)$booking['freelancer_id'].')';
  echo '</div>';
  echo '<div class="mb-2"><strong>Total Amount:</strong> ₱'.number_format((float)$booking['total_amount'],2).'</div>';

  // Payments
  echo '<div class="fw-semibold mb-1">Payments</div>';
  if ($payments) {
    echo '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>ID</th><th>Amount</th><th>Method</th><th>Phase</th><th>Status</th><th>Paid At</th></tr></thead><tbody>';
    foreach ($payments as $p) {
      echo '<tr>';
      echo   '<td>'.(int)$p['payment_id'].'</td>';
      echo   '<td>₱'.number_format((float)$p['amount'],2).'</td>';
      echo   '<td>'.h($p['method']).'</td>';
      echo   '<td>'.h($p['payment_phase']).'</td>';
      echo   '<td>'.h($p['status']).'</td>';
      echo   '<td>'.h($p['paid_at']).'</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  } else {
    echo '<div class="text-muted mb-2">No payments for this booking.</div>';
  }

  // Messages
  echo '<div class="fw-semibold mb-1">Messages</div>';
  if ($messages) {
    echo '<ul class="mb-2">';
    foreach ($messages as $m) {
      echo '<li>'.h($m['body']).' <small class="text-muted">('.h($m['created_at']).')</small></li>';
    }
    echo '</ul>';
  } else {
    echo '<div class="text-muted mb-2">No messages.</div>';
  }

  // Dispute
  echo '<div class="fw-semibold mb-1">Dispute</div>';
  if ($dispute) {
    $dstatus = $dispute['status'];
    $badge = $dstatus==='resolved'?'success':($dstatus==='open'?'warning':'secondary');
    echo '<div>Status: <span class="badge bg-'.$badge.'">'.h($dstatus).'</span></div>';
    echo '<div>Reason: '.h($dispute['reason_code']).'</div>';
    echo '<div>Description: '.h($dispute['description']).'</div>';
  } else {
    echo '<div class="text-muted">No dispute for this booking.</div>';
  }
  // Admin Notes
  echo '<hr class="my-3">';
  echo '<div class="fw-semibold mb-1">Admin Notes</div>';
  if ($notes) {
    echo '<ul class="mb-2">';
    foreach ($notes as $n) {
      echo '<li><strong>'.h(($n['first_name']??'').' '.($n['last_name']??'')).':</strong> '.h($n['note']).' <small class="text-muted">('.h($n['created_at']).')</small></li>';
    }
    echo '</ul>';
  } else {
    echo '<div class="text-muted mb-2">No notes yet.</div>';
  }
  echo '<form method="POST" action="admin_note_action.php" class="d-flex gap-2">';
  echo csrf_input();
  echo   '<input type="hidden" name="target_type" value="booking">';
  echo   '<input type="hidden" name="target_id" value="'.(int)$bid.'">';
  echo   '<input type="text" name="note" class="form-control" placeholder="Add internal note…" required>';
  echo   '<button class="btn btn-sm btn-primary">Add</button>';
  echo '</form>';
  exit;
}

// Lightweight AJAX endpoint for user detail modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'user_detail') {
  $uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
  $user = $uid ? $pdo->query("SELECT * FROM users WHERE user_id=$uid")->fetch(PDO::FETCH_ASSOC) : null;
  header('Content-Type: text/html; charset=UTF-8');
    if ($rich) {
      $pendingServices = $pdo->query("\r
        SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.min_units,s.status,s.created_at,s.updated_at,\r
               s.slug, s.category_id,\r
               s.flagged, s.flagged_reason, s.flagged_at,\r
               u.first_name,u.last_name,u.user_id AS uid,u.profile_picture,\r
               sc.name AS category_name\r
        FROM services s\r
        JOIN users u ON u.user_id=s.freelancer_id\r
        LEFT JOIN service_categories sc ON sc.category_id=s.category_id\r
        WHERE (s.status='paused' OR (s.status='draft' AND s.flagged_at IS NULL))\r
        ORDER BY s.created_at DESC\r
      ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $pendingServices = $pdo->query("SELECT * FROM services WHERE (status='paused' OR (status='draft' AND flagged_at IS NULL)) ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
  // Admin notes for user
  $uNotes = [];
  try { $uNotes = $uid ? $pdo->query("SELECT n.note_id,n.note,n.created_at,u.first_name,u.last_name FROM admin_notes n JOIN users u ON u.user_id=n.admin_id WHERE n.target_type='user' AND n.target_id=$uid ORDER BY n.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) : []; } catch (Throwable $e) {}
  echo '<div class="fw-semibold mb-1">Recent Bookings</div>';
  if ($recentBookings) {
    echo '<ul class="mb-2">';
    foreach ($recentBookings as $b) {
      echo '<li>#'.(int)$b['booking_id'].' • '.h($b['status']).' • ₱'.number_format((float)$b['total_amount'],2).' <small class="text-muted">('.h($b['created_at']).')</small></li>';
    }
    echo '</ul>';
  } else {
    echo '<div class="text-muted mb-2">No bookings.</div>';
  }

  echo '<div class="fw-semibold mb-1">Recent Reviews</div>';
  if ($recentReviews) {
    echo '<ul class="mb-0">';
    foreach ($recentReviews as $r) {
      echo '<li><span class="badge bg-success">'.(int)$r['rating'].'/5</span> - '.h($r['comment'] ?? '').' <small class="text-muted">('.h($r['created_at']).')</small></li>';
    }
    echo '</ul>';
  } else {
    echo '<div class="text-muted">No reviews.</div>';
  }
  echo '<hr class="my-3">';
  echo '<div class="fw-semibold mb-1">Admin Notes</div>';
  if ($uNotes) {
    echo '<ul class="mb-2">';
    foreach ($uNotes as $n) {
      echo '<li><strong>'.h(($n['first_name']??'').' '.($n['last_name']??'')).':</strong> '.h($n['note']).' <small class="text-muted">('.h($n['created_at']).')</small></li>';
    }
    echo '</ul>';
  } else {
    echo '<div class="text-muted mb-2">No notes yet.</div>';
  }
  echo '<form method="POST" action="admin_note_action.php" class="d-flex gap-2">';
  echo csrf_input();
  echo   '<input type="hidden" name="target_type" value="user">';
  echo   '<input type="hidden" name="target_id" value="'.(int)$uid.'">';
  echo   '<input type="text" name="note" class="form-control" placeholder="Add internal note…" required>';
  echo   '<button class="btn btn-sm btn-primary">Add</button>';
  echo '</form>';
  exit;
}

// Lightweight AJAX endpoint for service detail modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'service_detail') {
  $sid = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
  $service = null;
  if ($sid) {
    $sql = "SELECT s.*, u.first_name, u.last_name, sc.name AS category_name
            FROM services s
            JOIN users u ON s.freelancer_id=u.user_id
            LEFT JOIN service_categories sc ON sc.category_id=s.category_id
            WHERE s.service_id=$sid";
    $service = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
  }
  // Admin notes for service
  $sNotes = [];
  try { $sNotes = $sid ? $pdo->query("SELECT n.note_id,n.note,n.created_at,u.first_name,u.last_name FROM admin_notes n JOIN users u ON u.user_id=n.admin_id WHERE n.target_type='service' AND n.target_id=$sid ORDER BY n.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) : []; } catch (Throwable $e) {}
  header('Content-Type: text/html; charset=UTF-8');
  if (!$service) {
    echo '<div class="text-muted">Service not found.</div>';
    exit;
  }
  echo '<div class="mb-2">';
  echo   '<div class="mb-1"><strong>Service:</strong> #'.(int)$service['service_id'].' • <span class="badge bg-'.($service['status']==='active'?'success':($service['status']==='paused'?'secondary':($service['status']==='draft'?'warning text-dark':'dark'))).'">'.h($service['status']).'</span></div>';
  echo   '<div><strong>Title:</strong> '.h($service['title']).'</div>';
  echo   '<div class="text-muted small">Created: '.h($service['created_at']).' • Updated: '.h($service['updated_at'] ?? '').'</div>';
  echo '</div>';

  echo   '<strong>Freelancer:</strong> '.h(($service['first_name'] ?? '').' '.($service['last_name'] ?? '')).' (ID '.(int)$service['freelancer_id'].')';
  echo   ' • <strong>Category:</strong> '.h($service['category_name'] ?? '—');
  echo '</div>';

  echo '<div class="mb-2">';
  echo   '<span class="badge bg-warning text-dark">₱'.number_format((float)$service['base_price'],2).'</span>';
  echo   ' <span class="badge bg-light text-dark border">'.h($service['price_unit']).'</span>';
  echo   ' <span class="badge bg-light text-dark border">Min '.(int)$service['min_units'].'</span>';
  echo '</div>';

  echo '<div class="fw-semibold mb-1">Description</div>';
  echo '<div class="border rounded p-2 bg-white" style="max-height:180px;overflow:auto;white-space:pre-wrap;">';
  echo   nl2br(h($service['description'] ?? ''));
  echo '</div>';
  // Notes
  echo '<hr class="my-3">';
  echo '<div class="fw-semibold mb-1">Admin Notes</div>';
  if ($sNotes) {
    echo '<ul class="mb-2">';
    foreach ($sNotes as $n) {
      echo '<li><strong>'.h(($n['first_name']??'').' '.($n['last_name']??'')).':</strong> '.h($n['note']).' <small class="text-muted">('.h($n['created_at']).')</small></li>';
    }
    echo '</ul>';
  } else {
    echo '<div class="text-muted mb-2">No notes yet.</div>';
  }
  echo '<form method="POST" action="admin_note_action.php" class="d-flex gap-2">';
  echo csrf_input();
  echo   '<input type="hidden" name="target_type" value="service">';
  echo   '<input type="hidden" name="target_id" value="'.(int)$sid.'">';
  echo   '<input type="text" name="note" class="form-control" placeholder="Add internal note…" required>';
  echo   '<button class="btn btn-sm btn-primary">Add</button>';
  echo '</form>';
  exit;
}

// Lightweight AJAX endpoint for report target preview
if (isset($_GET['ajax']) && $_GET['ajax'] === 'report_target') {
  $type = $_GET['target_type'] ?? '';
  $tid = isset($_GET['target_id']) ? (int)$_GET['target_id'] : 0;
  $rid = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
  header('Content-Type: text/html; charset=UTF-8');
  if (!$type || !$tid) { echo '<div class="text-muted">Invalid target.</div>'; exit; }

  // If report_id provided, show reporter's reason/description and meta
  if ($rid) {
    $rep = $pdo->query("SELECT report_id,reporter_id,report_type,target_id,description,status,created_at FROM reports WHERE report_id=$rid")->fetch(PDO::FETCH_ASSOC);
    if ($rep) {
      $rbadge = $rep['status']==='resolved' ? 'success' : ($rep['status']==='under_review' ? 'warning' : ($rep['status']==='rejected' ? 'secondary' : 'info'));
      echo '<div class="mb-2 d-flex justify-content-between align-items-start">';
      echo   '<div>';
      echo     '<div><strong>Report:</strong> #'.(int)$rep['report_id'].' • <span class="badge bg-'.$rbadge.'">'.h($rep['status']).'</span></div>';
      echo     '<div class="text-muted small">Type: '.h($rep['report_type']).' • Target: #'.(int)$rep['target_id'].' • Created: '.h($rep['created_at']).'</div>';
      echo   '</div>';
      echo   '<div class="text-muted small">Reporter: #'.(int)$rep['reporter_id'].'</div>';
      echo '</div>';
      echo '<div class="small text-muted mb-1">Reporter Reason</div>';
      echo '<div class="border rounded p-2 bg-white mb-3" style="max-height:160px;overflow:auto;white-space:pre-wrap;">'.nl2br(h($rep['description'] ?? '')).'</div>';
      echo '<hr class="my-2">';
    }
  }

  if ($type === 'user') {
    $u = $pdo->query("SELECT user_id,first_name,last_name,email,user_type,status,profile_picture,created_at FROM users WHERE user_id=$tid")->fetch(PDO::FETCH_ASSOC);
    if (!$u) { echo '<div class="text-muted">User not found.</div>'; exit; }
    $avatar = !empty($u['profile_picture']) ? h($u['profile_picture']) : 'img/profile_icon.webp';
   echo '<div class="d-flex align-items-center mb-2">'
       . '<img src="'.h($avatar).'" class="avatar-sm me-2 border" />'
       . '<div><div><strong>User:</strong> #'.(int)$u['user_id'].' • <span class="badge bg-'.($u['status']==='active'?'success':'secondary').'">'.h($u['status']).'</span></div>'
       . '<div>'.h($u['first_name'].' '.$u['last_name']).' • '.h($u['email']).'</div>'
       . '<div class="text-muted small">Type: '.h($u['user_type']).' • Joined: '.h($u['created_at']).'</div>'
       . '</div></div>';
   echo '<div class="mt-3 d-flex flex-wrap gap-2">';
   if ($rid) {
  echo '<form method="POST" action="unavail.php" class="d-inline">';
  echo csrf_input();
  echo '<input type="hidden" name="report_id" value="'.(int)$rid.'">';
  echo '<button class="btn btn-success btn-sm" name="action" value="resolve">Resolve Report</button>';
  echo '</form>';
   }
   echo '<form method="POST" action="admin_user_action.php" class="d-inline">';
   echo csrf_input();
   echo '<input type="hidden" name="user_id" value="'.(int)$u['user_id'].'">';
   echo '<button class="btn btn-warning btn-sm" name="action" value="suspend">Suspend User</button>';
   echo '</form>';
   echo '</div>';
    exit;
  }

  if ($type === 'service') {
    $s = $pdo->query("SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.min_units,s.status,s.created_at,s.updated_at, s.category_id,
                             u.first_name,u.last_name,u.user_id AS uid, sc.name AS category_name
                      FROM services s JOIN users u ON u.user_id=s.freelancer_id
                      LEFT JOIN service_categories sc ON sc.category_id=s.category_id
                      WHERE s.service_id=$tid")->fetch(PDO::FETCH_ASSOC);
    if (!$s) { echo '<div class="text-muted">Service not found.</div>'; exit; }
    echo '<div class="mb-2"><strong>Service:</strong> #'.(int)$s['service_id'].' • <span class="badge bg-'.($s['status']==='active'?'success':($s['status']==='paused'?'secondary':($s['status']==='draft'?'warning text-dark':'dark'))).'">'.h($s['status']).'</span></div>';
    echo '<div><strong>Title:</strong> '.h($s['title']).'</div>';
    echo '<div><strong>Freelancer:</strong> '.h(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')).' (User #'.(int)($s['uid'] ?? 0).')</div>';
    echo '<div><strong>Category:</strong> '.h($s['category_name'] ?? 'Uncategorized').'</div>';
    echo '<div class="mb-2"><span class="chip chip-warning">₱'.number_format((float)$s['base_price'],2).'</span> <span class="chip">'.h($s['price_unit']).'</span> <span class="chip">Min '.(int)$s['min_units'].'</span></div>';
   echo '<div class="small text-muted mb-1">Description</div>';
    echo '<div class="border rounded p-2 bg-white" style="max-height:160px;overflow:auto;white-space:pre-wrap;">'.nl2br(h($s['description'] ?? '')).'</div>';
   echo '<div class="mt-3 d-flex flex-wrap gap-2">';
   if ($rid) {
  echo '<form method="POST" action="unavail.php" class="d-inline">';
  echo csrf_input();
  echo '<input type="hidden" name="report_id" value="'.(int)$rid.'">';
  echo '<button class="btn btn-success btn-sm" name="action" value="resolve">Resolve Report</button>';
  echo '</form>';
   }
   echo '<form method="POST" action="admin_service_action.php" class="d-inline">';
   echo csrf_input();
   echo '<input type="hidden" name="service_id" value="'.(int)$s['service_id'].'">';
   echo '<button class="btn btn-outline-dark btn-sm" name="action" value="archive">Archive Service</button>';
   echo '</form>';
   echo '<form method="POST" action="admin_service_action.php" class="d-inline" onsubmit="return confirm(\'Delete this service?\');">';
   echo csrf_input();
   echo '<input type="hidden" name="service_id" value="'.(int)$s['service_id'].'">';
   echo '<button class="btn btn-outline-danger btn-sm" name="action" value="delete">Delete Service</button>';
   echo '</form>';
   echo '</div>';
    exit;
  }

  if ($type === 'review') {
    $r = $pdo->query("SELECT r.*, u1.first_name AS reviewer_first,u1.last_name AS reviewer_last, u2.first_name AS reviewee_first,u2.last_name AS reviewee_last
                      FROM reviews r JOIN users u1 ON r.reviewer_id=u1.user_id JOIN users u2 ON r.reviewee_id=u2.user_id WHERE r.review_id=$tid")->fetch(PDO::FETCH_ASSOC);
    if (!$r) { echo '<div class="text-muted">Review not found.</div>'; exit; }
    echo '<div class="mb-2"><strong>Review:</strong> #'.(int)$r['review_id'].' • <span class="badge bg-success">'.(int)$r['rating'].'/5</span></div>';
    echo '<div><strong>Reviewer:</strong> '.h(($r['reviewer_first'] ?? '').' '.($r['reviewer_last'] ?? '')).' • <strong>Reviewee:</strong> '.h(($r['reviewee_first'] ?? '').' '.($r['reviewee_last'] ?? '')).'</div>';
    echo '<div class="small text-muted">Booking: #'.(int)$r['booking_id'].' • Created: '.h($r['created_at']).'</div>';
    echo '<div class="small text-muted mb-1">Comment</div>';
    echo '<div class="border rounded p-2 bg-white" style="max-height:160px;overflow:auto;white-space:pre-wrap;">'.nl2br(h($r['comment'] ?? '')).'</div>';
    if (!empty($r['reply'])) {
      echo '<div class="small text-muted mt-2 mb-1">Reply</div>';
      echo '<div class="border rounded p-2 bg-white" style="max-height:120px;overflow:auto;white-space:pre-wrap;">'.nl2br(h($r['reply'])).'</div>';
    }
    echo '<div class="mt-3 d-flex flex-wrap gap-2">';
    if ($rid) {
  echo '<form method="POST" action="unavail.php" class="d-inline">';
  echo csrf_input();
  echo '<input type="hidden" name="report_id" value="'.(int)$rid.'">';
  echo '<button class="btn btn-success btn-sm" name="action" value="resolve">Resolve Report</button>';
  echo '</form>';
    }
    echo '<form method="POST" action="admin_review_action.php" class="d-inline" onsubmit="return confirm(\'Delete this review?\');">';
    echo csrf_input();
    echo '<input type="hidden" name="review_id" value="'.(int)$r['review_id'].'">';
    echo '<button class="btn btn-outline-danger btn-sm" name="action" value="delete">Delete Review</button>';
    echo '</form>';
    echo '</div>';
    exit;
  }

  echo '<div class="text-muted">Unsupported target type.</div>';
  exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>TaskHive Admin</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
    :root{
      /* Tweak these if you want more/less centering */
      --content-max: 1280px;    /* max width of the main content */
      --content-pad-x: 32px;    /* horizontal padding inside main */
      --content-pad-x-lg: 44px; /* a bit more pad on desktop */
    }
    body { background: #f5f5f8; }
    .sidebar {
      min-height: 100vh; background: #212529; color: #fff; padding-top: 24px; position: sticky; top: 0;
    }
    .sidebar .nav-link { color: #cfd8dc; margin-bottom: 6px; border-radius: 6px; }
    .sidebar .nav-link.active, .sidebar .nav-link:hover { background: #343a40; color: #fff; }
    .sidebar .nav-link i { margin-right: 8px; }
    .sidebar .sidebar-title { color: #ffc107; font-weight: 700; font-size: 1.1rem; margin: 0 0 18px 8px; }
    main { padding: 24px var(--content-pad-x); }
    @media (min-width: 992px){
      main { padding: 28px var(--content-pad-x-lg); }
    }
    .content-shell{ max-width: var(--content-max); margin: 0 auto; width: 100%; }
    .stat-card { min-height:130px; }
    .stat-alert { background:#fff3cd; border-left:4px solid #ffa726; font-size:1rem; }
    .toplist { background:#fffae0; }
    .table-sm td, .table-sm th { font-size: 0.95rem; }
    .content-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 18px; }
    .content-header .title { margin: 0; }
    .search-inline { max-width: 280px; }
    .sticky-topbar { position: sticky; top: 0; z-index: 2; background: #f5f5f8; padding-top: 12px; }
    /* Lightweight helpers for richer cards */
    .avatar-sm{ width:36px; height:36px; border-radius:50%; object-fit:cover; }
    .chip{ display:inline-block; font-size:.85rem; padding:.2rem .55rem; border-radius:999px; border:1px solid rgba(0,0,0,.08); background:#f8f9fa; }
    .chip-warning{ background:#fff3cd; border-color:#ffe08a; }
    .chip-light{ background:#f1f3f5; }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row g-0">
    <!-- Sidebar -->
    <nav class="col-md-2 col-lg-2 sidebar">
      <div class="sidebar-title">TaskHive 🐝 Admin</div>
      <ul class="nav flex-column px-2">
        <li class="nav-item"><a href="?view=overview" class="nav-link <?= active($view,'overview') ?>"><i class="bi bi-speedometer"></i>Overview</a></li>
        <li class="nav-item"><a href="?view=users" class="nav-link <?= active($view,'users') ?>"><i class="bi bi-people"></i>Users</a></li>
    <li class="nav-item"><a href="?view=services" class="nav-link <?= active($view,'services') ?>"><i class="bi bi-hammer"></i>Services</a></li>
  <li class="nav-item"><a href="?view=service_queue" class="nav-link <?= active($view,'service_queue') ?>"><i class="bi bi-hourglass-split"></i>Service Approval<?php if ($pendingApprovalsCount>0): ?><span class="badge bg-warning text-dark ms-2"><?= $pendingApprovalsCount ?></span><?php endif; ?></a></li>
  <li class="nav-item"><a href="?view=rejected_services" class="nav-link <?= active($view,'rejected_services') ?>"><i class="bi bi-x-circle"></i>Rejected Services<?php if ($rejectedServicesCount>0): ?><span class="badge bg-secondary ms-2"><?= $rejectedServicesCount ?></span><?php endif; ?></a></li>
        <li class="nav-item"><a href="?view=bookings" class="nav-link <?= active($view,'bookings') ?>"><i class="bi bi-journal-text"></i>Bookings</a></li>
        <li class="nav-item"><a href="?view=payments" class="nav-link <?= active($view,'payments') ?>"><i class="bi bi-credit-card"></i>Payments</a></li>
        <li class="nav-item"><a href="?view=commissions" class="nav-link <?= active($view,'commissions') ?>"><i class="bi bi-cash-coin"></i>Commissions</a></li>
        <li class="nav-item"><a href="?view=reviews" class="nav-link <?= active($view,'reviews') ?>"><i class="bi bi-star"></i>Reviews</a></li>
        <li class="nav-item"><a href="?view=flagged_reviews" class="nav-link <?= active($view,'flagged_reviews') ?>"><i class="bi bi-exclamation-octagon"></i>Flagged Reviews</a></li>
  <!-- <li class="nav-item"><a href="?view=disputes" class="nav-link <?= active($view,'disputes') ?>"><i class="bi bi-slash-circle"></i>Disputes</a></li> -->
        <li class="nav-item"><a href="?view=suspended" class="nav-link <?= active($view,'suspended') ?>"><i class="bi bi-person-x"></i>Suspended Users</a></li>
        <li class="nav-item"><a href="?view=notifications" class="nav-link <?= active($view,'notifications') ?>"><i class="bi bi-bell"></i>Notifications</a></li>
        <li class="nav-item"><a href="?view=notify_user" class="nav-link <?= active($view,'notify_user') ?>"><i class="bi bi-envelope"></i>Send Notification</a></li>
        <li class="nav-item"><a href="?view=analytics" class="nav-link <?= active($view,'analytics') ?>"><i class="bi bi-graph-up-arrow"></i>Analytics</a></li>
  <!-- <li class="nav-item"><a href="?view=reports" class="nav-link <?= active($view,'reports') ?>"><i class="bi bi-flag"></i>Reports</a></li> -->
  <!-- <li class="nav-item"><a href="?view=system" class="nav-link <?= active($view,'system') ?>"><i class="bi bi-gear"></i>System Check</a></li> -->
  <li class="nav-item mt-2"><a href="unavail.php" class="nav-link"><i class="bi bi-download"></i>Export Users</a></li>
  <li class="nav-item"><a href="unavail.php" class="nav-link"><i class="bi bi-download"></i>Export Bookings</a></li>
  <li class="nav-item"><a href="unavail.php" class="nav-link"><i class="bi bi-download"></i>Export Payments</a></li>
  <li class="nav-item mt-3"><a href="unavail.php" class="nav-link text-danger"><i class="bi bi-box-arrow-right"></i>Logout</a></li>
      </ul>
      <div class="px-3 pb-3 text-secondary small mt-auto">&copy; <?= date('Y') ?> TaskHive</div>
    </nav>

    <!-- Main Content -->
    <main class="col-md-10 col-lg-10 ms-md-auto">
      <div class="content-shell">
        <div class="sticky-topbar">
          <?= flash_render(); ?>
        </div>

<?php
// Render content by view
switch ($view) {

  // 1) OVERVIEW
  case 'overview':
    $totalRevenue = (float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status IN ('escrowed','released')")->fetchColumn();
    $todayBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $todayRevenue = (float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE DATE(paid_at)=CURDATE()")->fetchColumn();
    $thisMonth = date('Y-m');
    $newUsersMonth = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at,'%Y-%m') = '$thisMonth'")->fetchColumn();
    $openDisputes = (int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review')")->fetchColumn();
    $failedPayments = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status='failed'")->fetchColumn();
    $suspendedUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='suspended'")->fetchColumn();
    $recentBookings = $pdo->query("SELECT booking_id,title_snapshot,status,total_amount,created_at FROM bookings ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $recentPayments = $pdo->query("SELECT payment_id,booking_id,amount,status,paid_at FROM payments ORDER BY paid_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $recentDisputes = $pdo->query("SELECT dispute_id,booking_id,status,created_at FROM disputes ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $topServices = $pdo->query("SELECT title,base_price,total_reviews FROM services ORDER BY total_reviews DESC, base_price DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $topFreelancers = $pdo->query("SELECT first_name,last_name,avg_rating,total_reviews FROM users WHERE user_type='freelancer' ORDER BY avg_rating DESC, total_reviews DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Overview</h3>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-2"><div class="card stat-card p-3 text-center"><div class="h6 mb-1">Users</div><div class="display-6"><?= $totalUsers ?></div></div></div>
        <div class="col-md-2"><div class="card stat-card p-3 text-center"><div class="h6 mb-1">Services</div><div class="display-6"><?= $totalServices ?></div></div></div>
        <div class="col-md-2"><div class="card stat-card p-3 text-center"><div class="h6 mb-1">Bookings</div><div class="display-6"><?= $totalBookings ?></div></div></div>
        <div class="col-md-2"><div class="card stat-card p-3 text-center"><div class="h6 mb-1">Payments</div><div class="display-6"><?= number_format($totalPaymentsCount) ?></div></div></div>
        <div class="col-md-2"><div class="card stat-card p-3 text-center"><div class="h6 mb-1">New Users (Mo)</div><div class="display-6"><?= $newUsersMonth ?></div></div></div>
        <div class="col-md-2"><div class="card stat-card p-3 text-center"><div class="h6 mb-1">Revenue</div><div class="display-6 text-success">₱<?= number_format($totalRevenue,2) ?></div></div></div>
      </div>

      <div class="row g-3 mb-4">
        <?php if ($openDisputes): ?>
          <div class="col-md-3"><div class="stat-alert p-3 rounded"><strong><?= $openDisputes ?></strong> unresolved disputes. <a href="?view=disputes">Review</a></div></div>
        <?php endif; ?>
        <?php if ($failedPayments): ?>
          <div class="col-md-3"><div class="stat-alert p-3 rounded"><strong><?= $failedPayments ?></strong> failed payments. <a href="?view=payments">Fix</a></div></div>
        <?php endif; ?>
        <?php if ($suspendedUsers): ?>
          <div class="col-md-3"><div class="stat-alert p-3 rounded"><strong><?= $suspendedUsers ?></strong> suspended users. <a href="?view=suspended">Manage</a></div></div>
        <?php endif; ?>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card p-3">
          <h6>Today's Bookings</h6><div class="display-6"><?= $todayBookings ?></div>
        </div></div>
        <div class="col-md-4"><div class="card p-3">
          <h6>Today's Revenue</h6><div class="display-6 text-success">₱<?= number_format($todayRevenue,2) ?></div>
        </div></div>
        <div class="col-md-4"><div class="card p-3">
          <h6>Active Freelancers</h6>
          <div class="display-6"><?= (int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_type='freelancer' AND status='active'")->fetchColumn(); ?></div>
        </div></div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card p-3">
          <h6 class="mb-2">Recent Bookings</h6>
          <table class="table table-sm"><thead><tr><th>ID</th><th>Service</th><th>Status</th><th>Amount</th></tr></thead><tbody>
            <?php foreach ($recentBookings as $b): ?>
              <tr><td><?= (int)$b['booking_id'] ?></td><td><?= h($b['title_snapshot']) ?></td><td><?= h($b['status']) ?></td><td>₱<?= number_format((float)$b['total_amount'],2) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <a href="?view=bookings" class="btn btn-sm btn-outline-primary">View All</a>
        </div></div>

        <div class="col-md-4"><div class="card p-3">
          <h6 class="mb-2">Recent Payments</h6>
          <table class="table table-sm"><thead><tr><th>ID</th><th>Booking</th><th>Amount</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($recentPayments as $p): ?>
              <tr><td><?= (int)$p['payment_id'] ?></td><td><?= (int)$p['booking_id'] ?></td><td>₱<?= number_format((float)$p['amount'],2) ?></td><td><?= h($p['status']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <a href="?view=payments" class="btn btn-sm btn-outline-primary">View All</a>
        </div></div>

        <div class="col-md-4"><div class="card p-3">
          <h6 class="mb-2">Recent Disputes</h6>
          <table class="table table-sm"><thead><tr><th>ID</th><th>Booking</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($recentDisputes as $d): ?>
              <tr><td><?= (int)$d['dispute_id'] ?></td><td><?= (int)$d['booking_id'] ?></td><td><?= h($d['status']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <a href="?view=disputes" class="btn btn-sm btn-outline-primary">View All</a>
        </div></div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card p-3 toplist">
          <h6 class="mb-2">Top Services</h6>
          <table class="table table-sm"><thead><tr><th>Title</th><th>Price</th><th>Reviews</th></tr></thead><tbody>
            <?php foreach ($topServices as $s): ?>
              <tr><td><?= h($s['title']) ?></td><td>₱<?= number_format((float)$s['base_price'],2) ?></td><td><?= (int)$s['total_reviews'] ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <a href="?view=services" class="btn btn-sm btn-outline-primary">View All</a>
        </div></div>
        <div class="col-md-6"><div class="card p-3 toplist">
          <h6 class="mb-2">Top Freelancers</h6>
          <table class="table table-sm"><thead><tr><th>Name</th><th>Rating</th><th>Reviews</th></tr></thead><tbody>
            <?php foreach ($topFreelancers as $u): ?>
              <tr><td><?= h($u['first_name'].' '.$u['last_name']) ?></td><td><?= number_format((float)$u['avg_rating'],2) ?></td><td><?= (int)$u['total_reviews'] ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <a href="?view=users" class="btn btn-sm btn-outline-primary">View All</a>
        </div></div>
      </div>

      <div class="card p-4 mb-4">
        <h5 class="mb-2">Platform Revenue</h5>
        <div class="fs-3 fw-bold text-success">₱<?= number_format((float)$totalRevenue,2) ?></div>
        <div class="mt-3">
          <a href="unavail.php" class="btn btn-outline-secondary btn-sm me-2">Export Bookings CSV</a>
          <a href="unavail.php" class="btn btn-outline-secondary btn-sm me-2">Export Payments CSV</a>
          <a href="unavail.php" class="btn btn-outline-secondary btn-sm">Export Users CSV</a>
        </div>
      </div>

      <div class="card p-4 mb-4">
        <h5 class="mb-2">Admin Announcement</h5>
        <div class="text-muted">[Sample] Scheduled downtime on Friday, Oct 4, 10pm-11pm. Please monitor system health and user support tickets.</div>
      </div>
<?php
  break;

  // 2) USERS (with search)
  case 'users':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT user_id,first_name,last_name,email,user_type,status,created_at FROM users WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (CONCAT(first_name,' ',last_name) LIKE :q1 OR email LIKE :q2 OR user_type LIKE :q3 OR CAST(user_id AS CHAR) LIKE :q4)";
      $params = [
        ':q1' => "%$q%",
        ':q2' => "%$q%",
        ':q3' => "%$q%",
        ':q4' => "%$q%",
      ];
    }
    $sql .= " ORDER BY created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Users</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="users">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search name, email, id, type" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=users">Clear</a><?php endif; ?>
        </form>
        <div class="text-secondary">Total: <span class="badge bg-secondary"><?= $totalUsers ?></span></div>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Type</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= (int)$u['user_id'] ?></td>
              <td><?= h($u['first_name'].' '.$u['last_name']) ?></td>
              <td><?= h($u['email']) ?></td>
              <td><?= h($u['user_type']) ?></td>
              <td><?= h($u['status']) ?></td>
              <td><?= h($u['created_at']) ?></td>
              <td>
                <button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#userDetailModal" data-user-id="<?= (int)$u['user_id'] ?>">View</button>
                <form method="POST" action="admin_user_action.php" class="d-inline">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <button class="btn btn-sm btn-danger" name="action" value="suspend">Suspend</button>
                  <button class="btn btn-sm btn-warning" name="action" value="delete">Delete</button>
                  <button class="btn btn-sm btn-success" name="action" value="activate">Activate</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$users): ?>
            <tr><td colspan="7" class="text-muted text-center">No users found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- User Detail Modal -->
      <div class="modal fade" id="userDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">User Details</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="text-muted">Loading…</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
        document.addEventListener('DOMContentLoaded', function(){
          var modalEl = document.getElementById('userDetailModal');
          if (!modalEl) return;
          modalEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var uid = button && button.getAttribute('data-user-id');
            var body = modalEl.querySelector('.modal-body');
            body.innerHTML = '<div class="text-muted">Loading…</div>';
            if (!uid) { body.textContent = 'Missing user id.'; return; }
            fetch('?view=users&ajax=user_detail&user_id=' + encodeURIComponent(uid), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
              .then(function(r){ return r.text(); })
              .then(function(html){ body.innerHTML = html; })
              .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load user details.</div>'; });
          });
        });
      </script>
<?php
  break;

  

  // 4) SERVICES (with search)
  case 'services':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT s.service_id,s.title,s.base_price,s.price_unit,s.status,s.created_at,u.first_name,u.last_name
            FROM services s JOIN users u ON s.freelancer_id=u.user_id
            WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (s.title LIKE :q1 OR CONCAT(u.first_name,' ',u.last_name) LIKE :q2 OR CAST(s.service_id AS CHAR) LIKE :q3)";
      $params = [
        ':q1' => "%$q%",
        ':q2' => "%$q%",
        ':q3' => "%$q%",
      ];
    }
    $sql .= " ORDER BY s.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Services</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="services">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search title, id, freelancer" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=services">Clear</a><?php endif; ?>
        </form>
        <div class="text-secondary">Total: <span class="badge bg-secondary"><?= $totalServices ?></span></div>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>ID</th><th>Title</th><th>Freelancer</th><th>Price</th><th>Unit</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr>
              <td><?= (int)$s['service_id'] ?></td>
              <td><?= h($s['title']) ?></td>
              <td><?= h($s['first_name'].' '.$s['last_name']) ?></td>
              <td>₱<?= number_format((float)$s['base_price'],2) ?></td>
              <td><?= h($s['price_unit']) ?></td>
              <td><?= h($s['status']) ?></td>
              <td><?= h($s['created_at']) ?></td>
              <td>
                <button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#serviceDetailModal" data-service-id="<?= (int)$s['service_id'] ?>">View</button>
                <form method="POST" action="admin_service_action.php" class="d-inline">
                  <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                  <button class="btn btn-sm btn-warning" name="action" value="archive">Archive</button>
                  <button class="btn btn-sm btn-danger" name="action" value="delete">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$services): ?>
            <tr><td colspan="8" class="text-muted text-center">No services found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Service Detail Modal -->
      <div class="modal fade" id="serviceDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Service Details</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="text-muted">Loading…</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
        document.addEventListener('DOMContentLoaded', function(){
          var modalEl = document.getElementById('serviceDetailModal');
          if (!modalEl) return;
          modalEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var sid = button && button.getAttribute('data-service-id');
            var body = modalEl.querySelector('.modal-body');
            body.innerHTML = '<div class="text-muted">Loading…</div>';
            if (!sid) { body.textContent = 'Missing service id.'; return; }
            fetch('?view=services&ajax=service_detail&service_id=' + encodeURIComponent(sid), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
              .then(function(r){ return r.text(); })
              .then(function(html){ body.innerHTML = html; })
              .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load service details.</div>'; });
          });
        });
      </script>
<?php
  break;

  // 5) SERVICE APPROVAL QUEUE (unchanged list, has its own Inspect)
  case 'service_queue':
    $rich = isset($_GET['rich']) && $_GET['rich'] === '1';
    // Load saved rejection reasons (best-effort) and seed defaults if empty
    $savedReasons = [];
    try {
      $savedReasons = $pdo->query("SELECT reason_id,label FROM rejection_reasons ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
      if (!$savedReasons) {
        // Seed a few helpful defaults
        $pdo->exec("INSERT INTO rejection_reasons (label) VALUES
          ('Incomplete details'),
          ('Pricing unclear or misleading'),
          ('Prohibited content or service'),
          ('Low quality description')");
        $savedReasons = $pdo->query("SELECT reason_id,label FROM rejection_reasons ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
      }
    } catch (Throwable $e) { /* table might not exist or no rights; ignore */ }

    if ($rich) {
      $pendingServices = $pdo->query("
        SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.min_units,s.status,s.created_at,s.updated_at,
               s.slug, s.category_id,
               s.flagged, s.flagged_reason, s.flagged_at,
               u.first_name,u.last_name,u.user_id AS uid,u.profile_picture,
               sc.name AS category_name
        FROM services s
        JOIN users u ON u.user_id=s.freelancer_id
        LEFT JOIN service_categories sc ON sc.category_id=s.category_id
        WHERE (s.status='paused' OR (s.status='draft' AND s.flagged_at IS NULL))
        ORDER BY s.created_at DESC
      ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $pendingServices = $pdo->query("SELECT * FROM services WHERE (status='paused' OR (status='draft' AND flagged_at IS NULL)) ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $flaggedServices = [];
    $hasFlagCol = true;
    try { $flaggedServices = $pdo->query("SELECT service_id,title,created_at FROM services WHERE flagged=1 ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { $hasFlagCol = false; }
?>
      <div class="content-header">
        <h3 class="title">Service Approval</h3>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <input type="text" id="svcFilter" class="form-control form-control-sm search-inline" placeholder="Filter by title, freelancer, category" style="max-width:300px;">
          <?php if ($rich): ?>
            <a class="btn btn-sm btn-outline-secondary" href="?view=service_queue&rich=0">Compact List</a>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-primary" href="?view=service_queue&rich=1">Rich Cards</a>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-dark" href="admin_service_inspect.php?service_id=<?= isset($pendingServices[0]['service_id']) ? (int)$pendingServices[0]['service_id'] : 0 ?>" <?= empty($pendingServices)?'style="pointer-events:none;opacity:.5"':''; ?>>Open First Pending</a>
          <!-- Bulk actions toolbar -->
          <form id="bulkActionsForm" class="d-flex align-items-center gap-2 ms-auto" method="POST" action="admin_service_action.php" onsubmit="return confirmBulk(event);">
            <?= csrf_input(); ?>
            <input type="hidden" name="action" id="bulkActionInput" value="">
            <input type="hidden" name="reason" id="bulkReasonInput" value="">
            <div class="btn-group btn-group-sm" role="group" aria-label="Bulk actions">
              <button type="button" class="btn btn-outline-success" onclick="setBulkAction('bulk_approve')" title="Approve selected"><i class="bi bi-check2"></i> Approve Selected</button>
              <button type="button" class="btn btn-outline-danger" onclick="setBulkReject()" title="Reject selected"><i class="bi bi-x"></i> Reject Selected</button>
            </div>
          </form>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-2" data-bs-toggle="modal" data-bs-target="#reasonsModal">Manage Reasons</button>
        </div>
      </div>

      <?php if ($rich): ?>
        <div class="row g-3 mb-4" id="svcCardGrid">
          <?php if ($pendingServices): ?>
            <?php foreach ($pendingServices as $s): 
              $flagged = (int)($s['flagged'] ?? 0);
              $status = $s['status'] ?? 'draft';
              $cat = $s['category_name'] ?? 'Uncategorized';
              $fullName = trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''));
              $avatar = !empty($s['profile_picture']) ? h($s['profile_picture']) : 'img/profile_icon.webp';
            ?>
              <div class="col-md-6">
                <div class="card h-100 shadow-sm border-0">
                  <div class="card-body">
                    <div class="form-check form-check-inline float-end">
                      <input class="form-check-input bulk-checkbox" type="checkbox" value="<?= (int)$s['service_id'] ?>">
                    </div>
                    <div class="d-flex align-items-start mb-2">
                      <img src="<?= h($avatar) ?>" alt="avatar" class="avatar-sm me-2 border">
                      <div class="flex-grow-1">
                        <div class="d-flex align-items-start justify-content-between">
                          <div class="me-2">
                            <h5 class="mb-1 text-truncate" title="<?= h($s['title']) ?>"><?= h($s['title']) ?></h5>
                            <div class="small text-muted">
                              ID <?= (int)$s['service_id'] ?> • Slug: <?= h($s['slug'] ?? '') ?>
                            </div>
                            <div class="mt-1">
                              <span class="chip">By <?= h($fullName) ?> (User #<?= (int)($s['uid'] ?? 0) ?>)</span>
                              <span class="chip chip-light ms-1">Category: <?= h($cat) ?></span>
                            </div>
                          </div>
                          <div class="text-end">
                            <span class="badge bg-<?= $status==='active'?'success':($status==='paused'?'secondary':($status==='draft'?'warning text-dark':'dark')) ?>"><?= h(ucfirst($status)) ?></span>
                            <?php if (isset($s['flagged'])): ?>
                              <?php if ($flagged): ?>
                                <span class="badge bg-danger ms-1">Flagged</span>
                              <?php else: ?>
                                <span class="badge bg-light text-dark border ms-1">Not flagged</span>
                              <?php endif; ?>
                            <?php endif; ?>
                          </div>
                        </div>
                        <div class="mt-2">
                          <span class="chip chip-warning">₱<?= number_format((float)$s['base_price'],2) ?></span>
                          <span class="chip ms-1"><?= h($s['price_unit']) ?></span>
                          <span class="chip ms-1">Min <?= (int)$s['min_units'] ?></span>
                          <span class="text-muted small ms-2">Created: <?= h($s['created_at']) ?></span>
                        </div>
                      </div>
                    </div>

                    <div class="small text-muted mb-1">Description</div>
                    <div class="border rounded p-2 bg-white" style="max-height:140px;overflow:auto;white-space:pre-wrap;">
                      <?= nl2br(h($s['description'] ?? '')) ?>
                    </div>

                    <?php if (!empty($s['flagged_reason'])): ?>
                      <div class="alert alert-warning mt-2 py-1 px-2 small mb-0">
                        <strong>Flag reason:</strong> <?= h($s['flagged_reason']) ?>
                        <?php if (!empty($s['flagged_at'])): ?>
                          <span class="text-muted">• <?= h($s['flagged_at']) ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                    <div class="mt-3 d-flex flex-wrap gap-2">
                      <a class="btn btn-outline-secondary btn-sm" href="admin_service_inspect.php?service_id=<?= (int)$s['service_id'] ?>" target="_blank">Inspect</a>

                      <form method="POST" action="admin_service_action.php" class="d-inline">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                        <button class="btn btn-success btn-sm" name="action" value="approve">Approve</button>
                      </form>

                      <form method="POST" action="admin_service_action.php" class="d-inline">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                        <div class="input-group input-group-sm" style="max-width: 460px;">
                          <select class="form-select" onchange="this.nextElementSibling.value=this.value || this.nextElementSibling.value">
                            <option value="">Saved reasons…</option>
                            <?php foreach ($savedReasons as $rr): ?>
                              <option value="<?= h($rr['label']) ?>"><?= h($rr['label']) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <input type="text" name="reason" class="form-control" placeholder="Rejection reason (optional)">
                          <button class="btn btn-outline-danger" name="action" value="reject">Reject to Draft</button>
                        </div>
                      </form>

                      <?php if (isset($s['flagged'])): ?>
                        <form method="POST" action="admin_service_action.php" class="d-inline">
                          <?= csrf_input(); ?>
                          <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                          <div class="input-group input-group-sm" style="max-width: 380px;">
                            <input type="text" name="reason" class="form-control" placeholder="Flag reason (optional)">
                            <button class="btn btn-outline-warning" name="action" value="flag">Flag</button>
                          </div>
                        </form>
                        <form method="POST" action="admin_service_action.php" class="d-inline">
                          <?= csrf_input(); ?>
                          <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                          <button class="btn btn-outline-secondary btn-sm" name="action" value="unflag">Unflag</button>
                        </form>
                      <?php endif; ?>

                      <form method="POST" action="admin_service_action.php" class="d-inline" onsubmit="return confirm('Archive this service?');">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                        <button class="btn btn-outline-dark btn-sm" name="action" value="archive">Archive</button>
                      </form>

                      <form method="POST" action="admin_service_action.php" class="d-inline" onsubmit="return confirm('Delete this service permanently?');">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                        <button class="btn btn-outline-danger btn-sm" name="action" value="delete">Delete</button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-12"><div class="alert alert-light border">No services awaiting approval.</div></div>
          <?php endif; ?>
        </div>
        <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
          // Simple client-side filter on title, freelancer, category
          (function(){
            var input = document.getElementById('svcFilter');
            var grid = document.getElementById('svcCardGrid');
            if (!input || !grid) return;
            input.addEventListener('input', function(){
              var q = (this.value || '').toLowerCase();
              var cards = grid.querySelectorAll('.col-md-6');
              cards.forEach(function(col){
                var txt = col.innerText.toLowerCase();
                col.style.display = txt.indexOf(q) !== -1 ? '' : 'none';
              });
            });
          })();

          // Bulk selection + actions
          function getSelectedServiceIds(){
            var boxes = document.querySelectorAll('.bulk-checkbox:checked');
            var ids = [];
            boxes.forEach(function(b){ ids.push(b.value); });
            return ids;
          }
          function setBulkAction(act){
            var ids = getSelectedServiceIds();
            if (!ids.length) { Swal.fire('No selection','Select at least one service.','info'); return; }
            document.getElementById('bulkActionInput').value = act;
            submitBulk(ids);
          }
          function setBulkReject(){
            var ids = getSelectedServiceIds();
            if (!ids.length) { Swal.fire('No selection','Select at least one service.','info'); return; }
            var inputOptions = <?php
              $opt = [''=>'Custom…'];
              foreach ($savedReasons as $rr) { $opt[$rr['label']] = $rr['label']; }
              echo json_encode($opt);
            ?>;
            Swal.fire({
              title: 'Reject selected services?', input: 'select', inputOptions: inputOptions,
              inputPlaceholder: 'Pick a saved reason',
              showCancelButton: true,
              confirmButtonText: 'Continue'
            }).then(function(res){
              if (!res.isConfirmed) return;
              var prefill = res.value || '';
              Swal.fire({
                title: 'Add rejection reason',
                input: 'text',
                inputValue: prefill,
                inputPlaceholder: 'Optional detailed reason',
                showCancelButton: true,
                confirmButtonText: 'Reject'
              }).then(function(res2){
                if (!res2.isConfirmed) return;
                document.getElementById('bulkActionInput').value = 'bulk_reject';
                document.getElementById('bulkReasonInput').value = res2.value || prefill || '';
                submitBulk(ids);
              });
            });
          }
          function submitBulk(ids){
            var form = document.getElementById('bulkActionsForm');
            // Clear prior selections
            form.querySelectorAll('input[name="service_ids[]"]').forEach(function(n){ n.remove(); });
            ids.forEach(function(id){
              var el = document.createElement('input');
              el.type = 'hidden';
              el.name = 'service_ids[]';
              el.value = id;
              form.appendChild(el);
            });
            form.submit();
          }
          function confirmBulk(e){ return true; }
        </script>
      <?php else: ?>
        <div class="card p-3 mb-4">
          <h6 class="mb-0">Waiting Approval</h6>
          <ul class="mb-0 mt-2" id="compactList">
            <?php foreach ($pendingServices as $s): ?>
              <li>
                <input class="form-check-input me-2 bulk-checkbox" type="checkbox" value="<?= (int)$s['service_id'] ?>">
                <?= h($s['title']) ?> (ID <?= (int)$s['service_id'] ?>)
                <a href="admin_service_inspect.php?service_id=<?= (int)$s['service_id'] ?>" class="ms-2">View</a>
                <form method="POST" action="admin_service_action.php" class="d-inline ms-2">
                  <?= csrf_input(); ?>
                  <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                  <button class="btn btn-sm btn-success" name="action" value="approve">Approve</button>
                  <div class="input-group input-group-sm ms-2" style="max-width:420px; display:inline-flex; vertical-align:middle;">
                    <select class="form-select" onchange="this.nextElementSibling.value=this.value || this.nextElementSibling.value">
                      <option value="">Saved reasons…</option>
                      <?php foreach ($savedReasons as $rr): ?>
                        <option value="<?= h($rr['label']) ?>"><?= h($rr['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="text" name="reason" class="form-control" placeholder="Rejection reason (optional)">
                    <button class="btn btn-danger" name="action" value="reject">Reject to Draft</button>
                  </div>
                </form>
              </li>
            <?php endforeach; ?>
            <?php if (!$pendingServices): ?><li class="text-muted">No services awaiting approval.</li><?php endif; ?>
          </ul>
        </div>
        <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
          // In compact view, the header bulk toolbar is reused; no duplicate toolbar here.
        </script>
      <?php endif; ?>

      <!-- Manage Saved Reasons Modal -->
      <div class="modal fade" id="reasonsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Saved Rejection Reasons</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <form method="POST" action="admin_rejection_reason_action.php" class="d-flex gap-2 mb-3">
                <?= csrf_input(); ?>
                <input type="hidden" name="action" value="add">
                <input type="text" class="form-control" name="label" placeholder="Add new reason…" required>
                <button class="btn btn-primary">Add</button>
              </form>
              <ul class="list-group">
                <?php if ($savedReasons): foreach ($savedReasons as $rr): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><?= h($rr['label']) ?></span>
                    <form method="POST" action="admin_rejection_reason_action.php" onsubmit="return confirm('Delete this reason?');" class="ms-2">
                      <?= csrf_input(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="reason_id" value="<?= (int)$rr['reason_id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  </li>
                <?php endforeach; else: ?>
                  <li class="list-group-item text-muted">No saved reasons yet.</li>
                <?php endif; ?>
              </ul>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <div class="card p-3">
        <h6>Flagged Services</h6>
        <?php if ($hasFlagCol && $flaggedServices): ?>
          <ul class="mb-0">
          <?php foreach ($flaggedServices as $fs): ?>
            <li>
              <?= h($fs['title']) ?> (ID <?= (int)$fs['service_id'] ?>)
              <span class="text-danger ms-1">(Flagged)</span>
              <a href="admin_service_inspect.php?service_id=<?= (int)$fs['service_id'] ?>" class="ms-2">View</a>
            </li>
          <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-muted">No flagged services<?= $hasFlagCol ? '' : " or 'flagged' column not present." ?></div>
        <?php endif; ?>
      </div>
<?php
  break;

  // 5b) REJECTED SERVICES (draft + reviewed)
  case 'rejected_services':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.min_units,s.status,s.created_at,s.updated_at,
                   s.slug, s.category_id,
                   s.flagged, s.flagged_reason, s.flagged_at,
                   u.first_name,u.last_name,u.user_id AS uid,u.profile_picture,
                   sc.name AS category_name
            FROM services s
            JOIN users u ON u.user_id=s.freelancer_id
            LEFT JOIN service_categories sc ON sc.category_id=s.category_id
            WHERE s.status='draft' AND s.flagged_at IS NOT NULL";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (s.title LIKE :q1 OR CONCAT(u.first_name,' ',u.last_name) LIKE :q2 OR sc.name LIKE :q3 OR CAST(s.service_id AS CHAR) LIKE :q4)";
      $params = [':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%", ':q4'=>"%$q%"];
    }
    $sql .= " ORDER BY s.flagged_at DESC, s.updated_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rejected = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Rejected Services</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="rejected_services">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search title, freelancer, category, ID" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=rejected_services">Clear</a><?php endif; ?>
        </form>
      </div>

      <div class="row g-3 mb-4">
        <?php if ($rejected): foreach ($rejected as $s):
          $fullName = trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''));
          $avatar = !empty($s['profile_picture']) ? h($s['profile_picture']) : 'img/profile_icon.webp';
          $cat = $s['category_name'] ?? 'Uncategorized';
        ?>
        <div class="col-md-6">
          <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
              <div class="d-flex align-items-start mb-2">
                <img src="<?= h($avatar) ?>" alt="avatar" class="avatar-sm me-2 border">
                <div class="flex-grow-1">
                  <div class="d-flex align-items-start justify-content-between">
                    <div class="me-2">
                      <h5 class="mb-1 text-truncate" title="<?= h($s['title']) ?>"><?= h($s['title']) ?></h5>
                      <div class="small text-muted">ID <?= (int)$s['service_id'] ?> • Slug: <?= h($s['slug'] ?? '') ?></div>
                      <div class="mt-1">
                        <span class="chip">By <?= h($fullName) ?> (User #<?= (int)($s['uid'] ?? 0) ?>)</span>
                        <span class="chip chip-light ms-1">Category: <?= h($cat) ?></span>
                      </div>
                    </div>
                    <div class="text-end">
                      <span class="badge bg-warning text-dark">Draft (Rejected)</span>
                      <?php if (!empty($s['flagged_at'])): ?><div class="small text-muted">Reviewed: <?= h($s['flagged_at']) ?></div><?php endif; ?>
                    </div>
                  </div>
                  <div class="mt-2">
                    <span class="chip chip-warning">₱<?= number_format((float)$s['base_price'],2) ?></span>
                    <span class="chip ms-1"><?= h($s['price_unit']) ?></span>
                    <span class="chip ms-1">Min <?= (int)$s['min_units'] ?></span>
                    <span class="text-muted small ms-2">Updated: <?= h($s['updated_at'] ?? $s['created_at']) ?></span>
                  </div>
                </div>
              </div>

              <?php if (!empty($s['flagged_reason'])): ?>
                <div class="alert alert-warning mt-2 py-1 px-2 small mb-0"><strong>Reason:</strong> <?= h($s['flagged_reason']) ?></div>
              <?php endif; ?>

              <div class="small text-muted mb-1 mt-2">Description</div>
              <div class="border rounded p-2 bg-white" style="max-height:140px;overflow:auto;white-space:pre-wrap;"><?= nl2br(h($s['description'] ?? '')) ?></div>

              <div class="mt-3 d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="admin_service_inspect.php?service_id=<?= (int)$s['service_id'] ?>" target="_blank">Inspect</a>

                <form method="POST" action="admin_service_action.php" class="d-inline">
                  <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                  <button class="btn btn-outline-primary btn-sm" name="action" value="unflag">Return to queue</button>
                </form>

                <form method="POST" action="admin_service_action.php" class="d-inline">
                  <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                  <button class="btn btn-success btn-sm" name="action" value="approve">Approve</button>
                </form>

                <form method="POST" action="admin_service_action.php" class="d-inline" onsubmit="return confirm('Archive this service?');">
                  <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                  <button class="btn btn-outline-dark btn-sm" name="action" value="archive">Archive</button>
                </form>

                <form method="POST" action="admin_service_action.php" class="d-inline" onsubmit="return confirm('Delete this service permanently?');">
                  <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                  <button class="btn btn-outline-danger btn-sm" name="action" value="delete">Delete</button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; else: ?>
          <div class="col-12"><div class="alert alert-light border">No rejected services.</div></div>
        <?php endif; ?>
      </div>
<?php
  break;

  // 6) BOOKINGS (with search)
  case 'bookings':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT b.booking_id,b.title_snapshot,b.status,b.total_amount,b.payment_status,b.payment_terms_status,b.created_at,
                   u1.first_name AS client_first,u1.last_name AS client_last,u2.first_name AS free_first,u2.last_name AS free_last
            FROM bookings b
            JOIN users u1 ON b.client_id=u1.user_id
            JOIN users u2 ON b.freelancer_id=u2.user_id
            WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (
        b.title_snapshot LIKE :q1
        OR CONCAT(u1.first_name,' ',u1.last_name) LIKE :q2
        OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q3
        OR b.status LIKE :q4
        OR b.payment_status LIKE :q5
        OR b.payment_terms_status LIKE :q6
        OR CAST(b.booking_id AS CHAR) LIKE :q7
      )";
      $params = [
        ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%", ':q4'=>"%$q%",
        ':q5'=>"%$q%", ':q6'=>"%$q%", ':q7'=>"%$q%",
      ];
    }
    $sql .= " ORDER BY b.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Bookings</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="bookings">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search booking id, service, client, freelancer, status" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=bookings">Clear</a><?php endif; ?>
        </form>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>ID</th><th>Service</th><th>Client</th><th>Freelancer</th><th>Status</th><th>Pay Status</th><th>Terms</th><th>Total</th><th>Created</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($bookings as $b): ?>
            <tr>
              <td><?= (int)$b['booking_id'] ?></td>
              <td><?= h($b['title_snapshot']) ?></td>
              <td><?= h($b['client_first'].' '.$b['client_last']) ?></td>
              <td><?= h($b['free_first'].' '.$b['free_last']) ?></td>
              <td><?= h($b['status']) ?></td>
              <td><?= h($b['payment_status']) ?></td>
              <td><?= h($b['payment_terms_status']) ?></td>
              <td>₱<?= number_format((float)$b['total_amount'],2) ?></td>
              <td><?= h($b['created_at']) ?></td>
              <td><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bookingDetailModal" data-booking-id="<?= (int)$b['booking_id'] ?>">View</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$bookings): ?>
            <tr><td colspan="10" class="text-muted text-center">No bookings found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Booking Detail Modal -->
      <div class="modal fade" id="bookingDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Booking Details</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="text-muted">Loading…</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
        document.addEventListener('DOMContentLoaded', function(){
          var modalEl = document.getElementById('bookingDetailModal');
          if (!modalEl) return;
          modalEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var bid = button && button.getAttribute('data-booking-id');
            var body = modalEl.querySelector('.modal-body');
            body.innerHTML = '<div class="text-muted">Loading…</div>';
            if (!bid) { body.textContent = 'Missing booking id.'; return; }
            fetch('?view=bookings&ajax=booking_detail&booking_id=' + encodeURIComponent(bid), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
              .then(function(r){ return r.text(); })
              .then(function(html){ body.innerHTML = html; })
              .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load booking details.</div>'; });
          });
        });
      </script>
<?php
  break;

  

  // 8) PAYMENTS (with search)
  case 'payments':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT p.payment_id,p.booking_id,p.amount,p.method,p.payment_phase,p.status,p.paid_at,
                   u1.first_name AS client_first,u1.last_name AS client_last,
                   u2.first_name AS free_first,u2.last_name AS free_last
            FROM payments p
            JOIN bookings b ON p.booking_id=b.booking_id
            JOIN users u1 ON b.client_id=u1.user_id
            JOIN users u2 ON b.freelancer_id=u2.user_id
            WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (
        CONCAT(u1.first_name,' ',u1.last_name) LIKE :q1
        OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q2
        OR p.method LIKE :q3
        OR p.payment_phase LIKE :q4
        OR p.status LIKE :q5
        OR CAST(p.payment_id AS CHAR) LIKE :q6
        OR CAST(p.booking_id AS CHAR) LIKE :q7
      )";
      $params = [
        ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%", ':q4'=>"%$q%",
        ':q5'=>"%$q%", ':q6'=>"%$q%", ':q7'=>"%$q%",
      ];
    }
    $sql .= " ORDER BY p.paid_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sum = (float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status IN ('escrowed','released')")->fetchColumn();
?>
      <div class="content-header">
        <h3 class="title">Payments</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="payments">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search id, booking, user, method, status" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=payments">Clear</a><?php endif; ?>
        </form>
        <div class="text-success"><strong>Total (escrowed/released):</strong> ₱<?= number_format($sum,2) ?></div>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>ID</th><th>Booking</th><th>Client</th><th>Freelancer</th><th>Amount</th><th>Method</th><th>Phase</th><th>Status</th><th>Paid At</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= (int)$p['payment_id'] ?></td>
              <td><?= (int)$p['booking_id'] ?></td>
              <td><?= h($p['client_first'].' '.$p['client_last']) ?></td>
              <td><?= h($p['free_first'].' '.$p['free_last']) ?></td>
              <td>₱<?= number_format((float)$p['amount'],2) ?></td>
              <td><?= h($p['method']) ?></td>
              <td><?= h($p['payment_phase']) ?></td>
              <td><?= h($p['status']) ?></td>
              <td><?= h($p['paid_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?>
            <tr><td colspan="9" class="text-muted text-center">No payments found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
<?php
  break;

  // 9) COMMISSIONS (with search)
  case 'commissions':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT c.booking_id,c.percentage,c.amount,c.created_at,
                   b.total_amount,s.title AS service_title,
                   u1.first_name AS client_first, u1.last_name AS client_last,
                   u2.first_name AS free_first, u2.last_name AS free_last
            FROM commissions c
            JOIN bookings b ON c.booking_id=b.booking_id
            JOIN services s ON b.service_id=s.service_id
            JOIN users u1 ON b.client_id=u1.user_id
            JOIN users u2 ON b.freelancer_id=u2.user_id
            WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (
        s.title LIKE :q1
        OR CONCAT(u1.first_name,' ',u1.last_name) LIKE :q2
        OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q3
        OR CAST(c.booking_id AS CHAR) LIKE :q4
      )";
      $params = [
        ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%", ':q4'=>"%$q%",
      ];
    }
    $sql .= " ORDER BY c.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Commissions</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="commissions">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search booking, service, user" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=commissions">Clear</a><?php endif; ?>
        </form>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>Date</th><th>Booking</th><th>Service</th><th>Client</th><th>Freelancer</th><th>Total</th><th>%</th><th>Commission</th></tr></thead>
          <tbody>
          <?php foreach ($commissions as $c): ?>
            <tr>
              <td><?= h($c['created_at']) ?></td>
              <td><?= (int)$c['booking_id'] ?></td>
              <td><?= h($c['service_title']) ?></td>
              <td><?= h($c['client_first'].' '.$c['client_last']) ?></td>
              <td><?= h($c['free_first'].' '.$c['free_last']) ?></td>
              <td>₱<?= number_format((float)$c['total_amount'],2) ?></td>
              <td><?= number_format((float)$c['percentage'],2) ?>%</td>
              <td class="text-success fw-bold">₱<?= number_format((float)$c['amount'],2) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$commissions): ?>
            <tr><td colspan="8" class="text-muted text-center">No commissions found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
<?php
  break;

  // 10) REVIEWS (with search)
  case 'reviews':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT r.review_id,r.booking_id,r.rating,r.comment,r.reply,r.created_at,
                   u1.first_name AS reviewer_first,u1.last_name AS reviewer_last,
                   u2.first_name AS reviewee_first,u2.last_name AS reviewee_last
            FROM reviews r
            JOIN users u1 ON r.reviewer_id=u1.user_id
            JOIN users u2 ON r.reviewee_id=u2.user_id
            WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (
        CONCAT(u1.first_name,' ',u1.last_name) LIKE :q1
        OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q2
        OR r.comment LIKE :q3
        OR r.reply LIKE :q4
        OR CAST(r.booking_id AS CHAR) LIKE :q5
        OR CAST(r.rating AS CHAR) LIKE :q6
      )";
      $params = [
        ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%", ':q4'=>"%$q%",
        ':q5'=>"%$q%", ':q6'=>"%$q%",
      ];
    }
    $sql .= " ORDER BY r.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Reviews</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="reviews">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search reviewer, reviewee, text, booking" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=reviews">Clear</a><?php endif; ?>
        </form>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>Date</th><th>Booking</th><th>Reviewer</th><th>Reviewee</th><th>Rating</th><th>Comment</th><th>Reply</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($reviews as $r): ?>
            <tr>
              <td><?= h($r['created_at']) ?></td>
              <td><?= (int)$r['booking_id'] ?></td>
              <td><?= h($r['reviewer_first'].' '.$r['reviewer_last']) ?></td>
              <td><?= h($r['reviewee_first'].' '.$r['reviewee_last']) ?></td>
              <td><span class="badge bg-success"><?= (int)$r['rating'] ?>/5</span></td>
              <td><?= nl2br(h($r['comment'] ?? '')) ?></td>
              <td><?= nl2br(h($r['reply'] ?? '')) ?></td>
              <td>
                <form method="POST" action="admin_review_action.php" class="d-inline">
                  <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
                  <button class="btn btn-sm btn-danger" name="action" value="delete">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$reviews): ?>
            <tr><td colspan="8" class="text-muted text-center">No reviews found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
<?php
  break;

  // 11) FLAGGED REVIEWS (with search)
  case 'flagged_reviews':
    $q = trim($_GET['q'] ?? '');
    $flagged = [];
    $flaggedCol = true;
    try {
      $sql = "SELECT review_id,booking_id,rating,comment,created_at FROM reviews WHERE flagged=1";
      $params = [];
      if ($q !== '') {
        $sql .= " AND (comment LIKE :q1 OR CAST(booking_id AS CHAR) LIKE :q2 OR CAST(rating AS CHAR) LIKE :q3)";
        $params = [
          ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%",
        ];
      }
      $sql .= " ORDER BY created_at DESC";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $flagged = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $flaggedCol = false; }
?>
      <div class="content-header">
        <h3 class="title">Flagged Reviews</h3>
        <?php if ($flaggedCol): ?>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="flagged_reviews">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search text, booking, rating" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=flagged_reviews">Clear</a><?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
      <?php if (!$flaggedCol): ?>
        <div class="alert alert-info">The 'flagged' column is not present in reviews. Add it to use this view.</div>
      <?php endif; ?>
      <ul class="list-group">
        <?php foreach ($flagged as $r): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>#<?= (int)$r['review_id'] ?> • Booking <?= (int)$r['booking_id'] ?> • <span class="badge bg-success"><?= (int)$r['rating'] ?>/5</span> • <?= h($r['comment']) ?></span>
            <form method="POST" action="admin_review_action.php" class="d-inline">
              <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
              <button class="btn btn-sm btn-danger" name="action" value="delete">Delete</button>
            </form>
          </li>
        <?php endforeach; ?>
        <?php if ($flaggedCol && !$flagged): ?><li class="list-group-item text-muted">No flagged reviews.</li><?php endif; ?>
      </ul>
<?php
  break;

  // 12) DISPUTES (with search)
  case 'disputes':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT d.dispute_id,d.booking_id,d.reason_code,d.description,d.status,d.resolution,d.created_at,
                   u1.first_name AS raised_by_first,u1.last_name AS raised_by_last,
                   u2.first_name AS against_first,u2.last_name AS against_last
            FROM disputes d
            JOIN users u1 ON d.raised_by_id=u1.user_id
            JOIN users u2 ON d.against_id=u2.user_id
            WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (
        CONCAT(u1.first_name,' ',u1.last_name) LIKE :q1
        OR CONCAT(u2.first_name,' ',u2.last_name) LIKE :q2
        OR d.reason_code LIKE :q3
        OR d.status LIKE :q4
        OR d.description LIKE :q5
        OR CAST(d.booking_id AS CHAR) LIKE :q6
        OR CAST(d.dispute_id AS CHAR) LIKE :q7
      )";
      $params = [
        ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%", ':q4'=>"%$q%",
        ':q5'=>"%$q%", ':q6'=>"%$q%", ':q7'=>"%$q%",
      ];
    }
    $sql .= " ORDER BY d.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Disputes</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="disputes">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search booking, users, reason, status" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=disputes">Clear</a><?php endif; ?>
        </form>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Booking</th>
              <th>Raised By</th>
              <th>Against</th>
              <th>Reason</th>
              <th>Description</th>
              <th>Status</th>
              <th>Resolution</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($disputes as $d): ?>
            <tr>
              <td><?= h($d['created_at']) ?></td>
              <td><?= (int)$d['booking_id'] ?></td>
              <td><?= h($d['raised_by_first'].' '.$d['raised_by_last']) ?></td>
              <td><?= h($d['against_first'].' '.$d['against_last']) ?></td>
              <td><?= h($d['reason_code']) ?></td>
              <td><?= nl2br(h($d['description'] ?? '')) ?></td>
              <td>
                <span class="badge bg-<?= $d['status']=='resolved'?'success':($d['status']=='open'?'warning':'secondary') ?>">
                  <?= h($d['status']) ?>
                </span>
              </td>
              <td><?= nl2br(h($d['resolution'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$disputes): ?>
            <tr><td colspan="8" class="text-muted text-center">No disputes found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
<?php
  break;

  // 13) SUSPENDED USERS (with search)
  case 'suspended':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT user_id,first_name,last_name,email,user_type,status,created_at FROM users WHERE status='suspended'";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (CONCAT(first_name,' ',last_name) LIKE :q1 OR email LIKE :q2 OR CAST(user_id AS CHAR) LIKE :q3)";
      $params = [
        ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%",
      ];
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sus = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Suspended Users</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="suspended">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search name, email, id" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=suspended">Clear</a><?php endif; ?>
        </form>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Type</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($sus as $u): ?>
            <tr>
              <td><?= (int)$u['user_id'] ?></td>
              <td><?= h($u['first_name'].' '.$u['last_name']) ?></td>
              <td><?= h($u['email']) ?></td>
              <td><?= h($u['user_type']) ?></td>
              <td><?= h($u['status']) ?></td>
              <td><?= h($u['created_at']) ?></td>
              <td>
                <form method="POST" action="admin_user_action.php" class="d-inline">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <button class="btn btn-sm btn-success" name="action" value="activate">Activate</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$sus): ?>
            <tr><td colspan="7" class="text-muted text-center">No suspended users found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
<?php
  break;

  // 14) NOTIFICATIONS (with search)
  case 'notifications':
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT n.notification_id,n.user_id,n.type,n.data,n.read_at,n.created_at,u.first_name,u.last_name
            FROM notifications n
            JOIN users u ON n.user_id=u.user_id
            WHERE 1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (
        CONCAT(u.first_name,' ',u.last_name) LIKE :q1
        OR n.type LIKE :q2
        OR n.data LIKE :q3
        OR CAST(n.notification_id AS CHAR) LIKE :q4
        OR CAST(n.user_id AS CHAR) LIKE :q5
      )";
      $params = [
        ':q1'=>"%$q%", ':q2'=>"%$q%", ':q3'=>"%$q%", ':q4'=>"%$q%", ':q5'=>"%$q%"
      ];
    }
    $sql .= " ORDER BY n.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
      <div class="content-header">
        <h3 class="title">Notifications</h3>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="view" value="notifications">
          <input class="form-control search-inline" type="text" name="q" placeholder="Search user, type, data" value="<?= h($q) ?>">
          <button class="btn btn-outline-primary">Search</button>
          <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="?view=notifications">Clear</a><?php endif; ?>
        </form>
      </div>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>Date</th><th>User</th><th>Type</th><th>Data</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($notifs as $n): ?>
            <tr>
              <td><?= h($n['created_at']) ?></td>
              <td><?= h($n['first_name'].' '.$n['last_name']) ?></td>
              <td><?= h($n['type']) ?></td>
              <td><pre class="mb-0" style="white-space:pre-wrap"><?= h($n['data']) ?></pre></td>
              <td><span class="badge bg-<?= $n['read_at'] ? 'success' : 'warning' ?>"><?= $n['read_at'] ? 'Read' : 'Unread' ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$notifs): ?>
            <tr><td colspan="5" class="text-muted text-center">No notifications found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
<?php
  break;

  // 15) SEND NOTIFICATION (unchanged)
  case 'notify_user':
?>
      <div class="content-header">
        <h3 class="title">Send Notification</h3>
      </div>
      <div class="card p-3">
        <form method="POST" action="admin_notify_user.php" class="row g-3">
          <div class="col-md-3">
            <label class="form-label">User ID</label>
            <input type="number" name="user_id" class="form-control" required>
          </div>
          <div class="col-md-9">
            <label class="form-label">Message</label>
            <textarea name="text" class="form-control" rows="2" required></textarea>
          </div>
          <div class="col-12">
            <button class="btn btn-primary">Send</button>
          </div>
        </form>
      </div>
<?php
  break;

  // 16) ANALYTICS (unchanged)
  case 'analytics':
    $monthly = $pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as bookings FROM bookings GROUP BY month ORDER BY month ASC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    $revenues = $pdo->query("SELECT DATE_FORMAT(paid_at,'%Y-%m') as month, SUM(amount) as revenue FROM payments WHERE status IN ('escrowed','released') GROUP BY month ORDER BY month ASC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    $labelsA = array_column($monthly, 'month');
    $dataA = array_map('intval', array_column($monthly, 'bookings'));
    $labelsB = array_column($revenues, 'month');
    $dataB = array_map(fn($v)=> (float)$v, array_column($revenues, 'revenue'));
?>
      <div class="content-header">
        <h3 class="title">Analytics</h3>
      </div>
      <div class="row g-3">
        <div class="col-md-6"><div class="card p-3"><h6>Bookings (last 12)</h6><canvas id="bookingsChart"></canvas></div></div>
        <div class="col-md-6"><div class="card p-3"><h6>Revenue (last 12)</h6><canvas id="revenueChart"></canvas></div></div>
      </div>
      <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
      <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
        const bLabels = <?= json_encode($labelsA) ?>;
        const bData = <?= json_encode($dataA) ?>;
        const rLabels = <?= json_encode($labelsB) ?>;
        const rData = <?= json_encode($dataB) ?>;
        new Chart(document.getElementById('bookingsChart').getContext('2d'), {
          type: 'line',
          data: { labels: bLabels, datasets: [{ label: 'Bookings', data: bData, backgroundColor: '#ffca28', borderColor: '#ffa726', fill: true, tension: 0.3 }] },
          options: { responsive: true }
        });
        new Chart(document.getElementById('revenueChart').getContext('2d'), {
          type: 'line',
          data: { labels: rLabels, datasets: [{ label: 'Revenue', data: rData, backgroundColor: '#81c784', borderColor: '#388e3c', fill: true, tension: 0.3 }] },
          options: { responsive: true }
        });
      </script>
<?php
  break;

  // 17) REPORTS (unchanged listing)
  case 'reports':
    $reports = [];
    $hasReports = true;
    try { $reports = $pdo->query("SELECT * FROM reports ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { $hasReports = false; }
?>
      <div class="content-header">
        <h3 class="title">Reports</h3>
      </div>
      <?php if (!$hasReports): ?>
        <div class="alert alert-info">Reports table not found. Create a 'reports' table to use this view.</div>
      <?php endif; ?>
      <?php if ($reports): ?>
      <div class="card p-2">
        <table class="table table-bordered bg-white mb-0">
          <thead><tr><th>ID</th><th>Reporter</th><th>Type</th><th>Target</th><th>Description</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($reports as $r): ?>
              <tr>
                <td><?= h($r['report_id']) ?></td>
                <td><?= h($r['reporter_id']) ?></td>
                <td><?= h($r['report_type']) ?></td>
                <td><?= h($r['target_id']) ?></td>
                <td><?= h($r['description']) ?></td>
                <td><?= h($r['status']) ?></td>
                <td><?= h($r['created_at']) ?></td>
                <td>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reportTargetModal" data-report-id="<?= (int)$r['report_id'] ?>" data-target-type="<?= h($r['report_type']) ?>" data-target-id="<?= (int)$r['target_id'] ?>">View</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!-- Report Target Modal -->
      <div class="modal fade" id="reportTargetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Reported Item</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><div class="text-muted">Loading…</div></div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function(){
          var modalEl = document.getElementById('reportTargetModal');
          if (!modalEl) return;
          modalEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var rid = button && button.getAttribute('data-report-id');
            var ttype = button && button.getAttribute('data-target-type');
            var tid = button && button.getAttribute('data-target-id');
            var body = modalEl.querySelector('.modal-body');
            body.innerHTML = '<div class="text-muted">Loading…</div>';
            if (!rid || !ttype || !tid) { body.textContent = 'Missing report context.'; return; }
            fetch('?view=reports&ajax=report_target&report_id=' + encodeURIComponent(rid) + '&target_type=' + encodeURIComponent(ttype) + '&target_id=' + encodeURIComponent(tid), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
              .then(function(r){ return r.text(); })
              .then(function(html){ body.innerHTML = html; })
              .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load reported item.</div>'; });
          });
        });
      </script>
      <?php else: ?>
        <div class="text-muted">No reports to show.</div>
      <?php endif; ?>
<?php
  break;

  // 18) SYSTEM CHECK (DB health)
  case 'system':
    $status = ['admin_notes'=>false,'rejection_reasons'=>false];
    // Check if tables exist
    try { $pdo->query("SELECT 1 FROM admin_notes LIMIT 1"); $status['admin_notes']=true; } catch (Throwable $e) {}
    try { $pdo->query("SELECT 1 FROM rejection_reasons LIMIT 1"); $status['rejection_reasons']=true; } catch (Throwable $e) {}
?>
      <div class="content-header">
        <h3 class="title">System Check</h3>
      </div>
      <div class="card p-3">
        <h6 class="mb-2">Database Tables</h6>
        <ul>
          <li>admin_notes: <?= $status['admin_notes'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Missing</span>' ?></li>
          <li>rejection_reasons: <?= $status['rejection_reasons'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Missing</span>' ?></li>
        </ul>
        <div class="mt-3">
          <form method="post" action="admin_system_fix.php" class="d-inline">
            <input type="hidden" name="target" value="admin_notes">
            <button class="btn btn-sm btn-outline-primary" <?= $status['admin_notes']? 'disabled':'' ?>>Create admin_notes</button>
          </form>
          <form method="post" action="admin_system_fix.php" class="d-inline ms-2">
            <input type="hidden" name="target" value="rejection_reasons">
            <button class="btn btn-sm btn-outline-primary" <?= $status['rejection_reasons']? 'disabled':'' ?>>Create rejection_reasons</button>
          </form>
        </div>
        <div class="text-muted small mt-3">Use these buttons if your DB user lacks CREATE TABLE permissions during normal usage.</div>
      </div>
<?php
  break;

  default:
?>
      <div class="alert alert-warning">Unknown view. <a href="?view=overview">Go to Overview</a></div>
<?php
}
?>
      </div><!-- /content-shell -->
    </main>
  </div>
</div>
</body>
<!-- Bootstrap JS bundle (for modal functionality) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</html>