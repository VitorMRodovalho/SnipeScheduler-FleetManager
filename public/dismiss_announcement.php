<?php
/**
 * Dismiss an announcement for the current user
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/announcements.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $announcementId = (int)($_POST['announcement_id'] ?? 0);
    $redirect = $_POST['redirect'] ?? 'dashboard.php';
    $userEmail = $currentUser['email'] ?? '';
    
    if ($announcementId > 0 && $userEmail) {
        dismiss_announcement($announcementId, $userEmail, $pdo);
    }
    
    // Sanitize redirect to prevent open redirect
    if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?=&]+$/', $redirect)) {
        $redirect = 'dashboard.php';
    }
    
    header('Location: ' . $redirect);
    exit;
}

header('Location: dashboard.php');
exit;
