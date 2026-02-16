<?php
// Start session
session_start();
require_once __DIR__ . '/includes/csp.php';
require_once __DIR__ . '/includes/csrf.php';

// If user is already logged in, redirect to home page
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Fixed impersonation by user ID (freelancer=1, client=2, admin=5)
if (isset($_REQUEST['impersonate'])) {
    $uid = (int)$_REQUEST['impersonate'];
    if (in_array($uid, [1,2,5], true)) {
        require_once 'database.php';
        $db = new database();
        $u = $db->getUser($uid);
        if ($u) {
            $_SESSION['user_id'] = (int)$u['user_id'];
            $_SESSION['user_type'] = (string)$u['user_type'];
            $_SESSION['user_email'] = (string)($u['email'] ?? '');
            $_SESSION['user_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            if ($u['user_type'] === 'freelancer') {
                header('Location: freelancer_dashboard.php');
            } elseif ($u['user_type'] === 'admin') {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: client_dashboard.php');
            }
            exit();
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $_SESSION['login_errors'] = ['Security check failed. Please retry.'];
        header('Location: login.php');
        exit();
    }
    require_once 'database.php';
    $db = new database();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $errors = [];
    
    // Validation
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        try {
            // Use project auth helper (aligns with users.user_id/password_hash/user_type)
            $user = $db->loginUser($email, $password);

            if ($user) {
                // Login successful
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['user_email'] = $email;
                $_SESSION['user_name'] = ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '');
                $_SESSION['user_type'] = $user['user_type'] ?? 'client';

                // Redirect to home page after successful login
                header('Location: index.php');
                exit();
            } else {
                $errors[] = 'Invalid email or password';
            }
        } catch (Throwable $e) {
            $errors[] = 'Login failed. Please try again.';
            error_log($e->getMessage());
        }
    }
    
    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['login_errors'] = $errors;
    }
}

