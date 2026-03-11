<?php
require_once __DIR__ . '/bootstrap.php';

// auth.php
// Simple authentication guard used by all protected pages.

session_start();

$script = basename($_SERVER['PHP_SELF']);
$loginPath = defined('AUTH_LOGIN_PATH') ? AUTH_LOGIN_PATH : 'login';
$loginProcessPath = defined('AUTH_LOGIN_PROCESS_PATH') ? AUTH_LOGIN_PROCESS_PATH : 'login_process';

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
// Periodic group re-validation (every 15 minutes)
// Catches group changes made in Snipe-IT mid-session
// ==========================================
$revalidateInterval = 15 * 60; // 15 minutes
$lastCheck = $_SESSION['group_revalidated_at'] ?? 0;

if ((time() - $lastCheck) > $revalidateInterval) {
    require_once __DIR__ . '/snipeit_client.php';
    $perms = get_user_permissions_from_snipeit($currentUser['email'] ?? '');

    if (!$perms['exists'] || empty($perms['has_fleet_access'])) {
        session_destroy();
        $loginPath = defined('AUTH_LOGIN_PATH') ? AUTH_LOGIN_PATH : 'login';
        header('Location: ' . $loginPath . '?error=' . urlencode('Your fleet access has been removed. Please contact the Fleet Administrator.'));
        exit;
    }

    // Update session with latest permissions from Snipe-IT
    $_SESSION['user']['is_super_admin'] = $perms['is_super_admin'];
    $_SESSION['user']['is_admin']       = $perms['is_admin'];
    $_SESSION['user']['is_staff']       = $perms['is_staff'];
    $_SESSION['user']['is_vip']         = $perms['is_vip'];
    $_SESSION['group_revalidated_at']   = time();

    // Refresh $currentUser for this request
    $currentUser = $_SESSION['user'];
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
