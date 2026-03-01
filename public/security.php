<?php
/**
 * Security & Backup Dashboard
 * Super Admin only - View security status, backup logs, and maintenance commands
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active = 'security.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isSuperAdmin = !empty($currentUser['is_super_admin']);

// Super Admin only
if (!$isSuperAdmin) {
    header('Location: dashboard');
    exit;
}

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

// Get backup log
$backupLog = '';
$backupLogPath = '/var/log/snipescheduler-backup.log';
if (file_exists($backupLogPath) && is_readable($backupLogPath)) {
    $backupLog = shell_exec("tail -50 " . escapeshellarg($backupLogPath) . " 2>/dev/null");
}

// Get backup files list
$backupFiles = [];
$backupDir = '/var/backups/snipescheduler/';
if (is_dir($backupDir)) {
    $files = shell_exec("ls -lh " . escapeshellarg($backupDir) . " 2>/dev/null | grep '.tar.gz'");
    $backupFiles = array_filter(explode("\n", $files));
}

// Get last backup info
$lastBackup = '';
if (!empty($backupFiles)) {
    $lastBackup = end($backupFiles);
}

// Security checks
$securityChecks = [
    'config_permissions' => [
        'label' => 'Config file permissions (640)',
        'check' => function() {
            $perms = substr(sprintf('%o', fileperms('/var/www/snipescheduler/config/config.php')), -3);
            return $perms === '640';
        }
    ],
    'htaccess_exists' => [
        'label' => '.htaccess security headers',
        'check' => function() {
            return file_exists('/var/www/snipescheduler/public/.htaccess');
        }
    ],
    'csrf_protection' => [
        'label' => 'CSRF protection enabled',
        'check' => function() {
            return file_exists('/var/www/snipescheduler/src/csrf.php');
        }
    ],
    'directory_protected' => [
        'label' => 'Sensitive directories protected',
        'check' => function() {
            return file_exists('/var/www/snipescheduler/.htaccess');
        }
    ],
    'backup_script' => [
        'label' => 'Backup script exists',
        'check' => function() {
            return file_exists('/usr/local/bin/backup-snipescheduler.sh');
        }
    ],
    'backup_recent' => [
        'label' => 'Backup within last 48 hours',
        'check' => function() {
            $dir = '/var/backups/snipescheduler/';
            if (!is_dir($dir)) return false;
            $files = glob($dir . '*.tar.gz');
            if (empty($files)) return false;
            $latest = max(array_map('filemtime', $files));
            return (time() - $latest) < 172800; // 48 hours
        }
    ],
];

// Run security checks
$checkResults = [];
foreach ($securityChecks as $key => $check) {
    try {
        $checkResults[$key] = $check['check']();
    } catch (Exception $e) {
        $checkResults[$key] = false;
    }
}
$passedChecks = count(array_filter($checkResults));
$totalChecks = count($checkResults);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security & Backup</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.3.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
    <div class="container">
        <div class="page-shell">
            <?= layout_logo_tag() ?>
            <div class="page-header">
                <h1><i class="bi bi-shield-lock me-2"></i>Security & Backup</h1>
                <p class="text-muted">System security status, backup logs, and maintenance commands</p>
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
                    <a class="nav-link" href="notifications">Notifications</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="announcements">Announcements</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="security">Security</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings">Settings</a>
                </li>
            </ul>
            
            <div class="top-bar mb-3">
                <div class="top-bar-user">
                    Logged in as: <strong><?= h($userName) ?></strong>
                </div>
                <div class="top-bar-actions">
                    <a href="logout" class="btn btn-link btn-sm">Log out</a>
                </div>
            </div>
            
            <div class="row">
                <!-- Security Status -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Security Status</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fs-4">
                                    <?php if ($passedChecks === $totalChecks): ?>
                                        <span class="text-success"><i class="bi bi-check-circle-fill"></i> All Checks Passed</span>
                                    <?php else: ?>
                                        <span class="text-warning"><i class="bi bi-exclamation-triangle-fill"></i> <?= $passedChecks ?>/<?= $totalChecks ?> Passed</span>
                                    <?php endif; ?>
                                </span>
                                <span class="badge bg-secondary"><?= date('M j, Y g:i A') ?></span>
                            </div>
                            
                            <table class="table table-sm">
                                <tbody>
                                    <?php foreach ($securityChecks as $key => $check): ?>
                                        <tr>
                                            <td>
                                                <?php if ($checkResults[$key]): ?>
                                                    <i class="bi bi-check-circle-fill text-success"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-x-circle-fill text-danger"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($check['label']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Backup Status -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-hdd me-2"></i>Backup Status</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($lastBackup): ?>
                                <div class="alert alert-success mb-3">
                                    <strong>Latest Backup:</strong><br>
                                    <code class="text-dark"><?= h($lastBackup) ?></code>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">No backups found</div>
                            <?php endif; ?>
                            
                            <h6>Backup Schedule</h6>
                            <ul class="list-unstyled mb-3">
                                <li><i class="bi bi-clock me-2"></i>Daily at 2:00 AM</li>
                                <li><i class="bi bi-calendar me-2"></i>Retention: 30 days</li>
                                <li><i class="bi bi-folder me-2"></i>Location: /var/backups/snipescheduler/</li>
                            </ul>
                            
                            <h6>Recent Backups (<?= count($backupFiles) ?>)</h6>
                            <div class="small text-muted" style="max-height: 150px; overflow-y: auto;">
                                <?php foreach (array_reverse($backupFiles) as $file): ?>
                                    <div><?= h($file) ?></div>
                                <?php endforeach; ?>
                                <?php if (empty($backupFiles)): ?>
                                    <em>No backup files found</em>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Maintenance Commands Reference -->
            <div class="card mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="bi bi-terminal me-2"></i>Maintenance Commands Reference</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">These commands are for server administration via SSH. They are read-only references.</p>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="bi bi-hdd me-2"></i>Backup Commands</h6>
                            <table class="table table-sm table-bordered">
                                <tr>
                                    <td><code>sudo /usr/local/bin/backup-snipescheduler.sh</code></td>
                                    <td>Run backup now</td>
                                </tr>
                                <tr>
                                    <td><code>sudo ls -la /var/backups/snipescheduler/</code></td>
                                    <td>List backups</td>
                                </tr>
                                <tr>
                                    <td><code>cat /var/log/snipescheduler-backup.log</code></td>
                                    <td>View backup log</td>
                                </tr>
                            </table>
                            
                            <h6 class="mt-3"><i class="bi bi-database me-2"></i>Database Commands</h6>
                            <table class="table table-sm table-bordered">
                                <tr>
                                    <td><code>sudo mysql -u root snipescheduler</code></td>
                                    <td>Access database</td>
                                </tr>
                                <tr>
                                    <td><code>sudo mysqldump -u root snipescheduler > backup.sql</code></td>
                                    <td>Export database</td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6><i class="bi bi-arrow-repeat me-2"></i>Service Commands</h6>
                            <table class="table table-sm table-bordered">
                                <tr>
                                    <td><code>sudo systemctl reload apache2</code></td>
                                    <td>Reload Apache</td>
                                </tr>
                                <tr>
                                    <td><code>sudo systemctl restart apache2</code></td>
                                    <td>Restart Apache</td>
                                </tr>
                                <tr>
                                    <td><code>sudo tail -f /var/log/apache2/error.log</code></td>
                                    <td>Watch error log</td>
                                </tr>
                            </table>
                            
                            <h6 class="mt-3"><i class="bi bi-shield me-2"></i>Security Commands</h6>
                            <table class="table table-sm table-bordered">
                                <tr>
                                    <td><code>sudo chmod 640 /var/www/snipescheduler/config/config.php</code></td>
                                    <td>Fix config permissions</td>
                                </tr>
                                <tr>
                                    <td><code>sudo chown www-data:www-data -R /var/www/snipescheduler</code></td>
                                    <td>Fix ownership</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <h6 class="mt-3"><i class="bi bi-arrow-clockwise me-2"></i>Restore Procedure</h6>
                    <div class="bg-light p-3 rounded">
                        <pre class="mb-0"><code># Extract backup
cd /var/backups/snipescheduler
sudo tar -xzf backup_YYYY-MM-DD_HHMMSS.tar.gz

# Restore database
mysql -u root snipescheduler < YYYY-MM-DD_HHMMSS/database.sql

# Restore config
sudo cp YYYY-MM-DD_HHMMSS/config.php /var/www/snipescheduler/config/</code></pre>
                    </div>
                </div>
            </div>
            
            <!-- Backup Log -->
            <div class="card">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Backup Log (Last 50 Lines)</h5>
                    <span class="small">/var/log/snipescheduler-backup.log</span>
                </div>
                <div class="card-body">
                    <pre class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85rem;"><?= h($backupLog ?: 'No backup log available or not readable.') ?></pre>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php layout_footer(); ?>
</body>
</html>
