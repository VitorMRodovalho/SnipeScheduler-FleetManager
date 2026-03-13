<?php
/**
 * API: Dismiss a missed reservation (mark as resolved)
 * POST only, staff/admin required.
 *
 * @since v2.1.0
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
csrf_check();
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$reservationId = (int)($_POST['reservation_id'] ?? 0);
if ($reservationId <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

$stmt = $pdo->prepare("
    UPDATE reservations
    SET missed_resolved = 1,
        missed_resolved_by = ?,
        missed_resolved_at = NOW()
    WHERE id = ? AND status = 'missed'
");
$stmt->execute([$userName, $reservationId]);

if ($stmt->rowCount() > 0) {
    activity_log_event('missed_resolved', 'Missed reservation dismissed/resolved', [
        'subject_type' => 'reservation',
        'subject_id'   => $reservationId,
    ]);
}

header('Location: /booking/dashboard');
exit;
