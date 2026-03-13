<?php
/**
 * Serve inspection photos securely.
 * Validates authentication and reservation ownership before serving.
 *
 * @since v2.1.0
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/inspection_photos.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

$reservationId = (int)($_GET['reservation_id'] ?? 0);
$type = $_GET['type'] ?? '';
$filename = $_GET['file'] ?? '';

if (!$reservationId || !$filename) {
    http_response_code(400);
    echo 'Missing parameters.';
    exit;
}

// Verify reservation access: owner or staff
$stmt = $pdo->prepare("SELECT user_email FROM reservations WHERE id = ? LIMIT 1");
$stmt->execute([$reservationId]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$res) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

if (!$isStaff && ($res['user_email'] ?? '') !== ($currentUser['email'] ?? '')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

serve_inspection_photo($pdo, $reservationId, $type, $filename);
