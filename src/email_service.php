<?php
/**
 * Fleet Email Service
 * Uses existing SMTP config from config.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class FleetEmailService
{
    private $config;
    private $pdo;
    private $enabled = true;
    
    public function __construct($pdo)
    {
        $this->config = require CONFIG_PATH . '/config.php';
        $this->pdo = $pdo;
    }
    
    /**
     * Create configured PHPMailer instance
     */
    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $smtp = $this->config['smtp'];
        
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = $smtp['port'];
        $mail->SMTPSecure = $smtp['encryption'] ?? 'tls';
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        
        $fromEmail = $smtp['from_email'] ?: $smtp['username'];
        $fromName = $smtp['from_name'] ?: 'FDT Fleet Management';
        $mail->setFrom($fromEmail, $fromName);
        $mail->isHTML(true);
        
        return $mail;
    }
    
/**
     * Get all staff/admin emails for notifications (from config)
     */
    public function getStaffEmails(): array
    {
        $emails = [];
        $auth = $this->config['auth'] ?? [];
        
        // Get admin emails from config
        if (!empty($auth['microsoft_admin_emails'])) {
            $emails = array_merge($emails, $auth['microsoft_admin_emails']);
        }
        
        // Get staff/checkout emails from config
        if (!empty($auth['microsoft_checkout_emails'])) {
            $emails = array_merge($emails, $auth['microsoft_checkout_emails']);
        }
        
        // Also check Google if configured
        if (!empty($auth['google_admin_emails'])) {
            $emails = array_merge($emails, $auth['google_admin_emails']);
        }
        if (!empty($auth['google_checkout_emails'])) {
            $emails = array_merge($emails, $auth['google_checkout_emails']);
        }
        
        // Remove duplicates and empty values
        $emails = array_unique(array_filter($emails));
        
        return $emails;
    }
    
    /**
     * Get admin emails only (for critical alerts)
     */
    public function getAdminEmails(): array
    {
        $auth = $this->config['auth'] ?? [];
        $emails = array_merge(
            $auth['microsoft_admin_emails'] ?? [],
            $auth['google_admin_emails'] ?? []
        );
        return array_unique(array_filter($emails));
    }
   
    /**
     * Send email - queues if SMTP fails (AWS blocks port 587)
     */
    private function send(PHPMailer $mail): bool
    {
        if (!$this->enabled) {
            error_log("Email disabled: " . $mail->Subject);
            return true;
        }
        
        // Get recipients before attempting send
        $recipients = $mail->getAllRecipientAddresses();
        $toEmail = array_key_first($recipients);
        $toName = $recipients[$toEmail] ?? '';
        
        try {
            $mail->send();
            error_log("Email sent: " . $mail->Subject . " to " . $toEmail);
            return true;
        } catch (Exception $e) {
            error_log("Email SMTP failed, queueing: " . $mail->ErrorInfo);
            
            // Queue the email for later sending (when SES is configured)
            $this->queueEmail($toEmail, $toName, $mail->Subject, $mail->Body);
            return true; // Return true so workflow continues
        }
    }
    
    /**
     * Queue email for later sending
     */
    private function queueEmail(string $toEmail, string $toName, string $subject, string $body): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_queue (to_email, to_name, subject, body, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$toEmail, $toName, $subject, $body]);
            error_log("Email queued: {$subject} to {$toEmail}");
            return true;
        } catch (Exception $e) {
            error_log("Failed to queue email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get pending emails count
     */
    public function getPendingEmailCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'");
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Base email template
     */
    private function template(string $title, string $content, string $actionUrl = '', string $actionText = ''): string
    {
        $button = '';
        if ($actionUrl && $actionText) {
            $button = "<p style='margin: 25px 0;'><a href='{$actionUrl}' style='background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>{$actionText}</a></p>";
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1a5a7a 0%, #2d7d9a 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 25px; border: 1px solid #dee2e6; }
                .footer { background: #e9ecef; padding: 15px; text-align: center; font-size: 12px; color: #6c757d; border-radius: 0 0 8px 8px; }
                .info-box { background: white; border-left: 4px solid #0d6efd; padding: 15px; margin: 15px 0; }
                .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }
                .success-box { background: #d1e7dd; border-left: 4px solid #198754; padding: 15px; margin: 15px 0; }
                .danger-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0; font-size: 24px;'>🚗 FDT Fleet Management</h1>
                    <p style='margin: 5px 0 0 0; opacity: 0.9;'>{$title}</p>
                </div>
                <div class='content'>
                    {$content}
                    {$button}
                </div>
                <div class='footer'>
                    <p>Frederick Douglass Tunnel Project - Fleet Vehicle Management System</p>
                    <p>This is an automated notification. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * NEW RESERVATION - Notify requester + staff
     */
    public function notifyNewReservation(array $reservation): bool
    {
        $baseUrl = 'https://inventory.amtrakfdt.com/booking';
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        // Email to requester
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "Reservation Submitted - {$assetName}";
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p>Your vehicle reservation has been submitted and is pending approval.</p>
                <div class='info-box'>
                    <strong>Reservation Details:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Pickup:</strong> " . date('M j, Y g:i A', strtotime($reservation['start_datetime'])) . "<br>
                    <strong>Return:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "<br>
                    <strong>Status:</strong> Pending Approval
                </div>
                <p>You will receive another email once your reservation is approved or rejected.</p>
            ";
            
            $mail->Body = $this->template('Reservation Submitted', $content, "{$baseUrl}/my_bookings.php", 'View My Reservations');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Email to requester failed: " . $e->getMessage());
        }
        
        // Email to staff
        $staffEmails = $this->getStaffEmails();
        if (!empty($staffEmails)) {
            try {
                $mail = $this->createMailer();
                foreach ($staffEmails as $email) {
                    $mail->addAddress($email);
                }
                $mail->Subject = "🔔 New Reservation Request - {$reservation['user_name']}";
                
                $content = "
                    <p>A new vehicle reservation requires your approval.</p>
                    <div class='warning-box'>
                        <strong>Request Details:</strong><br>
                        <strong>Requested By:</strong> {$reservation['user_name']} ({$reservation['user_email']})<br>
                        <strong>Vehicle:</strong> {$assetName}<br>
                        <strong>Pickup:</strong> " . date('M j, Y g:i A', strtotime($reservation['start_datetime'])) . "<br>
                        <strong>Return:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "
                    </div>
                ";
                
                $mail->Body = $this->template('Approval Required', $content, "{$baseUrl}/approval.php", 'Review & Approve');
                $this->send($mail);
            } catch (Exception $e) {
                error_log("Email to staff failed: " . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * VIP AUTO-APPROVED - Notify requester only
     */
    public function notifyAutoApproved(array $reservation): bool
    {
        $baseUrl = 'https://inventory.amtrakfdt.com/booking';
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "✅ Reservation Auto-Approved - {$assetName}";
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p>Your vehicle reservation has been <strong>automatically approved</strong> (VIP status).</p>
                <div class='success-box'>
                    <strong>Reservation Details:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Pickup:</strong> " . date('M j, Y g:i A', strtotime($reservation['start_datetime'])) . "<br>
                    <strong>Return:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "<br>
                    <strong>Status:</strong> Ready for Checkout
                </div>
                <p>You can proceed with vehicle checkout when ready.</p>
            ";
            
            $mail->Body = $this->template('Reservation Approved', $content, "{$baseUrl}/my_bookings.php", 'View & Checkout');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Auto-approval email failed: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * RESERVATION APPROVED - Notify requester
     */
    public function notifyApproved(array $reservation, string $approverName): bool
    {
        $baseUrl = 'https://inventory.amtrakfdt.com/booking';
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "✅ Reservation Approved - {$assetName}";
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p>Great news! Your vehicle reservation has been <strong>approved</strong>.</p>
                <div class='success-box'>
                    <strong>Reservation Details:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Pickup:</strong> " . date('M j, Y g:i A', strtotime($reservation['start_datetime'])) . "<br>
                    <strong>Return:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "<br>
                    <strong>Approved By:</strong> {$approverName}
                </div>
                <p>Please complete the checkout process when you pick up the vehicle.</p>
            ";
            
            $mail->Body = $this->template('Reservation Approved', $content, "{$baseUrl}/my_bookings.php", 'View & Checkout');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Approval email failed: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * RESERVATION REJECTED - Notify requester
     */
    public function notifyRejected(array $reservation, string $approverName, string $reason = ''): bool
    {
        $baseUrl = 'https://inventory.amtrakfdt.com/booking';
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "❌ Reservation Rejected - {$assetName}";
            
            $reasonText = $reason ? "<br><strong>Reason:</strong> {$reason}" : '';
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p>Unfortunately, your vehicle reservation has been <strong>rejected</strong>.</p>
                <div class='danger-box'>
                    <strong>Reservation Details:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Requested Pickup:</strong> " . date('M j, Y g:i A', strtotime($reservation['start_datetime'])) . "<br>
                    <strong>Rejected By:</strong> {$approverName}{$reasonText}
                </div>
                <p>Please submit a new reservation if you still need a vehicle.</p>
            ";
            
            $mail->Body = $this->template('Reservation Rejected', $content, "{$baseUrl}/vehicle_reserve.php", 'Book Another Vehicle');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Rejection email failed: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * CHECKOUT COMPLETED - Notify requester
     */
    public function notifyCheckout(array $reservation, string $mileage): bool
    {
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "🚗 Vehicle Checked Out - {$assetName}";
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p>You have successfully checked out a vehicle.</p>
                <div class='info-box'>
                    <strong>Checkout Receipt:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Checkout Time:</strong> " . date('M j, Y g:i A') . "<br>
                    <strong>Mileage at Checkout:</strong> {$mileage}<br>
                    <strong>Expected Return:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "
                </div>
                <p>Please remember to complete the checkin process when you return the vehicle.</p>
            ";
            
            $mail->Body = $this->template('Vehicle Checked Out', $content);
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Checkout email failed: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * CHECKIN COMPLETED - Notify requester
     */
    public function notifyCheckin(array $reservation, string $mileage, bool $maintenanceFlag = false): bool
    {
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "✅ Vehicle Returned - {$assetName}";
            
            $maintenanceNote = $maintenanceFlag ? "<br><strong style='color: #dc3545;'>⚠️ Maintenance Issue Reported</strong>" : '';
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p>Thank you for returning the vehicle.</p>
                <div class='success-box'>
                    <strong>Return Receipt:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Return Time:</strong> " . date('M j, Y g:i A') . "<br>
                    <strong>Mileage at Return:</strong> {$mileage}{$maintenanceNote}
                </div>
            ";
            
            $mail->Body = $this->template('Vehicle Returned', $content);
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Checkin email failed: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * MAINTENANCE FLAGGED - Notify staff
     */
    public function notifyMaintenanceFlag(array $reservation, string $notes): bool
    {
        $baseUrl = 'https://inventory.amtrakfdt.com';
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        $staffEmails = $this->getStaffEmails();
        
        if (empty($staffEmails)) return true;
        
        try {
            $mail = $this->createMailer();
            foreach ($staffEmails as $email) {
                $mail->addAddress($email);
            }
            
            $mail->Subject = "🔧 Maintenance Required - {$assetName}";
            
            $content = "
                <p>A vehicle has been flagged for maintenance during checkin.</p>
                <div class='danger-box'>
                    <strong>Maintenance Alert:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Reported By:</strong> {$reservation['user_name']}<br>
                    <strong>Return Time:</strong> " . date('M j, Y g:i A') . "<br>
                    <strong>Issue Description:</strong><br>" . nl2br(htmlspecialchars($notes)) . "
                </div>
                <p>Please review and schedule maintenance as needed.</p>
            ";
            
            $mail->Body = $this->template('Maintenance Required', $content, "{$baseUrl}/hardware", 'View in Snipe-IT');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Maintenance email failed: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * PICKUP REMINDER - Called by cron
     */
    public function notifyPickupReminder(array $reservation): bool
    {
        $baseUrl = 'https://inventory.amtrakfdt.com/booking';
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "⏰ Reminder: Vehicle Pickup Soon - {$assetName}";
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p>This is a reminder that your vehicle reservation is coming up.</p>
                <div class='warning-box'>
                    <strong>Upcoming Pickup:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Pickup Time:</strong> " . date('M j, Y g:i A', strtotime($reservation['start_datetime'])) . "<br>
                    <strong>Return By:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "
                </div>
                <p>Don't forget to complete the checkout inspection when picking up the vehicle.</p>
            ";
            
            $mail->Body = $this->template('Pickup Reminder', $content, "{$baseUrl}/my_bookings.php", 'View Reservation');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Reminder email failed: " . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * OVERDUE ALERT - Called by cron
     */
    public function notifyOverdue(array $reservation): bool
    {
        $baseUrl = 'https://inventory.amtrakfdt.com/booking';
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        // Email to requester
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = "⚠️ OVERDUE: Please Return Vehicle - {$assetName}";
            
            $content = "
                <p>Hi {$reservation['user_name']},</p>
                <p><strong>Your vehicle return is overdue.</strong></p>
                <div class='danger-box'>
                    <strong>Overdue Vehicle:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Was Due:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "<br>
                    <strong>Current Time:</strong> " . date('M j, Y g:i A') . "
                </div>
                <p>Please return the vehicle and complete the checkin process immediately.</p>
            ";
            
            $mail->Body = $this->template('Vehicle Overdue', $content, "{$baseUrl}/my_bookings.php", 'Complete Checkin');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Overdue email to user failed: " . $e->getMessage());
        }
        
        // Email to staff
        $staffEmails = $this->getStaffEmails();
        if (!empty($staffEmails)) {
            try {
                $mail = $this->createMailer();
                foreach ($staffEmails as $email) {
                    $mail->addAddress($email);
                }
                $mail->Subject = "⚠️ Overdue Vehicle Alert - {$assetName}";
                
                $content = "
                    <p>A vehicle return is overdue.</p>
                    <div class='danger-box'>
                        <strong>Overdue Details:</strong><br>
                        <strong>Vehicle:</strong> {$assetName}<br>
                        <strong>Checked Out By:</strong> {$reservation['user_name']} ({$reservation['user_email']})<br>
                        <strong>Was Due:</strong> " . date('M j, Y g:i A', strtotime($reservation['end_datetime'])) . "
                    </div>
                ";
                
                $mail->Body = $this->template('Overdue Alert', $content);
                $this->send($mail);
            } catch (Exception $e) {
                error_log("Overdue email to staff failed: " . $e->getMessage());
            }
        }
        
        return true;
    }
}

/**
 * Helper function to get email service instance
 */
function get_email_service($pdo): FleetEmailService
{
    return new FleetEmailService($pdo);
}
