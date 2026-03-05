<?php
/**
 * NotificationService
 *
 * Channel-agnostic event dispatcher.
 *
 * Usage (replaces all direct email_service.php calls):
 *
 *   NotificationService::fire('vehicle_checked_out', [
 *       'driver_name'      => 'John Smith',
 *       'vehicle_name'     => '2023 Ford F-150',
 *       'asset_tag'        => 'BPTR-VEH-012',
 *       'destination'      => 'Area 2: Wilkens Interlocking',
 *       'scheduled_return' => 'Mar 5, 2026 5:00 PM',
 *       'reservation_id'   => 42,
 *   ]);
 *
 * The method reads the channel setting for the event from email_notification_settings,
 * renders the shared template, then routes to email, Teams, or both — transparently.
 * Callers never need to know which channel is active.
 *
 * Design principles:
 *   - Templates are shared between email and Teams (one edit, all channels).
 *   - Audience (admin vs staff) is declared per event here; not scattered in callers.
 *   - All delivery is async via the queue — fire() never blocks.
 *   - Graceful degradation: if Teams is off or unconfigured, email proceeds normally.
 *   - No emojis in code — templates are admin-controlled.
 */

require_once __DIR__ . '/teams_service.php';

class NotificationService
{
    /**
     * Registry of all notifiable events.
     *
     * 'audience' controls:
     *   - Which email recipients are targeted (existing email_service logic)
     *   - Which Teams webhook channel receives the card:
     *       'admin'     → teams_webhook_url_admin
     *       'staff'     → teams_webhook_url_fleet_ops
     *       'requester' → teams_webhook_url_fleet_ops  (visible to ops team)
     *       'both'      → teams_webhook_url_fleet_ops  (staff + requester events go to ops)
     *
     * 'action_param' is the URL path appended to base_url for the card action button.
     * Use {reservation_id} or {vehicle_id} as placeholders — resolved at dispatch time.
     */
    private const EVENTS = [
        'reservation_submitted'       => [
            'label'        => 'Reservation Submitted',
            'audience'     => 'both',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'reservation_approved'        => [
            'label'        => 'Reservation Approved',
            'audience'     => 'requester',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'reservation_rejected'        => [
            'label'        => 'Reservation Rejected',
            'audience'     => 'requester',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'vehicle_checked_out'         => [
            'label'        => 'Vehicle Checked Out',
            'audience'     => 'both',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'vehicle_checked_in'          => [
            'label'        => 'Vehicle Checked In',
            'audience'     => 'both',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'maintenance_flagged'         => [
            'label'        => 'Maintenance Flagged',
            'audience'     => 'admin',
            'teams_ch'     => 'admin',
            'action_param' => '/vehicles/detail/{vehicle_id}',
        ],
        'pickup_reminder'             => [
            'label'        => 'Pickup Reminder',
            'audience'     => 'requester',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'return_overdue'              => [
            'label'        => 'Vehicle Overdue',
            'audience'     => 'both',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'reservation_cancelled'       => [
            'label'        => 'Reservation Cancelled',
            'audience'     => 'requester',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'mileage_anomaly'             => [
            'label'        => 'Mileage Anomaly Detected',
            'audience'     => 'admin',
            'teams_ch'     => 'admin',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'compliance_expiring'         => [
            'label'        => 'Compliance Document Expiring',
            'audience'     => 'admin',
            'teams_ch'     => 'admin',
            'action_param' => '/vehicles/detail/{vehicle_id}',
        ],
        'reservation_redirected'      => [
            'label'        => 'Reservation Redirected',
            'audience'     => 'both',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'reservation_redirect_failed' => [
            'label'        => 'Reservation Redirect Failed',
            'audience'     => 'both',
            'teams_ch'     => 'fleet_ops',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
        'overdue_redirect_staff'      => [
            'label'        => 'Overdue Redirect — Staff Alert',
            'audience'     => 'admin',
            'teams_ch'     => 'admin',
            'action_param' => '/reservations/detail/{reservation_id}',
        ],
    ];

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Fire a named notification event.
     *
     * @param  string $event_key  One of the keys defined in EVENTS above.
     * @param  array  $context    Template variable map, e.g. ['driver_name' => 'John Smith', ...]
     * @param  object|null $db    PDO or mysqli connection (uses global $pdo if null)
     * @return void
     */
    public static function fire(string $event_key, array $context, $db = null): void
    {
        global $pdo;
        $db = $db ?? $pdo;

        if (!isset(self::EVENTS[$event_key])) {
            error_log("[NotificationService] Unknown event key: '{$event_key}'");
            return;
        }

        $event_meta = self::EVENTS[$event_key];

        // Load per-event settings from DB (channel, subject_template, body_template)
        $settings = self::loadEventSettings($event_key, $db);
        if (!$settings) {
            error_log("[NotificationService] No settings found for event '{$event_key}' — skipping.");
            return;
        }

        $channel = $settings['channel'] ?? 'email';
        if ($channel === 'none') {
            return; // Explicitly disabled by admin
        }

        // Resolve templates using existing resolveSubject / resolveBody pattern
        $subject = self::resolveTemplate($settings['subject_template'] ?? '', $event_key, $context, 'subject');
        $body    = self::resolveTemplate($settings['body_template']  ?? '', $event_key, $context, 'body');

        // Build action URL for Teams card button
        $action_url = self::resolveActionUrl($event_meta['action_param'] ?? '', $context);

        // Route to channels
        if (in_array($channel, ['email', 'both'], true)) {
            self::dispatchEmail($event_key, $subject, $body, $context, $event_meta, $db);
        }

        if (in_array($channel, ['teams', 'both'], true)) {
            self::dispatchTeams($subject, $body, $action_url, $event_meta['teams_ch'], $db);
        }
    }

    /**
     * Return the full EVENTS registry (used by admin UI to render settings rows).
     */
    public static function getEvents(): array
    {
        return self::EVENTS;
    }

    // ------------------------------------------------------------------
    // Email dispatch
    // ------------------------------------------------------------------

    /**
     * Delegate to existing email_service.php methods.
     * This preserves 100% of the existing email logic — NotificationService
     * simply calls the same methods that callers used to call directly.
     *
     * INTEGRATION NOTE:
     *   Each event_key maps to a method on EmailService (or equivalent function).
     *   The existing callers (checkout.php, scheduled_tasks.php, etc.) should
     *   be replaced with NotificationService::fire() calls over time.
     *   Until then, this method handles the dispatch internally.
     */
    private static function dispatchEmail(
        string $event_key,
        string $subject,
        string $body,
        array  $context,
        array  $event_meta,
        $db
    ): void {
        // The existing email_service functions expect specific parameters.
        // We pass $context through; each function picks what it needs.
        // This dispatch table maps event keys to existing email_service calls.
        // Update this map if email_service method names differ in your codebase.

        $method_map = [
            'reservation_submitted'       => 'sendReservationSubmitted',
            'reservation_approved'        => 'sendReservationApproved',
            'reservation_rejected'        => 'sendReservationRejected',
            'vehicle_checked_out'         => 'sendVehicleCheckedOut',
            'vehicle_checked_in'          => 'sendVehicleCheckedIn',
            'maintenance_flagged'         => 'sendMaintenanceFlagged',
            'pickup_reminder'             => 'sendPickupReminder',
            'return_overdue'              => 'sendReturnOverdue',
            'reservation_cancelled'       => 'sendReservationCancelled',
            'mileage_anomaly'             => 'sendMileageAnomaly',
            'compliance_expiring'         => 'sendComplianceExpiring',
            'reservation_redirected'      => 'sendReservationRedirected',
            'reservation_redirect_failed' => 'sendReservationRedirectFailed',
            'overdue_redirect_staff'      => 'sendOverdueRedirectStaff',
        ];

        if (!isset($method_map[$event_key])) {
            error_log("[NotificationService] No email method mapped for '{$event_key}'");
            return;
        }

        $method = $method_map[$event_key];

        if (!function_exists($method) && !method_exists('EmailService', $method)) {
            error_log("[NotificationService] Email method '{$method}' not found.");
            return;
        }

        // Call via global function or static class method depending on email_service structure
        if (function_exists($method)) {
            $method($context, $db);
        } else {
            EmailService::$method($context, $db);
        }
    }

    // ------------------------------------------------------------------
    // Teams dispatch (enqueues — never delivers synchronously)
    // ------------------------------------------------------------------

    private static function dispatchTeams(
        string $title,
        string $body,
        string $action_url,
        string $audience,
        $db
    ): void {
        try {
            $stmt = $db->prepare("
                INSERT INTO email_queue
                    (channel, teams_audience, subject, body, action_url, status, created_at)
                VALUES
                    ('teams', :audience, :subject, :body, :action_url, 'pending', NOW())
            ");
            $stmt->execute([
                ':audience'   => $audience,
                ':subject'    => $title,
                ':body'       => $body,
                ':action_url' => $action_url,
            ]);
        } catch (Exception $e) {
            error_log('[NotificationService] Failed to enqueue Teams notification: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Template resolution
    // ------------------------------------------------------------------

    /**
     * Resolve a template string by substituting {placeholder} tokens from $context.
     * Falls back to hardcoded defaults in email_service.php if the DB template is empty.
     *
     * This mirrors the existing resolveSubject() / resolveBody() pattern.
     */
    private static function resolveTemplate(
        string $template,
        string $event_key,
        array  $context,
        string $type          // 'subject' | 'body'
    ): string {
        // If DB template is blank, fall back to whatever email_service uses for this event.
        // That keeps existing email content intact and reuses it for Teams cards.
        if (trim($template) === '') {
            if ($type === 'subject' && function_exists('resolveSubject')) {
                return resolveSubject($event_key, $context);
            }
            if ($type === 'body' && function_exists('resolveBody')) {
                return resolveBody($event_key, $context);
            }
            return '';
        }

        // Simple token substitution: {key} → $context['key']
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($context) {
            return $context[$m[1]] ?? $m[0]; // Leave unresolved tokens as-is
        }, $template);
    }

    /**
     * Resolve action URL tokens from context.
     * e.g. '/reservations/detail/{reservation_id}' → '/reservations/detail/42'
     */
    private static function resolveActionUrl(string $param, array $context): string
    {
        if (empty($param)) {
            return '';
        }

        global $config;
        $base = rtrim($config['app']['base_url'] ?? '', '/');

        $resolved = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($context) {
            return $context[$m[1]] ?? '';
        }, $param);

        // If any placeholder couldn't be resolved (empty), return just the base URL
        if (strpos($resolved, '{}') !== false || preg_match('/\{/', $resolved)) {
            return $base . '/';
        }

        return $base . $resolved;
    }

    // ------------------------------------------------------------------
    // DB helpers
    // ------------------------------------------------------------------

    private static function loadEventSettings(string $event_key, $db): ?array
    {
        try {
            $stmt = $db->prepare("
                SELECT channel, subject_template, body_template, is_enabled
                FROM email_notification_settings
                WHERE event_key = :key
                LIMIT 1
            ");
            $stmt->execute([':key' => $event_key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log('[NotificationService] Failed to load event settings: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Load system_settings as a flat key→value array.
     * Called by process_email_queue.php for Teams delivery.
     */
    public static function loadSystemSettings($db): array
    {
        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            return $rows ?: [];
        } catch (Exception $e) {
            error_log('[NotificationService] Failed to load system_settings: ' . $e->getMessage());
            return [];
        }
    }
}
