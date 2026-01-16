<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/activity_log.php';

session_start();

if (!empty($_SESSION['user'])) {
    activity_log_event('user_logout', 'User logged out');
}

session_unset();
session_destroy();
header('Location: index.php');
exit;
