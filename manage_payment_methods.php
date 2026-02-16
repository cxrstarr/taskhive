<?php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/csrf.php';

function ensure_logged_in_freelancer(): array {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    $user = [
        'user_id'   => (int)$_SESSION['user_id'],
        'user_type' => $_SESSION['user_type'] ?? 'client'
    ];
    if ($user['user_type'] !== 'freelancer') {
        http_response_code(403);
        echo "Forbidden: Only freelancers can manage receiving methods.";
        exit;
    }
    return $user;
}

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type'=>$type, 'msg'=>$msg];
}
function flash_out(): void {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $cls = $f['type']==='success' ? 'alert-success' : 'alert-danger';
        echo '<div class="alert '.$cls.' small">'.$f['msg'].'</div>';
    }
}

$user = ensure_logged_in_freelancer();
$db   = new database();

$task = $_POST['task'] ?? $_GET['task'] ?? '';

if ($task === 'add' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: manage_payment_methods.php'); exit; }
    $method_type    = $_POST['method_type'] ?? '';
    $display_label  = trim($_POST['display_label'] ?? '');
    $account_name   = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $bank_name      = null; // not used for gcash/paymaya
    $qr_code_url    = null;

    if (!in_array($method_type, ['gcash','paymaya'], true)) {
        flash_set('error','Choose a valid type (GCash/PayMaya).');
        header('Location: manage_payment_methods.php');
        exit;
    }

    // Handle QR upload if any
    if (!empty($_FILES['qr_code']['name']) && is_uploaded_file($_FILES['qr_code']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png','jpg','jpeg','gif','webp'], true)) {
            flash_set('error','QR code must be an image file.');
            header('Location: manage_payment_methods.php');
            exit;
        }
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $filename = time().'_qr_'.bin2hex(random_bytes(3)).'.'.$ext;
        $dest     = $dir . '/' . $filename;
        if (!@move_uploaded_file($_FILES['qr_code']['tmp_name'], $dest)) {
            flash_set('error','Failed to upload QR code.');
            header('Location: manage_payment_methods.php');
            exit;
        }
        // Public URL path (adjust if your web root differs)
        $qr_code_url = 'uploads/'.$filename;
    }

    $newId = $db->addFreelancerPaymentMethod(
        $user['user_id'],
        $method_type,
        $display_label ?: strtoupper($method_type).' Payment',
        $account_name ?: null,
        $account_number ?: null,
        $bank_name,
        $qr_code_url,
        []
    );

    if ($newId) {
        flash_set('success','Payment method added.');
    } else {
        flash_set('error','Failed to add method.');
    }
    header('Location: manage_payment_methods.php');
    exit;
}

if ($task === 'toggle' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: manage_payment_methods.php'); exit; }
    $method_id = (int)($_POST['method_id'] ?? 0);
    $row = $db->getFreelancerPaymentMethod($method_id, $user['user_id']);
    if (!$row) {
        flash_set('error','Method not found.');
        header('Location: manage_payment_methods.php');
        exit;
    }
    $newActive = (int)!((int)$row['is_active']);
    $ok = $db->updateFreelancerPaymentMethod($method_id, $user['user_id'], ['is_active'=>$newActive]);
    flash_set($ok?'success':'error', $ok?('Method '.($newActive?'activated':'deactivated').'.'):'Update failed.');
    header('Location: manage_payment_methods.php');
    exit;
}

if ($task === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_validate()) { flash_set('error','Security check failed.'); header('Location: manage_payment_methods.php'); exit; }
    $method_id = (int)($_POST['method_id'] ?? 0);
    $row = $db->getFreelancerPaymentMethod($method_id, $user['user_id']);
    if (!$row) {
        flash_set('error','Method not found.');
        header('Location: manage_payment_methods.php');
        exit;
    }
    $ok = $db->deleteFreelancerPaymentMethod($method_id, $user['user_id']);
    flash_set($ok?'success':'error', $ok?'Method deleted.':'Delete failed.');
    header('Location: manage_payment_methods.php');
    exit;
}

$methods = $db->listFreelancerPaymentMethods($user['user_id'], false);
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
 <meta charset="UTF-8">
 <title>Manage Payment Methods</title>
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
 <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Payment Receiving Methods</h3>
    <a href="freelancer_profile.php" class="btn btn-sm btn-secondary">&larr; Back</a>
  </div>

  <?php flash_out(); ?>

  <div class="card mb-4">
    <div class="card-header">Add New Method</div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data" class="row g-3">
        <?= csrf_input(); ?>
        <input type="hidden" name="task" value="add">
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="method_type" class="form-select" required>
            <option value="">Choose...</option>
            <option value="gcash">GCash</option>
            <option value="paymaya">PayMaya</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Display Label</label>
          <input type="text" name="display_label" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Account Name</label>
          <input type="text" name="account_name" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Account / No.</label>
          <input type="text" name="account_number" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">QR Code (optional)</label>
          <input type="file" name="qr_code" class="form-control" accept="image/*">
        </div>
        <div class="col-12">
          <button class="btn btn-primary">Add Method</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Your Methods</div>
    <div class="card-body">
      <?php if (!$methods): ?>
        <p class="text-muted mb-0">No methods yet.</p>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($methods as $m): ?>
            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div class="small text-muted"><?= htmlspecialchars(strtoupper($m['method_type'])) ?></div>
                    <h6 class="mb-1"><?= htmlspecialchars($m['display_label']) ?></h6>
                  </div>
                  <span class="badge <?= $m['is_active']?'bg-success':'bg-secondary' ?>">
                    <?= $m['is_active']?'Active':'Inactive' ?>
                  </span>
                </div>
                <div class="small mt-2">
                  <?php if ($m['account_name']): ?>
                    <div>Account Name: <strong><?= htmlspecialchars($m['account_name']) ?></strong></div>
                  <?php endif; ?>
                  <?php if ($m['account_number']): ?>
                    <div>Account / No.: <code><?= htmlspecialchars($m['account_number']) ?></code></div>
                  <?php endif; ?>
                  <?php if ($m['qr_code_url']): ?>
                    <div class="mt-1">QR: <a target="_blank" href="<?= htmlspecialchars($m['qr_code_url']) ?>">View</a></div>
                  <?php endif; ?>
                </div>
                <div class="d-flex gap-2 mt-3">
                  <form method="POST" onsubmit="return confirm('Toggle active state for this method?');">
                    <?= csrf_input(); ?>
                    <input type="hidden" name="task" value="toggle">
                    <input type="hidden" name="method_id" value="<?= (int)$m['method_id'] ?>">
                    <button class="btn btn-sm <?= $m['is_active']?'btn-outline-secondary':'btn-outline-success' ?>">
                      <?= $m['is_active']?'Deactivate':'Activate' ?>
                    </button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Delete this method?');">
                    <?= csrf_input(); ?>
                    <input type="hidden" name="task" value="delete">
                    <input type="hidden" name="method_id" value="<?= (int)$m['method_id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>