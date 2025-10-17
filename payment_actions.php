<?php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/flash.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId   = (int)$_SESSION['user_id'];
$userType = $_SESSION['user_type'] ?? 'client';

$db  = new database();
$pdo = $db->opencon();

$task = $_POST['task'] ?? '';

if ($task === 'make_payment') {
    $conversation_id   = (int)($_POST['conversation_id'] ?? 0);
    $booking_id        = (int)($_POST['booking_id'] ?? 0);
    $phase             = $_POST['phase'] ?? '';
    $paymentMethod     = $_POST['pay_method'] ?? '';
    $receiverMethodId  = isset($_POST['receiver_method_id']) && $_POST['receiver_method_id'] !== '' ? (int)$_POST['receiver_method_id'] : null;
    $reference_code    = trim($_POST['reference_code'] ?? '');
    $returnUrl         = trim($_POST['return'] ?? '');

    // Normalize amount (strip currency symbols and non-numeric except dot)
    $rawAmount = (string)($_POST['amount'] ?? '0');
    $sanitized = preg_replace('/[^0-9.]/', '', $rawAmount);
    $amount    = (float)$sanitized;

    // Validate booking and role
    $booking = $db->fetchBookingWithContext($booking_id);
    if (!$booking) {
        flash_set('error','Booking not found.');
        header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
        exit;
    }
    if ((int)$booking['client_id'] !== $userId) {
        flash_set('error','Only the booking client can pay.');
        header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
        exit;
    }
    if (!in_array($paymentMethod, ['gcash','paymaya'], true)) {
        flash_set('error','Choose a valid method (GCash/PayMaya).');
        header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
        exit;
    }

    // Optional payer details structure
    $payerDetails = [
        'channel' => $paymentMethod,
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];

    // Server-side phase inference and validation
    $terms   = strtolower((string)($booking['payment_method'] ?? ''));
    $status  = strtolower((string)($booking['status'] ?? ''));
    $downPct = isset($booking['downpayment_percent']) ? (float)$booking['downpayment_percent'] : 50.0;
    $total   = (float)$booking['total_amount'];
    $paidUp  = (float)($booking['paid_upfront_amount'] ?? 0);
    $totalPd = (float)($booking['total_paid_amount'] ?? 0);
    $remaining = round(max(0.0, $total - $totalPd), 2);

    if ($phase === '') {
        // First, infer based on already-set terms, if any
        if ($terms === 'advance') {
            $phase = 'full_advance';
        } elseif ($terms === 'downpayment') {
            $needDp = round($total * ($downPct/100), 2);
            $phase = ($paidUp + 0.001 < $needDp) ? 'downpayment' : 'balance';
        } elseif ($terms === 'postpaid') {
            $phase = 'postpaid_full';
        } else {
            // No terms chosen yet: infer from amount and status
            if ($amount >= $remaining - 0.01) {
                // Paying everything now. If already delivered, treat as postpaid; else advance.
                $phase = ($status === 'delivered') ? 'postpaid_full' : 'full_advance';
            } else {
                // Partial upfront -> downpayment
                $phase = 'downpayment';
            }
        }
    }

    // Enforce sane amount (allow partials; block only if <=0 or exceeds remaining)
    $remaining = round(max(0.0, $total - $totalPd), 2);
    if ($amount <= 0) {
        flash_set('error','Amount must be greater than zero.');
        header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
        exit;
    }
    if ($remaining <= 0) {
        flash_set('error','This booking is already fully paid.');
        header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
        exit;
    }
    if ($amount - $remaining > 0.01) {
        flash_set('error','Amount exceeds remaining balance.');
        header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
        exit;
    }

    // If payment terms have not been accepted yet, accept them implicitly based on phase
    if (strtolower((string)($booking['payment_terms_status'] ?? '')) !== 'accepted') {
        $impliedTerms = null;
        if (in_array($phase, ['full_advance'], true)) $impliedTerms = 'advance';
        elseif (in_array($phase, ['downpayment','balance'], true)) $impliedTerms = 'downpayment';
        elseif (in_array($phase, ['postpaid_full'], true)) $impliedTerms = 'postpaid';
        if ($impliedTerms) {
            try {
                $pdo->prepare("UPDATE bookings SET payment_method=:m, payment_terms_status='accepted' WHERE booking_id=:b LIMIT 1")
                    ->execute([':m'=>$impliedTerms, ':b'=>$booking_id]);
                // Refresh booking snapshot for recordPayment expectations
                $booking = $db->fetchBookingWithContext($booking_id);
                $terms = strtolower((string)($booking['payment_method'] ?? ''));
                $downPct = isset($booking['downpayment_percent']) ? (float)$booking['downpayment_percent'] : 50.0;
                $total   = (float)$booking['total_amount'];
                $paidUp  = (float)($booking['paid_upfront_amount'] ?? 0);
                $totalPd = (float)($booking['total_paid_amount'] ?? 0);
                $remaining = round(max(0.0, $total - $totalPd), 2);
            } catch (Throwable $e) {
                flash_set('error','Could not accept payment terms: '.$e->getMessage());
                header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
                exit;
            }
        }
    }

    // If no receiver method explicitly chosen, default to the first active method of the freelancer
    if ($receiverMethodId === null) {
        try {
            $methods = $db->listFreelancerPaymentMethods((int)$booking['freelancer_id'], true);
            if (is_array($methods) && count($methods) > 0) {
                $receiverMethodId = (int)$methods[0]['method_id'];
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    $res = $db->recordPayment(
        $booking_id,
        $userId,
        $amount,
        $phase,
        $paymentMethod,
        $payerDetails,
        $reference_code ?: null,
        true,
        $receiverMethodId
    );

    if ($res === true) {
        flash_set('success','Payment recorded.');
    } else {
        // Log the error for diagnostics
        error_log('[payment_actions] Payment failed: ' . (is_string($res) ? $res : json_encode($res)));
        flash_set('error', is_string($res) ? $res : 'Payment failed.');
    }
    header('Location: '.($returnUrl ?: 'conversation.php?id='.$conversation_id));
    exit;
}

// Fallback
header('Location: inbox.php');
exit;