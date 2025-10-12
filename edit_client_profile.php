<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    flash_set('error','Unauthorized.');
    header("Location: login.php"); exit;
}

$db = new database();
$user_id = (int)$_SESSION['user_id'];

$phone = trim($_POST['phone'] ?? '');
$bio   = trim($_POST['bio'] ?? '');
$updates = [
    'phone' => $phone,
    'bio'   => $bio
];

if (!empty($_FILES['profile_picture']['name'])) {
    if ($_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png','gif','webp'])) {
            flash_set('error','Unsupported image type.');
            header("Location: client_profile.php"); exit;
        }
        if (!is_dir('uploads')) mkdir('uploads',0775,true);
        $filename = 'uploads/'.time().'_'.bin2hex(random_bytes(5)).'.'.$ext;
        move_uploaded_file($_FILES['profile_picture']['tmp_name'],$filename);
        $updates['profile_picture'] = $filename;
    } else {
        flash_set('error','Profile picture upload failed.');
        header("Location: client_profile.php"); exit;
    }
}

if ($db->updateUserProfile($user_id,$updates)) {
    flash_set('success','Profile updated.');
} else {
    flash_set('error','No changes saved.');
}
header("Location: client_profile.php");