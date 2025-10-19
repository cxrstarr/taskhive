<?php
// Start session for potential redirects or error messages
session_start();

// If user is already logged in, redirect to dashboard
// if (isset($_SESSION['user_id'])) {
//     header('Location: dashboard.php');
//     exit();
// }

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'database.php';
    $db = new database();
    $pdo = $db->opencon();
    
    // Sanitize and validate inputs
    $firstName = trim($_POST['firstName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $skills = $_POST['skills'] ?? '';
    $hourlyRate = $_POST['hourlyRate'] ?? '';
    $bio = trim($_POST['bio'] ?? '');
    $profilePictureDataUrl = $_POST['profilePicture'] ?? '';
    
    $errors = [];
    
    // Validation
    if (empty($firstName)) $errors[] = 'First name is required';
    if (empty($lastName)) $errors[] = 'Last name is required';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
    if (empty($phone)) $errors[] = 'Phone number is required';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
    if (empty($address)) $errors[] = 'Address is required';
    
    if (empty($errors)) {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $errors[] = 'Email already registered';
            } else {
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Optional: handle base64 profile picture (data URL)
                $storedProfilePath = null;
                if ($profilePictureDataUrl && preg_match('#^data:(image/(jpeg|png|gif|webp));base64,#i', $profilePictureDataUrl, $m)) {
                    $mime = strtolower($m[1]);
                    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                    $ext = $extMap[$mime] ?? 'jpg';
                    $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $profilePictureDataUrl);
                    $raw = base64_decode($base64, true);
                    if ($raw !== false) {
                        // Enforce 5MB max (UI hint)
                        if (strlen($raw) <= 5 * 1024 * 1024) {
                            $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
                            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                            $name = 'profile_'.uniqid('', true).'.'.$ext;
                            $dest = $dir.$name;
                            if (@file_put_contents($dest, $raw) !== false) {
                                $storedProfilePath = 'uploads/'.$name;
                            }
                        }
                    }
                }

                // Insert user (align with schema)
                $stmt = $pdo->prepare("INSERT INTO users 
                    (first_name, last_name, email, phone, password_hash, user_type, profile_picture, bio, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'freelancer', ?, ?, NOW())");
                $stmt->execute([
                    $firstName,
                    $lastName,
                    $email,
                    $phone ?: null,
                    $hashedPassword,
                    $storedProfilePath,
                    $bio ?: null
                ]);

                // Get the new user ID
                $userId = (int)$pdo->lastInsertId();

                // Create freelancer profile details
                $fpStmt = $pdo->prepare("INSERT INTO freelancer_profiles (user_id, skills, address, hourly_rate, created_at) VALUES (?, ?, ?, ?, NOW())");
                $skillsStr = is_string($skills) ? $skills : json_encode($skills);
                $fpStmt->execute([$userId, $skillsStr ?: null, $address ?: null, $hourlyRate !== '' ? $hourlyRate : null]);
                
                // Set session and redirect
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_name'] = $firstName . ' ' . $lastName;
                $_SESSION['user_type'] = 'freelancer';
                
                // Redirect to dashboard
                header('Location: freelancer_dashboard.php?registered=1');
                exit();
            }
        } catch (PDOException $e) {
            $errors[] = 'Registration failed. Please try again.';
            error_log($e->getMessage());
        }
    }
    
    // If there are errors, store them in session
    if (!empty($errors)) {
        $_SESSION['registration_errors'] = $errors;
    }
}

