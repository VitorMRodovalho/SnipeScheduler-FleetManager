<?php
/**
 * Email Notifications Admin
 * Configure who receives notifications for each event
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active = 'notifications.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isSuperAdmin = !empty($currentUser['is_super_admin']);

// Only Fleet Admin or Super Admin can access
if (!$isAdmin) {
    header('Location: dashboard');
    exit;
}

$success = '';
$error = '';

// Get current SMTP status
$smtpEnabled = false;
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_enabled'");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $smtpEnabled = $row['setting_value'] === '1';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_smtp') {
        $newStatus = isset($_POST['smtp_enabled']) ? '1' : '0';
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'smtp_enabled'");
        $stmt->execute([$newStatus]);
        $smtpEnabled = $newStatus === '1';
        $success = 'SMTP status updated.';
    }
    elseif ($action === 'save_notification') {
        $eventKey = $_POST['event_key'] ?? '';
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $notifyRequester = isset($_POST['notify_requester']) ? 1 : 0;
        $notifyStaff = isset($_POST['notify_staff']) ? 1 : 0;
        $notifyAdmin = isset($_POST['notify_admin']) ? 1 : 0;
        $customEmails = trim($_POST['custom_emails'] ?? '');
        $subjectTemplate = trim($_POST['subject_template'] ?? '');
        $bodyTemplate = trim($_POST['body_template'] ?? '');
        
        $userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
        
        $stmt = $pdo->prepare("
            UPDATE email_notification_settings 
            SET enabled = ?, notify_requester = ?, notify_staff = ?, notify_admin = ?,
                custom_emails = ?, subject_template = ?, body_template = ?, updated_by = ?
            WHERE event_key = ?
        ");
        $stmt->execute([
            $enabled, $notifyRequester, $notifyStaff, $notifyAdmin,
            $customEmails ?: null, $subjectTemplate ?: null, $bodyTemplate ?: null,
            $userName, $eventKey
        ]);
        
        $success = 'Notification settings saved for "' . htmlspecialchars($eventKey) . '".';
    }
    elseif ($action === 'test_email') {
        // Test email functionality
        $testEmail = trim($_POST['test_email'] ?? '');
        if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            require_once SRC_PATH . '/email_service.php';
            $emailService = get_email_service($pdo);
            
            // Check if SMTP is enabled
            if (!$smtpEnabled) {
                $error = 'SMTP is currently disabled. Enable it first to send test emails.';
            } else {
                try {
                    $result = $emailService->sendTestEmail($testEmail);
                    if ($result) {
                        $success = 'Test email sent to ' . htmlspecialchars($testEmail);
                    } else {
                        $error = 'Failed to send test email. Check SMTP configuration.';
                    }
                } catch (Exception $e) {
                    $error = 'Error sending test email: ' . htmlspecialchars($e->getMessage());
                }
            }
        } else {
            $error = 'Please enter a valid email address.';
        }
    }
}

// Get all notification settings
$stmt = $pdo->query("SELECT * FROM email_notification_settings ORDER BY id");
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get queued emails count
$stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending FROM email_queue");
$queueStats = $stmt->fetch(PDO::FETCH_ASSOC);

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

// Event descriptions for help text
$eventDescriptions = [
    'reservation_submitted' => 'Sent when a user submits a new vehicle reservation request.',
    'reservation_approved' => 'Sent when a reservation is approved by staff.',
    'reservation_rejected' => 'Sent when a reservation is rejected, includes reason.',
    'vehicle_checked_out' => 'Sent when a vehicle is checked out to the user.',
    'vehicle_checked_in' => 'Sent when a vehicle is returned/checked in.',
    'maintenance_flagged' => 'Sent when a vehicle is flagged for maintenance during check-in.',
    'pickup_reminder' => 'Sent 1 hour before scheduled pickup time.',
    'return_overdue' => 'Sent when a vehicle has not been returned by the expected time.',
];

// Default subjects for reference
$defaultSubjects = [
    'reservation_submitted' => '🚗 New Reservation Request - {vehicle}',
    'reservation_approved' => '✅ Reservation Approved - {vehicle}',
    'reservation_rejected' => '❌ Reservation Rejected - {vehicle}',
    'vehicle_checked_out' => '🔑 Vehicle Checked Out - {vehicle}',
    'vehicle_checked_in' => '✅ Vehicle Returned - {vehicle}',
    'maintenance_flagged' => '⚠️ Maintenance Required - {vehicle}',
    'pickup_reminder' => '⏰ Pickup Reminder - {vehicle}',
    'return_overdue' => '🚨 Overdue Return - {vehicle}',
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
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Notifications</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.3.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <style>
        .notification-card { transition: all 0.2s ease; }
        .notification-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .notification-card.disabled { opacity: 0.6; }
        .recipient-badge { font-size: 0.75rem; }
        .smtp-status { font-size: 1.5rem; }
    </style>
</head>
<body class="p-4">
    <div class="container">
        <div class="page-shell">
            <?= layout_logo_tag() ?>
            <div class="page-header">
                <h1><i class="bi bi-envelope me-2"></i>Email Notifications</h1>
                <p class="text-muted">Configure who receives notifications for each event</p>
            </div>
            
            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
            
            <ul class="nav nav-tabs reservations-subtabs mb-3">
                <li class="nav-item">
                    <a class="nav-link" href="vehicles">Vehicles</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users">Users</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="activity_log">Activity Log</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="notifications">Notifications</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="announcements">Announcements</a>
                </li>
                <?php if (!empty($currentUser['is_super_admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link" href="security">Security</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings">Settings</a>
                </li>
                <?php endif; ?>
            </ul>
            
            <div class="top-bar mb-3">
                <div class="top-bar-user">
                    <span class="text-muted">Logged in as:</span>
                    <strong><?= h($userName) ?></strong>
                    <span class="text-muted">(<?= h($currentUser['email'] ?? '') ?>)</span>
                    <?php if ($isAdmin): ?>
                        <span class="badge bg-danger ms-2">Admin</span>
                    <?php elseif ($isStaff): ?>
                        <span class="badge bg-primary ms-2">Staff</span>
                    <?php endif; ?>
                </div>
                <div class="top-bar-actions">
                    <a href="logout" class="btn btn-outline-secondary btn-sm">Log out</a>
                </div>
            </div>
            
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
            
            <!-- SMTP Status & Queue Stats -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">
                                        <i class="bi bi-router me-2"></i>SMTP Status
                                    </h5>
                                    <p class="text-muted small mb-0">
                                        <?php if ($smtpEnabled): ?>
                                            Emails will be sent immediately via SMTP
                                        <?php else: ?>
                                            Emails are being queued (SMTP disabled)
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="text-end">
                                    <span class="smtp-status">
                                        <?php if ($smtpEnabled): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php else: ?>
                                            <i class="bi bi-pause-circle-fill text-warning"></i>
                                        <?php endif; ?>
                                    </span>
                                    <form method="post" class="d-inline ms-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_smtp">
                                        <?php if ($smtpEnabled): ?>
                                            <button type="submit" class="btn btn-outline-warning btn-sm">
                                                <i class="bi bi-pause me-1"></i>Disable
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="smtp_enabled" value="1">
                                            <button type="submit" class="btn btn-outline-success btn-sm">
                                                <i class="bi bi-play me-1"></i>Enable
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h3 class="mb-0"><?= (int)($queueStats['pending'] ?? 0) ?></h3>
                            <small class="text-muted">Emails in Queue</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <form method="post" class="d-flex gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test_email">
                                <input type="email" name="test_email" class="form-control form-control-sm" 
                                    placeholder="test@email.com" required>
                                <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">
                                    <i class="bi bi-send"></i> Test
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notification Events -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-bell me-2"></i>Notification Events</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">On</th>
                                    <th>Event</th>
                                    <th class="text-center">Requester</th>
                                    <th class="text-center">Staff</th>
                                    <th class="text-center">Admin</th>
                                    <th>Custom Emails</th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notifications as $n): ?>
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
                                            <?php if ($n['notify_requester']): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            <?php else: ?>
                                                <i class="bi bi-dash text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($n['notify_staff']): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            <?php else: ?>
                                                <i class="bi bi-dash text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($n['notify_admin']): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            <?php else: ?>
                                                <i class="bi bi-dash text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($n['custom_emails']): ?>
                                                <span class="badge bg-info"><?= count(explode(',', $n['custom_emails'])) ?> custom</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
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
                        Use these placeholders in subject/body templates: 
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
    <div class="modal fade" id="editModal<?= $n['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_notification">
                    <input type="hidden" name="event_key" value="<?= h($n['event_key']) ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-pencil me-2"></i>Edit: <?= h($n['event_name']) ?>
                        </h5>
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
                        <h6>Recipients</h6>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                        name="notify_requester" id="requester<?= $n['id'] ?>" value="1"
                                        <?= $n['notify_requester'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="requester<?= $n['id'] ?>">
                                        <i class="bi bi-person me-1"></i>Requester
                                    </label>
                                    <div class="form-text">User who made the reservation</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                        name="notify_staff" id="staff<?= $n['id'] ?>" value="1"
                                        <?= $n['notify_staff'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="staff<?= $n['id'] ?>">
                                        <i class="bi bi-people me-1"></i>Fleet Staff
                                    </label>
                                    <div class="form-text">Staff members (Group 3)</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                        name="notify_admin" id="admin<?= $n['id'] ?>" value="1"
                                        <?= $n['notify_admin'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="admin<?= $n['id'] ?>">
                                        <i class="bi bi-shield me-1"></i>Fleet Admin
                                    </label>
                                    <div class="form-text">Administrators (Group 4)</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Custom Email Addresses</label>
                            <textarea name="custom_emails" class="form-control" rows="2"
                                placeholder="email1@example.com, email2@example.com"><?= h($n['custom_emails'] ?? '') ?></textarea>
                            <div class="form-text">Comma-separated list of additional recipients</div>
                        </div>
                        
                        <hr>
                        <h6>Email Template (Optional)</h6>
                        <p class="text-muted small">Leave blank to use default template</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Subject Line</label>
                            <input type="text" name="subject_template" class="form-control"
                                value="<?= h($n['subject_template'] ?? '') ?>"
                                placeholder="Default: <?= h($defaultSubjects[$n['event_key']] ?? '') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email Body</label>
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
                                <pre class="mb-0 small" style="white-space: pre-wrap; font-family: inherit;"><?= h($defaultBodies[$n['event_key']] ?? 'No default template') ?></pre>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Move modals to body to avoid z-index issues
    document.querySelectorAll('.modal').forEach(function(modal) {
        document.body.appendChild(modal);
    });
    </script>
    <?php layout_footer(); ?>
</body>
</html>
