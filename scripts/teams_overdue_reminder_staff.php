<?php
// scripts/teams_overdue_reminder_staff.php
// Cron-safe CLI script: find overdue Snipe-IT assets and send a Teams chat
// to a specific staff user with the full overdue list (all users' assets).
//
// Requirements:
// - Configure Microsoft Graph app with application permissions: Chat.Create, Chat.ReadWrite.All, User.Read.All
// - Environment variables:
//     GRAPH_TENANT_ID
//     GRAPH_CLIENT_ID
//     GRAPH_CLIENT_SECRET
//     GRAPH_SCOPE (optional, defaults to https://graph.microsoft.com/.default)
// - Set TARGET_UPN below to the staff member’s UPN/email to receive the chat.
//
// Usage (cron):
//     TARGET_UPN=staff@yourtenant.com GRAPH_TENANT_ID=... GRAPH_CLIENT_ID=... GRAPH_CLIENT_SECRET=... /usr/bin/php /path/to/scripts/teams_overdue_reminder_staff.php >> /var/log/reserveit_teams_staff.log 2>&1
//
// Azure setup (for chats):
// 1) Register an app in Entra ID → get Client ID and Tenant ID.
// 2) Create a client secret.
// 3) Add Microsoft Graph application permissions: Chat.Create, Chat.ReadWrite.All, User.Read.All.
// 4) Grant admin consent for those permissions.
// 5) Use the env vars above; GRAPH_SCOPE defaults to https://graph.microsoft.com/.default.

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';

$targetUpn = getenv('TARGET_UPN') ?: '';
if ($targetUpn === '') {
    fwrite(STDERR, "[error] TARGET_UPN is required.\n");
    exit(1);
}

// -------------------------------------------------------------------------
// Microsoft Graph helpers
// -------------------------------------------------------------------------
function staff_graph_get_token(): string
{
    $tenant = getenv('GRAPH_TENANT_ID');
    $client = getenv('GRAPH_CLIENT_ID');
    $secret = getenv('GRAPH_CLIENT_SECRET');
    $scope  = getenv('GRAPH_SCOPE') ?: 'https://graph.microsoft.com/.default';

    if (!$tenant || !$client || !$secret) {
        throw new RuntimeException('GRAPH_TENANT_ID, GRAPH_CLIENT_ID, and GRAPH_CLIENT_SECRET must be set.');
    }

    $tokenUrl = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $client,
            'client_secret' => $secret,
            'scope'         => $scope,
        ]),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('Failed to request token: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($code >= 400 || !isset($data['access_token'])) {
        throw new RuntimeException('Token request failed: ' . ($data['error_description'] ?? $raw));
    }
    return $data['access_token'];
}

function staff_graph_send_chat(string $token, string $userUpn, string $body): void
{
    $chatUrl = "https://graph.microsoft.com/v1.0/chats";
    $payload = [
        'chatType' => 'oneOnOne',
        'members' => [
            [
                '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                'roles'       => ['owner'],
                'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('" . addslashes($userUpn) . "')",
            ]
        ],
    ];

    $ch = curl_init($chatUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('Graph create chat failed: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300) {
        throw new RuntimeException('Graph create chat HTTP ' . $code . ': ' . $raw);
    }
    $data = json_decode($raw, true);
    $chatId = $data['id'] ?? null;
    if (!$chatId) {
        throw new RuntimeException('Could not obtain chat ID from Graph response.');
    }

    $msgUrl = "https://graph.microsoft.com/v1.0/chats/{$chatId}/messages";
    $msgPayload = [
        'body' => [
            'contentType' => 'text',
            'content'     => $body,
        ],
    ];

    $ch = curl_init($msgUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($msgPayload),
    ]);
    $rawMsg = curl_exec($ch);
    if ($rawMsg === false) {
        throw new RuntimeException('Graph send message failed: ' . curl_error($ch));
    }
    $msgCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($msgCode >= 300) {
        throw new RuntimeException('Graph send message HTTP ' . $msgCode . ': ' . $rawMsg);
    }
}

// -------------------------------------------------------------------------
// Build overdue list
// -------------------------------------------------------------------------
try {
    $assets = list_checked_out_assets(true); // overdue only
} catch (Throwable $e) {
    fwrite(STDERR, "[error] Failed to load overdue assets: {$e->getMessage()}\n");
    exit(1);
}

if (empty($assets)) {
    echo "[info] No overdue assets found.\n";
    exit(0);
}

$lines = [];
foreach ($assets as $a) {
    $tag    = $a['asset_tag'] ?? '';
    $model  = $a['model']['name'] ?? '';
    $assigned = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
    $userEmail = '';
    $userName  = '';
    if (is_array($assigned)) {
        $userEmail = $assigned['email'] ?? ($assigned['username'] ?? '');
        $userName  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
    } elseif (is_string($assigned)) {
        $userName = $assigned;
    }
    $expRaw = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
    $exp    = $expRaw ? date('d/m/Y', strtotime($expRaw)) : 'unknown';

    $line = $model !== '' ? "{$tag} ({$model}) – due {$exp}" : "{$tag} – due {$exp}";
    if ($userEmail !== '') {
        $line .= " | User: {$userEmail}" . ($userName !== '' ? " ({$userName})" : '');
    }
    $lines[] = $line;
}

$body = "Overdue assets report:\n- " . implode("\n- ", $lines);

try {
    $token = staff_graph_get_token();
    staff_graph_send_chat($token, $targetUpn, $body);
    echo "[sent] Overdue report sent to {$targetUpn}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[error] Failed to send overdue report: {$e->getMessage()}\n");
    exit(1);
}

echo "[done]\n";