// Get any existing errors
$loginErrors = $_SESSION['login_errors'] ?? [];
unset($_SESSION['login_errors']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/bee.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - BeeHive</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
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
            overflow: hidden;
        }

        .container {
            width: 100%;
            max-width: 28rem;
            position: relative;
            z-index: 10;
        }

        /* Floating Bees */
        .floating-bee {
            position: fixed;
            font-size: 4rem;
            opacity: 0.2;
            pointer-events: none;
            z-index: 1;
        }

        .bee-1 {
            top: 5rem;
            left: 2.5rem;
            animation: float1 5s ease-in-out infinite;
        }

        .bee-2 {
            bottom: 8rem;
            right: 5rem;
            font-size: 3.5rem;
            animation: float2 6s ease-in-out infinite 1s;
        }

        .bee-3 {
            top: 50%;
            left: 25%;
            font-size: 2.5rem;
            opacity: 0.1;
            animation: float3 7s ease-in-out infinite 2s;
        }

        .bee-4 {
            top: 25%;
            right: 33%;
            font-size: 2rem;
            opacity: 0.15;
            animation: float1 5.5s ease-in-out infinite 0.5s;
        }

        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(15px, -25px) rotate(10deg); }
            50% { transform: translate(0, -50px) rotate(0deg); }
            75% { transform: translate(-15px, -25px) rotate(-10deg); }
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

        /* Back Button */
        .back-button {
            background: white;
            border: 2px solid #FCD34D;
            color: #92400E;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.2s ease;
            animation: fadeInDown 0.6s ease-out 0.2s backwards;
        }

        .back-button:hover {
            background: #FEF3C7;
            border-color: #FBBF24;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
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

        /* Login Card */
        .login-card {
            background: white;
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border: 4px solid #FCD34D;
            position: relative;
            overflow: hidden;
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

        /* Decorative corners */
        .corner-top {
            position: absolute;
            top: 0;
            right: 0;
            width: 5rem;
            height: 5rem;
            background: linear-gradient(to bottom left, #FEF3C7, transparent);
            border-radius: 0 0 0 100%;
            opacity: 0.5;
        }

        .corner-bottom {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 5rem;
            height: 5rem;
            background: linear-gradient(to top right, #FEF3C7, transparent);
            border-radius: 0 100% 0 0;
            opacity: 0.5;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 2rem;
            animation: fadeIn 0.6s ease-out 0.3s backwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .bee-logo {
            width: 3.5rem;
            height: 3.5rem;
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .header h1 {
            background: linear-gradient(135deg, #D97706 0%, #92400E 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 1.875rem;
            margin: 0;
        }

        .header p {
            color: #6B7280;
            font-size: 0.875rem;
            margin: 0;
        }

        /* Form */
        .login-form {
            animation: fadeIn 0.6s ease-out 0.4s backwards;
        }

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

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 1.25rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid #E5E7EB;
            border-radius: 0.75rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: #FBBF24;
            box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.1);
        }

        .form-group input.error {
            border-color: #EF4444;
        }

        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9CA3AF;
            cursor: pointer;
            padding: 0.25rem;
            transition: color 0.2s ease;
            font-size: 1.25rem;
        }

        .password-toggle:hover {
            color: #D97706;
        }

        .error-message {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: #EF4444;
            font-size: 0.8125rem;
            margin-top: 0.5rem;
            animation: shake 0.3s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 1.5rem;
        }

        .forgot-password a {
            color: #D97706;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .forgot-password a:hover {
            color: #92400E;
            text-decoration: underline;
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
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.5);
        }

        .submit-btn:active {
            transform: translateY(-1px);
        }

        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Register Link */
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.875rem;
            color: #6B7280;
            animation: fadeIn 0.6s ease-out 0.6s backwards;
        }

        .register-link a {
            color: #D97706;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .register-link a:hover {
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

        /* Responsive */
        @media (max-width: 640px) {
            .login-card {
                padding: 2rem 1.5rem;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .floating-bee {
                font-size: 2.5rem;
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
        <!-- Back Button -->
    <a href="index.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Back
        </a>

        <!-- Login Card -->
        <div class="login-card">
            <!-- Decorative Corners -->
            <div class="corner-top"></div>
            <div class="corner-bottom"></div>

            <!-- Header -->
            <div class="header">
                <div class="header-title">
                    <div class="bee-logo">🐝</div>
                    <h1>BeeHive Login</h1>
                </div>
                <p>Welcome back! Please login to continue</p>
            </div>

            <?php if (!empty($loginErrors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($loginErrors as $error): ?>
                        <li>
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Demo Quick Login -->
            <div class="register-link" style="margin-bottom:1rem;">
                Quick login as fixed users:
            </div>
            <div style="display:flex; gap:0.5rem; justify-content:center; margin-bottom:1.5rem;">
                <a href="login.php?impersonate=1" class="submit-btn" style="width:auto; padding:0.5rem 1rem; text-align:center; display:inline-block;">Freelancer</a>
                <a href="login.php?impersonate=2" class="submit-btn" style="width:auto; padding:0.5rem 1rem; text-align:center; display:inline-block;">Client</a>
                <a href="login.php?impersonate=5" class="submit-btn" style="width:auto; padding:0.5rem 1rem; text-align:center; display:inline-block;">Admin</a>
            </div>

            <!-- Form -->
            <form id="loginForm" class="login-form" method="POST" action="">
                <?php echo csrf_input(); ?>
                <!-- Email -->
                <div class="form-group">
                    <label for="email">Email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            placeholder="Enter your email"
                            required
                        >
                    </div>
                    <div class="error-message hidden" id="emailError">
                        <i class="fas fa-times-circle"></i>
                        <span>Valid email is required</span>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Enter your password"
                            required
                        >
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message hidden" id="passwordError">
                        <i class="fas fa-times-circle"></i>
                        <span>Password is required</span>
                    </div>
                </div>

                <!-- Forgot Password -->
                <div class="forgot-password">
                    <a href="unavail.php">Forgot password?</a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="submit-btn">
                    Login
                </button>
            </form>

            <!-- Register Link -->
            <div class="register-link">
                No account? <a href="register.php">Register</a>
            </div>
        </div>
    </div>

    <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
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

        // Form Validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            let isValid = true;
            
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
            if (!password.value) {
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
        ['email', 'password'].forEach(fieldId => {
            document.getElementById(fieldId).addEventListener('input', function() {
                if (this.value.trim()) {
                    hideError(fieldId);
                }
            });
        });
    </script>
</body>
</html>
