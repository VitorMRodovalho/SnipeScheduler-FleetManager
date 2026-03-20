#!/usr/bin/env php
<?php
/**
 * Fleet Onboarding Script — BL-008 Data Load
 * 
 * Usage:
 *   php scripts/onboard_fleet.php --step=1 --dry-run    # Check users (dry run)
 *   php scripts/onboard_fleet.php --step=1 --apply       # Create users in Snipe-IT + local DB
 *   php scripts/onboard_fleet.php --step=2 --dry-run    # Check training dates
 *   php scripts/onboard_fleet.php --step=2 --apply       # Set training dates
 *   php scripts/onboard_fleet.php --step=3 --dry-run    # Check vehicles
 *   php scripts/onboard_fleet.php --step=3 --apply       # Create vehicles in Snipe-IT
 *
 * ALWAYS run --dry-run first before --apply
 */

// Minimal CLI bootstrap — skip session/auth
define("BASE_PATH", __DIR__ . "/..");
define("SRC_PATH", BASE_PATH . "/src");
define("CONFIG_PATH", BASE_PATH . "/config");
error_reporting(E_ALL);
ini_set("display_errors", 1);
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';

$config = require CONFIG_PATH . '/config.php';

// Parse CLI args
$step = 0;
$dryRun = true;
foreach ($argv as $arg) {
    if (preg_match('/--step=(\d+)/', $arg, $m)) $step = (int)$m[1];
    if ($arg === '--apply') $dryRun = false;
    if ($arg === '--dry-run') $dryRun = true;
}

if ($step < 1 || $step > 3) {
    echo "Usage: php scripts/onboard_fleet.php --step=1|2|3 --dry-run|--apply\n";
    echo "  Step 1: Create/update users in Snipe-IT + local DB\n";
    echo "  Step 2: Set training dates for drivers\n";
    echo "  Step 3: Create vehicles in Snipe-IT\n";
    exit(1);
}

$mode = $dryRun ? 'DRY RUN' : 'APPLY';
echo "=== Fleet Onboarding — Step {$step} ({$mode}) ===\n\n";

// ============================================================
// DATA DEFINITIONS
// ============================================================

