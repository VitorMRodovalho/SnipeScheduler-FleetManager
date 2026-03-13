<?php
/**
 * CRON: Training Expiry Check
 * Runs weekly (Monday 8am). Finds drivers with expiring/expired training
 * and sends digest notification to Fleet Staff/Admin.
 *
 * Also sends individual reminder emails to drivers whose training
 * is expiring within 15 days.
 *
 * @since v1.4.x
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/email_service.php';
require_once SRC_PATH . '/notification_service.php';

// Load training settings
$stmtCfg = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('training_required', 'training_validity_months')");
$stmtCfg->execute();
$cfg = [];
while ($r = $stmtCfg->fetch()) { $cfg[$r['setting_key']] = $r['setting_value']; }

$trainingRequired = ($cfg['training_required'] ?? '1') === '1';
$validityMonths = (int)($cfg['training_validity_months'] ?? 12);

// Exit early if training is disabled or no expiration configured
if (!$trainingRequired) {
    echo "[" . date('Y-m-d H:i:s') . "] Training enforcement disabled. Skipping.\n";
    exit(0);
}

if ($validityMonths <= 0) {
    echo "[" . date('Y-m-d H:i:s') . "] No expiration period set. Skipping.\n";
    exit(0);
}

// Find drivers with training that is expired or expiring within 15 days
$stmt = $pdo->prepare("
    SELECT u.email, u.name, u.training_date
    FROM users u
    WHERE u.training_completed = 1
      AND u.training_date IS NOT NULL
");
$stmt->execute();
$users = $stmt->fetchAll();

$expiring = [];
$expired = [];
$now = time();
$fifteenDays = 15 * 86400;

foreach ($users as $u) {
    $expiryTs = strtotime($u['training_date'] . " +{$validityMonths} months");
    $expiryDate = date('M j, Y', $expiryTs);
    $trainDate = date('M j, Y', strtotime($u['training_date']));

    $record = [
        'name' => $u['name'],
        'email' => $u['email'],
        'training_date' => $trainDate,
        'expiry_date' => $expiryDate,
        'expiry_ts' => $expiryTs,
    ];

    if ($now > $expiryTs) {
        $record['status'] = 'expired';
        $expired[] = $record;
    } elseif (($expiryTs - $now) <= $fifteenDays) {
        $record['status'] = 'expiring';
        $expiring[] = $record;
    }
}

$totalIssues = count($expiring) + count($expired);

if ($totalIssues === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] No training issues found. All drivers current.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Found {$totalIssues} driver(s) needing attention ";
echo "(" . count($expired) . " expired, " . count($expiring) . " expiring soon)\n";

// Send staff/admin digest via NotificationService
$allIssues = array_merge($expired, $expiring);

// Sort: expired first, then by expiry date ascending
usort($allIssues, function($a, $b) {
    if ($a['status'] !== $b['status']) return $a['status'] === 'expired' ? -1 : 1;
    return $a['expiry_ts'] - $b['expiry_ts'];
});

try {
    NotificationService::fire('training_expiring', [
        'drivers' => $allIssues,
        'expiring_count' => count($expiring),
        'expired_count' => count($expired),
    ], $pdo);
    echo "[" . date('Y-m-d H:i:s') . "] Staff/admin digest notification sent.\n";
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR sending digest: " . $e->getMessage() . "\n";
}

// Send individual reminders to drivers via public EmailService method
$emailService = new EmailService();

foreach ($expiring as $d) {
    if ($emailService->notifyDriverTrainingReminder($d['email'], $d['name'], $d['expiry_date'], false)) {
        echo "[" . date('Y-m-d H:i:s') . "] Reminder sent to {$d['email']}\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR sending to {$d['email']}\n";
    }
}

foreach ($expired as $d) {
    if ($emailService->notifyDriverTrainingReminder($d['email'], $d['name'], $d['expiry_date'], true)) {
        echo "[" . date('Y-m-d H:i:s') . "] Expired notice sent to {$d['email']}\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR sending to {$d['email']}\n";
    }
}
echo "[" . date('Y-m-d H:i:s') . "] Training expiry check complete.\n";
