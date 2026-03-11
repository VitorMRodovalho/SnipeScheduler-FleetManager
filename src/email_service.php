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
        $fromName = $smtp['from_name'] ?: 'Fleet Management';
        $mail->setFrom($fromEmail, $fromName);
        $mail->isHTML(true);
        $mail->Timeout = 3;
        $mail->SMTPOptions = ["socket" => ["bindto" => "0:0"], "ssl" => ["verify_peer" => false, "verify_peer_name" => false, "allow_self_signed" => true]];
        $mail->SMTPKeepAlive = false; // 10 second timeout
        $mail->SMTPDebug = 0;
        
        return $mail;
    }
    
/**
     * Get all staff/admin emails for notifications (from config)
     */
    public function getStaffEmails(): array
    {
        // Dynamic: resolve from Snipe-IT group membership (no hardcoded emails)
        return get_emails_by_snipeit_groups([SNIPEIT_GROUP_FLEET_STAFF, SNIPEIT_GROUP_FLEET_ADMIN]);
    }
        if (!empty($auth['google_checkout_emails'])) {
            $emails = array_merge($emails, $auth['google_checkout_emails']);
        }

        return array_unique(array_filter($emails));
    }
    
    /**
     * Get admin emails only (for critical alerts)
     */
    public function getAdminEmails(): array
    {
        // Dynamic: resolve from Snipe-IT group membership (no hardcoded emails)
        return get_emails_by_snipeit_groups([SNIPEIT_GROUP_FLEET_ADMIN, SNIPEIT_GROUP_ADMINS]);
    }

    /**
     * Get staff/admin recipients based on notification settings (excludes requester)
     */
    public function getSettingsBasedRecipients(array $settings): array
    {
        $emails = [];
        if (!empty($settings['notify_staff'])) {
            $emails = array_merge($emails, $this->getStaffEmails());
        }
        if (!empty($settings['notify_admin'])) {
            $emails = array_merge($emails, $this->getAdminEmails());
        }
        if (!empty($settings['custom_emails'])) {
            $custom = array_map('trim', explode(',', $settings['custom_emails']));
            foreach ($custom as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[] = $e;
            }
        }
        return array_unique(array_filter($emails));
    }

    /**
     * Get notification settings for an event
     */
    public function getNotificationSettings(string $eventKey): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM email_notification_settings WHERE event_key = ?");
        $stmt->execute([$eventKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Check if SMTP is enabled globally
     */
    public function isSmtpEnabled(): bool
    {
        $stmt = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_enabled'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['setting_value'] === '1';
    }

    /**
     * Get recipients for an event based on settings
     */
    public function getEventRecipients(string $eventKey, ?string $requesterEmail = null, ?string $requesterName = null): array
    {
        $recipients = [];
        $settings = $this->getNotificationSettings($eventKey);
        
        if (!$settings || !$settings['enabled']) {
            return $recipients;
        }
        
        if ($settings['notify_requester'] && $requesterEmail) {
            $recipients[] = ['email' => $requesterEmail, 'name' => $requesterName ?? ''];
        }
        
        if ($settings['notify_staff']) {
            foreach ($this->getStaffEmails() as $email) {
                $recipients[] = ['email' => $email, 'name' => ''];
            }
        }
        
        if ($settings['notify_admin']) {
            foreach ($this->getAdminEmails() as $email) {
                $recipients[] = ['email' => $email, 'name' => ''];
            }
        }
        
        if (!empty($settings['custom_emails'])) {
            $customEmails = array_map('trim', explode(',', $settings['custom_emails']));
            foreach ($customEmails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = ['email' => $email, 'name' => ''];
                }
            }
        }
        
        return $recipients;
    }

    /**
     * Check if notification is enabled for an event
     */
    public function isNotificationEnabled(string $eventKey): bool
    {
        $settings = $this->getNotificationSettings($eventKey);
        return $settings && $settings['enabled'];
    }

    /**
     * Send a test email
     */
    public function sendTestEmail(string $toEmail): bool
    {
        try {
            $mail = $this->createMailer();
            $mail->addAddress($toEmail);
            $mail->Subject = "Test Email - Fleet Management System";
            
            $content = "
                <p>This is a test email from the Fleet Management System.</p>
                <p>If you received this email, your SMTP configuration is working correctly!</p>
                <div class='info-box'>
                    <strong>Sent at:</strong> " . date('M j, Y g:i A') . "<br>
                    <strong>Server:</strong> " . gethostname() . "
                </div>
            ";
            
            $mail->Body = $this->template('Test Email', $content);
            $mail->send();
            error_log("Test email sent to: " . $toEmail);
            return true;
        } catch (Exception $e) {
            error_log("Test email failed: " . $e->getMessage());
            throw $e;
        }
    }
   
    /**
     * Send email - queues if SMTP fails (AWS blocks port 587)
     */
    private function send(PHPMailer $mail): bool
    {
        // TEMPORARY: Queue directly until AWS SES configured
        $recipients = $mail->getAllRecipientAddresses();
        $toEmail = array_key_first($recipients);
        $toName = $recipients[$toEmail] ?? '';
        error_log("Email queued (SMTP disabled): " . $mail->Subject);
        $this->queueEmail($toEmail, $toName, $mail->Subject, $mail->Body);
        return true;
        
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
                    <h1 style='margin: 0; font-size: 24px;'>Fleet Management</h1>
                    <p style='margin: 5px 0 0 0; opacity: 0.9;'>{$title}</p>
                </div>
                <div class='content'>
                    {$content}
                    {$button}
                </div>
                <div class='footer'>
                    <p>Fleet Vehicle Management System</p>
                    <p>This is an automated notification. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";
    }

/**
     * Resolve email subject from DB custom template or use default.
     * Replaces {placeholders} in both DB and default subjects.
     */
    private function resolveSubject(string $eventKey, string $defaultSubject, array $vars = []): string
    {
        $settings = $this->getNotificationSettings($eventKey);
        $subject = (!empty($settings['subject_template']))
            ? $settings['subject_template']
            : $defaultSubject;

        foreach ($vars as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
        }
        return $subject;
    }

    /**
     * Resolve email body from DB custom template or return null (use hardcoded).
     * When DB template exists, converts plain text to styled HTML.
     */
    private function resolveBody(string $eventKey, array $vars = []): ?string
    {
        $settings = $this->getNotificationSettings($eventKey);
        if (empty($settings['body_template'])) {
            return null; // Caller uses hardcoded HTML body
        }

        $body = $settings['body_template'];
        foreach ($vars as $key => $value) {
            $body = str_replace('{' . $key . '}', htmlspecialchars($value), $body);
        }

        // Convert plain text line breaks to HTML
        return nl2br(htmlspecialchars_decode($body));
    }
    
/**
     * Build standard template variables from reservation data.
     */
    private function buildTemplateVars(array $data, array $extra = []): array
    {
        $vars = [
            'user' => $data['user_name'] ?? '',
            'vehicle' => $data['asset_name_cache'] ?? '',
        ];
        if (!empty($data['start_datetime'])) {
            $vars['date'] = date('M j, Y', strtotime($data['start_datetime']));
            $vars['time'] = date('g:i A', strtotime($data['start_datetime']));
        }
        if (!empty($data['end_datetime'])) {
            $vars['return_date'] = date('M j, Y', strtotime($data['end_datetime']));
            $vars['return_time'] = date('g:i A', strtotime($data['end_datetime']));
        }
        $vars['purpose'] = $data['notes'] ?? '';
        $vars['location'] = $data['location'] ?? '';
        return array_merge($vars, $extra);
    }


    /**
     * NEW RESERVATION - Notify requester + staff
     */
    public function notifyNewReservation(array $reservation): bool
    {
        $settings = $this->getNotificationSettings('reservation_submitted');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        $pickup = date('M j, Y g:i A', strtotime($reservation['start_datetime']));
        $return = date('M j, Y g:i A', strtotime($reservation['end_datetime']));

        // Email to requester
        if ($settings['notify_requester']) {
            try {
                $mail = $this->createMailer();
                $mail->addAddress($reservation['user_email'], $reservation['user_name']);
                $mail->Subject = $this->resolveSubject('reservation_submitted', 'New Reservation Request - {vehicle}', ['vehicle' => $assetName]);
                $content = "
                    <p>Hi {$reservation['user_name']},</p>
                    <p>Your vehicle reservation has been submitted and is pending approval.</p>
                    <div class='info-box'>
                        <strong>Reservation Details:</strong><br>
                        <strong>Vehicle:</strong> {$assetName}<br>
                        <strong>Pickup:</strong> {$pickup}<br>
                        <strong>Return:</strong> {$return}<br>
                        <strong>Status:</strong> Pending Approval
                    </div>
                    <p>You will receive another email once your reservation is approved or rejected.</p>
                ";
                $templateVars = $this->buildTemplateVars($reservation);
                $dbBody = $this->resolveBody('reservation_submitted', $templateVars ?? []);
                if ($dbBody !== null) $content = $dbBody;
                $mail->Body = $this->template('Reservation Submitted', $content, "{$baseUrl}/my_bookings", 'View My Reservations');
                $this->send($mail);
            } catch (Exception $e) {
                error_log("Email to requester failed: " . $e->getMessage());
            }
        }

        // Email to staff/admin based on DB settings
        $notifyEmails = $this->getSettingsBasedRecipients($settings);
        if (!empty($notifyEmails)) {
            try {
                $mail = $this->createMailer();
                foreach ($notifyEmails as $email) {
                    $mail->addAddress($email);
                }
                $mail->Subject = $this->resolveSubject('reservation_submitted', 'New Reservation Request - {user}', ['vehicle' => $assetName, 'user' => $reservation['user_name']]);
                $content = "
                    <p>A new vehicle reservation requires your approval.</p>
                    <div class='warning-box'>
                        <strong>Request Details:</strong><br>
                        <strong>Requested By:</strong> {$reservation['user_name']} ({$reservation['user_email']})<br>
                        <strong>Vehicle:</strong> {$assetName}<br>
                        <strong>Pickup:</strong> {$pickup}<br>
                        <strong>Return:</strong> {$return}
                    </div>
                ";
                $mail->Body = $this->template('Approval Required', $content, "{$baseUrl}/approval", 'Review & Approve');
                $this->send($mail);
            } catch (Exception $e) {
                error_log("Email to staff/admin failed: " . $e->getMessage());
            }
        }

        return true;
    }

    
    /**
     * VIP AUTO-APPROVED - Notify requester only
     */
    public function notifyAutoApproved(array $reservation): bool
    {
        $settings = $this->getNotificationSettings('reservation_approved');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = $this->resolveSubject('reservation_approved', 'Reservation Auto-Approved - {vehicle}', ['vehicle' => $assetName]);
            
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
            
            $templateVars = $this->buildTemplateVars($reservation);
            $dbBody = $this->resolveBody('reservation_approved', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
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
        $settings = $this->getNotificationSettings('reservation_approved');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = $this->resolveSubject('reservation_approved', 'Reservation Approved - {vehicle}', ['vehicle' => $assetName]);
            
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
            
            $templateVars = $this->buildTemplateVars($reservation, ['approver' => $approverName]);
            $dbBody = $this->resolveBody('reservation_approved', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
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
        $settings = $this->getNotificationSettings('reservation_rejected');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = $this->resolveSubject('reservation_rejected', 'Reservation Rejected - {vehicle}', ['vehicle' => $assetName]);
            
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
            
            $templateVars = $this->buildTemplateVars($reservation, ['reason' => $reason, 'approver' => $approverName]);
            $dbBody = $this->resolveBody('reservation_rejected', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
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
        $settings = $this->getNotificationSettings('vehicle_checked_out');
        if (!$settings || !$settings['enabled']) return true;

        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = $this->resolveSubject('vehicle_checked_out', 'Vehicle Checked Out - {vehicle}', ['vehicle' => $assetName]);
            
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
            
            $templateVars = $this->buildTemplateVars($reservation, ['mileage' => $mileage]);
            $dbBody = $this->resolveBody('vehicle_checked_out', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
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
        $settings = $this->getNotificationSettings('vehicle_checked_in');
        if (!$settings || !$settings['enabled']) return true;

        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = $this->resolveSubject('vehicle_checked_in', 'Vehicle Returned - {vehicle}', ['vehicle' => $assetName]);
            
            $maintenanceNote = $maintenanceFlag ? "<br><strong style='color: #dc3545;'>Maintenance Issue Reported</strong>" : '';
            
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
            
            $templateVars = $this->buildTemplateVars($reservation, ['mileage' => $mileage]);
            $dbBody = $this->resolveBody('vehicle_checked_in', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
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
        $settings = $this->getNotificationSettings('maintenance_flagged');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        $staffEmails = $this->getSettingsBasedRecipients($settings);
        
        if (empty($staffEmails)) return true;
        
        try {
            $mail = $this->createMailer();
            foreach ($staffEmails as $email) {
                $mail->addAddress($email);
            }
            
            $mail->Subject = $this->resolveSubject('maintenance_flagged', 'Maintenance Required - {vehicle}', ['vehicle' => $assetName]);
            
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
            
            $templateVars = $this->buildTemplateVars($reservation, ['notes' => $notes]);
            $dbBody = $this->resolveBody('maintenance_flagged', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
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
        $settings = $this->getNotificationSettings('pickup_reminder');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = $this->resolveSubject('pickup_reminder', 'Pickup Reminder - {vehicle}', ['vehicle' => $assetName]);
            
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
            
            $templateVars = $this->buildTemplateVars($reservation);
            $dbBody = $this->resolveBody('pickup_reminder', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
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
        $settings = $this->getNotificationSettings('return_overdue');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        
        // Email to requester
        try {
            $mail = $this->createMailer();
            $mail->addAddress($reservation['user_email'], $reservation['user_name']);
            $mail->Subject = $this->resolveSubject('return_overdue', 'Overdue Return - {vehicle}', ['vehicle' => $assetName]);
            
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
            
            $templateVars = $this->buildTemplateVars($reservation);
            $dbBody = $this->resolveBody('return_overdue', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
            $mail->Body = $this->template('Vehicle Overdue', $content, "{$baseUrl}/my_bookings.php", 'Complete Checkin');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Overdue email to user failed: " . $e->getMessage());
        }
        
        // Email to staff
        $staffEmails = $this->getSettingsBasedRecipients($settings);
        if (!empty($staffEmails)) {
            try {
                $mail = $this->createMailer();
                foreach ($staffEmails as $email) {
                    $mail->addAddress($email);
                }
                $mail->Subject = $this->resolveSubject('return_overdue', 'Overdue Return Alert - {vehicle}', ['vehicle' => $assetName]);
                
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
            }
        }

        return true;
    }

    /**
     * RESERVATION CANCELLED - Notify based on settings
     */
    public function notifyCancellation(array $reservation, string $cancelledBy): bool
    {
        $settings = $this->getNotificationSettings('reservation_cancelled');
        if (!$settings || !$settings['enabled']) return true;

        $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];

        if ($settings['notify_requester']) {
            try {
                $mail = $this->createMailer();
                $mail->addAddress($reservation['user_email'], $reservation['user_name']);
                $mail->Subject = $this->resolveSubject('reservation_cancelled', 'Reservation Cancelled - {vehicle}', ['vehicle' => $assetName]);
                $content = "
                    <p>Hi {$reservation['user_name']},</p>
                    <p>Your vehicle reservation has been <strong>cancelled</strong>.</p>
                    <div class='danger-box'>
                        <strong>Cancelled Reservation:</strong><br>
                        <strong>Vehicle:</strong> {$assetName}<br>
                        <strong>Cancelled By:</strong> {$cancelledBy}
                    </div>
                ";
                $templateVars = $this->buildTemplateVars($reservation, ['cancelled_by' => $cancelledBy]);
                $dbBody = $this->resolveBody('reservation_cancelled', $templateVars ?? []);
                if ($dbBody !== null) $content = $dbBody;
                $mail->Body = $this->template('Reservation Cancelled', $content, "{$baseUrl}/my_bookings", 'View My Reservations');
                $this->send($mail);
            } catch (Exception $e) {
                error_log("Cancellation email to requester failed: " . $e->getMessage());
            }
        }

        $notifyEmails = $this->getSettingsBasedRecipients($settings);
        if (!empty($notifyEmails)) {
            try {
                $mail = $this->createMailer();
                foreach ($notifyEmails as $email) {
                    $mail->addAddress($email);
                }
                $mail->Subject = $this->resolveSubject('reservation_cancelled', 'Reservation Cancelled - {vehicle}', ['vehicle' => $assetName]);
                $content = "
                    <p>A vehicle reservation has been cancelled.</p>
                    <div class='danger-box'>
                        <strong>Cancelled Reservation:</strong><br>
                        <strong>User:</strong> {$reservation['user_name']} ({$reservation['user_email']})<br>
                        <strong>Vehicle:</strong> {$assetName}<br>
                        <strong>Cancelled By:</strong> {$cancelledBy}
                    </div>
                ";
                $mail->Body = $this->template('Reservation Cancelled', $content, "{$baseUrl}/reservations", 'View Reservations');
                $this->send($mail);
            } catch (Exception $e) {
                error_log("Cancellation email to staff/admin failed: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * MILEAGE ANOMALY - Admin only alert
     */
    public function notifyMileageAnomaly(array $reservation, int $reported, int $previous, string $reason): bool
    {
        $settings = $this->getNotificationSettings('mileage_anomaly');
        if (!$settings || !$settings['enabled']) return true;

        $assetName = $reservation['asset_name_cache'] ?: 'Vehicle #' . $reservation['asset_id'];
        $notifyEmails = $this->getSettingsBasedRecipients($settings);
        if (empty($notifyEmails)) return true;

        try {
            $mail = $this->createMailer();
            foreach ($notifyEmails as $email) {
                $mail->addAddress($email);
            }
            $mail->Subject = $this->resolveSubject('mileage_anomaly', 'Mileage Anomaly Detected - {vehicle}', ['vehicle' => $assetName]);
            $content = "
                <p>A mileage anomaly was detected during vehicle checkout/checkin.</p>
                <div class='danger-box'>
                    <strong>Alert Details:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>User:</strong> {$reservation['user_name']} ({$reservation['user_email']})<br>
                    <strong>Previous Mileage:</strong> " . number_format($previous) . " mi<br>
                    <strong>Reported Mileage:</strong> " . number_format($reported) . " mi<br>
                    <strong>Issue:</strong> {$reason}
                </div>
                <p>Please review this entry and verify the odometer reading.</p>
            ";
            $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
            $templateVars = $this->buildTemplateVars($reservation, ['mileage_reported' => (string)$reported, 'mileage_previous' => (string)$previous, 'reason' => $reason]);
            $dbBody = $this->resolveBody('mileage_anomaly', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
            $mail->Body = $this->template('Mileage Anomaly Detected', $content, "{$baseUrl}/reports?report=usage", 'View Usage Report');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Mileage anomaly email failed: " . $e->getMessage());
        }

        return true;
    }

    /**
     * COMPLIANCE EXPIRING - Staff/Admin alert
     */
    public function notifyComplianceExpiring(array $asset, string $type, string $expiryDate, int $daysRemaining): bool
    {
        $settings = $this->getNotificationSettings('compliance_expiring');
        if (!$settings || !$settings['enabled']) return true;

        $assetName = ($asset['name'] ?? 'Vehicle') . ' [' . ($asset['asset_tag'] ?? '') . ']';
        $notifyEmails = $this->getSettingsBasedRecipients($settings);
        if (empty($notifyEmails)) return true;

        $urgency = $daysRemaining <= 7 ? 'danger-box' : 'warning-box';
        $prefix = $daysRemaining <= 7 ? '[URGENT] ' : '';

        try {
            $mail = $this->createMailer();
            foreach ($notifyEmails as $email) {
                $mail->addAddress($email);
            }
            $mail->Subject = $this->resolveSubject('compliance_expiring', '{type} Expiring - {vehicle}', ['vehicle' => $assetName, 'type' => $prefix . $type]);
            $content = "
                <p>A vehicle compliance item is expiring soon.</p>
                <div class='{$urgency}'>
                    <strong>Compliance Alert:</strong><br>
                    <strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Type:</strong> {$type}<br>
                    <strong>Expiry Date:</strong> {$expiryDate}<br>
                    <strong>Days Remaining:</strong> {$daysRemaining}
                </div>
                <p>Please arrange renewal before the expiry date.</p>
            ";
            $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
            $templateVars = ['vehicle' => $asset['name'] ?? '', 'type' => $type, 'expiry_date' => $expiryDate, 'days_remaining' => (string)$daysRemaining];
            $dbBody = $this->resolveBody('compliance_expiring', $templateVars ?? []);
            if ($dbBody !== null) $content = $dbBody;
            $mail->Body = $this->template('Compliance Alert', $content, "{$baseUrl}/reports?report=compliance", 'View Compliance Report');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Compliance expiring email failed: " . $e->getMessage());
        }

        return true;
    }
/**
     * RESERVATION REDIRECTED - Vehicle swap notification
     * Sent to requester and staff when a reservation is redirected to an alternate vehicle
     * @since v1.3.5
     */
    public function notifyReservationRedirected(array $reservation, array $newVehicle, string $reason = ''): bool
    {
        if (!$this->isNotificationEnabled('reservation_redirected')) return true;

        $assetName = $reservation['asset_name_cache'] ?? 'Unknown Vehicle';
        $newAssetName = $newVehicle['name'] ?? 'Unknown Vehicle';
        $userName = $reservation['user_name'] ?? 'User';
        $start = date('M j, Y g:i A', strtotime($reservation['start_datetime']));
        $end = date('M j, Y g:i A', strtotime($reservation['end_datetime']));
        $reasonNote = $reason ? "<p><strong>Reason:</strong> {$reason}</p>" : '';

        try {
            $content = "
                <p>Hi {$userName},</p>
                <p>Your upcoming reservation has been <strong>redirected to a different vehicle</strong> because the originally assigned vehicle is unavailable.</p>
                <div class='warning-box'>
                    <p><strong>Original Vehicle:</strong> {$assetName}<br>
                    <strong>New Vehicle:</strong> {$newAssetName}<br>
                    <strong>Pickup:</strong> {$start}<br>
                    <strong>Return:</strong> {$end}</p>
                    {$reasonNote}
                </div>
                <p>Your reservation times remain the same. Please proceed to pick up the new vehicle at the scheduled time.</p>
            ";

            $settings = $this->getNotificationSettings('reservation_redirected');
            $recipients = $this->getEventRecipients(
                'reservation_redirected',
                $reservation['user_email'] ?? null,
                $userName
            );

            $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
            foreach ($recipients as $r) {
                $mail = $this->createMailer();
                $mail->addAddress($r['email'], $r['name']);
                $mail->Subject = $this->resolveSubject('reservation_redirected', 'Reservation Redirected - {vehicle}', ['vehicle' => $newAssetName]);
                $templateVars = $this->buildTemplateVars($reservation, ['new_vehicle' => $newVehicle['name'] ?? '', 'reason' => $reason]);
                $dbBody = $this->resolveBody('reservation_redirected', $templateVars ?? []);
                if ($dbBody !== null) $content = $dbBody;
                $mail->Body = $this->template('Vehicle Redirect', $content, "{$baseUrl}/my_bookings", 'View My Reservations');
                $this->send($mail);
            }
        } catch (Exception $e) {
            error_log("Reservation redirected email failed: " . $e->getMessage());
        }
        return true;
    }

    /**
     * REDIRECT FAILED - No alternate vehicle available, reservation cancelled
     * Sent to requester and staff
     * @since v1.3.5
     */
    public function notifyRedirectFailed(array $reservation, string $reason = ''): bool
    {
        if (!$this->isNotificationEnabled('reservation_redirect_failed')) return true;

        $assetName = $reservation['asset_name_cache'] ?? 'Unknown Vehicle';
        $userName = $reservation['user_name'] ?? 'User';
        $start = date('M j, Y g:i A', strtotime($reservation['start_datetime']));
        $end = date('M j, Y g:i A', strtotime($reservation['end_datetime']));
        $reasonNote = $reason ? "<p><strong>Reason:</strong> {$reason}</p>" : '';

        try {
            $content = "
                <p>Hi {$userName},</p>
                <p>Unfortunately, your upcoming reservation has been <strong>cancelled</strong> because the assigned vehicle is unavailable and no alternate vehicle could be found at your location.</p>
                <div class='danger-box'>
                    <p><strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Pickup:</strong> {$start}<br>
                    <strong>Return:</strong> {$end}</p>
                    {$reasonNote}
                </div>
                <p>Please submit a new reservation request at your earliest convenience. We apologize for the inconvenience.</p>
            ";

            $recipients = $this->getEventRecipients(
                'reservation_redirect_failed',
                $reservation['user_email'] ?? null,
                $userName
            );

            $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
            foreach ($recipients as $r) {
                $mail = $this->createMailer();
                $mail->addAddress($r['email'], $r['name']);
                $mail->Subject = $this->resolveSubject('reservation_redirect_failed', 'Reservation Cancelled - {vehicle}', ['vehicle' => $assetName]);
                $templateVars = $this->buildTemplateVars($reservation, ['reason' => $reason]);
                $dbBody = $this->resolveBody('reservation_redirect_failed', $templateVars ?? []);
                if ($dbBody !== null) $content = $dbBody;
                $mail->Body = $this->template('Reservation Cancelled', $content, "{$baseUrl}/vehicle_reserve", 'Book Another Vehicle');
                $this->send($mail);
            }
        } catch (Exception $e) {
            error_log("Redirect failed email failed: " . $e->getMessage());
        }
        return true;
    }

    /**
     * OVERDUE REDIRECT STAFF ALERT - Notify staff/admin about overdue vehicle triggering redirect
     * @since v1.3.5
     */
    public function notifyOverdueRedirectStaff(array $reservation, string $action = 'redirected'): bool
    {
        if (!$this->isNotificationEnabled('overdue_redirect_staff')) return true;

        $assetName = $reservation['asset_name_cache'] ?? 'Unknown Vehicle';
        $userName = $reservation['user_name'] ?? 'User';
        $expected = date('M j, Y g:i A', strtotime($reservation['end_datetime']));
        $actionDesc = $action === 'redirected'
            ? 'The next reservation has been automatically redirected to an alternate vehicle.'
            : 'The next reservation has been cancelled because no alternate vehicle was available.';

        try {
            $content = "
                <p>An overdue vehicle has triggered an automatic reservation action.</p>
                <div class='warning-box'>
                    <p><strong>Vehicle:</strong> {$assetName}<br>
                    <strong>Assigned to:</strong> {$userName} ({$reservation['user_email']})<br>
                    <strong>Expected Return:</strong> {$expected}<br>
                    <strong>Action Taken:</strong> {$actionDesc}</p>
                </div>
                <p>Please follow up with {$userName} to ensure the vehicle is returned promptly.</p>
            ";

            $recipients = $this->getEventRecipients('overdue_redirect_staff');

            $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
            foreach ($recipients as $r) {
                $mail = $this->createMailer();
                $mail->addAddress($r['email'], $r['name']);
                $mail->Subject = $this->resolveSubject('overdue_redirect_staff', 'Overdue Vehicle Redirect Alert - {vehicle}', ['vehicle' => $assetName]);
                $templateVars = $this->buildTemplateVars($reservation, ['action' => $action]);
                $dbBody = $this->resolveBody('overdue_redirect_staff', $templateVars ?? []);
                if ($dbBody !== null) $content = $dbBody;
                $mail->Body = $this->template('Overdue Vehicle Alert', $content, "{$baseUrl}/reservations", 'View Reservations');
                $this->send($mail);
            }
        } catch (Exception $e) {
            error_log("Overdue redirect staff email failed: " . $e->getMessage());
        }
        return true;

    }

    /**
     * TRAINING EXPIRING - Weekly digest to staff/admin
     * @since v1.4.x
     */
    public function notifyTrainingExpiring(array $drivers, int $expiringCount, int $expiredCount): bool
    {
        $settings = $this->getNotificationSettings('training_expiring');
        if (!$settings || !$settings['enabled']) return true;
        $notifyEmails = $this->getSettingsBasedRecipients($settings);
        if (empty($notifyEmails)) return true;
        try {
            $mail = $this->createMailer();
            foreach ($notifyEmails as $email) {
                $mail->addAddress($email);
            }
            $totalCount = $expiringCount + $expiredCount;
            $mail->Subject = $this->resolveSubject('training_expiring', 'Driver Training Alert - {count} driver(s) need attention', ['count' => (string)$totalCount]);
            $rows = '';
            foreach ($drivers as $d) {
                $sc = $d['status'] === 'expired' ? 'danger-box' : 'warning-box';
                $sl = $d['status'] === 'expired' ? 'EXPIRED' : 'EXPIRING SOON';
                $rows .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;'>" . $d['name'] . "</td>"
                    . "<td style='padding:8px;border-bottom:1px solid #eee;'>" . $d['email'] . "</td>"
                    . "<td style='padding:8px;border-bottom:1px solid #eee;'>" . $d['training_date'] . "</td>"
                    . "<td style='padding:8px;border-bottom:1px solid #eee;'>" . $d['expiry_date'] . "</td>"
                    . "<td style='padding:8px;border-bottom:1px solid #eee;'><span class='" . $sc . "' style='padding:2px 8px;border-radius:4px;font-size:12px;'>" . $sl . "</span></td></tr>";
            }
            $content = "<p>The following drivers have training records that need attention:</p>";
            if ($expiredCount > 0) {
                $content .= "<div class='danger-box'><strong>" . $expiredCount . " driver(s)</strong> have expired training and are currently <strong>blocked from booking</strong>.</div>";
            }
            if ($expiringCount > 0) {
                $content .= "<div class='warning-box'><strong>" . $expiringCount . " driver(s)</strong> have training expiring within 15 days.</div>";
            }
            $content .= "<table style='width:100%;border-collapse:collapse;margin-top:15px;'>"
                . "<thead><tr style='background:#f8f9fa;'>"
                . "<th style='padding:8px;text-align:left;'>Driver</th>"
                . "<th style='padding:8px;text-align:left;'>Email</th>"
                . "<th style='padding:8px;text-align:left;'>Trained</th>"
                . "<th style='padding:8px;text-align:left;'>Expires</th>"
                . "<th style='padding:8px;text-align:left;'>Status</th>"
                . "</tr></thead><tbody>" . $rows . "</tbody></table>";
            $content .= "<p style='margin-top:15px;'>Please coordinate training renewals with affected drivers.</p>";
            $tv = ['count' => (string)$totalCount, 'expiring' => (string)$expiringCount, 'expired' => (string)$expiredCount];
            $dbBody = $this->resolveBody('training_expiring', $tv);
            if ($dbBody !== null) {
                $content = $dbBody;
            }
            $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
            $mail->Body = $this->template('Driver Training Alert', $content, $baseUrl . '/users?tab=list', 'View Users');
            $this->send($mail);
        } catch (Exception $e) {
            error_log("Training expiring email failed: " . $e->getMessage());
        }
        return true;
    }

    /**
     * Send individual training reminder to a driver
     * @since v1.4.x
     */
    public function notifyDriverTrainingReminder(string $email, string $name, string $expiryDate, bool $isExpired): bool
    {
        try {
            $mail = $this->createMailer();
            $mail->addAddress($email);
            $baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');
            if ($isExpired) {
                $mail->Subject = 'Driver Training Expired - Action Required';
                $c = "<p>Hi " . $name . ",</p>"
                    . "<div class='danger-box'><strong>Your Driver Safety Training expired on " . $expiryDate . ".</strong></div>"
                    . "<p>You are currently <strong>unable to reserve fleet vehicles</strong>. "
                    . "Please contact Fleet Staff immediately to renew your training.</p>";
                $mail->Body = $this->template('Training Expired', $c, $baseUrl . '/my_bookings', 'My Reservations');
            } else {
                $mail->Subject = 'Driver Training Expiring Soon';
                $c = "<p>Hi " . $name . ",</p>"
                    . "<div class='warning-box'><strong>Your Driver Safety Training expires on " . $expiryDate . ".</strong></div>"
                    . "<p>Please contact Fleet Staff to schedule your training renewal. "
                    . "After expiration, you will be unable to reserve fleet vehicles.</p>";
                $mail->Body = $this->template('Training Expiring', $c, $baseUrl . '/my_bookings', 'My Reservations');
            }
            $this->send($mail);
            return true;
        } catch (Exception $e) {
            error_log("Training reminder to " . $email . " failed: " . $e->getMessage());
            return false;
        }
    }

}

/**
 * Helper function to get email service instance
 */
function get_email_service($pdo): FleetEmailService
{
    return new FleetEmailService($pdo);
}
