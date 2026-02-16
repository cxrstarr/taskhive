<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function flash_set(string $type, string $message): void {
    $_SESSION['flash'][$type][] = $message;
}
function flash_render(): string {
    if (empty($_SESSION['flash'])) return '';
    $out = '';
    foreach ($_SESSION['flash'] as $type=>$msgs) {
        foreach ($msgs as $m) {
            $escaped = htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
            $icon = $type === 'success' ? 'success' : ($type==='error'?'error':'info');
            $nonceAttr = function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '';
            $out .= "<script$nonceAttr>Swal.fire({icon:'$icon', text: '$escaped'});</script>";
        }
    }
    unset($_SESSION['flash']);
    return $out;
}