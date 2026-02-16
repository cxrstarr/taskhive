<?php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/includes/csrf.php';

// Supports both form POST and AJAX fetch
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!csrf_validate()) {
    flash_set('error','Security check failed. Please try again.');
    header('Location: '.(isset($_POST['return']) ? (string)$_POST['return'] : 'inbox.php'));
    exit;
}

$booking_id = (int)($_POST['booking_id'] ?? 0);
$action     = trim((string)($_POST['action'] ?? ''));
$returnTo   = trim((string)($_POST['return'] ?? '')) ?: 'inbox.php';

// Basic validation
if ($booking_id <= 0 || $action === '') {
    flash_set('error','Invalid request.');
    header('Location: '.$returnTo);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['user_type'])) {
    flash_set('error','Please log in to continue.');
    header('Location: login.php');
    exit;
}

$db = new database();
$actor_id   = (int)$_SESSION['user_id'];
$userType   = strtolower((string)$_SESSION['user_type']);
$actor_role = in_array($userType, ['client','freelancer'], true) ? $userType : '';

if ($actor_role === '') {
    flash_set('error','Unauthorized.');
    header('Location: login.php');
    exit;
}

// Delegate to the unified booking action handler which:
// - Validates ownership and status transitions
// - Sends an in-conversation system message per action
// - Adds a booking_status_changed notification to the counterpart
$result = $db->performBookingAction($booking_id, $actor_id, $actor_role, $action);

$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');
$isAjax    = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($result === true) {
    if ($wantsJson || $isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    flash_set('success', 'Action processed: '.$action.'.');
    header('Location: '.$returnTo);
    exit;
}

// Error path
if ($wantsJson || $isAjax) {
    header('Content-Type: application/json', true, 400);
    echo json_encode(['ok' => false, 'error' => (string)$result]);
    exit;
}
flash_set('error', is_string($result) ? $result : 'Failed to process action.');
header('Location: '.$returnTo);
exit;
