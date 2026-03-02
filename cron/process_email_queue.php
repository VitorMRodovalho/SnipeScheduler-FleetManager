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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require CONFIG_PATH . '/config.php';
$smtpConfig = $config['smtp'];

// Get pending emails (max 10 per run to avoid timeout)
$stmt = $pdo->query("
    SELECT * FROM email_queue 
    WHERE status = 'pending' AND attempts < 3
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
