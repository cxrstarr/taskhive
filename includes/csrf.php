<?php
// Simple CSRF helpers (session token + hidden input + validation)
// Usage:
//   require_once __DIR__ . '/includes/csrf.php';
//   $token = csrf_token();
//   echo csrf_input();
//   if ($_SERVER['REQUEST_METHOD'] === 'POST') { if (!csrf_validate()) { /* handle error */ } }

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_input(string $name = '_token'): string {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="'.$n.'" value="'.$t.'">';
}

function csrf_get_token_from_request(): ?string {
    // Prefer POST field
    if (isset($_POST['_token']) && is_string($_POST['_token'])) {
        return $_POST['_token'];
    }
    // Allow header for AJAX submissions
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    return null;
}

function csrf_validate(): bool {
    $sessionToken = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
    $provided     = csrf_get_token_from_request();
    if (!$sessionToken || !$provided) return false;
    // Constant-time comparison
    if (function_exists('hash_equals')) {
        return hash_equals($sessionToken, $provided);
    }
    return $sessionToken === $provided;
}

function csrf_require_or_redirect(string $returnUrl = 'index.php'): void {
    if (!csrf_validate()) {
        // If flash helper exists, use it; otherwise generic 403
        if (function_exists('flash_set')) {
            flash_set('error', 'Security check failed. Please try again.');
            header('Location: '.$returnUrl);
            exit;
        }
        http_response_code(403);
        echo 'Security check failed.';
        exit;
    }
}
