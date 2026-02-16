<?php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/csrf.php';

// Guard: require login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new database();
$uid = (int)$_SESSION['user_id'];
$pdo = $db->opencon();

// Fetch current user
$u = $db->getUser($uid);
if (!$u) {
    header('Location: login.php');
    exit;
}

$errors = [];
$success = false;

// Helpers
function clean_text($s) {
    $s = is_string($s) ? trim($s) : '';
    // Normalize whitespace
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

// Handle POST (update profile)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) { $errors[] = 'Security check failed.'; }
    $first_name = clean_text($_POST['first_name'] ?? '');
    $last_name  = clean_text($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $bio        = trim($_POST['bio'] ?? '');

    if ($first_name === '' || strlen($first_name) > 100) {
        $errors[] = 'Please enter a valid first name.';
    }
    if ($last_name === '' || strlen($last_name) > 100) {
        $errors[] = 'Please enter a valid last name.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($bio) > 2000) {
        $errors[] = 'Bio is too long (max 2000 characters).';
    }

    // Ensure email is unique for other users (if email column exists/unique)
    if (!$errors) {
        try {
            $st = $pdo->prepare('SELECT user_id FROM users WHERE email = :email AND user_id <> :id LIMIT 1');
            $st->execute([':email' => $email, ':id' => $uid]);
            if ($st->fetch()) {
                $errors[] = 'This email is already in use by another account.';
            }
        } catch (Throwable $e) {
            // Ignore if schema differs; do not block update
        }
    }

    // Handle optional avatar upload
    $profile_picture = $u['profile_picture'] ?? '';
    if (!$errors && isset($_FILES['avatar']) && is_array($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $fileErr = (int)$_FILES['avatar']['error'];
        if ($fileErr !== UPLOAD_ERR_OK) {
            $errors[] = 'Failed to upload profile picture.';
        } else {
            $tmp  = $_FILES['avatar']['tmp_name'];
            $name = $_FILES['avatar']['name'] ?? 'upload';
            $size = (int)($_FILES['avatar']['size'] ?? 0);
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? @finfo_file($finfo, $tmp) : null;
            if ($finfo) { @finfo_close($finfo); }

            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!$mime || !isset($allowed[$mime])) {
                $errors[] = 'Invalid image type. Please upload a JPG, PNG, or WEBP file.';
            } elseif ($size > 2 * 1024 * 1024) { // 2MB
                $errors[] = 'Image is too large. Max size is 2MB.';
            } else {
                $ext = $allowed[$mime];
                $uploadDir = __DIR__ . '/img/uploads/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0775, true);
                }
                $safeBase = 'profile_' . uniqid('', true);
                $filename = $safeBase . '.' . $ext;
                $destAbs  = $uploadDir . $filename;
                $destRel  = 'img/uploads/' . $filename; // store relative path in DB

                if (!@move_uploaded_file($tmp, $destAbs)) {
                    $errors[] = 'Failed to save uploaded image.';
                } else {
                    // Optional: remove old file if it was in our uploads dir
                    if (!empty($profile_picture) && strpos($profile_picture, 'img/uploads/') === 0) {
                        $oldAbs = __DIR__ . '/' . $profile_picture;
                        if (is_file($oldAbs)) { @unlink($oldAbs); }
                    }
                    $profile_picture = $destRel;
                }
            }
        }
    }

    if (!$errors) {
        try {
            $sql = 'UPDATE users SET first_name = :fn, last_name = :ln, email = :em, bio = :bio, profile_picture = :pic WHERE user_id = :id';
            $stU = $pdo->prepare($sql);
            $stU->execute([
                ':fn' => $first_name,
                ':ln' => $last_name,
                ':em' => $email,
                ':bio' => $bio,
                ':pic' => $profile_picture,
                ':id' => $uid,
            ]);

            $success = true;
            // Refresh current values for display
            $u = $db->getUser($uid) ?: $u;
        } catch (Throwable $e) {
            $errors[] = 'Failed to update profile. Please try again later.';
        }
    }
}

// Derived fields for display
$currentUser = [
    'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'User',
    'email' => $u['email'] ?? '',
    'avatar' => ($u['profile_picture'] ?? '') ?: 'img/profile_icon.webp',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Profile - Task Hive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
        .dropdown { display: none; opacity: 0; }
        .dropdown.active { display: block; animation: fadeIn .2s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0 } to { opacity: 1 } }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 via-amber-50/30 to-orange-50/30">
    <!-- Navbar (simplified) -->
    <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-xl border-b border-amber-200/50 shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="index.php" class="flex items-center gap-3 cursor-pointer hover:scale-105 transition-transform">
                    <div class="relative">
                        <svg class="w-8 h-8 fill-amber-400 stroke-amber-600 stroke-2" viewBox="0 0 24 24">
                            <polygon points="12 2, 22 8.5, 22 15.5, 12 22, 2 15.5, 2 8.5" />
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <div class="w-2 h-2 rounded-full bg-amber-600"></div>
                        </div>
                    </div>
                    <h1 class="text-xl font-bold text-amber-900 tracking-tight hidden sm:block">Task Hive</h1>
                </a>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="<?php echo htmlspecialchars($currentUser['name']); ?>" class="w-8 h-8 rounded-full border-2 border-amber-400 object-cover">
                    <span class="hidden md:block text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-3xl mx-auto p-4 sm:p-6 lg:p-8">
        <div class="bg-white rounded-2xl shadow-xl border border-amber-200 p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-gray-900">Edit Profile</h2>
                <a href="client_profile.php" class="px-4 py-2 text-sm rounded-lg border border-amber-200 hover:bg-amber-50 text-amber-700">Back to Profile</a>
            </div>

            <?php if ($success): ?>
                <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800">Profile updated successfully.</div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800">
                    <ul class="list-disc list-inside text-sm">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="space-y-5">
                <?= csrf_input(); ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                        <input name="first_name" type="text" value="<?php echo htmlspecialchars($u['first_name'] ?? ''); ?>" required class="w-full rounded-lg border border-amber-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                        <input name="last_name" type="text" value="<?php echo htmlspecialchars($u['last_name'] ?? ''); ?>" required class="w-full rounded-lg border border-amber-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input name="email" type="email" value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>" required class="w-full rounded-lg border border-amber-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bio</label>
                    <textarea name="bio" rows="5" class="w-full rounded-lg border border-amber-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400" placeholder="Tell freelancers a bit about you..."><?php echo htmlspecialchars($u['bio'] ?? ''); ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-[auto,1fr] gap-4 items-center">
                    <div class="flex items-center gap-3">
                        <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="Current avatar" class="w-16 h-16 rounded-full border-2 border-amber-300 object-cover" />
                        <div class="text-sm text-gray-600">Current avatar</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Change Profile Picture</label>
                        <input name="avatar" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100" />
                        <p class="mt-1 text-xs text-gray-500">Accepted: JPG, PNG, WEBP. Max 2MB.</p>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 shadow-md hover:shadow-lg transition-all">
                        <i data-lucide="save" class="w-5 h-5"></i>
                        <span class="font-medium">Save Changes</span>
                    </button>
                    <a href="client_profile.php" class="ml-3 px-4 py-3 rounded-lg border border-amber-200 text-amber-700 hover:bg-amber-50">Cancel</a>
                </div>
            </form>
        </div>
    </main>

    <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
        lucide.createIcons();
    </script>
</body>
</html>
