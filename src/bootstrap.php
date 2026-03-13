<?php
// src/bootstrap.php
// Sets up shared paths and config loader for the application.

date_default_timezone_set('America/New_York');

// Harden session cookies before session_start()
if (session_status() === PHP_SESSION_NONE) {
    $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true);
    session_set_cookie_params([
        'httponly'  => true,
        'secure'   => !$isLocalhost,
        'samesite' => 'Lax',
    ]);
}

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
require_once SRC_PATH . '/datetime_helpers.php';

// CSRF Protection
require_once __DIR__ . '/csrf.php';