// 30 drivers from Pete's training list + 5 extras without training
$drivers = [
    // [first_name, last_name, email, training_expires (null = no training yet)]
    ['Shawn', 'McFadden', 'Shawn.McFadden@amtrak.com', '2027-12-18'],
    ['Charan', 'Dokka', 'Charan.Dokka@amtrak.com', '2027-12-18'],
    ['Clifton', 'Johnson', 'Clifton.Johnson@amtrak.com', '2027-12-18'],
    ['Wes', 'Albright', 'Wes.Albright@amtrak.com', '2027-12-18'],
    ['Edwin', 'Salcedo Rueda', 'Edwin.Salcedo@amtrak.com', '2028-01-13'],
    ['Brad', 'Spong', 'Brad.Spong@amtrak.com', '2028-02-18'],
    ['Pete', 'Wray', 'Pete.Wray@amtrak.com', '2027-12-11'],
    ['Jim', 'Matthews', 'Jim.Matthews@amtrak.com', '2027-12-27'],
    ['Dago', 'Beek', 'Dago.Beek@amtrak.com', '2028-03-11'],
    ['Damian', 'Carey', 'Damian.Carey@amtrak.com', '2027-12-19'],
    ['Mike', 'Keppel', 'Mike.Keppel@amtrak.com', '2028-03-05'],
    ['Matt', 'Sterbutzel', 'Matt.Sterbutzel@amtrak.com', '2028-02-25'],
    ['Tim', 'Walck', 'Tim.Walck@amtrak.com', '2028-02-12'],
    ['Steve', 'Berg', 'Steve.Berg@amtrak.com', '2028-03-25'],
    ['Claudia', 'Orozco', 'Claudia.Orozco@amtrak.com', '2028-04-04'],
    ['Gary', 'Milligan', 'Gary.Milligan@amtrak.com', '2028-05-09'],
    ['Keith', 'Waddell', 'Keith.Waddell@amtrak.com', '2028-03-05'],
    ['Koshy', 'Varghese', 'Koshy.Varghese@amtrak.com', '2028-03-26'],
    ['Shawn', 'Cummings', 'Shawn.Cummings@amtrak.com', '2026-04-06'],
    ['Brian', 'Lange', 'Brian.Lange@amtrak.com', '2028-06-30'],
    ['Heather', 'Smith', 'Heather.Smith@amtrak.com', '2028-06-16'],
    ['Aurelien', 'Gil', 'Aurelien.Gil@amtrak.com', '2028-05-09'],
    ['Rob', 'Shreeve', 'Rob.Shreeve@amtrak.com', '2028-09-15'],
    ['Ralph', 'Smith', 'Ralph.Smith.1@amtrak.com', '2028-04-12'],
    ['Rohit', 'Motwani', 'Rohit.Motwani.1@amtrak.com', '2028-08-24'],
    ['Nathan', 'Ureta', 'Nathan.Ureta@amtrak.com', '2027-12-20'],
    ['Ellison', 'Smith', 'Ellison.Smith@amtrak.com', '2029-01-20'],
    ['Joe', 'Kertis', 'Joseph.Kertis@amtrak.com', '2028-07-07'],
    ['Shane', 'Beabes', 'Shane.Beabes@amtrak.com', '2028-10-31'],
    ['Adam', 'Mouradi', 'Adam.Mouradi@amtrak.com', '2028-07-18'],
    // Extra drivers without training dates
    ['Homayoon', 'Niknia', 'Homayoon.Niknia@amtrak.com', null],
    ['Kaveh', 'Talebi', 'Kaveh.Talebi@amtrak.com', null],
    ['Poly', 'Ofwono', 'Poly.Ofwono@amtrak.com', null],
    ['Evan', 'Poland', 'Evan.Poland@amtrak.com', null],
];

// Rob Shreeve old account to deactivate
$deactivateOldAccount = [
    'email' => 'robert.shreeve@aecom.com',
    'reason' => 'Replaced by Amtrak account Rob.Shreeve@amtrak.com',
];

// 16 vehicles from Pete's spreadsheet
// [unit, vin, license_plate, reg_expiry, year, make, model, status, assignment, fuel_card]
$vehicles = [
    ['FDT-01', '1FMSK8DH7RGA88935', '6GH6975', '2026-07-31', 2024, 'Ford', 'Explorer', 'active', 'Dago Beek', '5551-1'],
    ['FDT-02', '1FMCU9GN0RUA39762', '6GH6977', '2026-07-31', 2024, 'Ford', 'Escape', 'active', 'Charan Dokka', '1233-3'],
    ['FDT-03', '1FMCU9GN5RUA81277', '6GH6976', '2026-07-31', 2024, 'Ford', 'Escape', 'active', 'Clifton Johnson', '1234-1'],
    ['FDT-04', 'JF2SKADC3LH41559', '2ULZ6227', '2024-12-31', 2024, 'Subaru', 'Forester', 'demobilized', null, null],
    ['FDT-05', '1FMCU9GNXRUB47371', '6GL6539', '2026-12-31', 2024, 'Ford', 'Escape', 'active', 'Quality', '1253-1'],
    ['FDT-06', '1FMCU9GN1RUB37330', '1GM9303', '2026-12-31', 2024, 'Ford', 'Escape', 'active', 'Ralph Smith', '1252-3'],
    ['FDT-07', '1FMCU9GN9SUA34002', '8GM8713', '2027-01-31', 2024, 'Ford', 'Escape', 'active', 'Joe Kertis', '3238-7'],
    ['FDT-08', '1FMCU9GN2SUA51191', '8GM8714', '2027-01-31', 2025, 'Ford', 'Escape', 'active', 'Wes Albright', null],
    ['FDT-09', '1FMCU9GN5SUA14474', '5GN9723', '2027-03-31', 2025, 'Ford', 'Escape', 'active', 'Pool', '0530-5'],
    ['FDT-10', '1FMCU9GN0SUA53036', '5GN9719', '2027-03-31', 2025, 'Ford', 'Escape', 'active', 'Matt Sterbutzel', null],
    ['FDT-11', '1FMUK8DH8SGA28029', '3GP2339', '2027-03-31', 2025, 'Ford', 'Explorer', 'active', 'Safety', '9096-6'],
    ['FDT-12', '1FMUK8DH3SGA80975', '3GP2338', '2027-03-31', 2025, 'Ford', 'Explorer', 'active', 'Shawn McFadden', '9094-1'],
    ['FDT-13', '1FMCU9GN5SUA05158', '5GP0744', '2027-03-31', 2025, 'Ford', 'Escape', 'active', 'Steve Berg', '9095-8'],
    ['FDT-14', '1FMCU9GN2SUA55449', '3GP2337', '2027-03-31', 2025, 'Ford', 'Escape', 'active', 'Koshy Varghese', '9097-4'],
    ['FDT-15', '1FMCU9GN8SUA66360', '8GP6732', '2027-04-30', 2025, 'Ford', 'Escape', 'active', 'Ellison Smith', '3412-2'],
    ['FDT-16', '1FMCU9GN5SUA17262', '8GP6733', '2027-04-30', 2025, 'Ford', 'Escape', 'active', 'Shawn Cummings', '3415-9'],
];

