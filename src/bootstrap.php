<?php
// src/bootstrap.php
// Sets up shared paths and config loader for the application.

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('SRC_PATH')) {
    define('SRC_PATH', APP_ROOT . '/src');
}

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', APP_ROOT . '/config');
}

require_once SRC_PATH . '/config_loader.php';

// Timezone is taken from config (app.timezone). Falls back to UTC rather than
// the former hardcoded America/New_York so deployments outside that zone do
// not silently render times in the wrong timezone.
$__bootstrapCfg = function_exists('load_config') ? @load_config() : null;
$__bootstrapTz  = is_array($__bootstrapCfg) ? ($__bootstrapCfg['app']['timezone'] ?? null) : null;
date_default_timezone_set(is_string($__bootstrapTz) && $__bootstrapTz !== '' ? $__bootstrapTz : 'UTC');
unset($__bootstrapCfg, $__bootstrapTz);

// Harden session cookies before session_start()
if (session_status() === PHP_SESSION_NONE) {
    $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true);
    session_set_cookie_params([
        'httponly'  => true,
        'secure'   => !$isLocalhost,
        'samesite' => 'Lax',
    ]);
}

require_once SRC_PATH . '/datetime_helpers.php';

// CSRF Protection
require_once __DIR__ . '/csrf.php';
