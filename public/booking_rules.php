<?php
/**
 * Booking Rules - Business Day & Availability Configuration
 * Accessible to Fleet Admin (not just Super Admin like Settings)
 *
 * @since v1.3.5
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

// CSRF Protection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}

require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/business_days.php';

$active = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied. Fleet Admin required.';
    exit;
}

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$messages = [];
$errors = [];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_business_days') {
            save_business_day_settings($_POST, $pdo);

            // Handle holiday toggles
            $allHolidays = get_all_holidays($pdo);
            if (isset($_POST['holiday_active']) && is_array($_POST['holiday_active'])) {
                $activeIds = array_map('intval', $_POST['holiday_active']);
                foreach ($allHolidays as $h) {
                    $shouldBeActive = in_array((int)$h['id'], $activeIds);
                    if ((bool)$h['is_active'] !== $shouldBeActive) {
                        toggle_holiday((int)$h['id'], $shouldBeActive, $pdo);
                    }
                }
            } else {
                // No checkboxes checked = deactivate all
                foreach ($allHolidays as $h) {
                    if ($h['is_active']) {
                        toggle_holiday((int)$h['id'], false, $pdo);
                    }
                }
            }

            $messages[] = 'Business day settings saved.';
        }

        if ($action === 'add_holiday' || ($action === 'save_business_days' && !empty($_POST['new_holiday_name']))) {
            if (!empty($_POST['new_holiday_name']) && !empty($_POST['new_holiday_date'])) {
                add_custom_holiday($_POST['new_holiday_name'], $_POST['new_holiday_date'], $pdo);
                $messages[] = 'Custom holiday added.';
            }
        }

        if ($action === 'delete_holiday' && !empty($_POST['delete_holiday_id'])) {
            delete_custom_holiday((int)$_POST['delete_holiday_id'], $pdo);
            $messages[] = 'Custom holiday deleted.';
        }

    } catch (Exception $e) {
        $errors[] = 'Error: ' . $e->getMessage();
    }

    // Redirect to prevent form resubmission (PRG pattern)
    if (empty($errors) && !empty($messages)) {
        $msgParam = urlencode(implode('|', $messages));
        header("Location: booking_rules?success={$msgParam}");
        exit;
    }
}

// Load current config
$bdConfig = get_business_day_config($pdo);
$currentYear = (int)date('Y');
$allHolidays = get_all_holidays($pdo);

// Group holidays by type
$majorHolidays = array_filter($allHolidays, fn($h) => $h['holiday_type'] === 'federal_major');
$minorHolidays = array_filter($allHolidays, fn($h) => $h['holiday_type'] === 'federal_minor');
$customHolidays = array_filter($allHolidays, fn($h) => $h['holiday_type'] === 'custom');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking Rules</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=<?= trim(file_get_contents(__DIR__ . '/../version.txt')) ?>">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Booking Rules</h1>
            <p class="text-muted">Business day calendar, vehicle turnaround buffer, and redirect settings</p>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <!-- Admin sub-tabs -->
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item"><a class="nav-link" href="vehicles">Vehicles</a></li>
            <li class="nav-item"><a class="nav-link" href="users">Users</a></li>
            <li class="nav-item"><a class="nav-link" href="activity_log">Activity Log</a></li>
            <li class="nav-item"><a class="nav-link" href="notifications">Notifications</a></li>
            <li class="nav-item"><a class="nav-link" href="announcements">Announcements</a></li>
            <li class="nav-item"><a class="nav-link active" href="booking_rules">Booking Rules</a></li>
            <li class="nav-item"><a class="nav-link" href="security">Security</a></li>
            <li class="nav-item"><a class="nav-link" href="settings">Settings</a></li>
        </ul>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?= h(str_replace('|', '. ', $_GET['success'])) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= implode('<br>', array_map('h', $errors)) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>

            <!-- Vehicle Turnaround Buffer & Redirect Config -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-1"><i class="bi bi-arrow-repeat me-2"></i>Vehicle Turnaround & Redirect</h5>
                    <p class="text-muted small mb-3">Controls the safety buffer between consecutive reservations on the same vehicle and the automatic redirect behavior when vehicles are overdue.</p>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label"><strong>Vehicle Turnaround Buffer</strong></label>
                            <div class="input-group">
                                <input type="number" name="business_day_buffer" class="form-control" min="1" max="10"
                                    value="<?= $bdConfig['buffer'] ?>">
                                <span class="input-group-text">business days</span>
                            </div>
                            <div class="form-text">
                                Minimum gap between end of one reservation and start of the next on the same vehicle.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><strong>Overdue Redirect Trigger</strong></label>
                            <div class="input-group">
                                <input type="number" name="redirect_overdue_minutes" class="form-control" min="15" max="240"
                                    value="<?= $bdConfig['redirect_overdue_minutes'] ?>">
                                <span class="input-group-text">minutes overdue</span>
                            </div>
                            <div class="form-text">
                                After this delay past expected return, the system redirects the next reservation to an alternate vehicle.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><strong>Redirect Lookahead Window</strong></label>
                            <div class="input-group">
                                <input type="number" name="redirect_lookahead_hours" class="form-control" min="6" max="72"
                                    value="<?= $bdConfig['redirect_lookahead_hours'] ?>">
                                <span class="input-group-text">hours ahead</span>
                            </div>
                            <div class="form-text">
                                Only trigger redirect if the next reservation starts within this window.
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border mb-0">
                        <i class="bi bi-shield-check me-2 text-primary"></i>
                        <strong>Why 2 business days?</strong> This buffer accounts for vehicles being returned late, needing inspection, or requiring minor maintenance after use.
                        Reducing below 2 days increases the risk of scheduling conflicts when vehicles are returned late.
                    </div>
                </div>
            </div>

            <!-- Working Days -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-1"><i class="bi bi-calendar-week me-2"></i>Working Days</h5>
                    <p class="text-muted small mb-3">Select which days of the week are considered business days. Unchecked days will be grayed out on the booking calendar and cannot be selected for pickup or return.</p>

                    <div class="d-flex flex-wrap gap-3">
                        <?php
                        $dayLabels = [
                            'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday',
                            'thursday' => 'Thursday', 'friday' => 'Friday',
                            'saturday' => 'Saturday', 'sunday' => 'Sunday'
                        ];
                        foreach ($dayLabels as $key => $label):
                        ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="business_days_<?= $key ?>" id="bd_<?= $key ?>"
                                    <?= $bdConfig['days'][$key] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bd_<?= $key ?>"><?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Holiday Calendar -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-1"><i class="bi bi-calendar-heart me-2"></i>Holiday Calendar (DMV Region)</h5>
                    <p class="text-muted small mb-3">Toggle holidays on/off. Active holidays are non-business days — reservations cannot start or end on these dates.</p>

                    <!-- Year filter -->
                    <div class="mb-3">
                        <label class="form-label small text-muted">Filter by year:</label>
                        <div class="btn-group btn-group-sm" role="group" id="yearFilter">
                            <button type="button" class="btn btn-outline-secondary active" data-year="all">All</button>
                            <?php for ($y = $currentYear; $y <= $currentYear + 4; $y++): ?>
                                <button type="button" class="btn btn-outline-secondary" data-year="<?= $y ?>"><?= $y ?></button>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Major Federal Holidays -->
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-success"><i class="bi bi-flag-fill me-1"></i>Federal Major Holidays</h6>
                                    <p class="small text-muted mb-2">Major holidays observed by most offices. All enabled by default.</p>
                                    <div class="holiday-list" style="max-height: 350px; overflow-y: auto;">
                                        <?php foreach ($majorHolidays as $h): ?>
                                            <div class="form-check holiday-row py-1" data-year="<?= date('Y', strtotime($h['holiday_date'])) ?>">
                                                <input class="form-check-input" type="checkbox" name="holiday_active[]" value="<?= $h['id'] ?>" id="hol_<?= $h['id'] ?>"
                                                    <?= $h['is_active'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="hol_<?= $h['id'] ?>">
                                                    <strong><?= h($h['name']) ?></strong>
                                                    <span class="text-muted ms-1"><?= date('M j, Y', strtotime($h['holiday_date'])) ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Minor Federal Holidays -->
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-warning"><i class="bi bi-flag me-1"></i>Federal Minor Holidays</h6>
                                    <p class="small text-muted mb-2">Disabled by default — enable if your office observes these.</p>
                                    <div class="holiday-list" style="max-height: 350px; overflow-y: auto;">
                                        <?php foreach ($minorHolidays as $h): ?>
                                            <div class="form-check holiday-row py-1" data-year="<?= date('Y', strtotime($h['holiday_date'])) ?>">
                                                <input class="form-check-input" type="checkbox" name="holiday_active[]" value="<?= $h['id'] ?>" id="hol_<?= $h['id'] ?>"
                                                    <?= $h['is_active'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="hol_<?= $h['id'] ?>">
                                                    <strong><?= h($h['name']) ?></strong>
                                                    <span class="text-muted ms-1"><?= date('M j, Y', strtotime($h['holiday_date'])) ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Holidays -->
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-subtitle mb-2 text-info"><i class="bi bi-plus-circle me-1"></i>Custom Holidays</h6>
                            <p class="small text-muted mb-2">Add company-specific days off (e.g., retreats, office closures).</p>

                            <?php if (!empty($customHolidays)): ?>
                                <div class="mb-3">
                                    <?php foreach ($customHolidays as $h): ?>
                                        <div class="d-flex align-items-center mb-1 holiday-row" data-year="<?= date('Y', strtotime($h['holiday_date'])) ?>">
                                            <input class="form-check-input me-2" type="checkbox" name="holiday_active[]" value="<?= $h['id'] ?>"
                                                <?= $h['is_active'] ? 'checked' : '' ?>>
                                            <span class="small me-2">
                                                <strong><?= h($h['name']) ?></strong>
                                                <span class="text-muted">(<?= date('M j, Y', strtotime($h['holiday_date'])) ?>)</span>
                                            </span>
                                            <button type="submit" name="action" value="delete_holiday" class="btn btn-outline-danger btn-sm py-0 px-1"
                                                onclick="return confirm('Delete this custom holiday?')">
                                                <input type="hidden" name="delete_holiday_id" value="<?= $h['id'] ?>">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="row g-2 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label small">Holiday Name</label>
                                    <input type="text" name="new_holiday_name" class="form-control form-control-sm" placeholder="e.g., Company Retreat">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Date</label>
                                    <input type="date" name="new_holiday_date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" name="action" value="save_business_days" class="btn btn-outline-info btn-sm w-100">
                                        <i class="bi bi-plus me-1"></i>Add Holiday
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between">
                <a href="blackouts" class="btn btn-outline-secondary">
                    <i class="bi bi-calendar-x me-1"></i>Manage Blackout Slots
                </a>
                <button type="submit" name="action" value="save_business_days" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save Booking Rules
                </button>
            </div>
        </form>

    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Year filter for holiday list
document.getElementById('yearFilter')?.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-year]');
    if (!btn) return;

    // Update active button
    this.querySelectorAll('.btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const year = btn.dataset.year;
    document.querySelectorAll('.holiday-row').forEach(row => {
        if (year === 'all' || row.dataset.year === year) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});
</script>
<?php layout_footer(); ?>
</body>
</html>
