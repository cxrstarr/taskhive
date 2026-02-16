<?php
session_start();
require_once __DIR__.'/database.php';
require_once __DIR__ . '/includes/csrf.php';

$db = new database();

// Determine current viewer and which profile to display
$viewerId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$viewer = $viewerId ? $db->getUser($viewerId) : null;

$profileUserId = isset($_GET['id']) ? (int)$_GET['id'] : ($viewerId ?? 0);
if ($profileUserId <= 0) { header('Location: login.php'); exit; }
$u = $db->getUser($profileUserId);
if (!$u) { http_response_code(404); echo 'User not found'; exit; }

$isFreelancer = ($u['user_type'] === 'freelancer');
$isOwner = ($viewerId && $viewerId === $profileUserId);
$viewerIsClient = ($viewer && ($viewer['user_type'] ?? '') === 'client');
$fp = $isFreelancer ? $db->getFreelancerProfile($profileUserId) : false;

// Build view model
$freelancer = [
    'freelancer_profile_id' => $fp['freelancer_profile_id'] ?? null,
    'user_id' => $u['user_id'],
    'name' => trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: ($u['email'] ?? 'User'),
    'email' => $u['email'] ?? '',
    'avatar' => $u['profile_picture'] ?: 'img/profile_icon.webp',
    'cover_image' => 'img/hivebg.jpg',
    'title' => $u['bio'] ? mb_strimwidth($u['bio'], 0, 60, '…') : ($isFreelancer ? 'Freelancer' : 'Member'),
    'skills' => $fp['skills'] ?? '',
    'address' => $fp['address'] ?? '',
    'hourly_rate' => $fp['hourly_rate'] ?? 0,
    'bio' => $u['bio'] ?? '',
    'rating' => (float)($u['avg_rating'] ?? 0),
    'total_reviews' => (int)($u['total_reviews'] ?? 0),
    'completed_jobs' => 0,
    'member_since' => $u['created_at'] ?? date('Y-m-d'),
    'verified' => true,
    'availability' => 'Available',
];

// Skills array for right panel
$skillsArray = [];
if (!empty($freelancer['skills'])) {
    $skillsArray = array_values(array_filter(array_map('trim', explode(',', (string)$freelancer['skills']))));
}

