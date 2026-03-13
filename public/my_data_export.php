<?php
/**
 * My Data Export — CCPA/Privacy compliance
 * Allows authenticated users to download all personal data the system holds about them.
 *
 * @since v2.1.0
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

// CSRF check for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

$userId = (string)($currentUser['id'] ?? '');
$userEmail = strtolower(trim($currentUser['email'] ?? ''));
$format = $_POST['format'] ?? $_GET['format'] ?? 'json';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show a simple confirmation page
    require_once SRC_PATH . '/layout.php';
    $active = 'my_bookings';
    $isAdmin = !empty($currentUser['is_admin']);
    $isStaff = !empty($currentUser['is_staff']) || $isAdmin;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Download My Data</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <link rel="stylesheet" href="assets/style.css?v=1.5.1">
        <link rel="stylesheet" href="/booking/css/mobile.css">
        <?= layout_theme_styles() ?>
    </head>
    <body class="p-4">
    <div class="container">
        <div class="page-shell">
            <?= layout_logo_tag() ?>
            <div class="page-header">
                <h1>Download My Data</h1>
                <p class="text-muted">Export all personal data the system holds about you</p>
            </div>

            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
            <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-download me-2"></i>Your Data Export</h5>
                    <p>This export includes:</p>
                    <ul>
                        <li>Your user profile (name, email, training status, VIP status)</li>
                        <li>All your reservations (dates, vehicles, status)</li>
                        <li>Inspection responses submitted during checkout/checkin</li>
                        <li>Activity log entries for your actions</li>
                        <li>Notifications sent to you</li>
                    </ul>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="format" value="json">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-filetype-json me-2"></i>Download as JSON
                                </button>
                                <small class="text-muted d-block mt-1">Complete export — all data types</small>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="format" value="csv">
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-filetype-csv me-2"></i>Download Reservations as CSV
                                </button>
                                <small class="text-muted d-block mt-1">Reservations only — spreadsheet-friendly</small>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <a href="my_bookings" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to My Reservations
            </a>
        </div>
    </div>
    <?php layout_footer(); ?>
    </body>
    </html>
    <?php
    exit;
}

// ── POST: Generate and download the export ──

// Log the export action
activity_log_event('data_export', 'User exported their personal data', [
    'metadata' => ['format' => $format],
]);

// 1. User profile
$profile = [
    'name' => trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')),
    'email' => $currentUser['email'] ?? '',
    'is_vip' => !empty($currentUser['is_vip']),
    'company' => $currentUser['company']['name'] ?? null,
];

// Training status
$trainingStmt = $pdo->prepare("SELECT training_completed, training_date FROM users WHERE email = ? OR id = ? LIMIT 1");
$trainingStmt->execute([$userEmail, $userId]);
$trainingRow = $trainingStmt->fetch(PDO::FETCH_ASSOC);
if ($trainingRow) {
    $profile['training_completed'] = (bool)($trainingRow['training_completed'] ?? false);
    $profile['training_date'] = $trainingRow['training_date'] ?? null;
}

// 2. Reservations
$resStmt = $pdo->prepare("
    SELECT id, asset_id, asset_name_cache, status, approval_status,
           start_datetime, end_datetime, user_name, notes,
           checkout_form_data, checkin_form_data,
           company_name, company_abbr,
           created_at, updated_at
    FROM reservations
    WHERE user_id = :uid OR LOWER(user_email) = :email
    ORDER BY id DESC
");
$resStmt->execute([':uid' => $userId, ':email' => $userEmail]);
$reservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Inspection responses (from checkout/checkin form data)
$inspections = [];
foreach ($reservations as $res) {
    $entry = ['reservation_id' => $res['id']];
    if (!empty($res['checkout_form_data'])) {
        $entry['checkout'] = json_decode($res['checkout_form_data'], true);
    }
    if (!empty($res['checkin_form_data'])) {
        $entry['checkin'] = json_decode($res['checkin_form_data'], true);
    }
    if (count($entry) > 1) {
        $inspections[] = $entry;
    }
}

// 4. Activity log entries
$actStmt = $pdo->prepare("
    SELECT event_type, message, created_at
    FROM activity_log
    WHERE actor_email = :email OR actor_id = :uid
    ORDER BY created_at DESC
");
$actStmt->execute([':email' => $userEmail, ':uid' => $userId]);
$activityLog = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Notifications sent to them
$notifData = [];
try {
    $notifStmt = $pdo->prepare("
        SELECT subject, status, created_at, sent_at
        FROM email_queue
        WHERE recipient_email = :email
        ORDER BY created_at DESC
    ");
    $notifStmt->execute([':email' => $userEmail]);
    $notifData = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // email_queue may not have recipient_email column — skip gracefully
}

// ── Format and download ──
$dateStr = date('Y-m-d');

if ($format === 'csv') {
    // CSV: Reservations only
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"my_fleet_data_{$dateStr}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Vehicle', 'Company', 'Status', 'Approval', 'Start', 'End', 'Purpose', 'Created']);
    foreach ($reservations as $r) {
        fputcsv($out, [
            $r['id'],
            $r['asset_name_cache'] ?? '',
            $r['company_name'] ?? '',
            $r['status'] ?? '',
            $r['approval_status'] ?? '',
            $r['start_datetime'] ?? '',
            $r['end_datetime'] ?? '',
            $r['notes'] ?? '',
            $r['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// JSON: Full export
$export = [
    'export_date' => date('c'),
    'system' => 'SnipeScheduler FleetManager',
    'profile' => $profile,
    'reservations' => array_map(function ($r) {
        // Strip raw form data blobs from reservation list (included in inspections)
        unset($r['checkout_form_data'], $r['checkin_form_data']);
        return $r;
    }, $reservations),
    'inspections' => $inspections,
    'activity_log' => $activityLog,
    'notifications' => $notifData,
];

header('Content-Type: application/json; charset=utf-8');
header("Content-Disposition: attachment; filename=\"my_fleet_data_{$dateStr}.json\"");
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
