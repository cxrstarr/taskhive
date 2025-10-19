<?php
// Start session for potential redirects or error messages
session_start();

// If user is already logged in, redirect to dashboard
// if (isset($_SESSION['user_id'])) {
//     header('Location: client-dashboard.php');
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
    $profilePictureDataUrl = $_POST['profilePicture'] ?? '';
    
    $errors = [];
    
    // Validation
    if (empty($firstName)) $errors[] = 'First name is required';
    if (empty($lastName)) $errors[] = 'Last name is required';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
    
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
                        if (strlen($raw) <= 5 * 1024 * 1024) { // <=5MB
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

                // Insert user with correct schema
                $stmt = $pdo->prepare("INSERT INTO users 
                    (first_name, last_name, email, phone, password_hash, user_type, profile_picture, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'client', ?, NOW())");
                $stmt->execute([
                    $firstName,
                    $lastName,
                    $email,
                    $phone ?: null,
                    $hashedPassword,
                    $storedProfilePath
                ]);
                
                // Get the new user ID
                $userId = $pdo->lastInsertId();
                
                // Set session and redirect
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_name'] = $firstName . ' ' . $lastName;
                $_SESSION['user_type'] = 'client';
                
                // Redirect to client dashboard
                header('Location: client_dashboard.php?registered=1');
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
    <title>Client Registration - BeeHive</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .container {
            width: 100%;
            max-width: 28rem;
            position: relative;
        }

        /* Floating Bees */
        .floating-bee {
            position: fixed;
            font-size: 3rem;
            opacity: 0.15;
            pointer-events: none;
            z-index: 1;
        }

        .bee-1 {
            top: 10%;
            left: 15%;
            font-size: 4rem;
            animation: float1 5s ease-in-out infinite;
        }

        .bee-2 {
            bottom: 15%;
            right: 10%;
            font-size: 3.5rem;
            animation: float2 6s ease-in-out infinite 1s;
        }

        .bee-3 {
            top: 60%;
            left: 5%;
            font-size: 2.5rem;
            opacity: 0.1;
            animation: float3 7s ease-in-out infinite 2s;
        }

        .bee-4 {
            top: 20%;
            right: 20%;
            font-size: 2rem;
            opacity: 0.12;
            animation: float1 5.5s ease-in-out infinite 1.5s;
        }

        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(15px, -25px) rotate(8deg); }
            50% { transform: translate(0, -50px) rotate(0deg); }
            75% { transform: translate(-15px, -25px) rotate(-8deg); }
        }

        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(-20px, 25px) rotate(-12deg); }
            50% { transform: translate(0, 50px) rotate(0deg); }
            75% { transform: translate(20px, 25px) rotate(12deg); }
        }

        @keyframes float3 {
            0%, 100% { transform: translate(0, 0); }
            33% { transform: translate(30px, -40px); }
            66% { transform: translate(-30px, -30px); }
        }

        /* Card */
        .card {
            background: white;
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border: 3px solid #FCD34D;
            position: relative;
            z-index: 10;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
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
            margin-top: 0; /* back-link moved outside the card, no overlap */
        }

        .header-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .bee-icon {
            width: 2.5rem;
            height: 2.5rem;
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .header h1 {
            font-size: 1.875rem;
            color: #92400E;
            margin: 0;
        }

        .header p {
            color: #6B7280;
            font-size: 0.9375rem;
            margin: 0;
        }

        /* Back button row above the card */
        .back-row {
            width: 100%;
            max-width: 28rem;
            margin: 0 auto 0.75rem auto;
            display: flex;
            justify-content: flex-end;
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

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
            font-size: 0.9375rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #E5E7EB;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group input:focus {
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
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }

        .error-message {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: #EF4444;
            font-size: 0.8125rem;
            margin-top: 0.5rem;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
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
            font-size: 0.8125rem;
            margin-top: 0.5rem;
            animation: fadeIn 0.3s ease-out;
        }

        /* Password wrapper */
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
            font-size: 1.125rem;
        }

        .password-toggle:hover {
            color: #D97706;
        }

        /* Password Strength */
        .password-strength {
            margin-top: 0.5rem;
            animation: fadeIn 0.3s ease-out;
        }

        .strength-bar-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .strength-bar {
            flex: 1;
            height: 0.375rem;
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
            min-width: 3.5rem;
        }

        /* File Upload */
        .file-upload-wrapper {
            margin-top: 0.5rem;
        }

        .file-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            background: white;
            border: 2px solid #FCD34D;
            border-radius: 0.5rem;
            color: #92400E;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
        }

        .file-upload-btn:hover {
            background: #FEF3C7;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .file-upload-btn i {
            font-size: 1rem;
        }

        .file-name {
            display: inline-block;
            margin-left: 0.75rem;
            font-size: 0.875rem;
            color: #6B7280;
            animation: fadeIn 0.3s ease-out;
        }

        .file-preview {
            margin-top: 0.75rem;
            display: none;
            animation: fadeIn 0.3s ease-out;
        }

        .file-preview.show {
            display: block;
        }

        .file-preview img {
            width: 6rem;
            height: 6rem;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #FCD34D;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            border: none;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.4);
            margin-top: 1.5rem;
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.5);
        }

        .submit-btn:active {
            transform: translateY(-1px);
        }

        /* Login Link */
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9375rem;
            color: #6B7280;
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

        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: 0.75rem;
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
            border: 2px solid #EF4444;
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
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .alert li:last-child {
            margin-bottom: 0;
        }

        /* Hidden */
        .hidden {
            display: none !important;
        }

        /* Optional text */
        .optional {
            font-weight: 400;
            color: #9CA3AF;
            font-size: 0.875rem;
        }

        /* Responsive */
        @media (max-width: 640px) {
            .card {
                padding: 2rem 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .back-row {
                justify-content: center;
            }

            /* header remains centered on mobile */

            .floating-bee {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Floating Bees -->
    <div class="floating-bee bee-1">🐝</div>
    <div class="floating-bee bee-2">🐝</div>
    <div class="floating-bee bee-3">🐝</div>
    <div class="floating-bee bee-4">🐝</div>

    <div class="container">
        <div class="back-row">
            <a href="register.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
        <div class="card">

            <div class="header">
                <div class="header-title">
                    <div class="bee-icon">🐝</div>
                    <h1>Client Registration</h1>
                </div>
                <p>Hire trusted local freelancers</p>
            </div>

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

            <form id="clientRegForm" method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName">First Name</label>
                        <input type="text" id="firstName" name="firstName" placeholder="First Name" required>
                        <div class="error-message hidden" id="firstNameError">
                            <i class="fas fa-times-circle"></i>
                            <span>First name is required</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="lastName">Last Name</label>
                        <input type="text" id="lastName" name="lastName" placeholder="Last Name" required>
                        <div class="error-message hidden" id="lastNameError">
                            <i class="fas fa-times-circle"></i>
                            <span>Last name is required</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="Email" required>
                    <div class="error-message hidden" id="emailError">
                        <i class="fas fa-times-circle"></i>
                        <span>Valid email is required</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone">Phone (optional)</label>
                    <input type="tel" id="phone" name="phone" placeholder="Phone (optional)">
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="optional">(min 8)</span></label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="Password (min 8)" required>
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
                        <i class="fas fa-times-circle"></i>
                        <span>Password must be at least 8 characters</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="profilePicture">Profile Picture <span class="optional">(optional)</span></label>
                    <div class="file-upload-wrapper">
                        <label for="profilePicture" class="file-upload-btn">
                            <i class="fas fa-upload"></i>
                            Choose File
                        </label>
                        <span class="file-name" id="fileName">No file chosen</span>
                        <input type="file" id="profilePicture" accept="image/*" class="hidden">
                        <input type="hidden" id="profilePictureData" name="profilePicture">
                    </div>
                    <div class="file-preview" id="filePreview">
                        <img id="previewImage" src="" alt="Profile Preview">
                    </div>
                </div>

                <button type="submit" class="submit-btn">
                    Register as Client
                </button>
            </form>

            <div class="login-link">
                Already have an account? <a href="login.php">Login</a>
            </div>
        </div>
    </div>

    <script>
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

        // Password Strength Indicator
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

        // Profile Picture Upload
        document.getElementById('profilePicture').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const fileName = document.getElementById('fileName');
            const filePreview = document.getElementById('filePreview');
            const previewImage = document.getElementById('previewImage');
            
            if (file) {
                // Update file name
                fileName.textContent = file.name;
                
                // Preview image
                const reader = new FileReader();
                reader.onload = function(event) {
                    previewImage.src = event.target.result;
                    filePreview.classList.add('show');
                    
                    // Store base64 data
                    document.getElementById('profilePictureData').value = event.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                fileName.textContent = 'No file chosen';
                filePreview.classList.remove('show');
            }
        });

        // Form Validation
        document.getElementById('clientRegForm').addEventListener('submit', function(e) {
            let isValid = true;
            
            // First Name
            const firstName = document.getElementById('firstName');
            if (!firstName.value.trim()) {
                showError('firstName');
                isValid = false;
            } else {
                hideError('firstName');
            }
            
            // Last Name
            const lastName = document.getElementById('lastName');
            if (!lastName.value.trim()) {
                showError('lastName');
                isValid = false;
            } else {
                hideError('lastName');
            }
            
            // Email
            const email = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email.value.trim() || !emailRegex.test(email.value)) {
                showError('email');
                isValid = false;
            } else {
                hideError('email');
            }
            
            // Password
            const password = document.getElementById('password');
            if (password.value.length < 8) {
                showError('password');
                isValid = false;
            } else {
                hideError('password');
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });

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

        // Real-time validation removal
        ['firstName', 'lastName', 'email', 'password'].forEach(fieldId => {
            document.getElementById(fieldId).addEventListener('input', function() {
                if (this.value.trim()) {
                    hideError(fieldId);
                }
            });
        });
    </script>
</body>
</html>
