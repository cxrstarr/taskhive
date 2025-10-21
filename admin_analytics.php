<?php
session_start();
require_once 'database.php';
if (empty($_SESSION['user_id'])) { header('Location: admin_login.php'); exit; }
$db = new database();
$user = $db->getUser((int)$_SESSION['user_id']);
if (!$user || $user['user_type'] !== 'admin') { echo "Access denied."; exit; }
$monthly = $db->opencon()->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as bookings FROM bookings GROUP BY month ORDER BY month DESC LIMIT 12")->fetchAll();
$revenues = $db->opencon()->query("SELECT DATE_FORMAT(paid_at,'%Y-%m') as month, SUM(amount) as revenue FROM payments WHERE status IN ('escrowed','released') GROUP BY month ORDER BY month DESC LIMIT 12")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Analytics - Admin</title>
  <link rel="stylesheet" href="public/css/admin_theme.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid"><span class="navbar-brand">TaskHive 🐝 Admin</span>
    <a href="admin_dashboard.php" class="btn btn-sm btn-outline-light">Dashboard</a>
  </div>
</nav>
<div class="container">
  <h3>Analytics</h3>
  <div class="row mb-4">
    <div class="col-md-6">
      <canvas id="bookingsChart"></canvas>
    </div>
    <div class="col-md-6">
      <canvas id="revenueChart"></canvas>
    </div>
  </div>
</div>
<script>
const bookingsData = {
  labels: [<?php foreach($monthly as $m) echo "'".$m['month']."',"; ?>],
  datasets: [{
    label: 'Bookings',
    data: [<?php foreach($monthly as $m) echo $m['bookings'].','; ?>],
    backgroundColor: '#ffca28',
    borderColor: '#ffa726',
    fill: true,
    tension: 0.3
  }]
};
const revenuesData = {
  labels: [<?php foreach($revenues as $r) echo "'".$r['month']."',"; ?>],
  datasets: [{
    label: 'Revenue',
    data: [<?php foreach($revenues as $r) echo $r['revenue'].','; ?>],
    backgroundColor: '#81c784',
    borderColor: '#388e3c',
    fill: true,
    tension: 0.3
  }]
};
new Chart(document.getElementById('bookingsChart').getContext('2d'), { type: 'line', data: bookingsData });
new Chart(document.getElementById('revenueChart').getContext('2d'), { type: 'line', data: revenuesData });
</script>
</body>
</html>