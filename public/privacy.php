<?php
/**
 * Privacy Notice — publicly accessible (no authentication required)
 *
 * @since v2.0.0
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/layout.php';

// Load config for theme styles (no auth needed)
$config = [];
try { $config = load_config(); } catch (Throwable $e) { $config = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Notice — Fleet Management System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.5.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell" style="max-width: 800px; margin: 0 auto;">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1><i class="bi bi-shield-lock me-2"></i>Privacy Notice</h1>
            <p class="text-muted">Fleet Management System</p>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5>1. What Data We Collect</h5>
                <p>This system collects and processes the following personal data in the course of fleet operations:</p>
                <ul>
                    <li><strong>Identity data:</strong> Full name, email address, and organizational affiliation (company/department)</li>
                    <li><strong>Vehicle usage data:</strong> Mileage readings, reservation dates and times, pickup/return locations, and destinations</li>
                    <li><strong>Inspection responses:</strong> Vehicle condition assessments completed during checkout and checkin</li>
                    <li><strong>Photos:</strong> Optional vehicle condition photographs captured during inspections</li>
                    <li><strong>Training records:</strong> Driver safety training completion dates and expiration status</li>
                    <li><strong>Login activity:</strong> Authentication timestamps, session data, and system actions</li>
                </ul>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5>2. Why We Collect It</h5>
                <p>Personal data is processed for the following legitimate business purposes:</p>
                <ul>
                    <li><strong>Fleet operations:</strong> Managing vehicle reservations, assignments, and returns</li>
                    <li><strong>Vehicle maintenance:</strong> Tracking vehicle condition, mileage, and service schedules</li>
                    <li><strong>Safety compliance:</strong> Ensuring drivers meet training requirements before operating vehicles</li>
                    <li><strong>Audit trail:</strong> Maintaining accountability for vehicle use, condition reporting, and operational decisions</li>
                </ul>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5>3. How Long We Keep It</h5>
                <p>Data retention periods are configured by your Fleet Administrator. Default retention:</p>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Data Type</th><th>Default Retention</th></tr></thead>
                        <tbody>
                            <tr><td>Activity logs</td><td>1 year (365 days)</td></tr>
                            <tr><td>Inspection photos</td><td>2 years (730 days)</td></tr>
                            <tr><td>Email/notification records</td><td>30 days</td></tr>
                            <tr><td>Reservation records</td><td>Indefinite (operational need)</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small">Automated purge jobs remove expired data on a weekly schedule. Your administrator may configure different retention periods.</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5>4. Who Has Access</h5>
                <ul>
                    <li><strong>Drivers</strong> can view only their own reservations, inspections, and activity</li>
                    <li><strong>Fleet Staff</strong> can view operational data for the vehicles they manage</li>
                    <li><strong>Fleet Admin and Super Admin</strong> have full system access for operational management</li>
                </ul>
                <p>Data is not sold to or shared with third parties. Access is controlled by role-based authentication tied to your organizational identity provider.</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5>5. Your Rights</h5>
                <ul>
                    <li><strong>Access:</strong> You can request a copy of all your personal data at any time using the <strong>"Download My Data"</strong> feature available from the My Reservations page</li>
                    <li><strong>Correction:</strong> Contact your Fleet Administrator to request correction of inaccurate personal data</li>
                    <li><strong>Deletion:</strong> Contact your Fleet Administrator to request deletion of your personal data, subject to legal and operational retention requirements</li>
                </ul>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5>6. Security</h5>
                <ul>
                    <li>All data is transmitted over encrypted connections (HTTPS)</li>
                    <li>Data is stored on access-controlled servers</li>
                    <li>Access is controlled by role-based authentication via SSO (Microsoft, Google, or LDAP)</li>
                    <li>Passwords are never stored in plaintext by this system</li>
                    <li>Session timeouts enforce automatic logout after periods of inactivity</li>
                    <li>Photo metadata (EXIF data including GPS coordinates) is stripped at upload</li>
                </ul>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5>7. Contact</h5>
                <p>For privacy concerns, data requests, or questions about this notice, contact your Fleet Administrator or system administrator.</p>
            </div>
        </div>

        <div class="text-center mt-4 mb-3">
            <a href="login" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Sign In
            </a>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