// ============================================================
// STEP 1: Users
// ============================================================
if ($step === 1) {
    $driversGroupId = $config['snipeit_groups']['drivers'] ?? 2;
    $companyId = 1; // Advance DP (BPTR)

    // First: deactivate old Rob Shreeve account
    echo "--- Deactivating old account: {$deactivateOldAccount['email']} ---\n";
    $oldUser = get_snipeit_user_by_email($deactivateOldAccount['email']);
    if ($oldUser) {
        echo "  Found Snipe-IT user ID {$oldUser['id']}: {$oldUser['name']}\n";
        if (!$dryRun) {
            // Deactivate in Snipe-IT
            $result = snipeit_request('PATCH', '/users/' . $oldUser['id'], [
                'activated' => false,
                'notes' => $deactivateOldAccount['reason'] . ' — ' . date('Y-m-d'),
            ]);
            echo "  Deactivated: " . ($result['status'] ?? $result['messages'] ?? 'unknown') . "\n";

            // Mark in local DB
            $pdo->prepare("UPDATE users SET name = CONCAT(name, ' [DEACTIVATED]') WHERE email = ?")
                ->execute([strtolower($deactivateOldAccount['email'])]);
            echo "  Local DB marked as deactivated\n";
        } else {
            echo "  [DRY RUN] Would deactivate Snipe-IT user and mark local DB\n";
        }
    } else {
        echo "  Not found in Snipe-IT — skipping\n";
    }
    echo "\n";

    // Process each driver
    $created = 0;
    $skipped = 0;
    $updated = 0;

    foreach ($drivers as [$firstName, $lastName, $email, $trainingExpires]) {
        $emailLower = strtolower($email);
        echo "--- {$firstName} {$lastName} ({$email}) ---\n";

        // Check if exists in Snipe-IT
        $existing = get_snipeit_user_by_email($emailLower);
        if ($existing) {
            echo "  Already in Snipe-IT (ID: {$existing['id']})\n";

            // Check group membership
            $groups = $existing['groups']['rows'] ?? [];
            $inDrivers = false;
            foreach ($groups as $g) {
                if (($g['id'] ?? 0) == $driversGroupId) $inDrivers = true;
            }
            if (!$inDrivers) {
                echo "  NOT in Drivers group — need to add\n";
                if (!$dryRun) {
                    $result = snipeit_request('PATCH', '/users/' . $existing['id'], [
                        'groups' => [$driversGroupId],
                        'company_id' => $companyId,
                    ]);
                    echo "  Updated group + company: " . json_encode($result['status'] ?? $result['messages'] ?? 'done') . "\n";
                    $updated++;
                } else {
                    echo "  [DRY RUN] Would add to Drivers group + set company\n";
                }
            } else {
                echo "  Already in Drivers group — OK\n";
                $skipped++;
            }
        } else {
            echo "  Not in Snipe-IT — creating\n";
            if (!$dryRun) {
                // Generate username from email prefix
                $username = strtolower(str_replace('@amtrak.com', '', $email));
                $username = str_replace('.', '_', $username);

                $payload = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $emailLower,
                    'username' => $username,
                    'password' => 'Tmp@' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 8)) . 'xZ#9', // Random password (SSO auth, never used)
                    'password_confirmation' => '', // Will be set same as password
                    'activated' => true,
                    'groups' => [$driversGroupId],
                    'company_id' => $companyId,
                ];
                // password_confirmation must match
                $payload['password_confirmation'] = $payload['password'];

                $result = snipeit_request('POST', '/users', $payload);
                if (!empty($result['id'])) {
                    echo "  Created Snipe-IT user ID: {$result['id']}\n";
                    $created++;
                } else {
                    echo "  ERROR: " . json_encode($result['messages'] ?? $result) . "\n";
                }
            } else {
                echo "  [DRY RUN] Would create in Snipe-IT as Driver, Company 1\n";
                $created++;
            }
        }

        // Check/create in local DB
        $localUser = $pdo->prepare("SELECT id, name, email FROM users WHERE LOWER(email) = ?");
        $localUser->execute([$emailLower]);
        $local = $localUser->fetch(PDO::FETCH_ASSOC);
        if ($local) {
            echo "  Local DB: exists (id={$local['id']})\n";
        } else {
            echo "  Local DB: not found — will be created on first SSO login\n";
        }
        echo "\n";
    }

    echo "=== Summary ===\n";
    echo "Created: {$created} | Updated: {$updated} | Skipped: {$skipped}\n";
    echo "Total processed: " . count($drivers) . "\n";
}

