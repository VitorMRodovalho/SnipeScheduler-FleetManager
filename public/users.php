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
require_once SRC_PATH . '/layout.php';

$active = 'activity_log.php'; // Keep Admin highlighted in nav
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
        
        if (empty($firstName) || empty($email)) {
            $error = 'First name and email are required.';
        } else {
            $existingUser = get_snipeit_user_by_email($email);
            if ($existingUser) {
                $error = 'A user with this email already exists in Snipe-IT.';
            } else {
                // Handle multiple groups
                if (is_array($groups)) {
                    $groups = implode(',', $groups);
                }
                
                $userData = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'username' => $email,
                    'email' => $email,
                    'activated' => $canLogin,
                    'groups' => $groups,
                    'vip' => $isVip,
                    'notes' => $notes ?: 'Created via SnipeScheduler - Safety driving certified',
                ];
                
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
            $error = 'Failed to activate user.';
        }
    } elseif ($action === 'toggle_vip') {
        $userId = (int)$_POST['user_id'];
        $currentVip = $_POST['current_vip'] === '1';
        $newVip = !$currentVip;
        
        if (set_user_vip_status($userId, $newVip)) {
            $success = $newVip ? 'User marked as VIP.' : 'VIP status removed.';
        } else {
            $error = 'Failed to update VIP status.';
        }
    }
}
// Get users from Snipe-IT
$search = $_GET['search'] ?? '';
$allUsers = get_snipeit_users(200, $search);

// Filter and categorize users - users can appear in MULTIPLE groups
$drivers = [];
$staff = [];
$admins = [];
$inactive = [];

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
    <link rel="stylesheet" href="assets/style.css?v=1.3.2">
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

        <!-- User Info -->
        <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-light rounded">
            <div>
                <span class="text-muted">Logged in as:</span> 
                <strong><?= h($userName) ?></strong> 
                <span class="text-muted">(<?= h($userEmail) ?>)</span>
            </div>
            <a href="logout" class="text-decoration-none">Log out</a>
        </div>

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
                <a class="nav-link" href="security">Security</a>
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
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th class="text-center">VIP</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($drivers as $user): ?>
                                    <?php $userVip = !empty($user['vip']); ?>
                                    <tr>
                                        <td><strong><?= h($user['name']) ?></strong></td>
                                        <td><?= h($user['email'] ?? '-') ?></td>
                                        <td><code><?= h($user['username']) ?></code></td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline">
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
                                            <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this user?');">
                                                <input type="hidden" name="action" value="deactivate_user">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Deactivate">
                                                    <i class="bi bi-pause-circle"></i>
                                                </button>
                                            </form>
                                            <a href="https://inventory.amtrakfdt.com/users/<?= $user['id'] ?>" target="_blank" 
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
                                        <td><strong><?= h($user['name']) ?></strong></td>
                                        <td><?= h($user['email'] ?? '-') ?></td>
                                        <td><code><?= h($user['username']) ?></code></td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline">
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
                                            <a href="https://inventory.amtrakfdt.com/users/<?= $user['id'] ?>" target="_blank" 
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
                                        <td><strong><?= h($user['name']) ?></strong></td>
                                        <td><?= h($user['email'] ?? '-') ?></td>
                                        <td><code><?= h($user['username']) ?></code></td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline">
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
                                            <a href="https://inventory.amtrakfdt.com/users/<?= $user['id'] ?>" target="_blank" 
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
                                       placeholder="user@aecom.com">
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
            <a href="https://inventory.amtrakfdt.com/users" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-up-right me-2"></i>Open Snipe-IT User Management
            </a>
        </div>

    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
