<?php
session_start();
require_once 'database.php';
require_once 'flash.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Freelancer Registration - TaskHive</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="loginregister.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<section class="register-section">
  <div class="register-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="mb-0">🐝 Freelancer Registration</h2>
      <a href="mainpage.php#feed" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
    </div>
    <p class="text-muted text-center">Showcase your skills & start earning.</p>
    <form method="POST" action="process_freelancer_register.php" enctype="multipart/form-data" novalidate>
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
        <input type="tel" name="phone" pattern="[0-9+\-\s]{7,20}" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Password (min 8)</label>
        <input type="password" minlength="8" name="password" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Skills (comma separated)</label>
        <input type="text" name="skills" class="form-control" required placeholder="delivery, tutoring, cleaning">
      </div>
      <div class="mb-3">
        <label class="form-label">Address / Location</label>
        <input type="text" name="address" class="form-control" required placeholder="City / Area">
      </div>
      <div class="mb-3">
        <label class="form-label">Hourly Rate (PHP)</label>
        <input type="number" step="0.01" min="0" name="hourly_rate" class="form-control" placeholder="e.g. 300">
      </div>
      <div class="mb-3">
        <label class="form-label">Profile Picture</label>
        <input type="file" name="profile_picture" class="form-control" accept="image/*">
      </div>
      <div class="mb-3">
        <label class="form-label">Brief Bio</label>
        <textarea name="bio" rows="3" class="form-control" required></textarea>
      </div>
      <div class="d-grid">
        <button class="btn btn-hive">Register as Freelancer</button>
      </div>
      <p class="mt-3">Already registered? <a href="login.php">Login</a></p>
    </form>
  </div>
</section>
<?= flash_render(); ?>
</body>
</html>