// Get any existing errors
$registrationErrors = $_SESSION['registration_errors'] ?? [];
unset($_SESSION['registration_errors']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join BeeHive - Freelancer Registration</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            background-color: #FFFBEB;
            background-image: 
                repeating-linear-gradient(0deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(60deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(120deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(180deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(240deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px),
                repeating-linear-gradient(300deg, transparent, transparent 86.6px, rgba(252, 211, 77, 0.15) 86.6px, rgba(252, 211, 77, 0.15) 87.6px);
        }

        .container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            position: relative;
            overflow: hidden;
        }

        /* Floating Bees */
        .floating-bee {
            position: absolute;
            font-size: 3rem;
            opacity: 0.2;
            pointer-events: none;
            animation: float 4s ease-in-out infinite;
        }

        .bee-1 {
            top: 5rem;
            left: 2.5rem;
            font-size: 4rem;
            animation: float1 4s ease-in-out infinite;
        }

        .bee-2 {
            bottom: 8rem;
            right: 5rem;
            font-size: 3.5rem;
            animation: float2 5s ease-in-out infinite 1s;
        }

        .bee-3 {
            top: 50%;
            left: 25%;
            font-size: 2.5rem;
            opacity: 0.1;
            animation: float3 6s ease-in-out infinite 2s;
        }

        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(10px, -20px) rotate(10deg); }
            50% { transform: translate(0, -40px) rotate(0deg); }
            75% { transform: translate(-10px, -20px) rotate(-10deg); }
        }

        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(-15px, 20px) rotate(-10deg); }
            50% { transform: translate(0, 40px) rotate(0deg); }
            75% { transform: translate(15px, 20px) rotate(10deg); }
        }

        @keyframes float3 {
            0%, 100% { transform: translate(0, 0); }
            25% { transform: translate(20px, -30px); }
            50% { transform: translate(40px, -60px); }
            75% { transform: translate(20px, -30px); }
        }

        /* Main Content */
        .content {
            width: 100%;
            max-width: 42rem;
            animation: fadeInUp 0.6s ease-out;
        }

        /* Back button row (aligns with content width) */
        .back-row {
            width: 100%;
            max-width: 42rem;
            margin: 0 auto 0.75rem auto;
            display: flex;
            justify-content: flex-start;
        }

        .back-link {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FCD34D;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .back-link:hover {
            background: #FCD34D;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 2rem;
            animation: fadeInDown 0.6s ease-out 0.2s backwards;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-container {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 5rem;
            height: 5rem;
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            border-radius: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .logo-container:hover {
            transform: scale(1.1) rotate(5deg);
        }

        .logo-container span {
            font-size: 3rem;
        }

        .header h1 {
            font-size: 2.25rem;
            background: linear-gradient(135deg, #D97706 0%, #92400E 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: #6B7280;
        }

        /* Progress Steps */
        .progress-container {
            display: flex;
            justify-content: center; /* center the whole 1-2-3 cluster */
            align-items: center;
            gap: 0.75rem; /* spacing between items */
            max-width: 100%;
            margin: 0 auto 2rem;
            animation: fadeIn 0.6s ease-out 0.4s backwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .step-item {
            display: flex;
            align-items: center;
            flex: 0 0 auto; /* don't stretch items, keep compact */
        }

        .step-circle {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            transition: all 0.3s ease;
            animation: scaleIn 0.3s ease-out backwards;
        }

        .step-circle.active {
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .step-circle.inactive {
            background: #E5E7EB;
            color: #9CA3AF;
        }

        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }

        .step-line {
            flex: 0 0 auto; /* fixed size line between circles */
            width: 3.5rem;
            height: 0.25rem;
            margin: 0 0.5rem;
            border-radius: 0.125rem;
            transition: all 0.5s ease;
        }

        .step-line.completed {
            background: linear-gradient(90deg, #FBBF24 0%, #F59E0B 100%);
        }

        .step-line.incomplete {
            background: #E5E7EB;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border: 2px solid #FDE68A;
            animation: scaleUp 0.5s ease-out 0.3s backwards;
        }

        @keyframes scaleUp {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .step-content {
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .step-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .step-header h2 {
            font-size: 1.5rem;
            color: #92400E;
            margin-bottom: 0.25rem;
        }

        .step-header p {
            font-size: 0.875rem;
            color: #6B7280;
        }

        /* Form Groups */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }

        .form-group label i {
            color: #D97706;
            font-size: 0.875rem;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.625rem 0.75rem;
            border: 2px solid #E5E7EB;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #FCD34D;
            box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.1);
        }

        .form-group input.error {
            border-color: #EF4444;
            animation: shake 0.3s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .error-message {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: #EF4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
            animation: fadeInError 0.3s ease-out;
        }

        @keyframes fadeInError {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .success-message {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: #10B981;
            font-size: 0.75rem;
            margin-top: 0.25rem;
            animation: fadeInError 0.3s ease-out;
        }

        /* Password Toggle */
        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6B7280;
            cursor: pointer;
            padding: 0.25rem;
            transition: color 0.2s ease;
        }

        .password-toggle:hover {
            color: #D97706;
        }

        /* Password Strength */
        .password-strength {
            margin-top: 0.5rem;
            animation: fadeInError 0.3s ease-out;
        }

        .strength-bar-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .strength-bar {
            flex: 1;
            height: 0.5rem;
            background: #E5E7EB;
            border-radius: 9999px;
            overflow: hidden;
        }

        .strength-bar-fill {
            height: 100%;
            transition: width 0.3s ease, background-color 0.3s ease;
            border-radius: 9999px;
        }

        .strength-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #374151;
        }

        /* Profile Picture Upload */
        .profile-upload {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .profile-preview {
            position: relative;
            width: 6rem;
            height: 6rem;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            border: 4px solid #FBBF24;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease;
        }

        .profile-preview:hover {
            transform: scale(1.05);
        }

        .profile-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-preview i {
            font-size: 2.5rem;
            color: #D97706;
        }

        .upload-button {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 2rem;
            height: 2rem;
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
        }

        .upload-button:hover {
            transform: scale(1.1);
        }

        .upload-button i {
            color: white;
            font-size: 0.875rem;
        }

        .upload-info p {
            font-size: 0.875rem;
            color: #6B7280;
            margin-bottom: 0.25rem;
        }

        .upload-info span {
            font-size: 0.75rem;
            color: #9CA3AF;
        }

        /* Skills */
        .skills-input-wrapper {
            margin-bottom: 1rem;
        }

        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .skill-tag {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            color: #92400E;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid #FCD34D;
            animation: popIn 0.3s ease-out;
        }

        @keyframes popIn {
            from {
                opacity: 0;
                transform: scale(0);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .skill-tag button {
            background: none;
            border: none;
            padding: 0.125rem;
            cursor: pointer;
            color: #92400E;
            border-radius: 50%;
            transition: background 0.2s ease;
        }

        .skill-tag button:hover {
            background: #FCD34D;
        }

        .skill-tag i.fa-sparkles {
            font-size: 0.75rem;
        }

        /* Character Counter */
        .char-counter {
            text-align: right;
            font-size: 0.75rem;
            color: #6B7280;
            margin-top: 0.5rem;
        }

        /* Buttons */
        .button-group {
            display: flex;
            gap: 0.75rem;
            margin-top: 2rem;
        }

        .btn {
            flex: 1;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-secondary {
            background: white;
            border: 2px solid #FDE68A;
            color: #92400E;
        }

        .btn-secondary:hover {
            background: #FEF3C7;
        }

        .btn-primary {
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 8px -1px rgba(245, 158, 11, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        /* Login Link */
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.875rem;
            color: #6B7280;
            animation: fadeIn 0.6s ease-out 0.5s backwards;
        }

        .login-link a {
            color: #D97706;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .login-link a:hover {
            color: #92400E;
            text-decoration: underline;
        }

        /* Hidden class */
        .hidden {
            display: none !important;
        }

        /* Star Rating Container */
        .rating-container {
            padding: 1rem;
            background: #F9FAFB;
            border-radius: 0.75rem;
            border: 2px dashed #E5E7EB;
            transition: all 0.2s ease;
        }

        .rating-container.has-rating {
            background: #FEF3C7;
            border-color: #FCD34D;
        }

        /* Responsive */
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .progress-container {
                padding: 0 1rem;
            }

            .step-circle {
                width: 2rem;
                height: 2rem;
                font-size: 0.875rem;
            }

            .header h1 {
                font-size: 1.875rem;
            }

            .logo-container {
                width: 4rem;
                height: 4rem;
            }

            .logo-container span {
                font-size: 2.5rem;
            }

            .floating-bee {
                display: none;
            }

            .back-row {
                justify-content: center;
            }
        }

        /* Server Error Messages */
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: #FEE2E2;
            border: 1px solid #EF4444;
            color: #991B1B;
        }

        .alert ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .alert li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .alert li:last-child {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Floating Bees -->
        <div class="floating-bee bee-1">🐝</div>
        <div class="floating-bee bee-2">🐝</div>
        <div class="floating-bee bee-3">🐝</div>

        <!-- Back Button -->
        <div class="back-row">
            <a class="back-link" href="register.php"><i class="fas fa-arrow-left"></i> Back</a>
        </div>

        <div class="content">
            <!-- Header -->
            <div class="header">
                <div class="logo-container">
                    <span>🐝</span>
                </div>
                <h1>Join BeeHive</h1>
                <p>Start your freelancing journey today!</p>
            </div>

            <!-- Progress Steps -->
            <div class="progress-container">
                <div class="step-item">
                    <div class="step-circle active" id="step1Circle">
                        <span class="step-number">1</span>
                        <i class="fas fa-check step-check hidden"></i>
                    </div>
                    <div class="step-line incomplete" id="line1"></div>
                </div>
                <div class="step-item">
                    <div class="step-circle inactive" id="step2Circle">
                        <span class="step-number">2</span>
                        <i class="fas fa-check step-check hidden"></i>
                    </div>
                    <div class="step-line incomplete" id="line2"></div>
                </div>
                <div class="step-item">
                    <div class="step-circle inactive" id="step3Circle">
                        <span class="step-number">3</span>
                        <i class="fas fa-check step-check hidden"></i>
                    </div>
                </div>
            </div>

            <!-- Form Card -->
            <div class="form-card">
                <?php if (!empty($registrationErrors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($registrationErrors as $error): ?>
                            <li>
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form id="registrationForm" method="POST" action="">
                    <!-- Step 1: Basic Info -->
                    <div class="step-content" id="step1">
                        <div class="step-header">
                            <h2>Personal Information</h2>
                            <p>Let's start with the basics</p>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="firstName">
                                    <i class="fas fa-user"></i>
                                    First Name
                                </label>
                                <input type="text" id="firstName" name="firstName" placeholder="John" required>
                                <div class="error-message hidden" id="firstNameError">
                                    <i class="fas fa-times"></i>
                                    <span>First name is required</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="lastName">
                                    <i class="fas fa-user"></i>
                                    Last Name
                                </label>
                                <input type="text" id="lastName" name="lastName" placeholder="Doe" required>
                                <div class="error-message hidden" id="lastNameError">
                                    <i class="fas fa-times"></i>
                                    <span>Last name is required</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">
                                <i class="fas fa-envelope"></i>
                                Email Address
                            </label>
                            <input type="email" id="email" name="email" placeholder="john.doe@example.com" required>
                            <div class="error-message hidden" id="emailError">
                                <i class="fas fa-times"></i>
                                <span>Valid email is required</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="phone">
                                <i class="fas fa-phone"></i>
                                Phone Number
                            </label>
                            <input type="tel" id="phone" name="phone" placeholder="+63 912 345 6789" required>
                            <div class="error-message hidden" id="phoneError">
                                <i class="fas fa-times"></i>
                                <span>Phone number is required</span>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Security & Location -->
                    <div class="step-content hidden" id="step2">
                        <div class="step-header">
                            <h2>Security & Location</h2>
                            <p>Secure your account</p>
                        </div>

                        <div class="form-group">
                            <label for="password">
                                <i class="fas fa-lock"></i>
                                Password
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" placeholder="••••••••" required>
                                <button type="button" class="password-toggle" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength hidden" id="passwordStrength">
                                <div class="strength-bar-container">
                                    <div class="strength-bar">
                                        <div class="strength-bar-fill" id="strengthBar"></div>
                                    </div>
                                    <span class="strength-label" id="strengthLabel">Weak</span>
                                </div>
                            </div>
                            <div class="error-message hidden" id="passwordError">
                                <i class="fas fa-times"></i>
                                <span>Password must be at least 8 characters</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirmPassword">
                                <i class="fas fa-lock"></i>
                                Confirm Password
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="confirmPassword" name="confirmPassword" placeholder="••••••••" required>
                                <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="success-message hidden" id="passwordMatch">
                                <i class="fas fa-check"></i>
                                <span>Passwords match</span>
                            </div>
                            <div class="error-message hidden" id="confirmPasswordError">
                                <i class="fas fa-times"></i>
                                <span>Passwords do not match</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address">
                                <i class="fas fa-map-marker-alt"></i>
                                Address
                            </label>
                            <input type="text" id="address" name="address" placeholder="123 Main St, City, Country" required>
                            <div class="error-message hidden" id="addressError">
                                <i class="fas fa-times"></i>
                                <span>Address is required</span>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Professional Info -->
                    <div class="step-content hidden" id="step3">
                        <div class="step-header">
                            <h2>Professional Details</h2>
                            <p>Tell us about your expertise</p>
                        </div>

                        <div class="profile-upload">
                            <div class="profile-preview" id="profilePreview">
                                <i class="fas fa-user"></i>
                                <img id="previewImage" class="hidden" src="" alt="Profile Preview">
                                <div class="upload-button" onclick="document.getElementById('profilePicture').click()">
                                    <i class="fas fa-camera"></i>
                                </div>
                            </div>
                            <div class="upload-info">
                                <p>Upload a professional photo</p>
                                <span>JPG, PNG or GIF (max 5MB)</span>
                            </div>
                            <input type="file" id="profilePicture" accept="image/*" class="hidden">
                            <input type="hidden" id="profilePictureData" name="profilePicture">
                        </div>

                        <div class="form-group">
                            <label for="skillsInput">
                                <i class="fas fa-briefcase"></i>
                                Skills
                            </label>
                            <div class="skills-input-wrapper">
                                <input type="text" id="skillsInput" placeholder="Type a skill and press Enter">
                                <input type="hidden" id="skillsData" name="skills">
                            </div>
                            <div class="skills-tags" id="skillsTags"></div>
                        </div>

                        <div class="form-group">
                            <label for="hourlyRate">
                                <i class="fas fa-dollar-sign"></i>
                                Hourly Rate (₱)
                            </label>
                            <input type="number" id="hourlyRate" name="hourlyRate" placeholder="50" min="0" step="0.01">
                        </div>

                        <div class="form-group">
                            <label for="bio">
                                <i class="fas fa-file-alt"></i>
                                Brief Bio
                            </label>
                            <textarea id="bio" name="bio" placeholder="Tell us about yourself and your expertise..." rows="4" maxlength="500"></textarea>
                            <div class="char-counter">
                                <span id="bioCount">0</span>/500 characters
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="button-group">
                        <button type="button" class="btn btn-secondary hidden" id="prevBtn">
                            <i class="fas fa-arrow-left"></i>
                            Previous
                        </button>
                        <button type="button" class="btn btn-primary" id="nextBtn">
                            Next
                            <i class="fas fa-arrow-right"></i>
                        </button>
                        <button type="submit" class="btn btn-primary hidden" id="submitBtn">
                            <i class="fas fa-sparkles"></i>
                            Create Account
                        </button>
                    </div>
                </form>

                <!-- Login Link -->
                <div class="login-link">
                    Already have an account? <a href="login.php">Sign in</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // State
        let currentStep = 1;
        let skills = [];

        // Elements
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');

        // Step circles
        const step1Circle = document.getElementById('step1Circle');
        const step2Circle = document.getElementById('step2Circle');
        const step3Circle = document.getElementById('step3Circle');
        const line1 = document.getElementById('line1');
        const line2 = document.getElementById('line2');

        // Password Toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('confirmPassword');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Password Strength
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthContainer = document.getElementById('passwordStrength');
            const strengthBar = document.getElementById('strengthBar');
            const strengthLabel = document.getElementById('strengthLabel');
            
            if (password.length === 0) {
                strengthContainer.classList.add('hidden');
                return;
            }
            
            strengthContainer.classList.remove('hidden');
            
            let score = 0;
            if (password.length >= 8) score++;
            if (password.match(/[a-z]/)) score++;
            if (password.match(/[A-Z]/)) score++;
            if (password.match(/[0-9]/)) score++;
            if (password.match(/[^a-zA-Z0-9]/)) score++;
            
            let width, label, color;
            
            if (score <= 2) {
                width = score * 20;
                label = 'Weak';
                color = '#EF4444';
            } else if (score === 3) {
                width = 60;
                label = 'Fair';
                color = '#F59E0B';
            } else if (score === 4) {
                width = 80;
                label = 'Good';
                color = '#3B82F6';
            } else {
                width = 100;
                label = 'Strong';
                color = '#10B981';
            }
            
            strengthBar.style.width = width + '%';
            strengthBar.style.backgroundColor = color;
            strengthLabel.textContent = label;
        });

        // Confirm Password Match
        document.getElementById('confirmPassword').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const matchMsg = document.getElementById('passwordMatch');
            const errorMsg = document.getElementById('confirmPasswordError');
            
            if (confirmPassword.length === 0) {
                matchMsg.classList.add('hidden');
                errorMsg.classList.add('hidden');
                return;
            }
            
            if (password === confirmPassword) {
                matchMsg.classList.remove('hidden');
                errorMsg.classList.add('hidden');
            } else {
                matchMsg.classList.add('hidden');
                errorMsg.classList.remove('hidden');
            }
        });

        // Profile Picture Upload
        document.getElementById('profilePicture').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const previewImage = document.getElementById('previewImage');
                    const profilePreview = document.getElementById('profilePreview');
                    
                    previewImage.src = event.target.result;
                    previewImage.classList.remove('hidden');
                    profilePreview.querySelector('i').classList.add('hidden');
                    
                    // Store base64 data
                    document.getElementById('profilePictureData').value = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        // Skills Management
        const skillsInput = document.getElementById('skillsInput');
        const skillsTags = document.getElementById('skillsTags');
        const skillsData = document.getElementById('skillsData');

        skillsInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const skill = this.value.trim();
                
                if (skill && !skills.includes(skill)) {
                    skills.push(skill);
                    renderSkills();
                    this.value = '';
                    updateSkillsData();
                }
            }
        });

        function renderSkills() {
            skillsTags.innerHTML = '';
            skills.forEach((skill, index) => {
                const tag = document.createElement('div');
                tag.className = 'skill-tag';
                tag.innerHTML = `
                    <i class="fas fa-sparkles"></i>
                    ${skill}
                    <button type="button" onclick="removeSkill(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                skillsTags.appendChild(tag);
            });
        }

        function removeSkill(index) {
            skills.splice(index, 1);
            renderSkills();
            updateSkillsData();
        }

        function updateSkillsData() {
            skillsData.value = JSON.stringify(skills);
        }

        // Bio Character Counter
        document.getElementById('bio').addEventListener('input', function() {
            const count = this.value.length;
            document.getElementById('bioCount').textContent = count;
        });

        // Validation
        function validateStep(step) {
            let isValid = true;
            
            if (step === 1) {
                const firstName = document.getElementById('firstName');
                const lastName = document.getElementById('lastName');
                const email = document.getElementById('email');
                const phone = document.getElementById('phone');
                
                // First Name
                if (!firstName.value.trim()) {
                    showError('firstName');
                    isValid = false;
                } else {
                    hideError('firstName');
                }
                
                // Last Name
                if (!lastName.value.trim()) {
                    showError('lastName');
                    isValid = false;
                } else {
                    hideError('lastName');
                }
                
                // Email
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!email.value.trim() || !emailRegex.test(email.value)) {
                    showError('email');
                    isValid = false;
                } else {
                    hideError('email');
                }
                
                // Phone
                const phoneDigits = phone.value.replace(/\D/g, '');
                if (!phone.value.trim() || phoneDigits.length < 10) {
                    showError('phone');
                    isValid = false;
                } else {
                    hideError('phone');
                }
            }
            
            if (step === 2) {
                const password = document.getElementById('password');
                const confirmPassword = document.getElementById('confirmPassword');
                const address = document.getElementById('address');
                
                // Password
                if (password.value.length < 8) {
                    showError('password');
                    isValid = false;
                } else {
                    hideError('password');
                }
                
                // Confirm Password
                if (password.value !== confirmPassword.value) {
                    showError('confirmPassword');
                    isValid = false;
                } else {
                    hideError('confirmPassword');
                }
                
                // Address
                if (!address.value.trim()) {
                    showError('address');
                    isValid = false;
                } else {
                    hideError('address');
                }
            }
            
            return isValid;
        }

        function showError(fieldId) {
            const field = document.getElementById(fieldId);
            const error = document.getElementById(fieldId + 'Error');
            
            field.classList.add('error');
            if (error) {
                error.classList.remove('hidden');
            }
        }

        function hideError(fieldId) {
            const field = document.getElementById(fieldId);
            const error = document.getElementById(fieldId + 'Error');
            
            field.classList.remove('error');
            if (error) {
                error.classList.add('hidden');
            }
        }

        // Navigation
        function showStep(step) {
            // Hide all steps
            step1.classList.add('hidden');
            step2.classList.add('hidden');
            step3.classList.add('hidden');
            
            // Show current step
            if (step === 1) {
                step1.classList.remove('hidden');
                prevBtn.classList.add('hidden');
                nextBtn.classList.remove('hidden');
                submitBtn.classList.add('hidden');
            } else if (step === 2) {
                step2.classList.remove('hidden');
                prevBtn.classList.remove('hidden');
                nextBtn.classList.remove('hidden');
                submitBtn.classList.add('hidden');
            } else if (step === 3) {
                step3.classList.remove('hidden');
                prevBtn.classList.remove('hidden');
                nextBtn.classList.add('hidden');
                submitBtn.classList.remove('hidden');
            }
            
            // Update step indicators
            updateStepIndicators(step);
        }

        function updateStepIndicators(step) {
            // Reset all
            [step1Circle, step2Circle, step3Circle].forEach(circle => {
                circle.classList.remove('active');
                circle.classList.add('inactive');
            });
            
            [line1, line2].forEach(line => {
                line.classList.remove('completed');
                line.classList.add('incomplete');
            });
            
            // Set active and completed
            if (step >= 1) {
                step1Circle.classList.add('active');
                step1Circle.classList.remove('inactive');
            }
            if (step >= 2) {
                step2Circle.classList.add('active');
                step2Circle.classList.remove('inactive');
                line1.classList.add('completed');
                line1.classList.remove('incomplete');
            }
            if (step >= 3) {
                step3Circle.classList.add('active');
                step3Circle.classList.remove('inactive');
                line2.classList.add('completed');
                line2.classList.remove('incomplete');
            }
        }

        // Button Events
        nextBtn.addEventListener('click', function() {
            if (validateStep(currentStep)) {
                currentStep++;
                showStep(currentStep);
            }
        });

        prevBtn.addEventListener('click', function() {
            currentStep--;
            showStep(currentStep);
        });

        // Form Submission
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            if (!validateStep(3)) {
                e.preventDefault();
            }
        });

        // Initialize
        showStep(1);
    </script>
</body>
</html>