// Data points
$pdo = $db->opencon();
// Ensure portfolio tables exist (lightweight migration)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        CONSTRAINT fk_portfolio_items_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Add archived_at column if not exists (soft-archive support)
    try { $pdo->exec("ALTER TABLE portfolio_items ADD COLUMN archived_at DATETIME NULL DEFAULT NULL"); } catch (Throwable $e2) { /* ignore if exists */ }
    $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_media (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        item_id BIGINT UNSIGNED NOT NULL,
        path VARCHAR(512) NOT NULL,
        sort_order INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (item_id),
        CONSTRAINT fk_portfolio_media_item FOREIGN KEY (item_id) REFERENCES portfolio_items(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // If creation fails (e.g., limited permissions), continue with filesystem-only fallback
}
// Completed jobs count
$stJobs = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE freelancer_id=:f AND status IN ('delivered','completed')");
$stJobs->execute([':f'=>$profileUserId]);
$freelancer['completed_jobs'] = (int)$stJobs->fetchColumn();
// Services (filter to approved/active)
$services = $isFreelancer ? $db->listFreelancerServices($profileUserId) : [];
$services = array_values(array_filter($services, fn($s)=>strtolower((string)($s['status'] ?? 'active'))==='active'));
$approvedServicesCount = count($services);

// Reliability metric: completed vs (delivered+completed)
$stDel = $pdo->prepare("SELECT 
    SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed
    FROM bookings WHERE freelancer_id=:f");
$stDel->execute([':f'=>$profileUserId]);
$rowDC = $stDel->fetch();
$delivered = (int)($rowDC['delivered'] ?? 0);
$completed = (int)($rowDC['completed'] ?? 0);
$den = $delivered + $completed;
$reliability = $den > 0 ? round(($completed / $den) * 100) : null;

// Reviews + distribution from DB
$reviews = $db->getFreelancerReviews($profileUserId, 50);
if ($freelancer['total_reviews'] === 0) { $freelancer['total_reviews'] = count($reviews); }
$stAvg = $pdo->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_reviews FROM reviews WHERE reviewee_id=:id");
$stAvg->execute([':id'=>$profileUserId]);
$agg = $stAvg->fetch();
if ($agg) {
    $freelancer['rating'] = (float)($agg['avg_rating'] ?? $freelancer['rating']);
    $freelancer['total_reviews'] = (int)($agg['total_reviews'] ?? $freelancer['total_reviews']);
}
$ratingDistribution = [1=>0,2=>0,3=>0,4=>0,5=>0];
foreach ($reviews as $rv) {
    $r=(int)($rv['rating'] ?? 0); if($r>=1&&$r<=5) $ratingDistribution[$r]++;
}

// Viewer header info
$currentUser = [
    'name' => $viewer ? trim(($viewer['first_name']??'').' '.($viewer['last_name']??'')) : 'Guest',
    'email' => $viewer['email'] ?? '',
    'avatar' => $viewer['profile_picture'] ?: 'img/profile_icon.webp'
];

// Portfolio items: prefer DB; fallback to filesystem scan
$portfolioItems = [];
$portfolioDir = __DIR__.'/img/uploads/portfolio/'.(int)$profileUserId;
// Also build a map for JS modal data
$portfolioData = [];
// Payment methods for freelancer (verified/active)
$paymentMethods = $isFreelancer ? $db->listFreelancerPaymentMethods($profileUserId, true) : [];
// Handle "Contact Me" start chat (viewer must be logged in and not the owner)
if ($viewerId && !$isOwner && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_chat_from_profile'])) {
    if (!csrf_validate()) { header('Location: freelancer_profile.php?id='.(int)$profileUserId); exit; }
    try {
        $otherId = (int)$profileUserId;
        if ($viewerId > 0 && $otherId > 0 && $viewerId !== $otherId) {
            $conversationId = $db->createOrGetGeneralConversation($viewerId, $otherId) ?: 0;
            $redir = 'inbox.php?user_id=' . urlencode((string)$otherId);
            if ($conversationId) { $redir .= '&conversation_id=' . urlencode((string)$conversationId); }
            header('Location: ' . $redir);
            exit;
        }
        // Fallback to inbox
        header('Location: inbox.php');
        exit;
    } catch (Throwable $e) {
        header('Location: inbox.php');
        exit;
    }
}
// Handle portfolio uploads (owner only)
if ($isOwner && $isFreelancer && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['portfolio_upload'])) {
    if (!csrf_validate()) { header('Location: freelancer_profile.php?id='.(int)$profileUserId); exit; }
    if (!is_dir($portfolioDir)) @mkdir($portfolioDir, 0777, true);
    // Gather portfolio meta
    $pTitle = trim($_POST['portfolio_title'] ?? '');
    $pDesc  = trim($_POST['portfolio_description'] ?? '');
    if ($pTitle === '') { $pTitle = 'Portfolio Item'; }
    // Insert a portfolio item row
    $itemId = null;
    try {
        $st = $pdo->prepare("INSERT INTO portfolio_items (user_id, title, description) VALUES (:uid, :t, :d)");
        $st->execute([':uid'=>$profileUserId, ':t'=>$pTitle, ':d'=>$pDesc]);
        $itemId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $itemId = null; // fallback to filesystem-only if DB insert fails
    }
    if (isset($_FILES['portfolio_images'])) {
        $files = $_FILES['portfolio_images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        for ($i=0; $i<$count; $i++) {
            $name = $files['name'][$i];
            $tmp  = $files['tmp_name'][$i];
            $err  = $files['error'][$i];
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext,['jpg','jpeg','png','webp','gif'],true)) continue;
            $fileName = uniqid('pf_', true).'.'.$ext;
            $dest = $portfolioDir.'/'.$fileName;
            if (@move_uploaded_file($tmp, $dest)) {
                // Insert media row if we have a DB item
                if ($itemId) {
                    try {
                        $relPath = 'img/uploads/portfolio/'.(int)$profileUserId.'/'.$fileName;
                        $stM = $pdo->prepare("INSERT INTO portfolio_media (item_id, path, sort_order) VALUES (:iid, :p, :s)");
                        $stM->execute([':iid'=>$itemId, ':p'=>$relPath, ':s'=>$i]);
                    } catch (Throwable $e) {}
                }
            }
        }
    }
    // Simple redirect (PRG) to avoid form resubmission
    header('Location: freelancer_profile.php?id='.urlencode((string)$profileUserId));
    exit;
}
// Handle portfolio manage actions (owner only): edit, archive, unarchive, delete
if ($isOwner && $isFreelancer && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['portfolio_action'])) {
    if (!csrf_validate()) { header('Location: freelancer_profile.php?id='.(int)$profileUserId); exit; }
    $action = (string)($_POST['portfolio_action'] ?? '');
    $itemId = (int)($_POST['item_id'] ?? 0);
    try {
        // Verify the item belongs to this user
        $stChk = $pdo->prepare("SELECT id FROM portfolio_items WHERE id=:id AND user_id=:uid LIMIT 1");
        $stChk->execute([':id'=>$itemId, ':uid'=>$profileUserId]);
        $exists = (int)($stChk->fetchColumn() ?: 0);
        if ($exists) {
            if ($action === 'edit') {
                $newTitle = trim((string)($_POST['title'] ?? ''));
                $newDesc  = trim((string)($_POST['description'] ?? ''));
                if ($newTitle === '') { $newTitle = 'Portfolio Item'; }
                $stUp = $pdo->prepare("UPDATE portfolio_items SET title=:t, description=:d WHERE id=:id AND user_id=:uid");
                $stUp->execute([':t'=>$newTitle, ':d'=>$newDesc, ':id'=>$itemId, ':uid'=>$profileUserId]);
            } elseif ($action === 'archive') {
                $pdo->prepare("UPDATE portfolio_items SET archived_at=NOW() WHERE id=:id AND user_id=:uid")
                    ->execute([':id'=>$itemId, ':uid'=>$profileUserId]);
            } elseif ($action === 'unarchive') {
                $pdo->prepare("UPDATE portfolio_items SET archived_at=NULL WHERE id=:id AND user_id=:uid")
                    ->execute([':id'=>$itemId, ':uid'=>$profileUserId]);
            } elseif ($action === 'delete') {
                // Delete media files from filesystem first (best-effort)
                try {
                    $stP = $pdo->prepare("SELECT path FROM portfolio_media WHERE item_id=:iid");
                    $stP->execute([':iid'=>$itemId]);
                    foreach ($stP->fetchAll() as $row) {
                        $rel = (string)($row['path'] ?? '');
                        if ($rel !== '' && strpos($rel, 'img/uploads/portfolio/') === 0) {
                            $abs = __DIR__ . '/' . str_replace(['\\','..'], ['/', ''], $rel);
                            if (is_file($abs)) { @unlink($abs); }
                        }
                    }
                } catch (Throwable $eDel) { /* ignore */ }
                // Deleting the item will cascade delete media rows
                $pdo->prepare("DELETE FROM portfolio_items WHERE id=:id AND user_id=:uid")
                    ->execute([':id'=>$itemId, ':uid'=>$profileUserId]);
            }
        }
    } catch (Throwable $e) { /* ignore */ }
    // Redirect back to portfolio tab (PRG)
    header('Location: freelancer_profile.php?id='.urlencode((string)$profileUserId).'&tab=portfolio');
    exit;
}
// Handle booking creation (client only)
if ($viewerIsClient && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_service'])) {
    if (!csrf_validate()) { header('Location: freelancer_profile.php?id='.(int)$profileUserId); exit; }
    $svcId = (int)($_POST['service_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    $clientNotes = trim((string)($_POST['client_notes'] ?? ''));
    $scheduledStart = trim((string)($_POST['scheduled_start'] ?? ''));
    $scheduledEnd = trim((string)($_POST['scheduled_end'] ?? ''));

    // Load service to validate and snapshot
    $stSvc = $pdo->prepare("SELECT service_id, freelancer_id, title, description, base_price, status FROM services WHERE service_id=:sid AND freelancer_id=:fid AND status='active' LIMIT 1");
    $stSvc->execute([':sid'=>$svcId, ':fid'=>$profileUserId]);
    $svc = $stSvc->fetch();

    if ($svc && (int)$svc['freelancer_id'] === $profileUserId && $viewerId && $viewerId !== $profileUserId) {
        $unitPrice = (float)$svc['base_price'];
        $totalAmount = $unitPrice * $qty; // platform_fee kept 0
        // Parse schedule datetimes (Y-m-d\TH:i from input type=datetime-local)
        $ss = $scheduledStart ? date('Y-m-d H:i:s', strtotime($scheduledStart)) : null;
        $se = $scheduledEnd ? date('Y-m-d H:i:s', strtotime($scheduledEnd)) : null;

        $stIns = $pdo->prepare("INSERT INTO bookings (
            service_id, client_id, freelancer_id, title_snapshot, description_snapshot,
            unit_price, quantity, platform_fee, total_amount, currency,
            scheduled_start, scheduled_end, status, payment_status, client_notes, created_at
        ) VALUES (
            :sid, :cid, :fid, :title, :descr, :price, :qty, 0.00, :total, 'PHP',
            :ss, :se, 'pending', 'unpaid', :notes, NOW()
        )");
        $stIns->execute([
            ':sid'=>$svc['service_id'],
            ':cid'=>$viewerId,
            ':fid'=>$profileUserId,
            ':title'=>$svc['title'],
            ':descr'=>$svc['description'],
            ':price'=>$unitPrice,
            ':qty'=>$qty,
            ':total'=>$totalAmount,
            ':ss'=>$ss,
            ':se'=>$se,
            ':notes'=>$clientNotes,
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        // Ensure a single general conversation between client and freelancer, then post a system message
        $conversationId = $db->createOrGetGeneralConversation($viewerId, $profileUserId) ?: 0;
        if ($conversationId) {
            $body = sprintf(
                "New booking #%d created for service '%s' (Qty: %d). Awaiting freelancer confirmation.",
                $bookingId,
                $svc['title'],
                $qty
            );
            // Use DB helper to insert message and update conversation timestamps
            try { $db->addMessage($conversationId, $viewerId, $body, 'system', $bookingId); } catch (Throwable $e) { /* ignore */ }
        }

        // Create notification for freelancer
        try {
            $notifData = json_encode(['booking_id'=>$bookingId,'service_id'=>$svc['service_id'],'client_id'=>$viewerId,'qty'=>$qty], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $stNot = $pdo->prepare("INSERT INTO notifications (user_id, type, data, created_at) VALUES (:uid, 'booking_created', :data, NOW())");
            $stNot->execute([':uid'=>$profileUserId, ':data'=>$notifData]);
        } catch (Throwable $e) { /* ignore */ }

        // Redirect straight to inbox (include conversation_id if available)
    $redir = 'inbox.php?user_id='.urlencode((string)$profileUserId).'&booking_id='.urlencode((string)$bookingId);
    if ($conversationId) { $redir .= '&conversation_id='.urlencode((string)$conversationId); }
        header('Location: ' . $redir);
        exit;
    }
}
// Load portfolio items from DB
$portfolioArchived = [];
$portfolioDbLoaded = false;
try {
    // Active (not archived)
    $st = $pdo->prepare("SELECT id, title, description, created_at FROM portfolio_items WHERE user_id = :uid AND (archived_at IS NULL) ORDER BY created_at DESC, id DESC");
    $st->execute([':uid'=>$profileUserId]);
    $items = $st->fetchAll();
    foreach ($items as $it) {
        $st2 = $pdo->prepare("SELECT id, path FROM portfolio_media WHERE item_id = :iid ORDER BY sort_order ASC, id ASC");
        $st2->execute([':iid'=>$it['id']]);
        $media = $st2->fetchAll();
        $firstImage = $media[0]['path'] ?? null;
        $portfolioItems[] = [
            'id' => (int)$it['id'],
            'title' => (string)$it['title'],
            'description' => (string)($it['description'] ?? ''),
            'image' => $firstImage ?: 'img/profile_icon.webp',
            'category' => 'Portfolio',
            'date' => date('M Y', strtotime($it['created_at'] ?? 'now')),
        ];
        $portfolioData[(int)$it['id']] = [
            'title' => (string)$it['title'],
            'description' => (string)($it['description'] ?? ''),
            'images' => array_values(array_map(fn($m)=> (string)$m['path'], $media)),
        ];
    }
    // Archived
    $stA = $pdo->prepare("SELECT id, title, description, created_at FROM portfolio_items WHERE user_id = :uid AND (archived_at IS NOT NULL) ORDER BY archived_at DESC, id DESC");
    $stA->execute([':uid'=>$profileUserId]);
    $itemsA = $stA->fetchAll();
    foreach ($itemsA as $it) {
        $st2 = $pdo->prepare("SELECT id, path FROM portfolio_media WHERE item_id = :iid ORDER BY sort_order ASC, id ASC");
        $st2->execute([':iid'=>$it['id']]);
        $media = $st2->fetchAll();
        $firstImage = $media[0]['path'] ?? null;
        $portfolioArchived[] = [
            'id' => (int)$it['id'],
            'title' => (string)$it['title'],
            'description' => (string)($it['description'] ?? ''),
            'image' => $firstImage ?: 'img/profile_icon.webp',
            'category' => 'Portfolio',
            'date' => date('M Y', strtotime($it['created_at'] ?? 'now')),
        ];
        // Also allow viewing images in modal
        $portfolioData[(int)$it['id']] = [
            'title' => (string)$it['title'],
            'description' => (string)($it['description'] ?? ''),
            'images' => array_values(array_map(fn($m)=> (string)$m['path'], $media)),
        ];
    }
    $portfolioDbLoaded = true;
} catch (Throwable $e) {
    // Fallback to filesystem scan
}
if (!$portfolioDbLoaded && is_dir($portfolioDir)) {
    $files = array_values(array_filter(@scandir($portfolioDir) ?: [], fn($f)=>$f!=='.'&&$f!=='..'));
    $i=1;
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png','webp','gif'],true)) continue;
        $imgPath = 'img/uploads/portfolio/'.(int)$profileUserId.'/'.$f;
        $portfolioItems[] = [
            'id'=>$i,
            'title'=>'Portfolio Item',
            'description'=>'',
            'image'=>$imgPath,
            'category'=>'Portfolio',
            'date'=>date('M Y', @filemtime($portfolioDir.'/'.$f))
        ];
        $portfolioData[$i] = [
            'title' => 'Portfolio Item',
            'description' => '',
            'images' => [$imgPath],
        ];
        $i++;
    }
}

// Unread inbox messages count for sidebar badge (for current viewer)
$unreadCount = 0;
if ($viewerId) {
    try { $unreadCount = (int)$db->countUnreadMessages($viewerId); } catch (Throwable $e) { $unreadCount = 0; }
}
// Notifications for the viewer (latest 10)
$notifications = [];
if ($viewerId) {
    try {
        $stN = $pdo->prepare("SELECT notification_id,type,data,created_at,read_at FROM notifications WHERE user_id=:u ORDER BY created_at DESC LIMIT 10");
        $stN->execute([':u'=>$viewerId]);
        foreach ($stN->fetchAll() as $n) {
            $type = (string)$n['type'];
            $data = [];
            if (!empty($n['data'])) {
                $d = json_decode($n['data'], true);
                if (is_array($d)) $data = $d;
            }
            $msg = ucfirst(str_replace('_',' ', $type)) . ' update';
            if (($type === 'system' || $type === 'admin_message') && isset($data['text'])) {
                $msg = 'System: ' . (string)$data['text'];
            } elseif ($type === 'booking_status_changed' && isset($data['status'], $data['booking_id'])) {
                $msg = "Booking #{$data['booking_id']} status: ".ucfirst(str_replace('_',' ',$data['status']));
            } elseif ($type === 'booking_created' && isset($data['booking_id'])) {
                $msg = "Booking #{$data['booking_id']} was created.";
            } elseif ($type === 'payment_recorded' && isset($data['amount'])) {
                $msg = 'Payment received: ₱'.number_format((float)$data['amount'],2);
            } elseif ($type === 'service_rejected') {
                $reason = isset($data['reason']) && $data['reason'] !== '' ? (string)$data['reason'] : '';
                $title  = isset($data['title']) && $data['title'] !== '' ? (string)$data['title'] : '';
                $msg = 'Service Approval: Rejected' . ($reason !== '' ? ' — ' . $reason : '') . ($title !== '' ? ' (' . $title . ')' : '');
            } elseif ($type === 'service_approved') {
                $title  = isset($data['title']) && $data['title'] !== '' ? (string)$data['title'] : '';
                $msg = 'Service Approval: Approved' . ($title !== '' ? ' — ' . $title : '');
            }
            $notifications[] = [
                'id' => (int)$n['notification_id'],
                'message' => $msg,
                'time' => date('M d, Y g:i A', strtotime($n['created_at'] ?? date('Y-m-d H:i:s'))),
                'unread' => empty($n['read_at']),
            ];
        }
    } catch (Throwable $e) { /* ignore */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Hive - <?php echo htmlspecialchars($freelancer['name']); ?> Profile</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .5; }
        }
        
        .sidebar { 
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
        }
        .sidebar.open { transform: translateX(0); }
        
        @media (min-width: 1024px) {
            .sidebar { transform: translateX(0) !important; }
        }
        
        @media (max-width: 1024px) {
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 30;
                top: 4rem;
            }
            .sidebar-overlay.active {
                display: block;
            }
        }
        
        .dropdown { display: none; opacity: 0; }
        .dropdown.active { display: block; animation: slideDown 0.2s ease-out forwards; }
        
        .sidebar-item {
            transition: all 0.2s ease;
        }
        
        .sidebar-item.active {
            background: linear-gradient(to right, #f59e0b, #f97316);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.3);
        }
        
        .sidebar-item:not(.active):hover {
            background: rgba(245, 158, 11, 0.1);
            transform: translateX(4px);
        }
        
        .notification-badge {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        .notification-item {
            transition: all 0.2s ease;
        }
        
        .notification-item:hover {
            background: rgba(245, 158, 11, 0.1);
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .scale-in {
            animation: scaleIn 0.4s ease-out forwards;
        }
        
        .portfolio-item {
            transition: all 0.3s ease;
        }
        
        .portfolio-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(245, 158, 11, 0.2);
        }
        
        .portfolio-item:hover .portfolio-overlay {
            opacity: 1;
        }
        
        .portfolio-overlay {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .skill-badge {
            transition: all 0.2s ease;
        }
        
        .skill-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .review-card {
            transition: all 0.3s ease;
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .review-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(245, 158, 11, 0.15);
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(245, 158, 11, 0.15);
        }
        
        .tab-button {
            transition: all 0.2s ease;
            position: relative;
        }
        
        .tab-button.active {
            color: #f59e0b;
        }
        
        .tab-button .tab-indicator {
            display: none;
            height: 3px;
            background: linear-gradient(to right, #f59e0b, #f97316);
            border-radius: 999px;
        }
        
        .tab-button.active .tab-indicator {
            display: block;
        }
        
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        .rating-bar {
            transition: width 0.6s ease-out;
        }
        
        .payment-badge {
            transition: all 0.2s ease;
        }
        
        .payment-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
        }
        
        .cert-badge {
            transition: all 0.3s ease;
        }
        
        .cert-badge:hover {
            transform: translateX(4px);
            background: rgba(245, 158, 11, 0.1);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 via-amber-50/30 to-orange-50/30">

    <!-- Sidebar Overlay (Mobile) -->
    <div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <!-- Navbar -->
    <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-xl border-b border-amber-200/50 shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Left Section -->
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="p-2 hover:bg-amber-100 rounded-lg transition-colors">
                        <i data-lucide="menu" class="w-5 h-5 text-gray-700"></i>
                    </button>

                    <a href="index.php" class="flex items-center gap-3 cursor-pointer hover:scale-105 transition-transform">
                        <div class="relative">
                            <svg class="w-8 h-8 fill-amber-400 stroke-amber-600 stroke-2" viewBox="0 0 24 24">
                                <polygon points="12 2, 22 8.5, 22 15.5, 12 22, 2 15.5, 2 8.5"/>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="w-2 h-2 rounded-full bg-amber-600"></div>
                            </div>
                        </div>
                        <div class="hidden sm:block">
                            <h1 class="text-xl font-bold text-amber-900 tracking-tight">Task Hive</h1>
                        </div>
                    </a>
                </div>

                <!-- Right Section -->
                <div class="flex items-center gap-3">
                    <!-- Notifications -->
                    <div class="relative">
                        <button onclick="toggleNotifications()" class="relative p-2 hover:bg-amber-100 rounded-full transition-colors">
                            <i data-lucide="bell" class="w-5 h-5 text-gray-700"></i>
                            <span class="notification-badge absolute top-1 right-1 w-2 h-2 bg-orange-500 rounded-full"></span>
                        </button>

                        <div id="notifications-dropdown" class="dropdown absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden">
                            <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border-b border-amber-200 flex items-center justify-between">
                                <h3 class="font-bold text-gray-900">Notifications</h3>
                                <button onclick="markAllAsRead()" class="text-xs text-amber-600 hover:text-amber-700 font-medium">Mark all as read</button>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item p-4 border-b border-amber-100 <?php echo $notif['unread'] ? 'bg-blue-50/50' : ''; ?>">
                                        <div class="flex items-start gap-3">
                                            <?php if ($notif['unread']): ?>
                                                <div class="w-2 h-2 bg-blue-500 rounded-full mt-2 flex-shrink-0"></div>
                                            <?php endif; ?>
                                            <div class="flex-1">
                                                <p class="text-sm text-gray-800"><?php echo htmlspecialchars($notif['message']); ?></p>
                                                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($notif['time']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!count($notifications)): ?>
                                    <div class="p-4 text-sm text-gray-600">No notifications</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <button onclick="toggleProfileDropdown()" class="flex items-center gap-3 px-3 py-2 hover:bg-amber-50 rounded-full transition-colors">
                            <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-8 h-8 rounded-full border-2 border-amber-400 object-cover">
                            <span class="hidden md:block text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                            <svg id="dropdown-arrow" class="w-4 h-4 text-gray-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <div id="profile-dropdown" class="dropdown absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-xl border border-amber-200 overflow-hidden">
                            <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border-b border-amber-200">
                                <div class="flex items-center gap-3">
                                    <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                    <div>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="py-2">
                                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="user" class="w-5 h-5 text-amber-600"></i>
                                    <span>View Dashboard</span>
                                </a>
                                
                                <a href="settings.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-amber-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="settings" class="w-5 h-5 text-amber-600"></i>
                                    <span>Settings</span>
                                </a>
                                <div class="my-2 border-t border-gray-200"></div>
                                <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-red-600 hover:bg-red-50 hover:translate-x-1 transition-all">
                                    <i data-lucide="log-out" class="w-5 h-5"></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar fixed left-0 top-16 h-[calc(100vh-4rem)] w-64 bg-gradient-to-b from-gray-900 via-gray-800 to-amber-900 text-white z-40 flex flex-col shadow-2xl">
            <!-- Navigation Title -->
            <div class="px-6 py-6 border-b border-white/10">
                <h2 class="text-lg font-bold text-amber-400 tracking-wide">Navigation</h2>
            </div>

            <!-- Main Menu -->
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto hide-scrollbar">
                <a href="inbox.php" class="sidebar-item flex items-center justify-between px-4 py-3 rounded-lg text-gray-300" data-name="inbox">
                    <div class="flex items-center gap-3">
                        <i data-lucide="inbox" class="w-5 h-5"></i>
                        <span class="font-medium tracking-wide">Inbox</span>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <span id="inbox-count-badge" class="px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold"><?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?></span>
                    <?php endif; ?>
                </a>


                <a href="feed.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Browse Services</span>
                </a>

                <a href="freelancer_profile.php" class="sidebar-item active flex items-center gap-3 px-4 py-3 rounded-lg">
                    <i data-lucide="user" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">My Profile</span>
                </a>


            </nav>

            <!-- Bottom Section -->
            <div class="px-3 py-4 border-t border-white/10 space-y-1">
                <a href="settings.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Settings</span>
                </a>
                <a href="helpsupport.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300">
                    <i data-lucide="help-circle" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Help & Support</span>
                </a>
                <a href="logout.php" class="sidebar-item flex items-center gap-3 px-4 py-3 rounded-lg text-red-400 hover:bg-red-500/10">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                    <span class="font-medium tracking-wide">Logout</span>
                </a>
            </div>

            <!-- Bee Icon -->
            <div class="absolute bottom-4 right-4 text-2xl opacity-20">🐝</div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 transition-all duration-300 lg:ml-64">
            <div class="min-h-screen">
                <!-- Cover Image -->
                <div class="relative h-64 bg-gradient-to-br from-amber-400 to-orange-500 overflow-hidden">
                    <img src="<?php echo htmlspecialchars($freelancer['cover_image']); ?>" alt="Cover" class="w-full h-full object-cover opacity-40">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent"></div>
                </div>

                <!-- Profile Header -->
                <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="relative -mt-20 mb-8">
                        <div class="bg-white rounded-2xl shadow-xl border border-amber-200 p-6">
                            <div class="flex flex-col md:flex-row gap-6 items-start md:items-center">
                                <!-- Avatar -->
                                <div class="relative">
                                    <img src="<?php echo htmlspecialchars($freelancer['avatar']); ?>" alt="<?php echo htmlspecialchars($freelancer['name']); ?>" class="w-32 h-32 rounded-full border-4 border-white shadow-lg object-cover">
                                    <?php if ($freelancer['verified']): ?>
                                        <div class="absolute bottom-2 right-2 bg-gradient-to-br from-amber-500 to-orange-500 rounded-full p-2 shadow-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-white"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="absolute -bottom-1 left-1/2 -translate-x-1/2 px-3 py-1 bg-green-500 text-white text-xs font-medium rounded-full whitespace-nowrap">
                                        <?php echo htmlspecialchars($freelancer['availability']); ?>
                                    </div>
                                </div>

                                <!-- Info -->
                                <div class="flex-1">
                                    <div class="flex items-start justify-between flex-wrap gap-4">
                                        <div>
                                            <div class="flex items-center gap-2 mb-1">
                                                <h1 class="text-gray-900"><?php echo htmlspecialchars($freelancer['name']); ?></h1>
                                                <?php if ($freelancer['verified']): ?>
                                                    <span class="flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-medium rounded-full">
                                                        <i data-lucide="shield-check" class="w-3 h-3"></i>
                                                        Verified
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-lg text-gray-700 mb-2"><?php echo htmlspecialchars($freelancer['title']); ?></p>
                                            <div class="flex items-center gap-4 text-sm text-gray-600 mb-2">
                                                <div class="flex items-center gap-1">
                                                    <i data-lucide="map-pin" class="w-4 h-4 text-amber-600"></i>
                                                    <span><?php echo htmlspecialchars($freelancer['address']); ?></span>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <i data-lucide="calendar" class="w-4 h-4 text-amber-600"></i>
                                                    <span>Member since <?php echo date('M Y', strtotime($freelancer['member_since'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <div class="flex items-center">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i data-lucide="star" class="w-4 h-4 <?php echo $i <= floor($freelancer['rating']) ? 'fill-amber-400 text-amber-400' : 'text-gray-300'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <span class="text-sm font-medium text-gray-900"><?php echo number_format($freelancer['rating'], 1); ?></span>
                                                <span class="text-sm text-gray-600">(<?php echo number_format($freelancer['total_reviews']); ?> reviews)</span>
                                            </div>
                                        </div>

                                        <?php if ($viewerId && !$isOwner): ?>
                                        <div class="flex flex-col gap-2">
                                            <form method="POST" action="freelancer_profile.php?id=<?php echo (int)$profileUserId; ?>" class="inline">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="start_chat_from_profile" value="1" />
                                                <button type="submit" class="flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 shadow-md hover:shadow-lg transition-all">
                                                    <i data-lucide="message-circle" class="w-5 h-5"></i>
                                                    <span class="font-medium">Contact Me</span>
                                                </button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Stats -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 pt-6 border-t border-amber-200">
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-amber-50 to-orange-50/50 rounded-lg">
                                    <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo number_format($freelancer['completed_jobs']); ?></div>
                                    <div class="text-sm text-gray-600">Completed Jobs</div>
                                </div>
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-amber-50 to-orange-50/50 rounded-lg">
                                    <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo number_format($freelancer['rating'], 1); ?>/5</div>
                                    <div class="text-sm text-gray-600">Rating</div>
                                </div>
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-amber-50 to-orange-50/50 rounded-lg">
                                    <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo number_format($approvedServicesCount); ?></div>
                                    <div class="text-sm text-gray-600">Approved Services</div>
                                </div>
                                <div class="stat-card text-center p-4 bg-gradient-to-br from-amber-50 to-orange-50/50 rounded-lg">
                                    <div class="text-2xl font-bold text-gray-900 mb-1">₱<?php echo number_format($freelancer['hourly_rate'], 2); ?></div>
                                    <div class="text-sm text-gray-600">Hourly Rate</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content Grid -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                        <!-- Left Column -->
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Tabs -->
                            <div class="bg-white rounded-2xl shadow-lg border border-amber-200">
                                <div class="flex border-b border-amber-200 overflow-x-auto hide-scrollbar">
                                    <button onclick="switchTab('about')" class="tab-button active flex-1 px-6 py-4 text-sm font-medium hover:bg-amber-50 transition-colors">
                                        About
                                        <div class="tab-indicator mt-2"></div>
                                    </button>
                                    <button onclick="switchTab('portfolio')" class="tab-button flex-1 px-6 py-4 text-sm font-medium text-gray-600 hover:bg-amber-50 transition-colors">
                                        Portfolio
                                        <div class="tab-indicator mt-2"></div>
                                    </button>
                                    <button onclick="switchTab('services')" class="tab-button flex-1 px-6 py-4 text-sm font-medium text-gray-600 hover:bg-amber-50 transition-colors">
                                        Services
                                        <div class="tab-indicator mt-2"></div>
                                    </button>
                                    <button onclick="switchTab('reviews')" class="tab-button flex-1 px-6 py-4 text-sm font-medium text-gray-600 hover:bg-amber-50 transition-colors">
                                        Reviews
                                        <div class="tab-indicator mt-2"></div>
                                    </button>
                                </div>

                                <!-- Tab Content -->
                                <div class="p-6">
                                    <!-- About Tab -->
                                    <div id="about-tab" class="tab-content">
                                        <h3 class="text-gray-900 mb-4">About Me</h3>
                                        <p class="text-gray-700 leading-relaxed mb-6"><?php echo nl2br(htmlspecialchars($freelancer['bio'])); ?></p>
                                    </div>

                                    <!-- Portfolio Tab -->
                                    <div id="portfolio-tab" class="tab-content hidden">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                            <?php foreach ($portfolioItems as $item): ?>
                                                <div class="portfolio-item bg-white rounded-xl overflow-hidden border border-amber-200 shadow-md cursor-pointer" onclick="viewPortfolioItem(<?php echo $item['id']; ?>)">
                                                    <div class="relative h-48 overflow-hidden">
                                                        <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="w-full h-full object-cover">
                                                        <div class="portfolio-overlay absolute inset-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent flex items-end p-4">
                                                            <div class="text-white">
                                                                <p class="text-xs mb-1"><?php echo htmlspecialchars($item['category']); ?></p>
                                                                <h4 class="font-medium"><?php echo htmlspecialchars($item['title']); ?></h4>
                                                            </div>
                                                        </div>
                                                        <?php if ($isOwner && $isFreelancer): ?>
                                                        <div class="absolute top-2 right-2 flex items-center gap-2" onclick="event.stopPropagation()">
                                                            <button type="button"
                                                                    class="px-2 py-1 text-xs bg-white/90 hover:bg-white rounded-md border border-amber-200 shadow-sm flex items-center gap-1"
                                                                    data-item-id="<?php echo (int)$item['id']; ?>"
                                                                    data-title="<?php echo htmlspecialchars($portfolioData[$item['id']]['title'] ?? $item['title'], ENT_QUOTES); ?>"
                                                                    data-desc="<?php echo htmlspecialchars($portfolioData[$item['id']]['description'] ?? $item['description'], ENT_QUOTES); ?>"
                                                                    onclick="openEditPortfolio(event, this)">
                                                                <i data-lucide="pencil" class="w-3 h-3 text-amber-700"></i><span>Edit</span>
                                                            </button>
                                                            <form method="post" onsubmit="event.stopPropagation(); return confirm('Archive this portfolio item?');">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="portfolio_action" value="archive" />
                                                                <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>" />
                                                                <button type="submit" class="px-2 py-1 text-xs bg-white/90 hover:bg-white rounded-md border border-amber-200 shadow-sm flex items-center gap-1 text-gray-700">
                                                                    <i data-lucide="archive" class="w-3 h-3"></i><span>Archive</span>
                                                                </button>
                                                            </form>
                                                            <form method="post" onsubmit="event.stopPropagation(); return confirm('Permanently delete this item and its images? This cannot be undone.');">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="portfolio_action" value="delete" />
                                                                <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>" />
                                                                <button type="submit" class="px-2 py-1 text-xs bg-red-600 hover:bg-red-700 text-white rounded-md border border-red-700/20 shadow-sm flex items-center gap-1">
                                                                    <i data-lucide="trash-2" class="w-3 h-3"></i><span>Delete</span>
                                                                </button>
                                                            </form>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="p-4">
                                                        <p class="text-sm text-gray-600 mb-2"><?php echo htmlspecialchars($item['description']); ?></p>
                                                        <div class="flex items-center justify-between">
                                                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($item['date']); ?></span>
                                                            <span class="text-xs text-amber-600 font-medium">View Details →</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($portfolioItems) === 0): ?>
                                                <div class="p-4 text-sm text-gray-600 bg-amber-50/50 border border-amber-200 rounded-lg">No portfolio items yet.</div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isOwner && $isFreelancer): ?>
                                        <form method="post" enctype="multipart/form-data" class="mt-6 p-4 border border-amber-200 rounded-xl bg-amber-50/30">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="portfolio_upload" value="1" />
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                                                    <input name="portfolio_title" type="text" maxlength="255" placeholder="e.g., Brand Design Case Study" class="w-full rounded-lg border-amber-200 focus:ring-amber-500 focus:border-amber-500" />
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                                    <input name="portfolio_description" type="text" placeholder="Short description (optional)" class="w-full rounded-lg border-amber-200 focus:ring-amber-500 focus:border-amber-500" />
                                                </div>
                                            </div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Upload portfolio images (JPG, PNG, WEBP, GIF)</label>
                                            <input type="file" name="portfolio_images[]" accept="image/*" multiple class="block w-full text-sm text-gray-600 mb-3" />
                                            <button class="px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg">Upload</button>
                                        </form>
                                        <?php if (!empty($portfolioArchived)): ?>
                                        <div class="mt-6">
                                            <button type="button" class="flex items-center gap-2 text-sm text-gray-700 hover:text-amber-700" onclick="toggleArchived()">
                                                <i data-lucide="archive" class="w-4 h-4"></i>
                                                <span>Archived (<?php echo count($portfolioArchived); ?>)</span>
                                                <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                            </button>
                                            <div id="archived-list" class="hidden mt-3 grid grid-cols-1 md:grid-cols-2 gap-6">
                                                <?php foreach ($portfolioArchived as $item): ?>
                                                    <div class="portfolio-item bg-white rounded-xl overflow-hidden border border-gray-200 shadow-sm cursor-pointer" onclick="viewPortfolioItem(<?php echo $item['id']; ?>)">
                                                        <div class="relative h-40 overflow-hidden">
                                                            <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="w-full h-full object-cover grayscale">
                                                            <div class="absolute top-2 right-2 flex items-center gap-2" onclick="event.stopPropagation()">
                                                                <form method="post" onsubmit="event.stopPropagation(); return confirm('Restore this portfolio item from archive?');">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="portfolio_action" value="unarchive" />
                                                                    <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>" />
                                                                    <button type="submit" class="px-2 py-1 text-xs bg-white/90 hover:bg-white rounded-md border border-amber-200 shadow-sm flex items-center gap-1 text-gray-700">
                                                                        <i data-lucide="rotate-ccw" class="w-3 h-3"></i><span>Unarchive</span>
                                                                    </button>
                                                                </form>
                                                                <form method="post" onsubmit="event.stopPropagation(); return confirm('Permanently delete this item and its images? This cannot be undone.');">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="portfolio_action" value="delete" />
                                                                    <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>" />
                                                                    <button type="submit" class="px-2 py-1 text-xs bg-red-600 hover:bg-red-700 text-white rounded-md border border-red-700/20 shadow-sm flex items-center gap-1">
                                                                        <i data-lucide="trash-2" class="w-3 h-3"></i><span>Delete</span>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                        <div class="p-4">
                                                            <h4 class="font-medium text-gray-800 flex items-center gap-2">
                                                                <i data-lucide="archive" class="w-4 h-4 text-gray-500"></i>
                                                                <?php echo htmlspecialchars($item['title']); ?>
                                                            </h4>
                                                            <p class="text-sm text-gray-500 mt-1 line-clamp-2"><?php echo htmlspecialchars($item['description']); ?></p>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Services Tab -->
                                    <div id="services-tab" class="tab-content hidden">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <?php if ($isFreelancer && count($services)>0): ?>
                                                <?php foreach ($services as $svc): ?>
                                                    <div class="portfolio-item bg-white rounded-xl overflow-hidden border border-amber-200 shadow-md"
                                                         data-service-id="<?php echo (int)$svc['service_id']; ?>"
                                                         data-title="<?php echo htmlspecialchars($svc['title']); ?>"
                                                         data-unit-price="<?php echo (float)($svc['base_price'] ?? 0); ?>">
                                                        <div class="p-4">
                                                            <div class="flex items-center justify-between mb-1">
                                                                <h4 class="font-medium text-gray-900 line-clamp-1" data-svc-title><?php echo htmlspecialchars($svc['title']); ?></h4>
                                                                <span class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($svc['created_at'] ?? 'now')); ?></span>
                                                            </div>
                                                            <p class="text-sm text-gray-600 line-clamp-2 mb-2"><?php echo htmlspecialchars(mb_strimwidth((string)($svc['description'] ?? ''),0,120,'…')); ?></p>
                                                            <div class="flex items-center justify-between text-sm">
                                                                <span class="text-amber-700 font-medium">₱<?php echo number_format((float)($svc['base_price'] ?? 0),2); ?></span>
                                                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">Active</span>
                                                            </div>
                                                            <?php if ($viewerIsClient && !$isOwner): ?>
                                                            <div class="mt-3 flex justify-end">
                                                                <button type="button"
                                                                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm"
                                                                    onclick="openBookingModal(<?php echo (int)$svc['service_id']; ?>, '<?php echo htmlspecialchars(addslashes($svc['title'])); ?>', <?php echo (float)($svc['base_price'] ?? 0); ?>)">
                                                                    Book Service
                                                                </button>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="p-4 text-sm text-gray-600 bg-amber-50/50 border border-amber-200 rounded-lg">No services to show.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Reviews Tab -->
                                    <div id="reviews-tab" class="tab-content hidden">
                                        <!-- Rating Summary -->
                                        <div class="bg-gradient-to-br from-amber-50 to-orange-50/50 rounded-xl p-6 mb-6 border border-amber-200">
                                            <div class="flex flex-col md:flex-row gap-6 items-center">
                                                <div class="text-center">
                                                    <div class="text-5xl font-bold text-gray-900 mb-2"><?php echo number_format($freelancer['rating'], 1); ?></div>
                                                    <div class="flex items-center justify-center mb-2">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i data-lucide="star" class="w-5 h-5 <?php echo $i <= floor($freelancer['rating']) ? 'fill-amber-400 text-amber-400' : 'text-gray-300'; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <p class="text-sm text-gray-600"><?php echo number_format((int)$freelancer['total_reviews']); ?> reviews</p>
                                                </div>
                                                <div class="flex-1 w-full">
                                                    <?php foreach ($ratingDistribution as $star => $count): ?>
                                                        <div class="flex items-center gap-3 mb-2">
                                                            <span class="text-sm text-gray-600 w-8"><?php echo $star; ?> ★</span>
                                                            <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                                                                <div class="rating-bar h-full bg-gradient-to-r from-amber-400 to-orange-500 rounded-full" style="width: <?php echo ($freelancer['total_reviews']>0)? round(($count / max(1,$freelancer['total_reviews'])) * 100) : 0; ?>%"></div>
                                                            </div>
                                                            <span class="text-sm text-gray-600 w-8"><?php echo $count; ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Reviews List -->
                                        <div class="space-y-4">
                                            <?php foreach ($reviews as $index => $review): ?>
                                                <div class="review-card p-6 bg-white rounded-xl border border-amber-200" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                                    <div class="flex items-start gap-4 mb-4">
                                                        <img src="<?php echo htmlspecialchars($review['profile_picture'] ?: 'img/profile_icon.webp'); ?>" alt="<?php echo htmlspecialchars(trim(($review['first_name']??'').' '.($review['last_name']??''))); ?>" class="w-12 h-12 rounded-full border-2 border-amber-400 object-cover">
                                                        <div class="flex-1">
                                                            <div class="flex items-center justify-between mb-1">
                                                                <h5 class="font-medium text-gray-900"><?php echo htmlspecialchars(trim(($review['first_name']??'').' '.($review['last_name']??''))); ?></h5>
                                                                <span class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($review['created_at'] ?? 'now')); ?></span>
                                                            </div>
                                                            <div class="flex items-center gap-2 mb-1">
                                                                <div class="flex">
                                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                        <i data-lucide="star" class="w-4 h-4 <?php echo $i <= (int)$review['rating'] ? 'fill-amber-400 text-amber-400' : 'text-gray-300'; ?>"></i>
                                                                    <?php endfor; ?>
                                                                </div>
                                                                <span class="text-sm font-medium text-gray-900"><?php echo number_format((float)$review['rating'], 1); ?></span>
                                                            </div>
                                                            <?php if (!empty($review['service_title'])): ?>
                                                            <p class="text-xs text-amber-600 font-medium">For: <?php echo htmlspecialchars($review['service_title']); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <p class="text-gray-700 leading-relaxed"><?php echo htmlspecialchars((string)($review['comment'] ?? '')); ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column -->
                        <div class="space-y-6">
                            <!-- Skills -->
                            <div class="bg-white rounded-2xl shadow-lg border border-amber-200 p-6">
                                <h3 class="text-gray-900 mb-4">Skills & Expertise</h3>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($skillsArray as $skill): ?>
                                        <span class="skill-badge px-4 py-2 bg-gradient-to-br from-amber-100 to-orange-100 text-amber-800 rounded-lg text-sm font-medium border border-amber-200">
                                            <?php echo htmlspecialchars($skill); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Payment Methods -->
                            <div class="bg-white rounded-2xl shadow-lg border border-amber-200 p-6">
                                <div class="flex items-center gap-2 mb-4">
                                    <i data-lucide="credit-card" class="w-5 h-5 text-amber-600"></i>
                                    <h3 class="text-gray-900">Payment Methods</h3>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach ($paymentMethods as $method): ?>
                                        <div class="payment-badge flex items-center justify-between p-3 bg-gradient-to-br from-amber-50 to-orange-50/50 rounded-lg border border-amber-200">
                                            <div class="flex items-center gap-3">
                                                <div class="p-2 bg-gradient-to-br from-amber-500 to-orange-500 rounded-lg">
                                                    <?php $icon = ($method['method_type']==='gcash')?'smartphone':(($method['method_type']==='paymaya')?'wallet':'credit-card'); ?>
                                                    <i data-lucide="<?php echo htmlspecialchars($icon); ?>" class="w-4 h-4 text-white"></i>
                                                </div>
                                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($method['display_label'] ?? strtoupper($method['method_type'])); ?></span>
                                            </div>
                                            <?php if (!isset($method['is_active']) || (int)$method['is_active']===1): ?>
                                                <i data-lucide="check-circle" class="w-4 h-4 text-green-500"></i>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="text-xs text-gray-500 mt-4">All payment methods are verified and secure</p>
                            </div>

                            <!-- Quick Info -->
                            <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-2xl shadow-lg p-6 text-white">
                                <h3 class="text-white mb-4">💡 Quick Info</h3>
                                <div class="space-y-3">
                                    <div class="flex items-start gap-3">
                                        <i data-lucide="clock" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                        <div>
                                            <p class="text-sm font-medium">Reliability</p>
                                            <p class="text-xs opacity-90"><?php echo $reliability!==null ? ($reliability.'% completed on time') : '—'; ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <i data-lucide="briefcase" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                        <div>
                                            <p class="text-sm font-medium">Success Rate</p>
                                            <p class="text-xs opacity-90">98% job completion</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <i data-lucide="repeat" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                        <div>
                                            <p class="text-sm font-medium">Repeat Clients</p>
                                            <p class="text-xs opacity-90">85% client return rate</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Trust & Safety -->
                            <div class="bg-white rounded-2xl shadow-lg border border-amber-200 p-6">
                                <div class="flex items-center gap-2 mb-4">
                                    <i data-lucide="shield-check" class="w-5 h-5 text-green-600"></i>
                                    <h3 class="text-gray-900">Trust & Safety</h3>
                                </div>
                                <div class="space-y-3">
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 bg-green-100 rounded-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i>
                                        </div>
                                        <span class="text-sm text-gray-700">Identity Verified</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 bg-green-100 rounded-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i>
                                        </div>
                                        <span class="text-sm text-gray-700">Payment Verified</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 bg-green-100 rounded-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i>
                                        </div>
                                        <span class="text-sm text-gray-700">Phone Verified</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 bg-green-100 rounded-lg">
                                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i>
                                        </div>
                                        <span class="text-sm text-gray-700">Email Verified</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
        lucide.createIcons();

        let sidebarOpen = false;
        let currentTab = 'about';

        function toggleSidebar() {
            sidebarOpen = !sidebarOpen;
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            
            if (sidebarOpen) {
                sidebar.classList.add('open');
                overlay.classList.add('active');
            } else {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            }
        }

        function toggleNotifications() {
            const dropdown = document.getElementById('notifications-dropdown');
            const profileDropdown = document.getElementById('profile-dropdown');
            
            profileDropdown.classList.remove('active');
            dropdown.classList.toggle('active');
        }

        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profile-dropdown');
            const notifDropdown = document.getElementById('notifications-dropdown');
            const arrow = document.getElementById('dropdown-arrow');
            
            notifDropdown.classList.remove('active');
            dropdown.classList.toggle('active');
            arrow.style.transform = dropdown.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0deg)';
        }

        document.addEventListener('click', function(event) {
            const profileDropdown = document.getElementById('profile-dropdown');
            const notifDropdown = document.getElementById('notifications-dropdown');
            const clickedButton = event.target.closest('button');
            
            if (!clickedButton || (!clickedButton.onclick && !clickedButton.getAttribute('onclick'))) {
                profileDropdown.classList.remove('active');
                notifDropdown.classList.remove('active');
                document.getElementById('dropdown-arrow').style.transform = 'rotate(0deg)';
            }
        });

        function markAllAsRead() {
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.classList.remove('bg-blue-50/50');
                const dot = item.querySelector('.bg-blue-500');
                if (dot) dot.remove();
            });
            
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.remove();
        }

        function switchTab(tab) {
            currentTab = tab;
            
            // Update tab buttons
            const tabs = document.querySelectorAll('.tab-button');
            tabs.forEach(t => {
                t.classList.remove('active');
                t.classList.add('text-gray-600');
            });
            event.target.closest('.tab-button').classList.add('active');
            event.target.closest('.tab-button').classList.remove('text-gray-600');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById(tab + '-tab').classList.remove('hidden');
            
            // Reinitialize icons
            setTimeout(() => lucide.createIcons(), 100);
        }

        function contactFreelancer() {
            window.location.href = 'inbox.php?user_id=<?php echo (int)$profileUserId; ?>';
        }

        function bookFreelancer() {
            alert('Booking system coming soon! This would open a booking modal.');
        }

        // Booking modal handlers
        function openBookingModal(serviceId, title, unitPrice) {
            const modal = document.getElementById('booking-modal');
            if (!modal) return;
            document.getElementById('booking-service-id').value = serviceId;
            document.getElementById('booking-service-title').value = title;
            document.getElementById('booking-unit-price').value = String(unitPrice || 0);
            document.getElementById('booking-qty').value = '1';
            updateBookingTotal();
            modal.classList.remove('hidden');
            setTimeout(()=> lucide.createIcons(), 0);
        }

        function closeBookingModal() {
            const modal = document.getElementById('booking-modal');
            if (modal) modal.classList.add('hidden');
        }

        function updateBookingTotal() {
            const qty = parseInt(document.getElementById('booking-qty').value || '1', 10);
            const unit = parseFloat(document.getElementById('booking-unit-price').value || '0');
            const total = (isNaN(qty)?1:qty) * (isNaN(unit)?0:unit);
            document.getElementById('booking-total').value = '₱' + total.toFixed(2);
        }

        function validateBookingForm() {
            const qty = parseInt(document.getElementById('booking-qty').value || '1', 10);
            if (!qty || qty < 1) { alert('Quantity must be at least 1'); return false; }
            return true;
        }

        // Portfolio modal data injected from PHP
        const PORTFOLIO_DATA = <?php echo json_encode($portfolioData, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;

        function viewPortfolioItem(id) {
            const data = PORTFOLIO_DATA[String(id)] || PORTFOLIO_DATA[id];
            if (!data) return;
            const modal = document.getElementById('portfolio-modal');
            const titleEl = document.getElementById('portfolio-modal-title');
            const descEl = document.getElementById('portfolio-modal-desc');
            const imgsEl = document.getElementById('portfolio-modal-images');
            titleEl.textContent = data.title || 'Portfolio Item';
            descEl.textContent = data.description || '';
            imgsEl.innerHTML = '';
            (data.images || []).forEach((src) => {
                const img = document.createElement('img');
                img.src = src;
                img.alt = data.title || 'Portfolio Image';
                img.className = 'w-full h-64 object-cover rounded-lg border border-amber-200 cursor-zoom-in';
                img.addEventListener('click', () => openImageLightbox(src));
                imgsEl.appendChild(img);
            });
            modal.classList.remove('hidden');
            setTimeout(()=> modal.classList.add('scale-in'), 0);
        }

        function closePortfolioModal() {
            const modal = document.getElementById('portfolio-modal');
            modal.classList.add('hidden');
        }

        // Close sidebar on desktop resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                sidebarOpen = false;
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebar-overlay').classList.remove('active');
            }
        });

        // Animate rating bars on load
        window.addEventListener('load', function() {
            const bars = document.querySelectorAll('.rating-bar');
            bars.forEach((bar, index) => {
                setTimeout(() => {
                    bar.style.width = bar.style.width;
                }, index * 100);
            });
        });

        // Initialize icons
        setTimeout(() => lucide.createIcons(), 100);

        // Auto-open booking modal from feed link
        <?php if ($viewerIsClient && !$isOwner): ?>
        (function(){
            try {
                const qs = new URLSearchParams(window.location.search);
                const svcId = qs.get('book_service_id');
                if (!svcId) return;
                const svcEl = document.querySelector(`[data-service-id="${CSS.escape(svcId)}"]`);
                const title = svcEl ? (svcEl.getAttribute('data-title') || svcEl.querySelector('[data-svc-title]')?.textContent || 'Service') : 'Service';
                const unit = svcEl ? parseFloat(svcEl.getAttribute('data-unit-price') || '0') : 0;
                openBookingModal(Number(svcId), title, unit);
                // Switch to Services tab if not visible
                const servicesTabBtn = Array.from(document.querySelectorAll('.tab-button')).find(btn => btn.textContent.trim().startsWith('Services'));
                if (servicesTabBtn) servicesTabBtn.click();
            } catch (e) { /* ignore */ }
        })();
        <?php endif; ?>

        // Live-refresh unread inbox badge (for logged-in viewer)
        const CURRENT_VIEWER_ID = <?php echo (int)($viewerId ?? 0); ?>;
        async function refreshUnreadBadge(){
            if (!CURRENT_VIEWER_ID) return;
            try {
                const res = await fetch('api/unread_count.php', { cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.ok) return;
                const cnt = Number(data.unreadCount || 0);
                const link = document.querySelector('.sidebar [data-name="inbox"]');
                if (!link) return;
                let badge = document.getElementById('inbox-count-badge');
                if (cnt > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.id = 'inbox-count-badge';
                        badge.className = 'px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full font-semibold';
                        link.appendChild(badge);
                    }
                    badge.textContent = cnt > 99 ? '99+' : String(cnt);
                } else if (badge) {
                    badge.remove();
                }
            } catch (_) { /* ignore */ }
        }
        refreshUnreadBadge();
        setInterval(refreshUnreadBadge, 5000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshUnreadBadge(); });
    </script>

    <!-- Portfolio Details Modal -->
    <div id="portfolio-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white rounded-2xl shadow-2xl border border-amber-200 max-w-3xl w-full p-6 relative">
            <button onclick="closePortfolioModal()" class="absolute top-3 right-3 p-2 rounded-full hover:bg-amber-50">
                <i data-lucide="x" class="w-5 h-5 text-gray-600"></i>
            </button>
            <h3 id="portfolio-modal-title" class="text-gray-900 mb-2"></h3>
            <p id="portfolio-modal-desc" class="text-gray-700 mb-4"></p>
            <div id="portfolio-modal-images" class="grid grid-cols-1 gap-3"></div>
        </div>
    </div>

    <!-- Book Service Modal (Client Only) -->
    <?php if ($viewerIsClient && !$isOwner): ?>
    <div id="booking-modal" class="hidden fixed inset-0 z-[55] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white rounded-2xl shadow-2xl border border-emerald-200 max-w-xl w-full p-6 relative">
            <button onclick="closeBookingModal()" class="absolute top-3 right-3 p-2 rounded-full hover:bg-emerald-50">
                <i data-lucide="x" class="w-5 h-5 text-gray-600"></i>
            </button>
            <h3 id="booking-modal-title" class="text-gray-900 mb-2">Book Service</h3>
            <form method="post" class="space-y-4" onsubmit="return validateBookingForm()">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="book_service" value="1" />
                <input type="hidden" name="service_id" id="booking-service-id" value="" />
                <input type="hidden" id="booking-unit-price" value="0" />
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Service</label>
                    <input id="booking-service-title" type="text" class="w-full rounded-lg border-emerald-200 bg-emerald-50/30" disabled />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input name="quantity" id="booking-qty" type="number" min="1" value="1" class="w-full rounded-lg border-emerald-200" oninput="updateBookingTotal()" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Total</label>
                        <input id="booking-total" type="text" class="w-full rounded-lg border-emerald-200 bg-gray-50" value="₱0.00" disabled />
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Preferred Start</label>
                        <input name="scheduled_start" id="booking-start" type="datetime-local" class="w-full rounded-lg border-emerald-200" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Preferred End</label>
                        <input name="scheduled_end" id="booking-end" type="datetime-local" class="w-full rounded-lg border-emerald-200" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Details / Requirements</label>
                    <textarea name="client_notes" id="booking-notes" rows="4" class="w-full rounded-lg border-emerald-200" placeholder="Provide details to help the freelancer start quickly"></textarea>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" class="px-4 py-2 rounded-md border" onclick="closeBookingModal()">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md">Confirm & Send</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Portfolio Modal (Owner Only) -->
    <?php if ($isOwner && $isFreelancer): ?>
    <div id="edit-portfolio-modal" class="hidden fixed inset-0 z-[65] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white rounded-2xl shadow-2xl border border-amber-200 max-w-lg w-full p-6 relative">
            <button type="button" onclick="closeEditPortfolio()" class="absolute top-3 right-3 p-2 rounded-full hover:bg-amber-50">
                <i data-lucide="x" class="w-5 h-5 text-gray-600"></i>
            </button>
            <h3 class="text-gray-900 mb-4">Edit Portfolio Item</h3>
            <form method="post" class="space-y-4" onsubmit="return validateEditPortfolio()">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="portfolio_action" value="edit" />
                <input type="hidden" name="item_id" id="edit-item-id" value="" />
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                    <input name="title" id="edit-title" type="text" maxlength="255" class="w-full rounded-lg border-amber-200" required />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" id="edit-desc" rows="4" class="w-full rounded-lg border-amber-200"></textarea>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" class="px-4 py-2 rounded-md border" onclick="closeEditPortfolio()">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Image Lightbox -->
    <div id="image-lightbox" class="hidden fixed inset-0 z-[60] bg-black/80">
        <button onclick="closeImageLightbox()" class="absolute top-4 right-4 p-2 rounded-full bg-white/10 hover:bg-white/20">
            <i data-lucide="x" class="w-5 h-5 text-white"></i>
        </button>
        <div class="w-full h-full flex items-center justify-center p-4">
            <div class="relative max-w-[95vw] max-h-[95vh] w-full h-full flex items-center justify-center overflow-hidden">
                <img id="lightbox-img" src="" alt="Image" class="max-w-full max-h-full object-contain select-none" style="transform: translate(0px, 0px) scale(1); transition: transform 0.1s ease-out;" />
                <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-2 bg-white/10 backdrop-blur rounded-full p-2">
                    <button id="lb-zoom-out" class="p-2 rounded-full bg-white/20 hover:bg-white/30" title="Zoom out" onclick="lightboxZoomOut()"><i data-lucide="minus" class="w-4 h-4 text-white"></i></button>
                    <button id="lb-zoom-reset" class="p-2 rounded-full bg-white/20 hover:bg-white/30" title="Reset" onclick="lightboxReset()"><i data-lucide="refresh-ccw" class="w-4 h-4 text-white"></i></button>
                    <button id="lb-zoom-in" class="p-2 rounded-full bg-white/20 hover:bg-white/30" title="Zoom in" onclick="lightboxZoomIn()"><i data-lucide="plus" class="w-4 h-4 text-white"></i></button>
                </div>
            </div>
        </div>
    </div>

    <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
        // Simple lightbox zoom/pan
        let lbScale = 1;
        // Portfolio edit helpers
        function openEditPortfolio(event, btn){
            try {
                const id = btn.getAttribute('data-item-id');
                const title = btn.getAttribute('data-title') || '';
                const desc = btn.getAttribute('data-desc') || '';
                document.getElementById('edit-item-id').value = id;
                document.getElementById('edit-title').value = title;
                document.getElementById('edit-desc').value = desc;
                const modal = document.getElementById('edit-portfolio-modal');
                modal.classList.remove('hidden');
                setTimeout(()=> lucide.createIcons(), 0);
            } catch (_) {}
        }
        function closeEditPortfolio(){
            const modal = document.getElementById('edit-portfolio-modal');
            if (modal) modal.classList.add('hidden');
        }
        function validateEditPortfolio(){
            const title = document.getElementById('edit-title').value.trim();
            if (!title) { alert('Title is required'); return false; }
            return true;
        }
        function toggleArchived(){
            const el = document.getElementById('archived-list');
            if (el) el.classList.toggle('hidden');
            setTimeout(()=> lucide.createIcons(), 0);
        }
        // If tab=portfolio in query, switch to Portfolio tab on load
        (function(){
            try {
                const qs = new URLSearchParams(window.location.search);
                if (qs.get('tab') === 'portfolio') {
                    const btn = Array.from(document.querySelectorAll('.tab-button')).find(b=>b.textContent.trim().startsWith('Portfolio'));
                    if (btn) btn.click();
                }
            } catch(_) {}
        })();
        let lbTx = 0, lbTy = 0;
        let lbDragging = false, lbStartX = 0, lbStartY = 0, lbOrigTx = 0, lbOrigTy = 0;

        function openImageLightbox(src) {
            const lb = document.getElementById('image-lightbox');
            const img = document.getElementById('lightbox-img');
            img.src = src;
            lbScale = 1; lbTx = 0; lbTy = 0; applyLightboxTransform();
            lb.classList.remove('hidden');
            // Re-initialize icons for newly visible controls
            setTimeout(() => lucide.createIcons(), 0);
        }

        function closeImageLightbox() {
            const lb = document.getElementById('image-lightbox');
            lb.classList.add('hidden');
        }

        function lightboxZoomIn(step = 0.2) { lbScale = Math.min(lbScale + step, 5); applyLightboxTransform(); }
        function lightboxZoomOut(step = 0.2) { lbScale = Math.max(lbScale - step, 0.2); applyLightboxTransform(); }
        function lightboxReset() { lbScale = 1; lbTx = 0; lbTy = 0; applyLightboxTransform(); }

        function applyLightboxTransform() {
            const img = document.getElementById('lightbox-img');
            img.style.transform = `translate(${lbTx}px, ${lbTy}px) scale(${lbScale})`;
            img.style.cursor = lbScale > 1 ? 'grab' : 'default';
        }

        (function attachLightboxDrag() {
            const img = document.getElementById('lightbox-img');
            const start = (clientX, clientY) => {
                if (lbScale <= 1) return; // no drag when not zoomed
                lbDragging = true; lbStartX = clientX; lbStartY = clientY; lbOrigTx = lbTx; lbOrigTy = lbTy;
                img.style.cursor = 'grabbing';
            };
            const move = (clientX, clientY) => {
                if (!lbDragging) return;
                const dx = clientX - lbStartX; const dy = clientY - lbStartY;
                lbTx = lbOrigTx + dx; lbTy = lbOrigTy + dy; applyLightboxTransform();
            };
            const end = () => { lbDragging = false; img.style.cursor = lbScale > 1 ? 'grab' : 'default'; };

            img.addEventListener('mousedown', e => { e.preventDefault(); start(e.clientX, e.clientY); });
            window.addEventListener('mousemove', e => move(e.clientX, e.clientY));
            window.addEventListener('mouseup', end);

            img.addEventListener('touchstart', e => { if (e.touches.length===1){ const t=e.touches[0]; start(t.clientX, t.clientY);} }, {passive:false});
            img.addEventListener('touchmove', e => { if (lbDragging && e.touches.length===1){ const t=e.touches[0]; move(t.clientX, t.clientY);} }, {passive:false});
            img.addEventListener('touchend', end);

            // Wheel to zoom
            img.addEventListener('wheel', e => {
                e.preventDefault();
                const delta = Math.sign(e.deltaY);
                if (delta > 0) lightboxZoomOut(0.1); else lightboxZoomIn(0.1);
            }, {passive:false});
        })();
    </script>

</body>
</html>
