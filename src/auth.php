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

// ==========================================
// Session idle timeout
// Timeout value is cached in session for 5 minutes to avoid a DB query per page load.
// ==========================================
$sessionTimeoutMinutes = $_SESSION['_timeout_minutes'] ?? 30;
$timeoutCachedAt = $_SESSION['_timeout_cached_at'] ?? 0;
if ((time() - $timeoutCachedAt) > 300) {
    // Refresh from DB every 5 minutes
    try {
        $__cfg = load_config();
        $__db = $__cfg['db_booking'] ?? null;
        if ($__db) {
            $__dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $__db['host'], $__db['port'], $__db['dbname'], $__db['charset'] ?? 'utf8mb4');
            $__pdo = new PDO($__dsn, $__db['username'], $__db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $__stmt = $__pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_minutes' LIMIT 1");
            $__stmt->execute();
            $toVal = $__stmt->fetchColumn();
            if ($toVal !== false && (int)$toVal > 0) {
                $sessionTimeoutMinutes = (int)$toVal;
            } elseif ($toVal === '0') {
                $sessionTimeoutMinutes = 0; // No timeout
            }
            unset($__pdo, $__stmt, $__dsn, $__db, $__cfg);
        }
    } catch (Throwable $e) {
        // DB not available yet — use default
    }
    $_SESSION['_timeout_minutes'] = $sessionTimeoutMinutes;
    $_SESSION['_timeout_cached_at'] = time();
}

if ($sessionTimeoutMinutes > 0 && !empty($_SESSION['last_activity'])) {
    $idleSeconds = time() - $_SESSION['last_activity'];
    if ($idleSeconds > ($sessionTimeoutMinutes * 60)) {
        session_destroy();
        $loginPath = defined('AUTH_LOGIN_PATH') ? AUTH_LOGIN_PATH : 'login';
        header('Location: ' . $loginPath . '?error=' . urlencode('Session expired due to inactivity. Please sign in again.'));
        exit;
    }
}
$_SESSION['last_activity'] = time();

// User is logged in – expose as $currentUser for the including script
$currentUser = $_SESSION['user'];

// ==========================================
// Periodic group re-validation (every 15 minutes)
// Catches group changes made in Snipe-IT mid-session
// ==========================================
$revalidateInterval = 2 * 60; // 2 minutes
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
    $_SESSION['user']['company']        = $perms['company'] ?? null;
    $_SESSION['user']['company_id']     = $perms['company']['id'] ?? null;
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
