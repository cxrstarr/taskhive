<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Generate a per-request nonce
$__csp_nonce = base64_encode(random_bytes(16));

function csp_nonce(): string {
    global $__csp_nonce;
    return $__csp_nonce;
}

function csp_script_nonce_attr(): string {
    return ' nonce="' . htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') . '"';
}
function csp_style_nonce_attr(): string {
    return ' nonce="' . htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') . '"';
}

// Send CSP header (script-src without unsafe-inline, with nonce)
$csp = [];
$csp[] = "default-src 'self'";
$csp[] = "base-uri 'self'";
$csp[] = "form-action 'self'";
$csp[] = "frame-ancestors 'self'";
$csp[] = "object-src 'none'";
$csp[] = "upgrade-insecure-requests";
$csp[] = "block-all-mixed-content";
$csp[] = "img-src 'self' data: blob: https://api.dicebear.com";
$csp[] = "script-src 'self' 'nonce-" . csp_nonce() . "' https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://unpkg.com";
$csp[] = "style-src 'self' 'nonce-" . csp_nonce() . "' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com";
$csp[] = "font-src 'self' data: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com";
$csp[] = "connect-src 'self'";
$csp[] = "frame-src 'self'";
$csp[] = "worker-src 'none'";
$csp[] = "media-src 'self'";
$csp[] = "manifest-src 'self'";

header('Content-Security-Policy: ' . implode('; ', $csp));

// Additional security headers (optional but recommended)
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
header('X-Frame-Options: SAMEORIGIN');
