<?php
require 'database.php';

if (!defined('DEBUG_DB_PAY')) {
    define('DEBUG_DB_PAY', true);  // enable verbose logs just for this test
}

/*
Usage examples:
  http://localhost/taskhive/TaskHive/test_record_payment.php?booking_id=15&phase=full_advance&method=gcash
  http://localhost/taskhive/TaskHive/test_record_payment.php?booking_id=15&phase=downpayment&method=gcash
Params:
  booking_id (int) REQUIRED
  phase = full_advance | downpayment | balance | postpaid_full
  method = gcash | paymaya | card | online_banking | other
*/

function h($v){ return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$phase     = $_GET['phase']  ?? 'full_advance';
$method    = $_GET['method'] ?? 'gcash';
$ref       = 'TESTREF'.rand(1000,9999);

if ($bookingId <= 0) {
    echo "<p style='color:red;font-family:Arial;'>Provide ?booking_id=## in the URL.</p>";
    exit;
}

$db = new database();
$booking = $db->fetchBookingWithContext($bookingId);

if (!$booking) {
    echo "<p style='color:red;'>Booking $bookingId not found.</p>";
    exit;
}

echo "<h2 style='font-family:Arial;'>Booking Snapshot</h2><pre style='background:#222;color:#eee;padding:8px;border-radius:6px;'>";
print_r([
  'booking_id'            => $booking['booking_id'],
  'client_id'             => $booking['client_id'],
  'freelancer_id'         => $booking['freelancer_id'],
  'status'                => $booking['status'],
  'payment_method_terms'  => $booking['payment_method'],
  'payment_terms_status'  => $booking['payment_terms_status'],
  'downpayment_percent'   => $booking['downpayment_percent'],
  'total_amount'          => $booking['total_amount'],
  'paid_upfront_amount'   => $booking['paid_upfront_amount'],
  'total_paid_amount'     => $booking['total_paid_amount'],
  'balance_due'           => $booking['balance_due'],
]);
echo "</pre>";

if ($booking['payment_terms_status'] !== 'accepted') {
    echo "<p style='color:orange;'>Payment terms are not accepted (current: ".h($booking['payment_terms_status'])."). Accept them in the UI first.</p>";
    exit;
}

// Compute expected amount
switch($phase){
    case 'full_advance':
    case 'postpaid_full':
        $expected = (float)$booking['total_amount'];
        break;
    case 'downpayment':
        $expected = round((float)$booking['total_amount'] * ((float)$booking['downpayment_percent']/100),2);
        break;
    case 'balance':
        $expected = round((float)$booking['total_amount'] - (float)$booking['paid_upfront_amount'],2);
        break;
    default:
        echo "<p style='color:red;'>Invalid phase value.</p>";
        exit;
}

echo "<p style='font-family:Arial;'>Phase: <strong>".h($phase)."</strong><br>
Method: <strong>".h($method)."</strong><br>
Expected Amount: <strong>₱".number_format($expected,2)."</strong></p>";

$payerDetails = [
  'diag_test' => true,
  'channel'   => $method,
  'script'    => 'test_record_payment.php'
];

echo "<p style='font-family:Arial;'>Attempting recordPayment() ...</p>";

$res = $db->recordPayment(
    $bookingId,
    (int)$booking['client_id'],  // auto-picked correct client
    $expected,
    $phase,
    $method,
    $payerDetails,
    $ref,
    true
);

if ($res === true) {
    echo "<h3 style='color:green;'>SUCCESS: Payment recorded</h3>";
} else {
    echo "<h3 style='color:red;'>FAILED</h3><p>".h($res)."</p>";
}

echo "<hr><p style='font-size:12px;color:#555;font-family:Arial;'>Check Apache/PHP error log for [recordPayment] debug lines (since DEBUG_DB_PAY is true).</p>";