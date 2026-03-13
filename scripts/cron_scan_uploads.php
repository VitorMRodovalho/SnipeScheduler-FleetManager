#!/usr/bin/env php
<?php
/**
 * CRON: ClamAV Upload Scanner
 *
 * Scans the uploads/inspections/ directory for malware using ClamAV (clamscan).
 * Infected files are moved to uploads/quarantine/ and an admin notification is fired.
 *
 * Schedule: hourly
 *   0 * * * * php /var/www/snipescheduler/scripts/cron_scan_uploads.php >> /var/log/snipescheduler/upload_scan.log 2>&1
 *
 * Prerequisites:
 *   sudo apt install clamav clamav-daemon
 *   sudo freshclam          # update virus definitions
 *   sudo systemctl enable clamav-freshclam
 *
 * @since v2.2.0
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

// Prevent concurrent runs
$lockFile = sys_get_temp_dir() . '/fleet_scan_uploads.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " [SKIP] Another scan is already running.\n";
    exit(0);
}

echo date('Y-m-d H:i:s') . " [START] Upload malware scan\n";

// ── Paths ──
$uploadDir     = realpath(__DIR__ . '/../uploads/inspections');
$quarantineDir = realpath(__DIR__ . '/../uploads/quarantine');

if (!$uploadDir || !is_dir($uploadDir)) {
    echo date('Y-m-d H:i:s') . " [SKIP] Upload directory not found: uploads/inspections/\n";
    exit(0);
}

if (!$quarantineDir) {
    @mkdir(__DIR__ . '/../uploads/quarantine', 0750, true);
    $quarantineDir = realpath(__DIR__ . '/../uploads/quarantine');
}

// ── Verify clamscan is available ──
$clamscanPath = trim(shell_exec('which clamscan 2>/dev/null') ?? '');
if (empty($clamscanPath)) {
    echo date('Y-m-d H:i:s') . " [ERROR] clamscan not found. Install ClamAV: sudo apt install clamav\n";

    // Record scan failure in system_settings so the Security Dashboard can display it
    try {
        $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('upload_scan_status', 'error_no_clamscan')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute();
        $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('upload_scan_last_run', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute([date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // ignore
    }
    exit(1);
}

// ── Run clamscan ──
// --infected: only print infected files
// --no-summary: skip the summary line (we parse output ourselves)
// --recursive: scan subdirectories
// --move: move infected files to quarantine
$cmd = sprintf(
    '%s --infected --recursive --move=%s %s 2>&1',
    escapeshellarg($clamscanPath),
    escapeshellarg($quarantineDir),
    escapeshellarg($uploadDir)
);

$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);

$outputText = implode("\n", $output);

// ClamAV exit codes: 0 = clean, 1 = infected found, 2 = error
$infectedFiles = [];
foreach ($output as $line) {
    // Format: "/path/to/file: VirusName FOUND"
    if (preg_match('/^(.+?):\s+(.+?)\s+FOUND$/i', $line, $m)) {
        $infectedFiles[] = [
            'file'  => basename($m[1]),
            'path'  => $m[1],
            'virus' => $m[2],
        ];
    }
}

$infectedCount = count($infectedFiles);
$scanStatus = $exitCode === 0 ? 'clean' : ($exitCode === 1 ? 'infected_found' : 'error');

echo date('Y-m-d H:i:s') . " [RESULT] Exit code: {$exitCode}, Infected: {$infectedCount}\n";
if ($infectedCount > 0) {
    foreach ($infectedFiles as $inf) {
        echo date('Y-m-d H:i:s') . " [QUARANTINED] {$inf['file']} — {$inf['virus']}\n";
    }
}

// ── Update system_settings for Security Dashboard ──
try {
    $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('upload_scan_status', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ")->execute([$scanStatus]);

    $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('upload_scan_last_run', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ")->execute([date('Y-m-d H:i:s')]);

    $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('upload_scan_infected_count', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ")->execute([(string)$infectedCount]);
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " [WARN] Could not update system_settings: " . $e->getMessage() . "\n";
}

// ── Activity log ──
activity_log_event('upload_scan', "Upload scan completed: {$scanStatus}, {$infectedCount} infected file(s)", [
    'metadata' => [
        'status'         => $scanStatus,
        'infected_count' => $infectedCount,
        'infected_files' => array_column($infectedFiles, 'file'),
    ],
]);

// ── Notify admins if malware found ──
if ($infectedCount > 0) {
    try {
        require_once SRC_PATH . '/notification_service.php';
        NotificationService::fire('malware_detected', [
            'infected_count' => $infectedCount,
            'infected_files' => $infectedFiles,
            'scan_time'      => date('M j, Y g:i A'),
            'quarantine_dir' => 'uploads/quarantine/',
        ], $pdo);
    } catch (Throwable $e) {
        echo date('Y-m-d H:i:s') . " [WARN] Could not fire malware_detected notification: " . $e->getMessage() . "\n";
    }
}

echo date('Y-m-d H:i:s') . " [DONE] Upload scan complete\n";

flock($fp, LOCK_UN);
fclose($fp);
