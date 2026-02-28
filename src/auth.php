<?php
require_once __DIR__ . '/bootstrap.php';

// auth.php
// Simple authentication guard used by all protected pages.

session_start();

$script = basename($_SERVER['PHP_SELF']);
$loginPath = defined('AUTH_LOGIN_PATH') ? AUTH_LOGIN_PATH : 'login.php';
$loginProcessPath = defined('AUTH_LOGIN_PROCESS_PATH') ? AUTH_LOGIN_PROCESS_PATH : 'login_process.php';

// If no logged-in user, redirect to login.php (except on login pages themselves)
if (empty($_SESSION['user'])) {
    if (!in_array($script, [basename($loginPath), basename($loginProcessPath)], true)) {
        header('Location: ' . $loginPath);
        exit;
    }
    // On login pages, do nothing more
    return;
}

// User is logged in – expose as $currentUser for the including script
$currentUser = $_SESSION['user'];

// ==========================================
// ADDED: Sync Logic (Runs ONLY once per session)
// ==========================================
if (empty($_SESSION['user_synced_to_snipeit'])) {
    
    // TODO: Insert your Snipe-IT sync logic here.
    // Example: SnipeITService::syncUser($currentUser);
    
    // Mark as synced so this block never runs again for this session
    $_SESSION['user_synced_to_snipeit'] = true;
}
// ==========================================

// Global HTML output helper:
if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars(
            htmlspecialchars_decode($value ?? '', ENT_QUOTES),
            ENT_QUOTES
        );
    }
}
