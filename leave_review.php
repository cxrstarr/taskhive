<?php
session_start();
require_once __DIR__ . '/database.php';

$db = new database();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$uid = (int)$_SESSION['user_id'];
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if ($booking_id <= 0) { header('Location: client_profile.php'); exit; }

$booking = $db->getBookingForClient($booking_id, $uid);
if (!$booking) { header('Location: client_profile.php'); exit; }

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating from 1 to 5.';
    } else {
        $ok = $db->leaveReviewAsClient($booking_id, $uid, $rating, $comment);
        if ($ok) {
            // Optional: system message to acknowledge
            try {
                $db->systemBookingMessage($booking_id, $uid, 'Client left a review. Thank you!');
            } catch (Throwable $e) {}
            header('Location: client_profile.php?review=thanks');
            exit;
        } else {
            $error = 'Unable to submit review. You can only review completed or delivered bookings once.';
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Leave a Review</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-amber-50">
  <div class="max-w-xl mx-auto mt-10 bg-white border border-amber-200 rounded-2xl shadow p-6">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Leave a Review</h1>
    <p class="text-sm text-gray-600 mb-6">Booking #<?= (int)$booking_id ?> • <?= htmlspecialchars($booking['service_title'] ?? 'Service') ?></p>
    <?php if (!empty($error)): ?>
      <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-700 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
      <div class="flex items-center gap-2 mb-4">
        <?php for ($i=1;$i<=5;$i++): ?>
          <label class="inline-flex items-center gap-1 cursor-pointer">
            <input type="radio" name="rating" value="<?= $i ?>" class="sr-only" />
            <span class="w-8 h-8 inline-flex items-center justify-center rounded border border-amber-200 text-amber-600 hover:bg-amber-50"><?= $i ?></span>
          </label>
        <?php endfor; ?>
      </div>

      <label class="block text-sm font-medium text-gray-700 mb-2">Comment (optional)</label>
      <textarea name="comment" rows="5" class="w-full border border-amber-200 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-amber-500/30" placeholder="Share your experience..."></textarea>

      <div class="mt-6 flex items-center justify-end gap-3">
        <a href="client_profile.php" class="px-4 py-2 rounded border border-gray-300 text-gray-700 hover:bg-gray-100">Cancel</a>
        <button type="submit" class="px-4 py-2 rounded bg-amber-600 text-white hover:bg-amber-700">Submit Review</button>
      </div>
    </form>
  </div>
</body>
</html>
