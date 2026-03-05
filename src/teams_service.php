<?php
/**
 * TeamsService
 *
 * Builds and delivers Microsoft Teams Adaptive Cards via Incoming Webhook.
 *
 * Design principles:
 *   - No emojis baked in — all text comes from admin-configured templates.
 *   - Delivery is always async (called from process_email_queue.php cron).
 *   - A single public entry point: TeamsService::deliver($queue_row, $settings).
 *   - Webhook URLs come exclusively from system_settings — never hardcoded.
 *   - Timeout is capped at 5 seconds; failure is logged but never fatal.
 *   - Uses Adaptive Card v1.4 (MessageCard format is deprecated by Microsoft).
 */
class TeamsService
{
    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Deliver a queued Teams notification.
     *
     * @param  array $queue_row  Row from email_queue (channel = 'teams')
     * @param  array $settings   Loaded system_settings as key→value array
     * @return bool              True on HTTP 200/202, false on any failure
     */
    public static function deliver(array $queue_row, array $settings): bool
    {
        if (empty($settings['teams_webhook_enabled']) || $settings['teams_webhook_enabled'] !== '1') {
            error_log('[TeamsService] Delivery skipped — teams_webhook_enabled is off.');
            return false;
        }

        $audience = $queue_row['teams_audience'] ?? 'fleet_ops';
        $url_key  = ($audience === 'admin') ? 'teams_webhook_url_admin' : 'teams_webhook_url_fleet_ops';
        $url      = $settings[$url_key] ?? '';

        if (empty($url)) {
            error_log("[TeamsService] Delivery skipped — webhook URL not configured for audience '{$audience}'.");
            return false;
        }

        $title      = $queue_row['subject']   ?? 'Fleet Management Notification';
        $body       = $queue_row['body']       ?? '';
        $action_url = $queue_row['action_url'] ?? '';

        $card    = self::buildCard($title, $body, $action_url);
        $payload = self::wrapPayload($card);

        return self::post($url, $payload);
    }

    /**
     * Send a test card immediately (synchronous, for admin UI "Test" button).
     *
     * @param  string $webhook_url  Full Teams incoming webhook URL
     * @param  string $audience     'fleet_ops' | 'admin'
     * @return array{success: bool, http_code: int, error: string}
     */
    public static function sendTest(string $webhook_url, string $audience = 'fleet_ops'): array
    {
        $label   = ($audience === 'admin') ? 'Admin Channel' : 'Fleet Ops Channel';
        $title   = 'SnipeScheduler — Test Notification';
        $body    = "This is a test message from the Fleet Management System.\n\n"
                 . "Channel: {$label}\n"
                 . "Sent at: " . date('Y-m-d H:i:s T');
        $action  = rtrim(self::baseUrl(), '/') . '/';

        $card    = self::buildCard($title, $body, $action, 'Open SnipeScheduler');
        $payload = self::wrapPayload($card);

        $ch = curl_init($webhook_url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response  = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        $success = in_array($http_code, [200, 202], true) && $error === '';

        return [
            'success'   => $success,
            'http_code' => $http_code,
            'error'     => $error,
        ];
    }

    // ------------------------------------------------------------------
    // Card builder
    // ------------------------------------------------------------------

    /**
     * Build an Adaptive Card array (does not include the Teams wrapper).
     *
     * Body text supports newlines — each paragraph becomes a separate TextBlock
     * so Teams renders them correctly (Teams collapses single \n otherwise).
     */
    public static function buildCard(
        string $title,
        string $body,
        string $action_url   = '',
        string $action_label = 'View in SnipeScheduler'
    ): array {
        $body_blocks = self::bodyToBlocks($body);

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type'    => 'AdaptiveCard',
            'version' => '1.4',
            'body'    => array_merge(
                [
                    [
                        'type'    => 'TextBlock',
                        'text'    => $title,
                        'weight'  => 'Bolder',
                        'size'    => 'Medium',
                        'wrap'    => true,
                        'color'   => 'Default',
                    ],
                ],
                $body_blocks
            ),
        ];

        if (!empty($action_url)) {
            $card['actions'] = [[
                'type'  => 'Action.OpenUrl',
                'title' => $action_label,
                'url'   => $action_url,
            ]];
        }

        return $card;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Wrap a card in the Teams message envelope required by Incoming Webhooks.
     */
    private static function wrapPayload(array $card): array
    {
        return [
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => $card,
            ]],
        ];
    }

    /**
     * POST JSON payload to a Teams webhook URL.
     *
     * @return bool  True on HTTP 200/202, false on any error.
     */
    private static function post(string $url, array $payload): bool
    {
        $json = json_encode($payload);
        if ($json === false) {
            error_log('[TeamsService] Failed to JSON-encode payload: ' . json_last_error_msg());
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,          // 5-second hard cap — never block the cron
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            error_log("[TeamsService] cURL error: {$error}");
            return false;
        }

        if (!in_array($http_code, [200, 202], true)) {
            error_log("[TeamsService] Unexpected HTTP {$http_code}. Response: {$response}");
            return false;
        }

        return true;
    }

    /**
     * Split body text into Adaptive Card TextBlock elements.
     * Double newlines = new paragraph block; single newlines are preserved within a block.
     */
    private static function bodyToBlocks(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        // Split on blank lines into paragraphs
        $paragraphs = preg_split('/\n{2,}/', trim($body));
        $blocks     = [];

        foreach ($paragraphs as $paragraph) {
            $text = trim($paragraph);
            if ($text === '') {
                continue;
            }
            $blocks[] = [
                'type'    => 'TextBlock',
                'text'    => $text,
                'wrap'    => true,
                'spacing' => 'Medium',
            ];
        }

        return $blocks;
    }

    /**
     * Read base URL from config for action buttons.
     * Falls back to a safe placeholder if config is unavailable.
     */
    private static function baseUrl(): string
    {
        // Config is already loaded globally by the time this runs
        global $config;
        return $config['app']['base_url'] ?? 'https://inventory.amtrakfdt.com/booking';
    }
}
