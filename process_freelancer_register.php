<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: freelancer.php"); exit;
}
if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: freelancer.php'); exit; }

$first = trim($_POST['first_name']??'');
$last  = trim($_POST['last_name']??'');
$email = trim($_POST['email']??'');
$phone = trim($_POST['phone']??'');
$password = $_POST['password'] ?? '';
$skills = trim($_POST['skills']??'');
$address = trim($_POST['address']??'');
$hourly_rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
$bio = trim($_POST['bio']??'');

if (!$first || !$last || !$email || !$password || !$skills || !$address || !$bio) {
    flash_set('error','Fill all required fields.');
    header("Location: freelancer.php"); exit;
}
if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
    flash_set('error','Invalid email.');
    header("Location: freelancer.php"); exit;
}
if (strlen($password) < 8) {
    flash_set('error','Password too short.');
    header("Location: freelancer.php"); exit;
}

$profile_picture = null;
if (!empty($_FILES['profile_picture']['name'])) {
    if ($_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png','gif','webp'])) {
            flash_set('error','Unsupported image type.');
            header("Location: freelancer.php"); exit;
        }
        if (!is_dir('uploads')) mkdir('uploads',0775,true);
        $profile_picture = 'uploads/'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        move_uploaded_file($_FILES['profile_picture']['tmp_name'], $profile_picture);
    } else {
    flash_set('error','Upload error.');
    header("Location: freelancer.php"); exit;
    }
}

$db = new database();
$user_id = $db->registerFreelancer($first,$last,$email,$password,$skills,$address,$hourly_rate,$phone,$profile_picture);
if (!$user_id) {
    flash_set('error','Email already in use or error encountered.');
    header("Location: freelancer.php"); exit;
}

$db->updateUserProfile($user_id, ['bio'=>$bio]);

$_SESSION['user_id'] = $user_id;
$_SESSION['user_type'] = 'freelancer';
flash_set('success','Welcome to TaskHive!');
header("Location: freelancer_profile.php");

?>