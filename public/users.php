<?php
/**
 * User Management - Integrated with Snipe-IT
 * Admin only - Manage drivers and staff
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

// CSRF Protection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/company_filter.php';

$active = 'activity_log';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// Admin only access
if (!$isAdmin) {
    header('Location: dashboard');
    exit;
}

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';

$success = '';
$error = '';
$tab = $_GET['tab'] ?? 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_user') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $groups = $_POST['groups'] ?? [SNIPEIT_GROUP_DRIVERS];
        $isVip = isset($_POST['vip']) && $_POST['vip'] == '1';
        $canLogin = isset($_POST['can_login']) && $_POST['can_login'] == '1';
        $notes = trim($_POST['notes'] ?? '');
        $companyId = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
        
        if (empty($firstName) || empty($email)) {
            $error = 'First name and email are required.';
        } else {
            $existingUser = get_snipeit_user_by_email($email);
            
            // Handle multiple groups from form
            if (is_array($groups)) {
                $requestedGroupIds = array_map('intval', $groups);
            } else {
                $requestedGroupIds = [intval($groups)];
            }
            
            if ($existingUser) {
                // User exists in Snipe-IT - merge groups instead of blocking
                $existingGroupIds = [];
                if (isset($existingUser['groups']['rows'])) {
                    $existingGroupIds = array_map('intval', array_column($existingUser['groups']['rows'], 'id'));
                }
                
                // Append new groups (union of existing + requested)
                $mergedGroupIds = array_values(array_unique(array_merge($existingGroupIds, $requestedGroupIds)));
                $mergedGroupsCsv = $mergedGroupIds; // Array for JSON API
                
                $updateData = ['groups' => $mergedGroupsCsv];
                if ($isVip) { $updateData['vip'] = true; }
                if ($notes) { $updateData['notes'] = $existingUser['notes'] . ' | ' . $notes; }
                if ($companyId) { $updateData['company_id'] = $companyId; }
                
                $result = update_snipeit_user($existingUser['id'], $updateData);
                
                if ($result !== null) {
                    $addedGroups = array_diff($requestedGroupIds, $existingGroupIds);
                    if (empty($addedGroups)) {
                        $success = "User '{$existingUser['name']}' already has all requested groups. No changes needed (ID: {$existingUser['id']}).";
                    } else {
                        $success = "User '{$existingUser['name']}' already existed in Snipe-IT. Groups updated successfully (ID: {$existingUser['id']}).";
                    }
                } else {
                    $error = "User exists in Snipe-IT but failed to update their groups. Check logs for details.";
                }
            } else {
                // New user - create in Snipe-IT
                $userData = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'username' => $email,
                    'email' => $email,
                    'activated' => $canLogin,
                    'groups' => implode(',', $requestedGroupIds),
                    'vip' => $isVip,
                    'notes' => $notes ?: 'Created via SnipeScheduler - Safety driving certified',
                ];
                if ($companyId) { $userData['company_id'] = $companyId; }
                
                $result = create_snipeit_user($userData);
                
                if ($result && isset($result['id'])) {
                    $success = "User '{$firstName} {$lastName}' created successfully (ID: {$result['id']}).";
                } else {
                    $error = 'Failed to create user in Snipe-IT. Check logs for details.';
                }
            }
        }
        
    } elseif ($action === 'deactivate_user') {
        $userId = (int)$_POST['user_id'];
        if (deactivate_snipeit_user($userId)) {
            $success = 'User deactivated successfully.';
        } else {
            $error = 'Failed to deactivate user.';
        }
        
    } elseif ($action === 'activate_user') {
        $userId = (int)$_POST['user_id'];
        if (activate_snipeit_user($userId)) {
            $success = 'User activated successfully.';
        } else {
}
    } elseif ($action === 'toggle_vip') {
        $userId = (int)$_POST['user_id'];
        $currentVip = $_POST['current_vip'] === '1';
        $newVip = !$currentVip;
if (set_user_vip_status($userId, $newVip)) {
            $success = $newVip ? 'User marked as VIP.' : 'VIP status removed.';
            array_map('unlink', glob(CONFIG_PATH . '/cache/*.json'));
        } else {
            $error = 'Failed to update VIP status.';
        }

    } elseif ($action === 'toggle_training') {
        $userEmail = trim($_POST['user_email'] ?? '');
        $currentState = (int)($_POST['current_training'] ?? 0);
        $newState = $currentState ? 0 : 1;
        $inputDate = trim($_POST['training_date_input'] ?? '');
        if ($userEmail) {
            if ($newState) {
                // Enabling: use provided date or today
                $trainingDate = $inputDate ?: date('Y-m-d');
                $trainingDate .= ' ' . date('H:i:s');
            } else {
                // Disabling: keep the date in DB (set training_completed=0 only)
                $trainingDate = null;
            }
            if ($newState && $trainingDate) {
                $stmtT = $pdo->prepare("UPDATE users SET training_completed = 1, training_date = ? WHERE email = ?");
                $stmtT->execute([$trainingDate, $userEmail]);
            } else {
                $stmtT = $pdo->prepare("UPDATE users SET training_completed = 0 WHERE email = ?");
                $stmtT->execute([$userEmail]);
            }
            if ($stmtT->rowCount() > 0) {
                $success = $newState ? 'Driver training marked as completed.' : 'Driver training status cleared (training date preserved).';
                activity_log_event('training_toggle', $newState ? 'Training completed' : 'Training cleared', [
                    'metadata' => ['target_email' => $userEmail, 'new_state' => $newState, 'training_date' => $inputDate ?: 'today'],
                ]);
            } else {
                $error = 'User not found in local database. They must log in at least once first.';
            }
        }
    }
}
// Load training settings for display
$stmtTS2 = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('training_required', 'training_validity_months')");
$stmtTS2->execute();
$trainCfg = [];
while ($r = $stmtTS2->fetch()) { $trainCfg[$r['setting_key']] = $r['setting_value']; }
$globalTrainingRequired = ($trainCfg['training_required'] ?? '1') === '1';
$globalValidityMonths = (int)($trainCfg['training_validity_months'] ?? 12);

// Get users from Snipe-IT
$search = $_GET['search'] ?? '';
$allUsers = get_snipeit_users(200, $search);

// Filter and categorize users - users can appear in MULTIPLE groups
$drivers = [];
$staff = [];
$admins = [];
$inactive = [];
$multiCompany = is_multi_company_enabled($pdo);
$allCompanies = $multiCompany ? get_all_companies() : [];

foreach ($allUsers as $user) {
    $userGroups = [];
    if (isset($user['groups']['rows'])) {
        $userGroups = array_column($user['groups']['rows'], 'id');
    }
    
    $isActive = $user['activated'] ?? true;
    
    if (!$isActive) {
        $inactive[] = $user;
        continue; // Inactive users only show in inactive tab
    }
    
    // User can appear in multiple groups
    if (in_array(SNIPEIT_GROUP_DRIVERS, $userGroups)) {
        $drivers[] = $user;
    }
    if (in_array(SNIPEIT_GROUP_FLEET_STAFF, $userGroups)) {
        $staff[] = $user;
    }
    if (in_array(SNIPEIT_GROUP_FLEET_ADMIN, $userGroups)) {
        $admins[] = $user;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management - Admin</title>
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
            <h1>Admin</h1>
            <p class="text-muted">Manage users, view activity, and configure settings</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

	<!-- Admin Tabs -->
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link" href="vehicles">Vehicles</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="users">Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="activity_log">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="notifications">Notifications</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="announcements">Announcements</a>
            </li>
            <?php if (!empty($currentUser['is_super_admin'])): ?>
            <li class="nav-item">
                <a class="nav-link" href="booking_rules">Booking Rules</a></li>
            <li class="nav-item"><a class="nav-link" href="security">Security</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings">Settings</a>
            </li>
            <?php endif; ?>
        </ul>
        


        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-success"><?= count($drivers) ?></div>
                        <small class="text-muted">Drivers</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-primary"><?= count($staff) ?></div>
                        <small class="text-muted">Staff</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-danger"><?= count($admins) ?></div>
                        <small class="text-muted">Admins</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-secondary"><?= count($inactive) ?></div>
                        <small class="text-muted">Inactive</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sub-tabs for user management -->
        <ul class="nav nav-pills mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'list' ? 'active' : '' ?>" href="?tab=list">
                    <i class="bi bi-people me-1"></i>All Users
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'create' ? 'active' : '' ?>" href="?tab=create">
                    <i class="bi bi-person-plus me-1"></i>Add User
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'inactive' ? 'active' : '' ?>" href="?tab=inactive">
                    <i class="bi bi-person-x me-1"></i>Inactive
                    <?php if (count($inactive) > 0): ?>
                        <span class="badge bg-secondary"><?= count($inactive) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <?php if ($tab === 'list'): ?>
            <!-- Search -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" class="row g-2">
                        <input type="hidden" name="tab" value="list">
                        <div class="col-md-8">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by name or email..." value="<?= h($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Search
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="?tab=list" class="btn btn-outline-secondary w-100">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

           <?php if ($multiCompany): ?>
            <div class="alert alert-info d-flex align-items-start mb-4">
                <i class="bi bi-building me-2 mt-1"></i>
                <div>
                    <strong>Multi-Entity Fleet:</strong> Your organisation has multiple companies configured.
                    A user's <em>Company</em> assignment determines which vehicles they can see and book.
                    Drivers and Staff see only their company's fleet; Fleet Admins see all vehicles across all entities.
                    To change a user's company, edit them in Snipe-IT or use the Company selector when adding a new user.
                </div>
            </div>
            <?php endif; ?>

            <!-- Drivers -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="bi bi-car-front me-2"></i>Drivers (<?= count($drivers) ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($drivers)): ?>
                        <p class="text-muted text-center py-4">No drivers found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <?php if ($multiCompany): ?><th>Company</th><?php endif; ?>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th class="text-center">VIP</th>
                                        <th class="text-center">Training</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($drivers as $user): ?>
                                    <?php $userVip = !empty($user['vip']); ?>
                                    <tr>
                                        <td><strong><?= h($user['name']) ?></strong><?= $multiCompany ? get_company_badge($user, $pdo) : '' ?></td>
                                        <?php if ($multiCompany): ?><td><?= h($user['company']['name'] ?? '—') ?></td><?php endif; ?>
                                        <td><?= h($user['email'] ?? '-') ?></td>
                                        <td><code><?= h($user['username']) ?></code></td>
<td class="text-center">
                                            <form method="post" class="d-inline" onsubmit="return confirm('<?= $userVip ? "Remove VIP status?" : "Grant VIP status (auto-approve reservations)?" ?>');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_vip">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <input type="hidden" name="current_vip" value="<?= $userVip ? '1' : '0' ?>">
                                                <button type="submit" class="btn btn-sm <?= $userVip ? 'btn-warning' : 'btn-outline-secondary' ?>"
                                                        title="<?= $userVip ? 'Remove VIP' : 'Make VIP' ?>">
                                                    <i class="bi bi-star<?= $userVip ? '-fill' : '' ?>"></i>
                                                </button>
                                            </form>
                                        </td>
<td class="text-center">
                                            <?php
                                            $stmtTC = $pdo->prepare("SELECT training_completed, training_date FROM users WHERE email = ?");
                                            $stmtTC->execute([$user['email'] ?? '']);
                                            $tcRow = $stmtTC->fetch();
                                            $isTrained = !empty($tcRow['training_completed']);
                                            $trainingDateStr = $tcRow['training_date'] ?? '';
                                            $trainingExpiry = '';
                                            $isExpired = false;
                                            $isExpiringSoon = false;
                                            if ($isTrained && $trainingDateStr && $globalValidityMonths > 0) {
                                                $expiryTs = strtotime($trainingDateStr . " +{$globalValidityMonths} months");
                                                $trainingExpiry = date('M j, Y', $expiryTs);
                                                $isExpired = time() > $expiryTs;
                                                $isExpiringSoon = !$isExpired && (time() > strtotime("-15 days", $expiryTs));
                                            }
                                            $btnClass = 'btn-outline-danger';
                                            $iconSuffix = '';
                                            $tooltip = 'Training NOT completed — click to set training date';
                                            if ($isTrained && !$isExpired) {
                                                $btnClass = $isExpiringSoon ? 'btn-warning' : 'btn-success';
                                                $iconSuffix = '-fill';
                                                $tooltip = 'Trained: ' . date('M j, Y', strtotime($trainingDateStr));
                                                if ($trainingExpiry) {
                                                    $tooltip .= $isExpiringSoon ? ' | EXPIRING: ' . $trainingExpiry : ' | Expires: ' . $trainingExpiry;
                                                }
                                                $tooltip .= ' — click to clear';
                                            } elseif ($isTrained && $isExpired) {
                                                $btnClass = 'btn-danger';
                                                $iconSuffix = '-fill';
                                                $tooltip = 'EXPIRED on ' . $trainingExpiry . ' (trained: ' . date('M j, Y', strtotime($trainingDateStr)) . ') — click to renew';
                                            }
                                            if (!$globalTrainingRequired) {
                                                $tooltip .= ' [Training enforcement OFF]';
                                            }
                                            $userEmailSafe = h($user['email'] ?? '');
                                            $userId = $user['id'] ?? 0;
                                            ?>
                                            <?php if ($isTrained && !$isExpired): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Clear training status for this driver? The training date will be preserved in the database.');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_training">
                                                    <input type="hidden" name="user_email" value="<?= $userEmailSafe ?>">
                                                    <input type="hidden" name="current_training" value="1">
                                                    <button type="submit" class="btn btn-sm <?= $btnClass ?>" title="<?= h($tooltip) ?>">
                                                        <i class="bi bi-mortarboard<?= $iconSuffix ?>"></i>
                                                    </button>
                                                </form>
                                                <?php if ($trainingExpiry): ?>
                                                    <div class="small <?= $isExpiringSoon ? 'text-warning fw-bold' : 'text-muted' ?>" style="font-size:0.7rem;">
                                                        <?= $isExpiringSoon ? 'Exp: ' : '' ?><?= $trainingExpiry ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm <?= $btnClass ?>" title="<?= h($tooltip) ?>"
                                                    onclick="document.getElementById('trainingModal<?= $userId ?>').style.display='block'">
                                                    <i class="bi bi-mortarboard<?= $iconSuffix ?>"></i>
                                                </button>
                                                <?php if ($isExpired && $trainingExpiry): ?>
                                                    <div class="small text-danger fw-bold" style="font-size:0.7rem;">Exp: <?= $trainingExpiry ?></div>
                                                <?php endif; ?>
                                                <div id="trainingModal<?= $userId ?>" style="display:none;" class="mt-1">
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Set training as completed?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_training">
                                                        <input type="hidden" name="user_email" value="<?= $userEmailSafe ?>">
                                                        <input type="hidden" name="current_training" value="0">
                                                        <div class="input-group input-group-sm" style="width:180px;">
                                                            <input type="date" name="training_date_input" class="form-control form-control-sm"
                                                                value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                                                            <button type="submit" class="btn btn-success btn-sm" title="Save training date">
                                                                <i class="bi bi-check"></i>
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </td>                                       

 <td>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this user?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="deactivate_user">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Deactivate">
                                                   <i class="bi bi-pause-circle"></i>
                                                </button>
                                            </form>
                                            <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/users/<?= $user['id'] ?>" target="_blank" 
                                               class="btn btn-sm btn-outline-secondary" title="View in Snipe-IT">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Staff -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-person-badge me-2"></i>Fleet Staff (<?= count($staff) ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($staff)): ?>
                        <p class="text-muted text-center py-4">No staff found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <?php if ($multiCompany): ?><th>Company</th><?php endif; ?>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th class="text-center">VIP</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($staff as $user): ?>
                                    <?php $userVip = !empty($user['vip']); ?>
                                    <tr>
                                        <td><strong><?= h($user['name']) ?></strong><?= $multiCompany ? get_company_badge($user, $pdo) : '' ?></td>
                                        <?php if ($multiCompany): ?><td><?= h($user['company']['name'] ?? '—') ?></td><?php endif; ?>
                                        <td><?= h($user['email'] ?? '-') ?></td>
                                        <td><code><?= h($user['username']) ?></code></td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline" onsubmit="return confirm('<?= $userVip ? "Remove VIP status?" : "Grant VIP status (auto-approve reservations)?" ?>');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_vip">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <input type="hidden" name="current_vip" value="<?= $userVip ? '1' : '0' ?>">
                                                <button type="submit" class="btn btn-sm <?= $userVip ? 'btn-warning' : 'btn-outline-secondary' ?>"
                                                        title="<?= $userVip ? 'Remove VIP' : 'Make VIP' ?>">
                                                    <i class="bi bi-star<?= $userVip ? '-fill' : '' ?>"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/users/<?= $user['id'] ?>" target="_blank" 
                                               class="btn btn-sm btn-outline-secondary" title="View in Snipe-IT">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Admins -->
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>Fleet Admins (<?= count($admins) ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($admins)): ?>
                        <p class="text-muted text-center py-4">No admins found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <?php if ($multiCompany): ?><th>Company</th><?php endif; ?>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th class="text-center">VIP</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admins as $user): ?>
                                    <?php $userVip = !empty($user['vip']); ?>
                                    <tr>
                                        <td><strong><?= h($user['name']) ?></strong><?= $multiCompany ? get_company_badge($user, $pdo) : '' ?></td>
                                        <?php if ($multiCompany): ?><td><?= h($user['company']['name'] ?? '—') ?></td><?php endif; ?>
                                        <td><?= h($user['email'] ?? '-') ?></td>
                                        <td><code><?= h($user['username']) ?></code></td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline" onsubmit="return confirm('<?= $userVip ? "Remove VIP status?" : "Grant VIP status (auto-approve reservations)?" ?>');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_vip">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <input type="hidden" name="current_vip" value="<?= $userVip ? '1' : '0' ?>">
                                                <button type="submit" class="btn btn-sm <?= $userVip ? 'btn-warning' : 'btn-outline-secondary' ?>"
                                                        title="<?= $userVip ? 'Remove VIP' : 'Make VIP' ?>">
                                                    <i class="bi bi-star<?= $userVip ? '-fill' : '' ?>"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/users/<?= $user['id'] ?>" target="_blank" 
                                               class="btn btn-sm btn-outline-secondary" title="View in Snipe-IT">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>



<?php elseif ($tab === 'create'): ?>
            <!-- Create New User -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Add New Driver/Staff</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">This will create a user in Snipe-IT with the appropriate permissions.</p>
                    <form method="post">
                                                <?= csrf_field() ?>
                    <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_user">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" required
                                       placeholder="user@example.com">
                                <small class="text-muted">Email will also be used as username</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Groups <span class="text-danger">*</span></label>
                                <select name="groups[]" class="form-select" multiple size="3" required>
                                    <option value="<?= SNIPEIT_GROUP_DRIVERS ?>" selected>Drivers - Can book vehicles</option>
                                    <option value="<?= SNIPEIT_GROUP_FLEET_STAFF ?>">Fleet Staff - Can approve bookings</option>
                                    <option value="<?= SNIPEIT_GROUP_FLEET_ADMIN ?>">Fleet Admin - Full access</option>
                                </select>
                                <small class="text-muted">Hold Ctrl/Cmd to select multiple groups</small>
                            </div>

                            <?php if ($multiCompany && !empty($allCompanies)): ?>
                            <div class="col-md-6">
                                <label class="form-label">Company</label>
                                <select name="company_id" class="form-select">
                                    <option value="">— No Company —</option>
                                    <?php foreach ($allCompanies as $co): ?>
                                        <option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Assigns the user to a fleet entity. Determines which vehicles they can see and book.</small>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-12">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input type="checkbox" name="can_login" class="form-check-input" id="canLogin" value="1" checked>
                                            <label class="form-check-label" for="canLogin">
                                                <strong>Can Login</strong>
                                            </label>
                                            <br><small class="text-muted">Uncheck to create inactive user</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input type="checkbox" name="vip" class="form-check-input" id="isVip" value="1">
                                            <label class="form-check-label" for="isVip">
                                                <strong>VIP User</strong>
                                            </label>
                                            <br><small class="text-muted">Auto-approve reservations</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2" 
                                          placeholder="e.g., Safety driving certification date, department..."></textarea>
                            </div>
                            
                            <div class="col-12">
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Note:</strong> User will be created in Snipe-IT with a temporary password. 
                                    They will log into SnipeScheduler using Microsoft OAuth (their corporate account).
                                    Access permissions are determined by their Snipe-IT groups.
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-person-plus me-2"></i>Create User
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($tab === 'inactive'): ?>        


            <!-- Inactive Users -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person-x me-2"></i>Inactive Users</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($inactive)): ?>
                        <p class="text-muted text-center py-4">No inactive users.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inactive as $user): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-muted"><?= h($user['name']) ?></strong>
                                            <span class="badge bg-secondary ms-2">Inactive</span>
                                        </td>
                                        <td><?= h($user['email'] ?? '-') ?></td>
                                        <td>
                                            <form method="post" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="activate_user">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="Reactivate">
                                                    <i class="bi bi-play-circle me-1"></i>Activate
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Link to Snipe-IT -->
        <div class="text-center mt-4">
            <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/users" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-up-right me-2"></i>Open Snipe-IT User Management
            </a>
        </div>

    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
