<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Client Registration - TaskHive</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="loginregister.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<section class="register-section">
  <div class="register-card" style="max-width:650px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="mb-0">🐝 Client Registration</h2>
      <a href="mainpage.php#feed" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
    </div>
    <p class="text-muted text-center">Hire trusted local freelancers.</p>
    <form method="POST" action="process_client_register.php" enctype="multipart/form-data" novalidate>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">First Name</label>
          <input type="text" name="first_name" class="form-control" required>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Last Name</label>
          <input type="text" name="last_name" class="form-control" required>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Phone (optional)</label>
        <input type="tel" pattern="[0-9+\-\s]{7,20}" name="phone" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Password (min 8)</label>
        <input type="password" name="password" minlength="8" class="form-control" required>
      </div>
      <div class="mb-4">
        <label class="form-label">Profile Picture (optional)</label>
        <input type="file" name="profile_picture" class="form-control" accept="image/*">
      </div>
      <div class="d-grid">
        <button class="btn btn-hive">Register as Client</button>
      </div>
      <p class="mt-3">Already have an account? <a href="login.php">Login</a></p>
    </form>
  </div>
</section>
<?= flash_render(); ?>
</body>
</html>