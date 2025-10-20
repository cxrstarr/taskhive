<?php
session_start();
require_once __DIR__.'/database.php';
require_once __DIR__.'/flash.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
if (empty($_SESSION['user_id'])) { flash_set('error','Login required.'); header('Location: login.php'); exit; }

$task            = $_POST['task'] ?? '';
$booking_id      = (int)($_POST['booking_id'] ?? 0);
$conversation_id = (int)($_POST['conversation_id'] ?? 0);
$reason_code     = trim($_POST['reason_code'] ?? '');
$description     = trim($_POST['description'] ?? '');
$user_id         = (int)$_SESSION['user_id'];

if ($task !== 'open' || $booking_id <= 0) {
  flash_set('error','Invalid dispute action.');
  header('Location: '.($conversation_id ? 'conversation.php?id='.$conversation_id : 'inbox.php')); exit;
}

$db  = new database();
$pdo = $db->opencon();

try {
  // Fetch booking and ensure participant
  $b = $db->fetchBookingWithContext($booking_id);
  if (!$b) throw new Exception('Booking not found.');
  if ($b['client_id'] != $user_id && $b['freelancer_id'] != $user_id) throw new Exception('Not your booking.');

  $raised_by_id = $user_id;
  $against_id   = ($user_id == (int)$b['client_id']) ? (int)$b['freelancer_id'] : (int)$b['client_id'];

  // Prevent duplicate open dispute on same booking
  $chk = $pdo->prepare("SELECT dispute_id FROM disputes WHERE booking_id=? AND status IN ('open','under_review') LIMIT 1");
  $chk->execute([$booking_id]);
  if ($chk->fetch()) throw new Exception('A dispute is already open for this booking.');

  $pdo->beginTransaction();

  // Insert dispute
  $ins = $pdo->prepare("INSERT INTO disputes
    (booking_id, raised_by_id, against_id, reason_code, description, status, created_at)
    VALUES (:b,:r,:a,:rc,:d,'open',NOW())");
  $ins->execute([
    ':b'=>$booking_id, ':r'=>$raised_by_id, ':a'=>$against_id,
    ':rc'=>$reason_code ?: null, ':d'=>$description ?: null
  ]);
  $dispute_id = (int)$pdo->lastInsertId();

  // Insert event
  $pdo->prepare("INSERT INTO dispute_events (dispute_id,actor_id,action,notes,created_at)
                 VALUES (:d,:u,'opened',:n,NOW())")
      ->execute([':d'=>$dispute_id, ':u'=>$user_id, ':n'=>($reason_code?$reason_code.' - ':'').$description]);

  // Mark booking as disputed (optional but helpful for admin visibility)
  $pdo->prepare("UPDATE bookings SET status='disputed', updated_at=NOW() WHERE booking_id=?")->execute([$booking_id]);

  // System message in conversation
  $db->systemBookingMessage($booking_id, $user_id, "A dispute has been opened (reason: ".($reason_code?:'n/a').").");

  $pdo->commit();
  flash_set('success','Dispute opened.');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_set('error',$e->getMessage());
}

header('Location: '.($conversation_id ? 'conversation.php?id='.$conversation_id : 'inbox.php'));