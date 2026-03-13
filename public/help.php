<?php
/**
 * Help & User Guide
 * Role-filtered, collapsible reference for all fleet management workflows.
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';

$active = 'help';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isSuperAdmin = !empty($currentUser['is_superadmin']);
$isFleetAdmin = $isAdmin; // Fleet Admin = Admin level

// Build tabs based on role
$tabs = [];

// All users
$tabs['booking'] = 'Booking';
$tabs['checkout'] = 'Checkout & Checkin';
$tabs['my_reservations'] = 'My Reservations';
$tabs['faq'] = 'FAQ';

// Staff+
if ($isStaff) {
    $tabs['approvals'] = 'Approvals';
    $tabs['maintenance'] = 'Maintenance';
    $tabs['reports'] = 'Reports';
    $tabs['training'] = 'Training Management';
}

// Admin+
if ($isAdmin) {
    $tabs['vehicles'] = 'Vehicle Management';
    $tabs['users'] = 'User Management';
    $tabs['booking_rules'] = 'Booking Rules';
    $tabs['checklists'] = 'Checklist Management';
    $tabs['notifications'] = 'Notifications';
    $tabs['announcements'] = 'Announcements';
    $tabs['multi_entity'] = 'Multi-Entity Fleet';
}

// Super Admin
if ($isSuperAdmin) {
    $tabs['system_settings'] = 'System Settings';
    $tabs['security'] = 'Security';
    $tabs['compliance'] = 'Data Compliance';
    $tabs['deployment'] = 'Deployment';
}

$activeTab = $_GET['tab'] ?? array_key_first($tabs);
if (!isset($tabs[$activeTab])) {
    $activeTab = array_key_first($tabs);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Help & User Guide</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.5.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <style>
        .help-search { max-width: 400px; }
        .accordion-button:not(.collapsed) { background: rgba(var(--primary-rgb), 0.08); }
        .help-section.hidden { display: none; }
        .help-tab-content { min-height: 300px; }
        .page-loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,.85);z-index:9999;display:flex;align-items:center;justify-content:center;}
    </style>
</head>
<body class="p-4">
<div class="page-loading-overlay"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1><i class="bi bi-question-circle me-2"></i>Help & User Guide</h1>
            <p class="text-muted">Reference guide for all fleet management workflows</p>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <!-- Search -->
        <div class="mb-4">
            <div class="input-group help-search">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autocomplete="off">
                <button type="button" class="btn btn-outline-secondary" id="helpSearchClear" style="display:none;">&times;</button>
            </div>
        </div>

        <!-- Tab Navigation -->
        <ul class="nav nav-pills mb-4 flex-wrap" id="helpTabs" role="tablist">
            <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" href="?tab=<?= $key ?>"><?= htmlspecialchars($label) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>

        <!-- Tab Content -->
        <div class="help-tab-content">

<?php if ($activeTab === 'booking'): ?>
<!-- BOOKING TAB -->
<div class="accordion" id="accBooking">
    <?php help_section('accBooking', 'book1', 'How to Reserve a Vehicle', '
        <ol>
            <li>Navigate to <strong>Book Vehicle</strong> from the main menu.</li>
            <li>Select your desired <strong>date range</strong> using the calendar picker. Only business days are available unless holidays are configured.</li>
            <li>Choose a <strong>pickup location</strong> — only vehicles at that location will be shown.</li>
            <li>Browse available vehicles. Each card shows the vehicle name, tag, status, and company badge (if multi-entity is enabled).</li>
            <li>Click <strong>Reserve</strong> on your chosen vehicle.</li>
            <li>Add an optional <strong>purpose/notes</strong> for the trip.</li>
            <li>Submit the reservation. You will receive a confirmation and the request enters the approval queue.</li>
        </ol>
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>VIP users are auto-approved and skip the queue.</div>
    '); ?>
    <?php help_section('accBooking', 'book2', 'Understanding the Calendar', '
        <ul>
            <li><strong>Business days:</strong> Configured by your Fleet Admin (typically Monday–Friday).</li>
            <li><strong>Holidays:</strong> Blocked dates set in Booking Rules. You cannot select these dates.</li>
            <li><strong>Blackout periods:</strong> Temporary restrictions (e.g., fleet freeze for audits).</li>
            <li><strong>Turnaround buffer:</strong> A gap between reservations to allow vehicle inspection and cleaning.</li>
        </ul>
    '); ?>
    <?php help_section('accBooking', 'book3', 'What Are Company Badges?', '
        <p>If your organization uses <strong>multi-entity fleet partitioning</strong>, each vehicle and user belongs to a company. Small colored badges appear next to vehicle and user names showing their company assignment.</p>
        <ul>
            <li>Drivers see only vehicles assigned to their company.</li>
            <li>Staff see vehicles for their company.</li>
            <li>Admins can see all vehicles across all companies.</li>
        </ul>
    '); ?>
    <?php help_section('accBooking', 'book4', 'Cancelling a Reservation', '
        <ol>
            <li>Go to <strong>My Reservations</strong>.</li>
            <li>Find the reservation you want to cancel.</li>
            <li>Click <strong>Cancel</strong> and confirm.</li>
            <li>The vehicle is released back to the available pool immediately.</li>
        </ol>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>You cannot cancel a reservation that is already checked out. Contact fleet staff for assistance.</div>
    '); ?>
</div>

<?php elseif ($activeTab === 'checkout'): ?>
<!-- CHECKOUT & CHECKIN TAB -->
<div class="accordion" id="accCheckout">
    <?php help_section('accCheckout', 'co1', 'Pre-Trip Inspection Modes', '
        <p>Before checking out a vehicle, you may be required to complete an inspection. There are three modes configured by your admin:</p>
        <ul>
            <li><strong>Quick Inspection:</strong> Confirm mileage and note any visible damage. Takes about 1 minute.</li>
            <li><strong>Full Inspection:</strong> Multi-point checklist covering tires, lights, fluids, interior, and exterior condition. Takes 3–5 minutes.</li>
            <li><strong>Off:</strong> No inspection required. Vehicle is checked out immediately.</li>
        </ul>
    '); ?>
    <?php help_section('accCheckout', 'co2', 'Taking Photos of Vehicle Condition', '
        <p>When photo uploads are enabled, you can document the vehicle condition before and after your trip.</p>
        <ul>
            <li>Up to <strong>5 photos</strong> per inspection event (10 MB max each).</li>
            <li>Photos are automatically stripped of GPS and EXIF metadata for privacy.</li>
            <li>Use your phone camera directly from the browser — no app needed.</li>
            <li>Focus on any existing damage, scratches, dents, or fluid leaks.</li>
        </ul>
    '); ?>
    <?php help_section('accCheckout', 'co3', 'Mileage Entry Rules', '
        <ul>
            <li>Enter the <strong>current odometer reading</strong> at checkout.</li>
            <li>At checkin, enter the <strong>return odometer reading</strong>.</li>
            <li>The system calculates trip miles automatically.</li>
            <li>If the return mileage is less than the checkout mileage, you will be prompted to verify.</li>
        </ul>
    '); ?>
    <?php help_section('accCheckout', 'co4', 'What to Do If You Find Damage', '
        <ol>
            <li>Document the damage with photos during your pre-trip inspection.</li>
            <li>Note the damage in the <strong>comments</strong> field.</li>
            <li>If the vehicle is unsafe to drive, <strong>do not check it out</strong> — notify fleet staff immediately.</li>
            <li>Staff will be notified automatically if you flag damage during inspection.</li>
        </ol>
    '); ?>
    <?php help_section('accCheckout', 'co5', 'Returning the Vehicle', '
        <ol>
            <li>Park the vehicle at the designated return location.</li>
            <li>Navigate to your active reservation in <strong>My Reservations</strong> or <strong>Dashboard</strong>.</li>
            <li>Click <strong>Check In</strong>.</li>
            <li>Enter the return odometer reading.</li>
            <li>Complete the post-trip inspection if required.</li>
            <li>Note any new damage or issues.</li>
            <li>Submit. The vehicle returns to the available pool.</li>
        </ol>
    '); ?>
</div>

<?php elseif ($activeTab === 'my_reservations'): ?>
<!-- MY RESERVATIONS TAB -->
<div class="accordion" id="accMyRes">
    <?php help_section('accMyRes', 'mr1', 'Reservation Statuses Explained', '
        <table class="table table-sm">
            <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td><span class="badge bg-warning text-dark">Pending</span></td><td>Submitted but not yet approved by fleet staff.</td></tr>
                <tr><td><span class="badge bg-success">Approved</span></td><td>Approved and ready for pickup at the scheduled time.</td></tr>
                <tr><td><span class="badge bg-primary">Checked Out</span></td><td>Vehicle has been picked up and is in use.</td></tr>
                <tr><td><span class="badge bg-secondary">Completed</span></td><td>Vehicle has been returned and checked in.</td></tr>
                <tr><td><span class="badge bg-danger">Missed</span></td><td>You did not pick up the vehicle within the cutoff window. The reservation was automatically cancelled.</td></tr>
                <tr><td><span class="badge bg-dark">Cancelled</span></td><td>You or a staff member cancelled this reservation.</td></tr>
            </tbody>
        </table>
    '); ?>
    <?php help_section('accMyRes', 'mr2', 'Downloading Your Personal Data', '
        <p>You can export all data the system holds about you:</p>
        <ol>
            <li>Go to <strong>My Reservations</strong>.</li>
            <li>Click the <strong>My Data</strong> button.</li>
            <li>A JSON file will download containing your reservations, inspection records, and activity log entries.</li>
        </ol>
        <p class="text-muted">This supports CCPA/privacy compliance requirements.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'faq'): ?>
<!-- FAQ TAB -->
<div class="accordion" id="accFaq">
    <?php help_section('accFaq', 'faq1', 'What if I forgot to check out?', '
        <p>If you do not check out the vehicle within the configured cutoff window (typically 60 minutes after your reservation start time), the reservation is automatically marked as <strong>Missed</strong>.</p>
        <ul>
            <li>The vehicle is released back to the available pool.</li>
            <li>You and fleet staff receive a notification.</li>
            <li>Contact fleet staff if you still need a vehicle — they can create a new reservation for you.</li>
        </ul>
    '); ?>
    <?php help_section('accFaq', 'faq2', 'What if I return the vehicle late?', '
        <p>If your reservation end time passes while the vehicle is still checked out:</p>
        <ul>
            <li>The reservation appears as <strong>Overdue</strong> on the staff dashboard.</li>
            <li>Overdue alerts are sent to fleet staff.</li>
            <li>Staff may <strong>force-checkin</strong> the vehicle if needed.</li>
            <li>Return the vehicle as soon as possible and check in normally.</li>
        </ul>
    '); ?>
    <?php help_section('accFaq', 'faq3', 'What if the vehicle has damage?', '
        <p>If you discover damage during your inspection:</p>
        <ul>
            <li>Take photos and document the damage in the inspection form.</li>
            <li>Fleet staff are automatically notified.</li>
            <li>If the vehicle is <strong>unsafe to drive</strong>, do not proceed — contact fleet staff for a replacement.</li>
        </ul>
    '); ?>
    <?php help_section('accFaq', 'faq4', 'How do I know if my training is current?', '
        <p>Check the <strong>Book Vehicle</strong> page. If your training has expired or is about to expire, a warning banner appears at the top of the page. You can also check your training status on <strong>My Reservations</strong>.</p>
        <p>Training validity is configured by your admin (typically 12 months). When training expires, you may be blocked from making new reservations until it is renewed.</p>
    '); ?>
    <?php help_section('accFaq', 'faq5', 'Can I book for someone else?', '
        <p>Only <strong>Fleet Staff</strong> and <strong>Fleet Admins</strong> can book vehicles on behalf of other users. If you need someone to book for you, contact your fleet office.</p>
    '); ?>
    <?php help_section('accFaq', 'faq6', 'What is a VIP user?', '
        <p>A VIP user has <strong>auto-approved reservations</strong>. When a VIP books a vehicle, the reservation is immediately confirmed without waiting in the approval queue.</p>
        <p>VIP status is granted by Fleet Admins and is typically reserved for executives or users with frequent, time-sensitive travel needs.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'approvals' && $isStaff): ?>
<!-- APPROVALS TAB -->
<div class="accordion" id="accApprovals">
    <?php help_section('accApprovals', 'app1', 'Processing the Approval Queue', '
        <ol>
            <li>Navigate to <strong>Approvals</strong> from the main menu.</li>
            <li>Pending reservations are listed with driver name, vehicle, dates, and purpose.</li>
            <li>Review each request and click <strong>Approve</strong> or <strong>Reject</strong>.</li>
            <li>Approved reservations lock the vehicle for that time slot.</li>
            <li>The driver receives an email/Teams notification of the decision.</li>
        </ol>
    '); ?>
    <?php help_section('accApprovals', 'app2', 'Approving vs. Rejecting', '
        <ul>
            <li><strong>Approve:</strong> Confirms the reservation. The vehicle status changes to Reserved.</li>
            <li><strong>Reject:</strong> Cancels the request. You must provide a reason, which is sent to the driver.</li>
        </ul>
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>If the vehicle has a scheduling conflict, the system will warn you before approving.</div>
    '); ?>
    <?php help_section('accApprovals', 'app3', 'What Happens When a Vehicle Is Already in Use', '
        <p>If another reservation overlaps the requested time slot, the system shows a conflict warning. You should:</p>
        <ul>
            <li>Reject the conflicting request with an explanation.</li>
            <li>Suggest an alternative vehicle or time slot.</li>
            <li>Or approve if the overlap is within an acceptable turnaround buffer.</li>
        </ul>
    '); ?>
</div>

<?php elseif ($activeTab === 'maintenance' && $isStaff): ?>
<!-- MAINTENANCE TAB -->
<div class="accordion" id="accMaint">
    <?php help_section('accMaint', 'mt1', 'Logging Maintenance', '
        <ol>
            <li>Go to <strong>Maintenance</strong> from the main menu.</li>
            <li>Click <strong>Log Maintenance</strong> or use the maintenance button on a vehicle card.</li>
            <li>Select the vehicle, maintenance type (Scheduled, Repair, Upgrade, Calibration), and enter details.</li>
            <li>Set the start date and expected completion date.</li>
            <li>The vehicle is placed in maintenance status and becomes unavailable for booking.</li>
        </ol>
    '); ?>
    <?php help_section('accMaint', 'mt2', 'Scheduled Maintenance Alerts', '
        <p>The system monitors vehicle mileage and time intervals to trigger maintenance alerts:</p>
        <ul>
            <li><strong>Oil change:</strong> Based on mileage interval (e.g., every 5,000 miles).</li>
            <li><strong>Tire rotation:</strong> Based on mileage interval.</li>
            <li><strong>Time-based:</strong> Based on days since last maintenance.</li>
        </ul>
        <p>Alerts appear on the Maintenance page and Dashboard for staff.</p>
    '); ?>
    <?php help_section('accMaint', 'mt3', 'Compliance Monitoring', '
        <p>The system tracks expiration dates for:</p>
        <ul>
            <li><strong>Insurance</strong> — alerts when approaching expiry.</li>
            <li><strong>Registration</strong> — alerts when approaching expiry.</li>
        </ul>
        <p>Expired vehicles are flagged on the compliance report and should be taken out of service.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'reports' && $isStaff): ?>
<!-- REPORTS TAB -->
<div class="accordion" id="accReports">
    <?php help_section('accReports', 'rpt1', 'Available Report Types', '
        <table class="table table-sm">
            <thead><tr><th>Report</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><strong>Summary</strong></td><td>High-level fleet stats: total reservations, completion rate, miles driven, top vehicles and users.</td></tr>
                <tr><td><strong>Usage</strong></td><td>Detailed trip log with dates, mileage, duration, and status for every reservation.</td></tr>
                <tr><td><strong>Utilization</strong></td><td>Per-vehicle utilization rates, idle vehicle identification, fleet averages.</td></tr>
                <tr><td><strong>Maintenance</strong></td><td>Service history, costs by vehicle, maintenance type breakdown.</td></tr>
                <tr><td><strong>Driver</strong></td><td>Per-driver analytics: trip counts, mileage, completion rates, training status.</td></tr>
                <tr><td><strong>Compliance</strong></td><td>Insurance and registration expiry tracking, worst-first priority sorting.</td></tr>
            </tbody>
        </table>
    '); ?>
    <?php help_section('accReports', 'rpt2', 'Filtering and Date Ranges', '
        <ul>
            <li>Use <strong>preset buttons</strong> (This Month, Last 90 Days, YTD, etc.) for quick date selection.</li>
            <li>Use <strong>custom dates</strong> for specific ranges.</li>
            <li>Filter by <strong>vehicle</strong>, <strong>user</strong>, <strong>status</strong>, or <strong>company</strong> (if multi-entity is enabled).</li>
        </ul>
    '); ?>
    <?php help_section('accReports', 'rpt3', 'CSV Export', '
        <p>Click the <strong>Export CSV</strong> button on any report to download the current filtered data as a spreadsheet-compatible file. The export respects all active filters.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'training' && $isStaff): ?>
<!-- TRAINING MANAGEMENT TAB -->
<div class="accordion" id="accTraining">
    <?php help_section('accTraining', 'tr1', 'Setting Training Dates', '
        <ol>
            <li>Go to <strong>Admin > Users</strong>.</li>
            <li>In the Drivers table, find the driver and click the <strong>mortarboard icon</strong>.</li>
            <li>Enter the training completion date.</li>
            <li>The system automatically calculates the expiration based on the configured validity period.</li>
        </ol>
    '); ?>
    <?php help_section('accTraining', 'tr2', 'Understanding Expiration Colors', '
        <table class="table table-sm">
            <thead><tr><th>Color</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td><span class="badge bg-success">Green</span></td><td>Training is valid and not expiring soon.</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Yellow</span></td><td>Training expires within 30 days. Driver should renew soon.</td></tr>
                <tr><td><span class="badge bg-danger">Red</span></td><td>Training has expired. Driver may be blocked from booking (if enforcement is on).</td></tr>
                <tr><td><span class="badge bg-secondary">Gray</span></td><td>No training date set.</td></tr>
            </tbody>
        </table>
    '); ?>
    <?php help_section('accTraining', 'tr3', 'Weekly Training Alerts', '
        <p>The system sends weekly notifications to staff listing drivers whose training is expiring within 30 days or has already expired. This helps you proactively schedule renewals.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'vehicles' && $isAdmin): ?>
<!-- VEHICLE MANAGEMENT TAB -->
<div class="accordion" id="accVehicles">
    <?php help_section('accVehicles', 'vh1', 'Adding a New Vehicle', '
        <ol>
            <li>Go to <strong>Admin > Vehicles</strong> or use <strong>List of Vehicles > Add Vehicle</strong>.</li>
            <li>Fill in the required fields: <strong>Model</strong>, <strong>Name</strong>, and <strong>Asset Tag</strong>.</li>
            <li>Enter compliance fields: <strong>VIN</strong>, <strong>License Plate</strong>, <strong>Insurance Expiry</strong>, <strong>Registration Expiry</strong>.</li>
            <li>Set the <strong>Home Location</strong> (where the vehicle is normally parked).</li>
            <li>If multi-entity is enabled, assign the vehicle to a <strong>Company</strong>.</li>
            <li>Submit. The vehicle appears in the fleet catalog and is available for booking.</li>
        </ol>
    '); ?>
    <?php help_section('accVehicles', 'vh2', 'Company Assignment for Multi-Entity', '
        <p>In a multi-entity fleet, each vehicle must be assigned to a company in Snipe-IT. This controls which users can see and book the vehicle. Admins can see all vehicles regardless of company assignment.</p>
    '); ?>
    <?php help_section('accVehicles', 'vh3', 'Vehicle Naming Convention', '
        <p>Recommended format: <code>[Year] [Make] [Model] — [Plate]</code></p>
        <p>Example: <code>2024 Toyota Hilux — ABC-1234</code></p>
        <p>Consistent naming makes it easier for drivers to identify vehicles in the booking list.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'users' && $isAdmin): ?>
<!-- USER MANAGEMENT TAB -->
<div class="accordion" id="accUsers">
    <?php help_section('accUsers', 'us1', 'How Users Are Provisioned', '
        <p>Users are created in <strong>Snipe-IT</strong> and sync automatically to the fleet system. There are two provisioning paths:</p>
        <ul>
            <li><strong>Auto-provisioning:</strong> When a user logs in via SSO (Microsoft/Google OAuth) for the first time, their account is matched to Snipe-IT by email. If they belong to a fleet group, they get access.</li>
            <li><strong>Manual creation:</strong> Admins can create users via the <strong>Add New User</strong> form, which creates them in Snipe-IT with the appropriate group membership.</li>
        </ul>
    '); ?>
    <?php help_section('accUsers', 'us2', 'Setting VIP Status', '
        <p>Click the <strong>star icon</strong> next to a user\'s name in the Users page. VIP users get auto-approved reservations without waiting in the approval queue.</p>
    '); ?>
    <?php help_section('accUsers', 'us3', 'Company Assignment', '
        <p>When adding a new user, select their company from the dropdown. This determines which vehicles they can see and book. To change an existing user\'s company, edit them directly in Snipe-IT.</p>
    '); ?>
    <?php help_section('accUsers', 'us4', 'Deactivating Users', '
        <p>Click the <strong>pause icon</strong> next to a user to deactivate them. Deactivated users cannot log in or make reservations. Their existing reservations remain in the system for reporting.</p>
    '); ?>
    <?php help_section('accUsers', 'us5', 'Employee Offboarding Procedure', '
        <ol>
            <li>Check for active reservations — cancel or reassign them.</li>
            <li>If the employee has a vehicle checked out, arrange return and force-checkin.</li>
            <li>Deactivate the user in the fleet system.</li>
            <li>Remove fleet groups in Snipe-IT.</li>
            <li>Deactivate or delete the user in Snipe-IT.</li>
            <li>Revoke SSO access at the identity provider (Azure AD / Google Workspace).</li>
        </ol>
    '); ?>
</div>

<?php elseif ($activeTab === 'booking_rules' && $isAdmin): ?>
<!-- BOOKING RULES TAB -->
<div class="accordion" id="accRules">
    <?php help_section('accRules', 'br1', 'Business Days and Holidays', '
        <p>Configure which days of the week are available for booking and add specific holiday dates that block reservations. Go to <strong>Admin > Booking Rules</strong> to manage these settings.</p>
    '); ?>
    <?php help_section('accRules', 'br2', 'Turnaround Buffer', '
        <p>The turnaround buffer adds a gap between consecutive reservations for the same vehicle. This allows time for inspection, cleaning, and refueling. Set this under Booking Rules > Turnaround Hours.</p>
    '); ?>
    <?php help_section('accRules', 'br3', 'Training Requirements', '
        <p>Enable or disable the training requirement for booking. When enabled, drivers must have valid, non-expired training to create reservations. Configure the validity period (in months).</p>
    '); ?>
    <?php help_section('accRules', 'br4', 'Inspection Mode Configuration', '
        <p>Choose one of three inspection modes:</p>
        <ul>
            <li><strong>Quick:</strong> Mileage and basic condition check.</li>
            <li><strong>Full:</strong> Comprehensive multi-point checklist.</li>
            <li><strong>Off:</strong> No inspection required.</li>
        </ul>
    '); ?>
    <?php help_section('accRules', 'br5', 'Photo Upload Toggle', '
        <p>Enable or disable the ability for drivers to upload photos during inspections. When enabled, drivers can upload up to 5 photos per inspection event.</p>
    '); ?>
    <?php help_section('accRules', 'br6', 'Missed Reservation Settings', '
        <p>Configure how long after a reservation start time the system waits before marking it as missed. Also set a release buffer to hold the vehicle before returning it to the available pool.</p>
    '); ?>
    <?php help_section('accRules', 'br7', 'Data Retention Settings', '
        <p>Configure how long the system retains:</p>
        <ul>
            <li><strong>Activity logs:</strong> 90 / 180 / 365 / 730 days.</li>
            <li><strong>Inspection photos:</strong> 1 / 2 / 3 years or indefinitely.</li>
            <li><strong>Email queue:</strong> 7 / 14 / 30 / 60 days.</li>
        </ul>
        <p>A weekly CRON job automatically purges expired data.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'checklists' && $isAdmin): ?>
<!-- CHECKLIST MANAGEMENT TAB -->
<div class="accordion" id="accChecklists">
    <?php help_section('accChecklists', 'cl1', 'Overview', '
        <p>The Checklist Management system allows Fleet Admins to create and customize inspection checklists that drivers complete during vehicle checkout and checkin.</p>
        <p>Go to <strong>Admin > Checklists</strong> to manage profiles, categories, items, and vehicle assignments.</p>
        <p><strong>Note:</strong> The inspection mode must be set to "Full" in Booking Rules for the detailed checklist to appear.</p>
    '); ?>
    <?php help_section('accChecklists', 'cl2', 'Profiles', '
        <p>A <strong>profile</strong> is a complete inspection checklist containing categories and items. You can create multiple profiles for different vehicle types.</p>
        <ul>
            <li><strong>Default Profile:</strong> Used when no specific profile is assigned to a vehicle model. Only one profile can be default.</li>
            <li><strong>Active/Inactive:</strong> Inactive profiles are not used for inspections but are preserved for historical reference.</li>
            <li><strong>Duplicate:</strong> Copy an existing profile as a starting point for a new one.</li>
        </ul>
    '); ?>
    <?php help_section('accChecklists', 'cl3', 'Categories and Items', '
        <p>Each profile contains <strong>categories</strong> (e.g., Tires, Lights, Interior) and each category contains <strong>items</strong> (e.g., "Front-Left tire condition").</p>
        <ul>
            <li>Categories can be reordered using the up/down buttons.</li>
            <li>Items have a label, safety-critical flag, and an "applies to" setting (checkout, checkin, or both).</li>
            <li>The "Overall Assessment" category typically contains a free-text comments field.</li>
        </ul>
    '); ?>
    <?php help_section('accChecklists', 'cl4', 'Safety-Critical Items', '
        <p>Items marked as <strong>safety-critical</strong> receive special treatment:</p>
        <ul>
            <li>Displayed with a red asterisk and "(Safety Critical)" label in the inspection form.</li>
            <li>If a driver marks a safety-critical item as "No" during checkout, a <strong>warning modal</strong> appears.</li>
            <li>The driver can choose to go back or proceed anyway (acknowledging the risk).</li>
            <li>If they proceed, Fleet Staff receives an automatic notification about the safety override.</li>
            <li>Examples: brakes, steering, seatbelts, headlights, windshield condition, fire extinguisher.</li>
        </ul>
    '); ?>
    <?php help_section('accChecklists', 'cl5', 'Vehicle Assignments', '
        <p>Assign specific checklist profiles to vehicle models from the <strong>Assignments</strong> tab.</p>
        <ul>
            <li>Each vehicle model can be assigned a different checklist profile.</li>
            <li>Models without an assignment automatically use the default profile.</li>
            <li>This allows heavy-duty trucks to have different inspection items than sedans, for example.</li>
        </ul>
    '); ?>
    <?php help_section('accChecklists', 'cl6', 'Analytics', '
        <p>The <strong>Analytics</strong> tab provides insight into inspection trends:</p>
        <ul>
            <li><strong>Top Failed Items:</strong> Most frequently failed items in the last 30 days.</li>
            <li><strong>Safety-Critical Failures:</strong> Count of inspections with safety failures over 30/90/365 days.</li>
            <li><strong>Inspections by Profile:</strong> Which profiles are being used most.</li>
        </ul>
    '); ?>
</div>

<?php elseif ($activeTab === 'notifications' && $isAdmin): ?>
<!-- NOTIFICATIONS TAB -->
<div class="accordion" id="accNotif">
    <?php help_section('accNotif', 'nf1', 'Event Types and Channels', '
        <p>The system can send notifications for various events including:</p>
        <ul>
            <li>Reservation created, approved, rejected, cancelled</li>
            <li>Vehicle checked out, checked in</li>
            <li>Overdue returns</li>
            <li>Missed reservations</li>
            <li>Maintenance due alerts</li>
            <li>Training expiration reminders</li>
        </ul>
        <p>Each event can be configured independently.</p>
    '); ?>
    <?php help_section('accNotif', 'nf2', 'Email vs. Teams Configuration', '
        <ul>
            <li><strong>Email:</strong> Uses SMTP configured in System Settings. Supports per-event subject and body templates.</li>
            <li><strong>Microsoft Teams:</strong> Uses incoming webhook URLs. Configure the webhook in System Settings, then enable Teams for each notification event.</li>
        </ul>
    '); ?>
    <?php help_section('accNotif', 'nf3', 'Testing Notifications', '
        <p>Use the <strong>Send Test</strong> button on the Notifications page to verify your email and Teams configuration is working correctly before enabling notifications for real events.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'announcements' && $isAdmin): ?>
<!-- ANNOUNCEMENTS TAB -->
<div class="accordion" id="accAnnounce">
    <?php help_section('accAnnounce', 'ann1', 'Creating Announcements', '
        <p>Announcements appear as banners on the Dashboard for all users. Use them for:</p>
        <ul>
            <li>System maintenance windows</li>
            <li>Policy changes</li>
            <li>Fleet updates (new vehicles, location changes)</li>
            <li>Emergency notices</li>
        </ul>
        <p>Set an urgency level (Info, Warning, Critical) and optional expiration date.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'multi_entity' && $isAdmin): ?>
<!-- MULTI-ENTITY FLEET TAB -->
<div class="accordion" id="accMulti">
    <?php help_section('accMulti', 'me1', 'When to Use Companies', '
        <p>Multi-entity fleet partitioning is useful when your organization has multiple subsidiaries, departments, or business units that each manage their own vehicle fleet.</p>
        <p>Each company sees only its own vehicles, while Fleet Admins retain full visibility across all entities.</p>
    '); ?>
    <?php help_section('accMulti', 'me2', 'Setup in Snipe-IT', '
        <ol>
            <li>Go to Snipe-IT > Settings > Companies.</li>
            <li>Create a company with a descriptive <strong>Name</strong>.</li>
            <li>Set the <strong>Notes</strong> field to a short abbreviation (e.g., "NYC" for New York Corp). This is used for badges.</li>
            <li>Optionally set <strong>Tag Color</strong> (hex code) for the badge color.</li>
            <li>Assign vehicles and users to this company in Snipe-IT.</li>
        </ol>
    '); ?>
    <?php help_section('accMulti', 'me3', 'How Filtering Works', '
        <ul>
            <li><strong>Drivers:</strong> See only vehicles assigned to their company.</li>
            <li><strong>Fleet Staff:</strong> See only their company\'s reservations and vehicles.</li>
            <li><strong>Fleet Admin / Super Admin:</strong> See all vehicles and reservations across all companies.</li>
        </ul>
    '); ?>
    <?php help_section('accMulti', 'me4', 'Admin Toggle', '
        <p>In <strong>System Settings</strong>, the multi-entity mode can be set to:</p>
        <ul>
            <li><strong>Auto:</strong> Enabled automatically when multiple companies exist in Snipe-IT.</li>
            <li><strong>On:</strong> Always enabled.</li>
            <li><strong>Off:</strong> Disabled even if multiple companies exist.</li>
        </ul>
    '); ?>
    <?php help_section('accMulti', 'me5', 'Location Scoping', '
        <p><strong>Pickup locations are shared across all entities.</strong> Locations in Snipe-IT do not have a direct company field, so all users see the same set of pickup and return locations.</p>
        <p>Vehicle availability is always filtered by company assignment — a driver will only see vehicles belonging to their company at any given location, even if vehicles from other companies are also parked there.</p>
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>This is by design. Snipe-IT location scoping has known conflicts with multi-tenant setups. The safe approach is to share locations and filter vehicles instead.</div>
    '); ?>
</div>

<?php elseif ($activeTab === 'system_settings' && $isSuperAdmin): ?>
<!-- SYSTEM SETTINGS TAB -->
<div class="accordion" id="accSysSettings">
    <?php help_section('accSysSettings', 'ss1', 'Authentication Configuration', '
        <p>The system supports three authentication providers:</p>
        <ul>
            <li><strong>Microsoft OAuth 2.0</strong> — via Azure AD / Entra ID. Requires app registration with client ID, secret, and tenant.</li>
            <li><strong>Google OAuth 2.0</strong> — via Google Workspace. Requires OAuth client credentials.</li>
            <li><strong>LDAP</strong> — Direct bind against Active Directory. Requires server address, base DN, and bind credentials.</li>
        </ul>
        <p>Configure in <code>config/config.php</code>. Multiple providers can be enabled simultaneously.</p>
    '); ?>
    <?php help_section('accSysSettings', 'ss2', 'SMTP Settings', '
        <p>Email notifications require SMTP configuration:</p>
        <ul>
            <li><strong>Host:</strong> Your SMTP server (e.g., smtp.office365.com)</li>
            <li><strong>Port:</strong> 587 (TLS) or 465 (SSL)</li>
            <li><strong>Username/Password:</strong> SMTP authentication credentials</li>
            <li><strong>From address:</strong> The sender email for all notifications</li>
        </ul>
    '); ?>
    <?php help_section('accSysSettings', 'ss3', 'Teams Webhook Setup', '
        <p>To send notifications to Microsoft Teams:</p>
        <ol>
            <li>In Teams, go to the target channel > Connectors > Incoming Webhook.</li>
            <li>Create a webhook and copy the URL.</li>
            <li>Paste the URL into System Settings > Teams Webhook URL.</li>
            <li>Enable Teams for each notification event on the Notifications page.</li>
        </ol>
    '); ?>
    <?php help_section('accSysSettings', 'ss4', 'Theme and Color Customization', '
        <p>Set your organization\'s primary brand color in System Settings. This color is applied to the navigation bar, buttons, and accent elements throughout the application. Enter a hex color code (e.g., #0078B9).</p>
    '); ?>
    <?php help_section('accSysSettings', 'ss5', 'Session Timeout', '
        <p>Configure the idle session timeout: 15, 30, 60, or 120 minutes. Users are automatically logged out after this period of inactivity. The setting is cached in the session for 5 minutes to minimize database queries.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'security' && $isSuperAdmin): ?>
<!-- SECURITY TAB -->
<div class="accordion" id="accSecurity">
    <?php help_section('accSecurity', 'sec1', 'CRON Sync Health Monitoring', '
        <p>The Security Dashboard (<strong>Admin > Security</strong>) monitors the health of CRON jobs. It checks:</p>
        <ul>
            <li>Last sync timestamp — alerts if stale (> 15 minutes).</li>
            <li>Reservation sync status.</li>
            <li>Missed reservation CRON job status.</li>
            <li>Data retention purge job status.</li>
        </ul>
    '); ?>
    <?php help_section('accSecurity', 'sec2', 'Backup Verification', '
        <p>The Security Dashboard checks for recent backups. If no backup is found within 48 hours, an alert is displayed. Ensure the backup CRON job runs daily at 2:00 AM.</p>
    '); ?>
    <?php help_section('accSecurity', 'sec3', 'Security Headers', '
        <p>The application sets the following security headers via <code>.htaccess</code>:</p>
        <ul>
            <li><code>X-Frame-Options: SAMEORIGIN</code> — prevents clickjacking</li>
            <li><code>X-Content-Type-Options: nosniff</code> — prevents MIME sniffing</li>
            <li><code>Content-Security-Policy</code> — restricts resource loading</li>
        </ul>
    '); ?>
    <?php help_section('accSecurity', 'sec4', 'Incident Response', '
        <p>A formal Incident Response Plan is documented in <code>docs/INCIDENT_RESPONSE.md</code>. It covers incident classification, detection sources, response team roles, and a 5-phase response procedure.</p>
        <p>Key contacts and escalation procedures should be reviewed quarterly.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'compliance' && $isSuperAdmin): ?>
<!-- DATA COMPLIANCE TAB -->
<div class="accordion" id="accCompliance">
    <?php help_section('accCompliance', 'dc1', 'What Data Is Collected', '
        <p>The system collects and stores:</p>
        <ul>
            <li>User profile information (name, email, company) — sourced from Snipe-IT</li>
            <li>Reservation records (dates, vehicles, status)</li>
            <li>Inspection records and photos</li>
            <li>Activity logs (login events, actions taken, IP addresses)</li>
            <li>Notification queue history</li>
        </ul>
        <p>A public privacy notice is available at <code>/booking/privacy</code> (no authentication required).</p>
    '); ?>
    <?php help_section('accCompliance', 'dc2', 'Data Retention Configuration', '
        <p>Configure retention periods in <strong>Admin > Booking Rules > Data Retention</strong>:</p>
        <ul>
            <li><strong>Activity logs:</strong> 90 / 180 / 365 / 730 days (default: 365)</li>
            <li><strong>Inspection photos:</strong> 1 / 2 / 3 years or never (default: 2 years)</li>
            <li><strong>Email queue:</strong> 7 / 14 / 30 / 60 days (default: 30)</li>
        </ul>
        <p>A weekly CRON job (<code>cron_data_retention.php</code>) purges expired data automatically.</p>
    '); ?>
    <?php help_section('accCompliance', 'dc3', 'Driver Data Export', '
        <p>Drivers can self-service export their personal data via <strong>My Reservations > My Data</strong>. This downloads a JSON file with all their reservations, inspections, and activity log entries. This supports CCPA compliance requirements.</p>
    '); ?>
    <?php help_section('accCompliance', 'dc4', 'Employee Offboarding', '
        <p>When an employee leaves the organization, follow the offboarding procedure in <strong>User Management > Employee Offboarding Procedure</strong>. Ensure all active reservations are resolved and access is revoked at both the fleet system and identity provider levels.</p>
    '); ?>
</div>

<?php elseif ($activeTab === 'deployment' && $isSuperAdmin): ?>
<!-- DEPLOYMENT TAB -->
<div class="accordion" id="accDeploy">
    <?php help_section('accDeploy', 'dep1', 'Architecture Overview', '
        <ul>
            <li><strong>Web server:</strong> Apache with PHP (mod_php or PHP-FPM)</li>
            <li><strong>Database:</strong> MySQL 5.7+ or MariaDB 10.3+</li>
            <li><strong>External dependency:</strong> Snipe-IT instance (API connectivity required)</li>
            <li><strong>Authentication:</strong> Microsoft OAuth / Google OAuth / LDAP</li>
            <li><strong>Notifications:</strong> SMTP email + Microsoft Teams webhooks</li>
        </ul>
    '); ?>
    <?php help_section('accDeploy', 'dep2', 'CRON Jobs Reference', '
        <table class="table table-sm">
            <thead><tr><th>Schedule</th><th>Script</th><th>Purpose</th></tr></thead>
            <tbody>
                <tr><td>*/5 * * * *</td><td>cron_sync_reservations.php</td><td>Sync reservation status with Snipe-IT</td></tr>
                <tr><td>*/10 * * * *</td><td>cron_mark_missed.php</td><td>Mark missed reservations</td></tr>
                <tr><td>*/15 * * * *</td><td>cron_sync_health.php</td><td>Monitor CRON job health</td></tr>
                <tr><td>0 2 * * *</td><td>backup-snipescheduler.sh</td><td>Daily database and file backup</td></tr>
                <tr><td>0 3 * * 0</td><td>cron_data_retention.php</td><td>Weekly data purge</td></tr>
            </tbody>
        </table>
    '); ?>
    <?php help_section('accDeploy', 'dep3', 'Snipe-IT Validation Script', '
        <p>Run <code>php scripts/validate_snipeit.php --strict</code> to verify:</p>
        <ul>
            <li>API connectivity and authentication</li>
            <li>Required groups exist (Drivers, Fleet Staff, Fleet Admin, Super Admin)</li>
            <li>Required status labels exist (VEH-Available, VEH-Reserved, VEH-In Service)</li>
            <li>Custom field definitions are in place</li>
        </ul>
    '); ?>
    <?php help_section('accDeploy', 'dep4', 'Backup and Restore', '
        <p>Daily backups include the database, configuration, uploaded photos, and version file. Stored in <code>/var/backups/snipescheduler/</code> with 30-day retention.</p>
        <p>For restoration procedures, see <code>docs/DISASTER_RECOVERY.md</code>.</p>
    '); ?>
    <?php help_section('accDeploy', 'dep5', 'Disaster Recovery', '
        <p>The DR plan targets:</p>
        <ul>
            <li><strong>RTO:</strong> 4 hours (fleet operations can fall back to manual checkout)</li>
            <li><strong>RPO:</strong> 24 hours (daily backups)</li>
        </ul>
        <p>Full disaster recovery procedures are documented in <code>docs/DISASTER_RECOVERY.md</code>.</p>
    '); ?>
</div>

<?php endif; ?>

        </div><!-- /help-tab-content -->

        <hr class="mt-5">
        <p class="text-muted small text-center">
            For technical documentation, visit the
            <a href="https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager" target="_blank" rel="noopener noreferrer">project repository</a>.
        </p>

        <?php layout_footer(); ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Search functionality
document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('.page-loading-overlay')?.remove();

    const searchInput = document.getElementById('helpSearch');
    const clearBtn = document.getElementById('helpSearchClear');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        clearBtn.style.display = query ? 'block' : 'none';

        document.querySelectorAll('.accordion-item').forEach(function(item) {
            const text = item.textContent.toLowerCase();
            if (!query || text.includes(query)) {
                item.classList.remove('hidden');
                item.style.display = '';
            } else {
                item.classList.add('hidden');
                item.style.display = 'none';
            }
        });
    });

    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });
});
</script>
</body>
</html>

<?php
/**
 * Helper: render a single collapsible accordion section.
 */
function help_section(string $parentId, string $id, string $title, string $body): void
{
    $expanded = false; // all collapsed by default
    echo '<div class="accordion-item help-section">';
    echo '<h2 class="accordion-header" id="heading' . $id . '">';
    echo '<button class="accordion-button ' . ($expanded ? '' : 'collapsed') . '" type="button" data-bs-toggle="collapse" data-bs-target="#collapse' . $id . '" aria-expanded="' . ($expanded ? 'true' : 'false') . '">';
    echo htmlspecialchars($title);
    echo '</button></h2>';
    echo '<div id="collapse' . $id . '" class="accordion-collapse collapse ' . ($expanded ? 'show' : '') . '" data-bs-parent="#' . $parentId . '">';
    echo '<div class="accordion-body">' . $body . '</div>';
    echo '</div></div>';
}
?>
