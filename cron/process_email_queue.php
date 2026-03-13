<?php
/**
 * Process pending emails in the queue
 * Run via cron every 5 minutes
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/teams_service.php';
require_once SRC_PATH . '/notification_service.php';

// Prevent concurrent runs with file lock
$lockFile = sys_get_temp_dir() . '/snipescheduler_email_queue.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Another instance is already running. Exiting.\n";
    fclose($lockFp);
    exit(0);
}
register_shutdown_function(function() use ($lockFp, $lockFile) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
});

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require CONFIG_PATH . '/config.php';
$smtpConfig = $config['smtp'];


// Load system_settings once per run (needed for Teams webhook URLs)
$system_settings = NotificationService::loadSystemSettings($pdo);

// ---------------------------------------------------------------
// TEAMS: process pending Teams notifications
// ---------------------------------------------------------------
$teams_stmt = $pdo->query("
    SELECT * FROM email_queue
    WHERE channel = 'teams' AND status = 'pending'
    ORDER BY created_at ASC
    LIMIT 10
");
$teams_queue = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($teams_queue)) {
    echo "Processing " . count($teams_queue) . " Teams notification(s)...\n\n";
    $teams_sent   = 0;
    $teams_failed = 0;

    foreach ($teams_queue as $row) {
        echo "Teams #{$row['id']}: {$row['subject']} [{$row['teams_audience']}]... ";

        $pdo->prepare("UPDATE email_queue SET status = 'processing' WHERE id = ?")
            ->execute([$row['id']]);

        $success = TeamsService::deliver($row, $system_settings);

        if ($success) {
            $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")
                ->execute([$row['id']]);
            echo "Sent\n";
            $teams_sent++;
        } else {
            $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = 'TeamsService::deliver returned false' WHERE id = ?")
                ->execute([$row['id']]);
            echo "Failed\n";
            $teams_failed++;
        }
    }

    echo "\n=== Teams Summary ===\n";
    echo "Sent: $teams_sent\n";
    echo "Failed: $teams_failed\n\n";
}

// Get pending emails (max 10 per run to avoid timeout)
$stmt = $pdo->query("
    SELECT * FROM email_queue 
    WHERE channel = 'email' AND status = 'pending' AND attempts < 3
    ORDER BY created_at ASC 
    LIMIT 10
");
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($emails)) {
    echo "No pending emails.\n";
    exit(0);
}

echo "Processing " . count($emails) . " emails...\n\n";

$sent = 0;
$failed = 0;

foreach ($emails as $email) {
    echo "Sending #{$email['id']}: {$email['subject']} to {$email['to_email']}... ";
    
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $smtpConfig['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpConfig['username'];
        $mail->Password   = $smtpConfig['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpConfig['port'];
        $mail->Timeout    = 30;
        
        $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
        $mail->addAddress($email['to_email'], $email['to_name'] ?? '');
        
        $mail->isHTML(true);
        $mail->Subject = $email['subject'];
        $mail->Body    = $email['body'];
        $mail->AltBody = strip_tags($email['body']);
        
        $mail->send();
        
        // Mark as sent
        $update = $pdo->prepare("
            UPDATE email_queue 
            SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 
            WHERE id = ?
        ");
        $update->execute([$email['id']]);
        
        echo "Sent\n";
        $sent++;
        
        // Small delay between emails
        usleep(500000);
        
    } catch (Exception $e) {
        // Mark as failed
        $update = $pdo->prepare("
            UPDATE email_queue 
            SET status = CASE WHEN attempts >= 2 THEN 'failed' ELSE status END,
                attempts = attempts + 1,
                error_message = ?
            WHERE id = ?
        ");
        $update->execute([$e->getMessage(), $email['id']]);
        
        echo "Failed: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n=== Summary ===\n";
echo "Sent: $sent\n";
echo "Failed: $failed\n";

// Show remaining pending
$remaining = $pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")->fetchColumn();
echo "Remaining in queue: $remaining\n";
