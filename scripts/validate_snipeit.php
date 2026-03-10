<?php
/**
 * Snipe-IT Configuration Validator
 *
 * Verifies that all required entities (groups, status labels, custom fields)
 * exist in the connected Snipe-IT instance and match config.php values.
 *
 * Run manually:   php scripts/validate_snipeit.php
 * Run at deploy:  php scripts/validate_snipeit.php --strict (exits 1 on failure)
 *
 * @since v1.5.0
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';

$strict = in_array('--strict', $argv ?? []);
$quiet  = in_array('--quiet', $argv ?? []);
$config = require CONFIG_PATH . '/config.php';

$pass = 0;
$warn = 0;
$fail = 0;

function check_pass(string $msg): void {
    global $pass, $quiet;
    $pass++;
    if (!$quiet) echo "  [PASS] {$msg}\n";
}

function check_warn(string $msg): void {
    global $warn;
    $warn++;
    echo "  [WARN] {$msg}\n";
}

function check_fail(string $msg): void {
    global $fail;
    $fail++;
    echo "  [FAIL] {$msg}\n";
}

echo "=== Snipe-IT Configuration Validation ===\n\n";

// ---------------------------------------------------------------
// 1. API Connectivity
// ---------------------------------------------------------------
echo "[1/4] API Connectivity\n";
$baseUrl = rtrim($config['snipeit']['base_url'] ?? '', '/');
$token = $config['snipeit']['api_token'] ?? '';

if (empty($baseUrl) || empty($token)) {
    check_fail("Snipe-IT base_url or api_token not configured");
} else {
    $response = snipeit_request('GET', '/statuslabels?limit=1');
    if ($response === null || isset($response['error'])) {
        check_fail("Cannot connect to Snipe-IT API at {$baseUrl}");
    } else {
        check_pass("API connection OK ({$baseUrl})");
    }
}

// ---------------------------------------------------------------
// 2. Groups
// ---------------------------------------------------------------
echo "\n[2/4] Groups\n";
$configGroups = $config['snipeit_groups'] ?? [];

if (empty($configGroups)) {
    check_fail("snipeit_groups not configured in config.php");
} else {
    $apiGroups = snipeit_request('GET', '/groups?limit=50');
    $apiGroupMap = []; // id => name
    if (isset($apiGroups['rows'])) {
        foreach ($apiGroups['rows'] as $g) {
            $apiGroupMap[(int)$g['id']] = $g['name'];
        }
    }

    $groupLabels = [
        'admins'      => 'Super Admin group',
        'drivers'     => 'Drivers group',
        'fleet_staff' => 'Fleet Staff group',
        'fleet_admin' => 'Fleet Admin group',
    ];

    foreach ($groupLabels as $key => $label) {
        $expectedId = $configGroups[$key] ?? null;
        if ($expectedId === null) {
            check_fail("{$label}: Not configured (missing snipeit_groups.{$key})");
        } elseif (isset($apiGroupMap[$expectedId])) {
            check_pass("{$label}: ID {$expectedId} = \"{$apiGroupMap[$expectedId]}\"");
        } else {
            check_fail("{$label}: ID {$expectedId} not found in Snipe-IT (available: " .
                implode(', ', array_map(fn($id, $name) => "{$id}={$name}", array_keys($apiGroupMap), $apiGroupMap)) . ")");
        }
    }
}

// ---------------------------------------------------------------
// 3. Status Labels
// ---------------------------------------------------------------
echo "\n[3/4] Status Labels\n";
$configStatuses = $config['snipeit_statuses'] ?? [];

if (empty($configStatuses)) {
    check_fail("snipeit_statuses not configured in config.php");
} else {
    $apiStatuses = snipeit_request('GET', '/statuslabels?limit=50');
    $apiStatusMap = []; // id => name
    if (isset($apiStatuses['rows'])) {
        foreach ($apiStatuses['rows'] as $s) {
            $apiStatusMap[(int)$s['id']] = $s['name'];
        }
    }

    $statusLabels = [
        'available'      => 'Available status',
        'in_service'     => 'In Service status',
        'out_of_service' => 'Out of Service status',
        'reserved'       => 'Reserved status',
    ];

    foreach ($statusLabels as $key => $label) {
        $expectedId = $configStatuses[$key] ?? null;
        if ($expectedId === null) {
            check_fail("{$label}: Not configured (missing snipeit_statuses.{$key})");
        } elseif (isset($apiStatusMap[$expectedId])) {
            check_pass("{$label}: ID {$expectedId} = \"{$apiStatusMap[$expectedId]}\"");
        } else {
            check_fail("{$label}: ID {$expectedId} not found in Snipe-IT (available: " .
                implode(', ', array_map(fn($id, $name) => "{$id}={$name}", array_keys($apiStatusMap), $apiStatusMap)) . ")");
        }
    }
}

// ---------------------------------------------------------------
// 4. Custom Fields
// ---------------------------------------------------------------
echo "\n[4/4] Custom Fields\n";
$configFields = $config['snipeit_fields'] ?? [];

if (empty($configFields)) {
    check_fail("snipeit_fields not configured in config.php");
} else {
    // Get all custom fields from Snipe-IT
    $apiFields = snipeit_request('GET', '/fields?limit=100');
    $apiFieldColumns = []; // db_column_name => field_name
    if (isset($apiFields['rows'])) {
        foreach ($apiFields['rows'] as $f) {
            $col = $f['db_column_name'] ?? $f['db_column'] ?? '';
            if ($col) {
                $apiFieldColumns[$col] = $f['name'] ?? $col;
            }
        }
    }

    $fieldLabels = [
        'current_mileage'            => 'Current Mileage',
        'last_oil_change_miles'      => 'Last Oil Change Miles',
        'last_tire_rotation_miles'   => 'Last Tire Rotation Miles',
        'visual_inspection_complete' => 'Visual Inspection Complete',
        'checkout_time'              => 'Checkout Time',
        'return_time'                => 'Return Time',
        'last_maintenance_date'      => 'Last Maintenance Date',
        'last_maintenance_mileage'   => 'Last Maintenance Mileage',
    ];

    foreach ($fieldLabels as $key => $label) {
        $expectedColumn = $configFields[$key] ?? null;
        if ($expectedColumn === null) {
            check_warn("{$label}: Not configured (missing snipeit_fields.{$key})");
        } elseif (isset($apiFieldColumns[$expectedColumn])) {
            check_pass("{$label}: {$expectedColumn} = \"{$apiFieldColumns[$expectedColumn]}\"");
        } else {
            // Field might exist but API doesn't expose db_column_name in all versions
            // So warn instead of fail
            check_warn("{$label}: Column \"{$expectedColumn}\" not found via API (may still work if field exists in Snipe-IT)");
        }
    }
}

// ---------------------------------------------------------------
// Summary
// ---------------------------------------------------------------
echo "\n=== SUMMARY ===\n";
echo "  PASS: {$pass}  |  WARN: {$warn}  |  FAIL: {$fail}\n";

if ($fail > 0) {
    echo "\n  ACTION REQUIRED: Fix the FAIL items above before going live.\n";
    echo "  Config file: config/config.php\n";
    echo "  Reference: config/config.example.php\n";
    if ($strict) {
        exit(1);
    }
} elseif ($warn > 0) {
    echo "\n  Some warnings detected. Review the WARN items above.\n";
} else {
    echo "\n  All checks passed! Snipe-IT configuration is valid.\n";
}
