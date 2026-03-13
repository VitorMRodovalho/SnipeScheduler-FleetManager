<?php
/**
 * Notifications Admin — Email + Microsoft Teams
 * Configure channels and recipients for each event
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/notification_service.php';
require_once SRC_PATH . '/auth.php';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active = 'activity_log';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isSuperAdmin = !empty($currentUser['is_super_admin']);

if (!$isAdmin) {
    header('Location: dashboard');
    exit;
}

$success = '';
$error = '';

// Load SMTP status
$smtpEnabled = false;
$row = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_enabled'")->fetch(PDO::FETCH_ASSOC);
if ($row) $smtpEnabled = $row['setting_value'] === '1';

// Load Teams settings
$teamsSettings = [];
$rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'teams_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$teamsEnabled        = ($rows['teams_webhook_enabled']       ?? '0') === '1';
$teamsUrlFleetOps    = $rows['teams_webhook_url_fleet_ops']  ?? '';
$teamsUrlAdmin       = $rows['teams_webhook_url_admin']      ?? '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_smtp') {
        $newStatus = isset($_POST['smtp_enabled']) ? '1' : '0';
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'smtp_enabled'")->execute([$newStatus]);
        $smtpEnabled = $newStatus === '1';
        $success = 'SMTP status updated.';
    }
    elseif ($action === 'save_teams_config') {
        $newEnabled  = isset($_POST['teams_webhook_enabled']) ? '1' : '0';
        $newFleetOps = trim($_POST['teams_webhook_url_fleet_ops'] ?? '');
        $newAdmin    = trim($_POST['teams_webhook_url_admin']     ?? '');

        if ((!empty($newFleetOps) && !filter_var($newFleetOps, FILTER_VALIDATE_URL)) ||
            (!empty($newAdmin)    && !filter_var($newAdmin,    FILTER_VALIDATE_URL))) {
            $error = 'Invalid webhook URL format.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->execute(['teams_webhook_enabled',       $newEnabled]);
            $stmt->execute(['teams_webhook_url_fleet_ops', $newFleetOps]);
            $stmt->execute(['teams_webhook_url_admin',     $newAdmin]);
            $teamsEnabled     = $newEnabled === '1';
            $teamsUrlFleetOps = $newFleetOps;
            $teamsUrlAdmin    = $newAdmin;
            $success = 'Teams configuration saved.';
        }
    }
    elseif ($action === 'save_notification') {
        $eventKey        = $_POST['event_key']        ?? '';
        $enabled         = isset($_POST['enabled'])          ? 1 : 0;
        $notifyRequester = isset($_POST['notify_requester']) ? 1 : 0;
        $notifyStaff     = isset($_POST['notify_staff'])     ? 1 : 0;
        $notifyAdmin     = isset($_POST['notify_admin'])     ? 1 : 0;
        $customEmails    = trim($_POST['custom_emails']      ?? '');
        $subjectTemplate = trim($_POST['subject_template']   ?? '');
        $bodyTemplate    = trim($_POST['body_template']      ?? '');
        $channel         = $_POST['channel'] ?? 'email';
        if (!in_array($channel, ['email','teams','both','none'])) $channel = 'email';

        $userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

        $pdo->prepare("
            UPDATE email_notification_settings
            SET enabled=?, notify_requester=?, notify_staff=?, notify_admin=?,
                custom_emails=?, subject_template=?, body_template=?, channel=?, updated_by=?
            WHERE event_key=?
        ")->execute([
            $enabled, $notifyRequester, $notifyStaff, $notifyAdmin,
            $customEmails ?: null, $subjectTemplate ?: null, $bodyTemplate ?: null,
            $channel, $userName, $eventKey
        ]);
        $success = 'Notification settings saved for "' . htmlspecialchars($eventKey) . '".';
    }
    elseif ($action === 'test_email') {
        $testEmail = trim($_POST['test_email'] ?? '');
        if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            require_once SRC_PATH . '/email_service.php';
            $emailService = get_email_service($pdo);
            if (!$smtpEnabled) {
                $error = 'SMTP is currently disabled. Enable it first.';
            } else {
                try {
                    $result = $emailService->sendTestEmail($testEmail);
                    $success = $result ? 'Test email sent to ' . htmlspecialchars($testEmail) : 'Failed to send test email.';
                    if (!$result) $error = $success;
                } catch (Exception $e) {
                    $error = 'Error: ' . htmlspecialchars($e->getMessage());
                }
            }
        } else {
            $error = 'Please enter a valid email address.';
        }
    }
}

// Load notification settings
$notifications = $pdo->query("SELECT * FROM email_notification_settings ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Queue stats — split by channel
$queueStats = $pdo->query("
    SELECT
        SUM(CASE WHEN status='pending' AND channel='email' THEN 1 ELSE 0 END) as email_pending,
        SUM(CASE WHEN status='pending' AND channel='teams' THEN 1 ELSE 0 END) as teams_pending
    FROM email_queue
")->fetch(PDO::FETCH_ASSOC);

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

$eventDescriptions = [
    'reservation_submitted'       => 'Sent when a user submits a new vehicle reservation request.',
    'reservation_approved'        => 'Sent when a reservation is approved by staff.',
    'reservation_rejected'        => 'Sent when a reservation is rejected, includes reason.',
    'vehicle_checked_out'         => 'Sent when a vehicle is checked out to the user.',
    'vehicle_checked_in'          => 'Sent when a vehicle is returned/checked in.',
    'maintenance_flagged'         => 'Sent when a vehicle is flagged for maintenance during check-in.',
    'pickup_reminder'             => 'Sent 1 hour before scheduled pickup time.',
    'return_overdue'              => 'Sent when a vehicle has not been returned by the expected time.',
    'reservation_cancelled'       => 'Sent when a reservation is cancelled by the user or staff.',
    'mileage_anomaly'             => 'Sent to admin when a mileage entry fails plausibility checks.',
    'compliance_expiring'         => 'Sent when vehicle insurance or registration is expiring within 30 days.',
    'reservation_redirected'      => 'Sent when a reservation is redirected to an alternate vehicle.',
    'reservation_redirect_failed' => 'Sent when a reservation is cancelled because no alternate vehicle is available.',
    'overdue_redirect_staff'      => 'Sent to staff/admin when an overdue vehicle triggers a reservation redirect.',
    'training_expiring'           => 'Weekly digest of drivers whose training certifications are expiring or expired.',
    'force_checkin'               => 'Sent when staff force-checks-in a vehicle (e.g. overdue driver unavailable).',
    'reservation_missed_driver'   => 'Sent to the driver when their reservation is marked as missed (no-show). Includes key urgency if key was collected.',
    'reservation_missed_staff'    => 'Sent to staff/admin when a reservation is marked as missed. Highlights key recovery urgency.',
];

$defaultSubjects = [
    'reservation_submitted'       => 'New Reservation Request - {vehicle}',
    'reservation_approved'        => 'Reservation Approved - {vehicle}',
    'reservation_rejected'        => 'Reservation Rejected - {vehicle}',
    'vehicle_checked_out'         => 'Vehicle Checked Out - {vehicle}',
    'vehicle_checked_in'          => 'Vehicle Returned - {vehicle}',
    'maintenance_flagged'         => 'Maintenance Required - {vehicle}',
    'pickup_reminder'             => 'Pickup Reminder - {vehicle}',
    'return_overdue'              => 'Overdue Return - {vehicle}',
    'reservation_cancelled'       => 'Reservation Cancelled - {vehicle}',
    'mileage_anomaly'             => 'Mileage Anomaly Detected - {vehicle}',
    'compliance_expiring'         => 'Compliance Expiring - {vehicle}',
    'reservation_redirected'      => 'Reservation Redirected - {vehicle}',
    'reservation_redirect_failed' => 'Reservation Cancelled (No Alternate) - {vehicle}',
    'overdue_redirect_staff'      => 'Overdue Vehicle Redirect Alert - {vehicle}',
    'training_expiring'           => 'Driver Training Alert - {count} driver(s) need attention',
    'force_checkin'               => 'Force Check-In - {vehicle}',
    'reservation_missed_driver'   => 'Missed Pickup - {vehicle}',
    'reservation_missed_staff'    => 'Missed Reservation Alert - {vehicle}',
];

$defaultBodies = [
    'reservation_submitted' => 'Hi {user},

Your reservation request has been submitted and is pending approval.

Vehicle: {vehicle}
Pickup: {date} at {time}
Return: {return_date} at {return_time}
Purpose: {purpose}

You will receive an email once your request is reviewed.

Thank you,
Fleet Management Team',

    'reservation_approved' => 'Hi {user},

Great news! Your reservation has been approved by {approver}.

Vehicle: {vehicle}
Pickup: {date} at {time}
Return: {return_date} at {return_time}
Location: {location}

Please arrive on time for your pickup. Remember to complete the checkout inspection.

Thank you,
Fleet Management Team',

    'reservation_rejected' => 'Hi {user},

Unfortunately, your reservation request has been declined.

Vehicle: {vehicle}
Requested: {date} at {time}
Reason: {reason}

If you have questions, please contact the Fleet Administrator.

Thank you,
Fleet Management Team',

    'vehicle_checked_out' => 'Hi {user},

You have successfully checked out the vehicle.

Vehicle: {vehicle}
Mileage: {mileage}
Expected Return: {return_date} at {return_time}

Please return the vehicle on time and complete the check-in inspection.

Drive safely!
Fleet Management Team',

    'vehicle_checked_in' => 'Hi {user},

Thank you for returning the vehicle.

Vehicle: {vehicle}
Mileage: {mileage}
Return Time: {date} at {time}

Your reservation is now complete.

Thank you,
Fleet Management Team',

    'maintenance_flagged' => 'ATTENTION: Maintenance Required

Vehicle: {vehicle}
Flagged by: {user}
Issue: {notes}

Please schedule maintenance as soon as possible.

Fleet Management System',

    'pickup_reminder' => 'Hi {user},

Reminder: Your vehicle reservation is coming up in 1 hour.

Vehicle: {vehicle}
Pickup: {date} at {time}
Location: {location}

Please arrive on time to complete the checkout inspection.

Thank you,
Fleet Management Team',

    'return_overdue' => 'Hi {user},

OVERDUE NOTICE: Your vehicle was due to be returned.

Vehicle: {vehicle}
Expected Return: {date} at {time}

Please return the vehicle as soon as possible and complete the check-in inspection.

If you need to extend your reservation, please contact Fleet Management immediately.

Thank you,
Fleet Management Team',

    'reservation_cancelled' => 'Hi {user},

Your reservation has been cancelled.

Vehicle: {vehicle}
Pickup: {date} at {time}

If this was unexpected, please contact the Fleet Administrator.

Thank you,
Fleet Management Team',

    'mileage_anomaly' => 'Mileage anomaly detected on a vehicle.

Vehicle: {vehicle}
Reported by: {user}

Please review the mileage entry in the Usage Report.

Fleet Management System',

    'compliance_expiring' => 'A vehicle compliance document is expiring soon.

Vehicle: {vehicle}

Please ensure the document is renewed before it expires.

Fleet Management System',

    'reservation_redirected' => 'Hi {user},

Your upcoming reservation has been redirected to a different vehicle because the originally assigned vehicle is unavailable.

New Vehicle: {vehicle}
Pickup: {date} at {time}
Return: {return_date} at {return_time}

Your reservation times remain the same.

Thank you,
Fleet Management Team',

    'reservation_redirect_failed' => 'Hi {user},

Unfortunately, your reservation has been cancelled because the assigned vehicle is unavailable and no alternate vehicle could be found.

Vehicle: {vehicle}
Pickup: {date} at {time}

Please submit a new reservation request at your earliest convenience.

Thank you,
Fleet Management Team',

    'overdue_redirect_staff' => 'An overdue vehicle has triggered an automatic reservation action.

Vehicle: {vehicle}
Assigned to: {user}

Please follow up to ensure the vehicle is returned promptly.

Fleet Management System',

    'training_expiring' => 'Driver training certifications need attention.

{count} driver(s) require action regarding their training status.

Please review the driver list and ensure all certifications are up to date.

Fleet Management System',

    'force_checkin' => 'A vehicle has been force-checked-in by staff.

Vehicle: {vehicle}

Please review the vehicle status and any outstanding reservations.

Fleet Management System',

    'reservation_missed_driver' => 'Hi {user},

Your scheduled vehicle pickup has been marked as missed.

Vehicle: {vehicle}
Scheduled Pickup: {date} at {time}

If you still need the vehicle, please contact the Fleet office immediately.

If a vehicle key was already collected, please return it as soon as possible.

Thank you,
Fleet Management Team',

    'reservation_missed_staff' => 'A reservation has been marked as missed (no-show).

Vehicle: {vehicle}
Driver: {user}
Scheduled Pickup: {date} at {time}

Please verify whether the driver collected a key and follow up accordingly. The vehicle has been released back to the available pool.

Fleet Management System',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.5.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <style>
        .notification-card { transition: all 0.2s ease; }
        .notification-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .notification-card.disabled { opacity: 0.6; }
        .smtp-status { font-size: 1.5rem; }
        .channel-badge-email  { background-color: #0d6efd; }
        .channel-badge-teams  { background-color: #6264a7; }
        .channel-badge-both   { background-color: #198754; }
        .channel-badge-none   { background-color: #6c757d; }
    </style>
</head>
<body class="p-4">
    <div class="container">
        <div class="page-shell">
            <?= layout_logo_tag() ?>
            <div class="page-header">
                <h1><i class="bi bi-bell me-2"></i>Notifications</h1>
                <p class="text-muted">Configure email and Microsoft Teams notifications for each event</p>
            </div>

            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
            <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

            <ul class="nav nav-tabs reservations-subtabs mb-3">
                <li class="nav-item"><a class="nav-link" href="vehicles">Vehicles</a></li>
                <li class="nav-item"><a class="nav-link" href="users">Users</a></li>
                <li class="nav-item"><a class="nav-link" href="activity_log">Activity Log</a></li>
                <li class="nav-item"><a class="nav-link active" href="notifications">Notifications</a></li>
                <li class="nav-item"><a class="nav-link" href="announcements">Announcements</a></li>
                <?php if (!empty($currentUser['is_super_admin'])): ?>
                <li class="nav-item"><a class="nav-link" href="booking_rules">Booking Rules</a></li>
                <li class="nav-item"><a class="nav-link" href="security">Security</a></li>
                <li class="nav-item"><a class="nav-link" href="settings">Settings</a></li>
                <?php endif; ?>
            </ul>


            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Channel Status Cards -->
            <div class="row mb-4">
                <!-- SMTP -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1"><i class="bi bi-router me-2"></i>SMTP / Email</h5>
                                    <p class="text-muted small mb-0">
                                        <?= $smtpEnabled ? 'Emails sent via SMTP' : 'Emails queued (SMTP disabled)' ?>
                                    </p>
                                </div>
                                <div class="text-end">
                                    <span class="smtp-status">
                                        <i class="bi <?= $smtpEnabled ? 'bi-check-circle-fill text-success' : 'bi-pause-circle-fill text-warning' ?>"></i>
                                    </span>
                                    <form method="post" class="d-inline ms-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_smtp">
                                        <?php if ($smtpEnabled): ?>
                                            <button type="submit" class="btn btn-outline-warning btn-sm"><i class="bi bi-pause me-1"></i>Disable</button>
                                        <?php else: ?>
                                            <input type="hidden" name="smtp_enabled" value="1">
                                            <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-play me-1"></i>Enable</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Teams -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="card-title mb-1"><i class="bi bi-microsoft-teams me-2"></i>Microsoft Teams</h5>
                                    <p class="text-muted small mb-0">
                                        <?= $teamsEnabled ? 'Cards delivered via Power Automate webhook' : 'Teams notifications disabled' ?>
                                    </p>
                                </div>
                                <span class="smtp-status">
                                    <i class="bi <?= $teamsEnabled ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-secondary' ?>"></i>
                                </span>
                            </div>
                            <button class="btn btn-outline-secondary btn-sm mt-2"
                                    type="button" data-bs-toggle="collapse" data-bs-target="#teamsConfigPanel">
                                <i class="bi bi-gear me-1"></i>Configure
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Queue Stats -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title mb-2"><i class="bi bi-stack me-2"></i>Queue</h5>
                            <div class="d-flex gap-3">
                                <div class="text-center">
                                    <div class="fw-bold fs-4"><?= (int)($queueStats['email_pending'] ?? 0) ?></div>
                                    <small class="text-muted">Email pending</small>
                                </div>
                                <div class="text-center">
                                    <div class="fw-bold fs-4" style="color:#6264a7"><?= (int)($queueStats['teams_pending'] ?? 0) ?></div>
                                    <small class="text-muted">Teams pending</small>
                                </div>
                            </div>
                            <div class="mt-2">
                                <form method="post" class="d-flex gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="test_email">
                                    <input type="email" name="test_email" class="form-control form-control-sm" placeholder="test@email.com" required>
                                    <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-send"></i> Test</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Teams Configuration Panel (collapsible) -->
            <div class="collapse mb-4" id="teamsConfigPanel">
                <div class="card border-0 shadow-sm">
                    <div class="card-header" style="background-color:#6264a7; color:white;">
                        <strong><i class="bi bi-microsoft-teams me-2"></i>Teams Webhook Configuration</strong>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Paste an <strong>Incoming Webhook</strong> or <strong>Power Automate HTTP trigger</strong> URL for each channel.
                        </p>
                        <form method="post" id="teamsConfigForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_teams_config">
                            <div class="row g-3 mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="teams_webhook_enabled"
                                               id="teams_webhook_enabled" value="1"
                                               <?= $teamsEnabled ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold" for="teams_webhook_enabled">
                                            Enable Teams Notifications
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Fleet Ops Channel URL
                                        <span class="fw-normal text-muted">(checkout, check-in, reservations)</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="url" class="form-control form-control-sm font-monospace"
                                               name="teams_webhook_url_fleet_ops" id="teams_url_fleet_ops"
                                               value="<?= h($teamsUrlFleetOps) ?>"
                                               placeholder="https://...">
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                onclick="testTeamsWebhook('fleet_ops')">Test</button>
                                    </div>
                                    <div id="test-result-fleet_ops" class="mt-1 small"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Admin Channel URL
                                        <span class="fw-normal text-muted">(anomalies, compliance, alerts)</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="url" class="form-control form-control-sm font-monospace"
                                               name="teams_webhook_url_admin" id="teams_url_admin"
                                               value="<?= h($teamsUrlAdmin) ?>"
                                               placeholder="https://...">
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                onclick="testTeamsWebhook('admin')">Test</button>
                                    </div>
                                    <div id="test-result-admin" class="mt-1 small"></div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-floppy me-1"></i>Save Teams Configuration
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Notification Events Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-bell me-2"></i>Notification Events</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width:40px;">On</th>
                                    <th>Event</th>
                                    <th class="text-center">Requester</th>
                                    <th class="text-center">Staff</th>
                                    <th class="text-center">Admin</th>
                                    <th>Custom Emails</th>
                                    <th style="width:120px;">Channel</th>
                                    <th style="width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notifications as $n): ?>
                                    <?php $channel = $n['channel'] ?? 'email'; ?>
                                    <tr class="<?= $n['enabled'] ? '' : 'table-secondary' ?>">
                                        <td>
                                            <form method="post" class="d-inline toggle-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="save_notification">
                                                <input type="hidden" name="event_key" value="<?= h($n['event_key']) ?>">
                                                <input type="hidden" name="notify_requester" value="<?= $n['notify_requester'] ?>">
                                                <input type="hidden" name="notify_staff" value="<?= $n['notify_staff'] ?>">
                                                <input type="hidden" name="notify_admin" value="<?= $n['notify_admin'] ?>">
                                                <input type="hidden" name="custom_emails" value="<?= h($n['custom_emails'] ?? '') ?>">
                                                <input type="hidden" name="channel" value="<?= h($channel) ?>">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox"
                                                        name="enabled" value="1"
                                                        <?= $n['enabled'] ? 'checked' : '' ?>
                                                        onchange="this.form.submit()">
                                                </div>
                                            </form>
                                        </td>
                                        <td>
                                            <strong><?= h($n['event_name']) ?></strong>
                                            <br><small class="text-muted"><?= h($eventDescriptions[$n['event_key']] ?? '') ?></small>
                                        </td>
                                        <td class="text-center">
                                            <i class="bi <?= $n['notify_requester'] ? 'bi-check-circle-fill text-success' : 'bi-dash text-muted' ?>"></i>
                                        </td>
                                        <td class="text-center">
                                            <i class="bi <?= $n['notify_staff'] ? 'bi-check-circle-fill text-success' : 'bi-dash text-muted' ?>"></i>
                                        </td>
                                        <td class="text-center">
                                            <i class="bi <?= $n['notify_admin'] ? 'bi-check-circle-fill text-success' : 'bi-dash text-muted' ?>"></i>
                                        </td>
                                        <td>
                                            <?php if ($n['custom_emails']): ?>
                                                <span class="badge bg-info"><?= count(explode(',', $n['custom_emails'])) ?> custom</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $badgeClass = [
                                                'email' => 'channel-badge-email',
                                                'teams' => 'channel-badge-teams',
                                                'both'  => 'channel-badge-both',
                                                'none'  => 'channel-badge-none',
                                            ][$channel] ?? 'channel-badge-email';
                                            $badgeLabel = [
                                                'email' => 'Email',
                                                'teams' => 'Teams',
                                                'both'  => 'Both',
                                                'none'  => 'Off',
                                            ][$channel] ?? 'Email';
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                data-bs-toggle="modal" data-bs-target="#editModal<?= $n['id'] ?>">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="card mt-3">
                <div class="card-body">
                    <h6 class="mb-2"><i class="bi bi-info-circle me-1"></i>Template Variables</h6>
                    <p class="small text-muted mb-0">
                        Use these placeholders in subject/body templates (shared between email and Teams cards):
                        <code>{vehicle}</code> - Vehicle name,
                        <code>{user}</code> - Requester name,
                        <code>{date}</code> - Reservation date,
                        <code>{time}</code> - Reservation time,
                        <code>{approver}</code> - Approver name,
                        <code>{reason}</code> - Rejection reason
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modals -->
    <?php foreach ($notifications as $n): ?>
    <?php $channel = $n['channel'] ?? 'email'; ?>
    <div class="modal fade" id="editModal<?= $n['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_notification">
                    <input type="hidden" name="event_key" value="<?= h($n['event_key']) ?>">

                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit: <?= h($n['event_name']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                        name="enabled" id="enabled<?= $n['id'] ?>" value="1"
                                        <?= $n['enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="enabled<?= $n['id'] ?>">
                                        <strong>Enable this notification</strong>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6>Recipients (Email)</h6>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="notify_requester" id="requester<?= $n['id'] ?>" value="1" <?= $n['notify_requester'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="requester<?= $n['id'] ?>"><i class="bi bi-person me-1"></i>Requester</label>
                                    <div class="form-text">User who made the reservation</div>
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="notify_staff" id="staff<?= $n['id'] ?>" value="1" <?= $n['notify_staff'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="staff<?= $n['id'] ?>"><i class="bi bi-people me-1"></i>Fleet Staff</label>
                                    <div class="form-text">Staff members (Group 3)</div>
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="notify_admin" id="admin<?= $n['id'] ?>" value="1" <?= $n['notify_admin'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="admin<?= $n['id'] ?>"><i class="bi bi-shield me-1"></i>Fleet Admin</label>
                                    <div class="form-text">Administrators (Group 4)</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Delivery Channel</h6>
                                <select name="channel" class="form-select form-select-sm">
                                    <option value="email" <?= $channel === 'email' ? 'selected' : '' ?>>Email only</option>
                                    <option value="teams" <?= $channel === 'teams' ? 'selected' : '' ?>>Teams only</option>
                                    <option value="both"  <?= $channel === 'both'  ? 'selected' : '' ?>>Both</option>
                                    <option value="none"  <?= $channel === 'none'  ? 'selected' : '' ?>>Disabled</option>
                                </select>
                                <div class="form-text">Controls whether this event sends email, a Teams card, or both.</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Custom Email Addresses</label>
                            <textarea name="custom_emails" class="form-control" rows="2"
                                placeholder="email1@example.com, email2@example.com"><?= h($n['custom_emails'] ?? '') ?></textarea>
                            <div class="form-text">Comma-separated additional recipients (email channel only)</div>
                        </div>

                        <hr>
                        <h6>Message Template <span class="fw-normal text-muted">(shared between email and Teams)</span></h6>
                        <p class="text-muted small">Leave blank to use the default template</p>

                        <div class="mb-3">
                            <label class="form-label">Subject / Card Title</label>
                            <input type="text" name="subject_template" class="form-control"
                                value="<?= h($n['subject_template'] ?? '') ?>"
                                placeholder="Default: <?= h($defaultSubjects[$n['event_key']] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Body</label>
                            <textarea name="body_template" class="form-control" rows="8"
                                placeholder="Leave blank to use default template..."><?= h($n['body_template'] ?? '') ?></textarea>
                        </div>

                        <div class="card bg-light">
                            <div class="card-header py-2">
                                <small class="fw-bold"><i class="bi bi-eye me-1"></i>Default Template Preview</small>
                            </div>
                            <div class="card-body py-2">
                                <small><strong>Subject:</strong> <?= h($defaultSubjects[$n['event_key']] ?? '') ?></small>
                                <hr class="my-2">
                                <pre class="mb-0 small" style="white-space:pre-wrap;font-family:inherit;"><?= h($defaultBodies[$n['event_key']] ?? 'No default template') ?></pre>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.querySelectorAll('.modal').forEach(function(m) { document.body.appendChild(m); });

    function testTeamsWebhook(audience) {
        var urlInput = audience === 'admin'
            ? document.getElementById('teams_url_admin')
            : document.getElementById('teams_url_fleet_ops');
        var resultEl = document.getElementById('test-result-' + audience);

        if (!urlInput.value.trim()) {
            resultEl.textContent = 'Please enter a URL first.';
            resultEl.className = 'mt-1 small text-warning';
            return;
        }

        resultEl.textContent = 'Sending test card...';
        resultEl.className = 'mt-1 small text-muted';

        fetch('/booking/api/test_teams_webhook', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                audience: audience,
                csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                resultEl.textContent = 'Test card sent — check Teams.';
                resultEl.className = 'mt-1 small text-success';
            } else {
                resultEl.textContent = 'Failed: ' + (data.error || 'Unknown error');
                resultEl.className = 'mt-1 small text-danger';
            }
        })
        .catch(function() {
            resultEl.textContent = 'Request failed. Check browser console.';
            resultEl.className = 'mt-1 small text-danger';
        });
    }
    </script>
    <?php layout_footer(); ?>
</body>
</html>