// ============================================================
// STEP 2: Training Dates
// ============================================================
if ($step === 2) {
    $updated = 0;
    $notFound = 0;

    foreach ($drivers as [$firstName, $lastName, $email, $trainingExpires]) {
        if ($trainingExpires === null) {
            echo "--- {$firstName} {$lastName}: No training date — skipping\n";
            continue;
        }

        $emailLower = strtolower($email);
        // Calculate issuance date (expiry - 36 months)
        $expiryDate = new DateTime($trainingExpires);
        $issuanceDate = (clone $expiryDate)->modify('-36 months');
        $issuanceDateStr = $issuanceDate->format('Y-m-d H:i:s');

        echo "--- {$firstName} {$lastName}: issued={$issuanceDate->format('Y-m-d')}, expires={$trainingExpires}\n";

        // Check local DB
        $stmt = $pdo->prepare("SELECT id, name, training_completed, training_date FROM users WHERE LOWER(email) = ?");
        $stmt->execute([$emailLower]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($user['training_completed'] && $user['training_date']) {
                echo "  Already has training date: {$user['training_date']} — ";
                // Check if we should update
                $existingDate = substr($user['training_date'], 0, 10);
                $newDate = $issuanceDate->format('Y-m-d');
                if ($existingDate === $newDate) {
                    echo "MATCHES — skipping\n";
                } else {
                    echo "DIFFERS (existing: {$existingDate}, new: {$newDate})\n";
                    if (!$dryRun) {
                        $pdo->prepare("UPDATE users SET training_completed = 1, training_date = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$issuanceDateStr, $user['id']]);
                        echo "  Updated\n";
                        $updated++;
                    } else {
                        echo "  [DRY RUN] Would update training date\n";
                    }
                }
            } else {
                echo "  No training date set\n";
                if (!$dryRun) {
                    $pdo->prepare("UPDATE users SET training_completed = 1, training_date = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$issuanceDateStr, $user['id']]);
                    echo "  Set training date: {$issuanceDateStr}\n";
                    $updated++;
                } else {
                    echo "  [DRY RUN] Would set training_completed=1, training_date={$issuanceDateStr}\n";
                }
            }
        } else {
            echo "  Not in local DB yet (will be set on first login)\n";
            $notFound++;
        }
        echo "\n";
    }

    echo "=== Summary ===\n";
    echo "Updated: {$updated} | Not in local DB: {$notFound}\n";
    echo "Note: Drivers not in local DB will have training set when they first log in via SSO.\n";
    echo "Consider adding training_date logic to the SSO login flow if not already present.\n";
}

// ============================================================
// STEP 3: Vehicles
// ============================================================
if ($step === 3) {
    // First, check existing vehicles to avoid duplicates
    echo "--- Checking existing vehicles ---\n";
    $existingAssets = snipeit_request('GET', '/hardware', ['limit' => 100, 'category_id' => $config['catalogue']['allowed_categories'][0] ?? 8]);
    $existingVINs = [];
    $existingTags = [];
    foreach ($existingAssets['rows'] ?? [] as $a) {
        if (!empty($a['serial'])) $existingVINs[strtoupper($a['serial'])] = $a['asset_tag'];
        $existingTags[$a['asset_tag']] = $a['name'];
    }
    echo "Found " . count($existingAssets['rows'] ?? []) . " existing vehicles\n\n";

    // Resolve model IDs — we need Ford Escape, Ford Explorer, Subaru Forester
    echo "--- Resolving model IDs ---\n";
    $models = snipeit_request('GET', '/models', ['limit' => 100]);
    $modelMap = [];
    foreach ($models['rows'] ?? [] as $m) {
        $modelMap[strtolower($m['name'])] = $m['id'];
        echo "  Model: {$m['name']} (ID: {$m['id']})\n";
    }
    echo "\n";

    // Map our vehicle models to Snipe-IT model IDs
    $modelLookup = function($year, $make, $model) use ($modelMap) {
        // Try exact match first
        $search = strtolower("{$model} {$year}");
        foreach ($modelMap as $name => $id) {
            if (stripos($name, $model) !== false && stripos($name, (string)$year) !== false) return $id;
        }
        // Try without year
        foreach ($modelMap as $name => $id) {
            if (stripos($name, $model) !== false) return $id;
        }
        return null;
    };

    // Status label IDs
    $availableStatusId = $config['snipeit_statuses']['available'] ?? 5;
    $outOfServiceStatusId = $config['snipeit_statuses']['out_of_service'] ?? 7;

    // Location ID for main office
    // Fetch from API
    echo "--- Resolving location ---\n";
    $locations = snipeit_request('GET', '/locations', ['limit' => 100]);
    $mainOfficeLocationId = null;
    foreach ($locations['rows'] ?? [] as $loc) {
        if (stripos($loc['name'], 'Main office') !== false || stripos($loc['name'], 'B&P DP Office') !== false) {
            $mainOfficeLocationId = $loc['id'];
            echo "  Main office location: {$loc['name']} (ID: {$loc['id']})\n";
            break;
        }
    }
    if (!$mainOfficeLocationId) {
        echo "  WARNING: Main office location not found! Using location ID 1 as fallback.\n";
        $mainOfficeLocationId = 1;
    }
    echo "\n";

    // Resolve custom field mapping
    echo "--- Resolving custom fields ---\n";
    $fieldMapping = [];
    $fieldsets = snipeit_request('GET', '/fieldsets', ['limit' => 100]);
    foreach ($fieldsets['rows'] ?? [] as $fs) {
        $fields = snipeit_request('GET', '/fieldsets/' . $fs['id']);
        foreach ($fields['fields']['rows'] ?? [] as $f) {
            $fieldMapping[strtolower($f['name'])] = $f['db_column_name'];
            echo "  {$f['name']} => {$f['db_column_name']}\n";
        }
    }
    echo "\n";

    $created = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($vehicles as [$unit, $vin, $plate, $regExpiry, $year, $make, $model, $status, $assignment, $fuelCard]) {
        $assetTag = $plate; // License plate as asset tag
        $name = "{$year} {$make} {$model}";
        $isActive = ($status === 'active');
        $statusId = $isActive ? $availableStatusId : $outOfServiceStatusId;

        echo "--- {$unit}: {$name} (VIN: {$vin}, Plate: {$plate}) ---\n";

        // Check duplicates
        if (isset($existingVINs[strtoupper($vin)])) {
            echo "  VIN already exists as {$existingVINs[strtoupper($vin)]} — skipping\n";
            $skipped++;
            echo "\n";
            continue;
        }
        if (isset($existingTags[$assetTag])) {
            echo "  Asset tag (plate) already exists: {$existingTags[$assetTag]} — skipping\n";
            $skipped++;
            echo "\n";
            continue;
        }

        // Resolve model ID
        $modelId = $modelLookup($year, $make, $model);
        if (!$modelId) {
            echo "  ERROR: No Snipe-IT model found for {$year} {$make} {$model}\n";
            echo "  Available models: " . implode(', ', array_keys($modelMap)) . "\n";
            $errors++;
            echo "\n";
            continue;
        }
        echo "  Model ID: {$modelId}\n";

        // Build custom fields
        $customFields = [];
        $mileageField = $fieldMapping['current mileage'] ?? null;
        $fuelCardField = $fieldMapping['fuel card #'] ?? $fieldMapping['fuel card'] ?? null;
        $visualInspField = $fieldMapping['visual inspection complete'] ?? null;

        if ($mileageField) $customFields[$mileageField] = '0';
        if ($fuelCard && $fuelCardField) $customFields[$fuelCardField] = $fuelCard;
        if ($visualInspField) $customFields[$visualInspField] = 'Yes';

        // Build payload
        $payload = [
            'asset_tag' => $assetTag,
            'name' => $name,
            'serial' => $vin,
            'model_id' => $modelId,
            'status_id' => $statusId,
            'rtd_location_id' => $mainOfficeLocationId,
            'company_id' => 1, // Advance DP (BPTR)
            'requestable' => $isActive,
            'notes' => "Unit {$unit}" . ($assignment ? " | Assignment: {$assignment}" : '') . ($isActive ? '' : ' | DEMOBILIZED'),
        ];

        // Add registration expiry if field exists
        // Snipe-IT expects next_audit_date or warranty_months — we'll use notes + custom field
        // For now, put expiry in a custom field if it exists
        $regField = $fieldMapping['registration expiry'] ?? $fieldMapping['insurance expiry'] ?? null;
        if ($regField) {
            $customFields[$regField] = $regExpiry;
        }

        // Merge custom fields
        $payload = array_merge($payload, $customFields);

        echo "  Status: " . ($isActive ? 'Available' : 'Out of Service') . "\n";
        echo "  Location: Main Office (ID: {$mainOfficeLocationId})\n";
        echo "  Assignment note: " . ($assignment ?? 'none') . "\n";
        echo "  Fuel card: " . ($fuelCard ?? 'none') . "\n";
        echo "  Custom fields: " . json_encode($customFields) . "\n";

        if (!$dryRun) {
            $result = snipeit_request('POST', '/hardware', $payload);
            if (!empty($result['id'])) {
                echo "  CREATED — Snipe-IT asset ID: {$result['id']}\n";
                $created++;
            } else {
                echo "  ERROR: " . json_encode($result['messages'] ?? $result['status'] ?? $result) . "\n";
                $errors++;
            }
        } else {
            echo "  [DRY RUN] Would create with payload above\n";
            $created++;
        }
        echo "\n";
    }

    echo "=== Summary ===\n";
    echo "Created: {$created} | Skipped: {$skipped} | Errors: {$errors}\n";
    echo "Total vehicles processed: " . count($vehicles) . "\n";

    if (!$dryRun) {
        echo "\nClearing API cache...\n";
        array_map('unlink', glob(CONFIG_PATH . '/cache/*.json'));
        echo "Done.\n";
    }
}
