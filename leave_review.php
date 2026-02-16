<?php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/csrf.php';

// Helper: compute a safe redirect target within this site
function taskhive_safe_redirect_target(?string $target): string {
  $target = trim((string)$target);
  if ($target === '') return '';
  // If absolute URL, allow only same-host then reduce to path+query
  if (preg_match('/^https?:\/\//i', $target)) {
    $parts = @parse_url($target);
    if (!$parts || empty($parts['host'])) return '';
    $currHost = $_SERVER['HTTP_HOST'] ?? '';
    if (strcasecmp((string)$parts['host'], (string)$currHost) !== 0) return '';
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
    $target = $path . $query;
  }
  // Disallow protocol-relative URLs
  if (preg_match('/^\/{2}/', $target)) return '';
  // Avoid redirecting back to this page
  if (stripos($target, 'leave_review.php') !== false) return '';
  return $target;
}

$db = new database();
// Compute default redirect for this request (GET param or HTTP referrer)
$redirectCandidate = isset($_GET['redirect']) ? (string)$_GET['redirect'] : '';
if ($redirectCandidate === '' && !empty($_SERVER['HTTP_REFERER'])) {
  $redirectCandidate = (string)$_SERVER['HTTP_REFERER'];
}
$redirectDefault = taskhive_safe_redirect_target($redirectCandidate);
// Modal mode flag (when embedding this page in an iframe or popup)
$isModal = isset($_GET['modal']) || isset($_POST['modal']);
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
  if (!csrf_validate()) { $error = 'Security check failed.'; }
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
      if ($isModal) {
        // In modal/iframe/popup: notify opener/parent and optionally close
        ?><!DOCTYPE html>
        <html><head><meta charset="utf-8"><title>Review Submitted</title></head>
        <body style="font-family:system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; padding:20px;">
        <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
          try {
            if (window.opener) { window.opener.postMessage({ type: 'review_submitted', ok: true }, '*'); }
            if (window.parent && window.parent !== window) { window.parent.postMessage({ type: 'review_submitted', ok: true }, '*'); }
          } catch (e) {}
          try { window.close(); } catch (e) {}
        </script>
        <p>Thank you for your review. You can close this window.</p>
        </body></html><?php
      } else {
        // Decide where to go back: prefer explicit redirect (POST/GET), then referrer, else fallback
        $redirParam = $_POST['redirect'] ?? $_GET['redirect'] ?? '';
        if ($redirParam === '' && !empty($_SERVER['HTTP_REFERER'])) {
          $redirParam = (string)$_SERVER['HTTP_REFERER'];
        }
        $go = taskhive_safe_redirect_target($redirParam);
        if ($go === '') { $go = 'client_profile.php?review=thanks'; }
        header('Location: ' . $go);
      }
            exit;
        } else {
            $error = 'Unable to submit review. You can only review completed or delivered bookings once.';
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
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
    <form method="post" id="review-form">
      <?= csrf_input(); ?>
      <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectDefault, ENT_QUOTES); ?>">
      <?php if ($isModal): ?><input type="hidden" name="modal" value="1"><?php endif; ?>
      <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
      <div id="review-stars" class="flex items-center gap-2 mb-4" role="radiogroup" aria-label="Rating">
        <!-- Built with JS below; 5 clickable stars -->
      </div>
      <input type="hidden" name="rating" id="rating" value="0" />

      <label class="block text-sm font-medium text-gray-700 mb-2">Comment (optional)</label>
      <textarea name="comment" rows="5" class="w-full border border-amber-200 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-amber-500/30" placeholder="Share your experience..."></textarea>

      <div class="mt-6 flex items-center justify-end gap-3">
        <?php if ($isModal): ?>
          <a href="#" onclick="try{ if (window.opener) window.opener.postMessage({type:'review_cancel'},'*'); if (window.parent && window.parent!==window) window.parent.postMessage({type:'review_cancel'},'*'); window.close(); }catch(e){} return false;" class="px-4 py-2 rounded border border-gray-300 text-gray-700 hover:bg-gray-100">Cancel</a>
        <?php else: ?>
          <a href="<?php echo htmlspecialchars($redirectDefault !== '' ? $redirectDefault : 'client_profile.php', ENT_QUOTES); ?>" class="px-4 py-2 rounded border border-gray-300 text-gray-700 hover:bg-gray-100">Cancel</a>
        <?php endif; ?>
        <button type="submit" class="px-4 py-2 rounded bg-amber-600 text-white hover:bg-amber-700">Submit Review</button>
      </div>
    </form>
  </div>
  <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
    (function(){
      const wrap = document.getElementById('review-stars');
      const hidden = document.getElementById('rating');
      if (!wrap || !hidden) return;
      function buildStars(){
        for (let i=1;i<=5;i++){
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'p-1';
          btn.dataset.value = String(i);
          btn.innerHTML = '<svg class="w-8 h-8 text-gray-300" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27z"/></svg>';
          btn.addEventListener('click', ()=> setRating(i));
          wrap.appendChild(btn);
        }
        setRating(0);
      }
      function setRating(v){
        hidden.value = String(v);
        const icons = wrap.querySelectorAll('svg');
        icons.forEach((svg, idx)=>{
          const n = idx+1;
          svg.classList.toggle('text-amber-500', n <= v);
          svg.classList.toggle('text-gray-300', n > v);
        });
      }
      buildStars();
      const form = document.getElementById('review-form');
      if (form) {
        form.addEventListener('submit', (e)=>{
          const v = parseInt(hidden.value||'0');
          if (isNaN(v) || v < 1 || v > 5) {
            e.preventDefault();
            alert('Please select a rating from 1 to 5.');
          }
        });
      }
    })();
  </script>
</body>
</html>
