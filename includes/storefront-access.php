<?php
/** Use the existing admin session for hidden-listing previews only. */
function fasCanViewHiddenProducts(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (empty($_COOKIE[session_name()])) {
            return false;
        }
        @session_start();
    }
    if (($_SESSION['admin_logged_in'] ?? false) !== true) return false;
    require_once __DIR__.'/../admin/auth.php';
    return (new AdminAuth())->isLoggedIn();
}
