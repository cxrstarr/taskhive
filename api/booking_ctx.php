<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../database.php';

try {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $conversationId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
    if ($conversationId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'invalid_conversation']);
        exit;
    }

    $db = new database();
    $pdo = $db->opencon();

    // Validate access and get participants
    $st = $pdo->prepare("SELECT client_id, freelancer_id FROM conversations WHERE conversation_id=:c AND (client_id=:u OR freelancer_id=:u) LIMIT 1");
    $st->execute([':c' => $conversationId, ':u' => $uid]);
    $row = $st->fetch();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    $clientId = (int)$row['client_id'];
    $freelancerId = (int)$row['freelancer_id'];

    // Determine statuses of interest based on perspective
    // Client only needs accepted/in_progress/delivered for payment; freelancer also sees pending
    $uRow = $db->getUser($uid);
    $userType = strtolower($uRow['user_type'] ?? '');
    $statuses = ($userType === 'freelancer')
        ? ['pending','accepted','in_progress','delivered']
        : ['accepted','in_progress','delivered'];

    $bk = $db->getLatestBookingBetweenUsers($clientId, $freelancerId, $statuses);
    $bookingCtx = null;
    $methods = [];
    if ($bk) {
        $bookingCtx = [
            'booking_id' => (int)$bk['booking_id'],
            'service_title' => $bk['service_title'] ?? ($bk['title_snapshot'] ?? 'Service'),
            'total_amount' => (float)($bk['total_amount'] ?? 0),
            'created_at' => $bk['created_at'] ?? null,
            'status' => strtolower($bk['status'] ?? ''),
            'payment_method' => $bk['payment_method'] ?? null,
            'payment_terms_status' => $bk['payment_terms_status'] ?? null,
            'downpayment_percent' => isset($bk['downpayment_percent']) ? (float)$bk['downpayment_percent'] : null,
            'paid_upfront_amount' => isset($bk['paid_upfront_amount']) ? (float)$bk['paid_upfront_amount'] : 0,
            'total_paid_amount' => isset($bk['total_paid_amount']) ? (float)$bk['total_paid_amount'] : 0,
        ];

        // For clients, also return freelancer's receiving methods
        if ($userType === 'client') {
            try { $methods = $db->listFreelancerPaymentMethods($freelancerId, true); } catch (Throwable $e) { $methods = []; }
        }
    }

    echo json_encode(['ok' => true, 'booking_ctx' => $bookingCtx, 'freelancer_methods' => $methods]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
