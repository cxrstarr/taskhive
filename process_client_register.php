<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: client.php");
    exit;
}
if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: client.php'); exit; }

$first = trim($_POST['first_name'] ?? '');
$last  = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$password = $_POST['password'] ?? '';

if (!$first || !$last || !$email || !$password) {
    flash_set('error','All required fields must be filled.');
    header("Location: client.php");
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error','Invalid email.');
    header("Location: client.php");
    exit;
}
if (strlen($password) < 8) {
    flash_set('error','Password must be at least 8 characters.');
    header("Location: client.php");
    exit;
}

$profile_picture = null;
if (!empty($_FILES['profile_picture']['name'])) {
    if ($_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            flash_set('error','Unsupported image type.');
            header("Location: client.php");
            exit;
        }
        if (!is_dir('uploads')) mkdir('uploads', 0775, true);
        $profile_picture = 'uploads/'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        move_uploaded_file($_FILES['profile_picture']['tmp_name'], $profile_picture);
    } else {
        flash_set('error','Upload error.');
        header("Location: client.php");
        exit;
    }
}

$db = new database();
$user_id = $db->registerClient($first, $last, $email, $password, $phone, $profile_picture);
if (!$user_id) {
    flash_set('error','Email already registered or error occurred.');
    header("Location: client.php");
    exit;
}

$_SESSION['user_id'] = $user_id;
$_SESSION['user_type'] = 'client';
flash_set('success','Registration successful!');
header("Location: client_profile.php");
exit;

?>