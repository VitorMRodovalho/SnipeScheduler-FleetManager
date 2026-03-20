#!/usr/bin/env php
<?php
/**
 * Asset Tag Migration — Plates → FDT-## Unit Numbers
 * 
 * Usage:
 *   php scripts/migrate_asset_tags.php --dry-run     # Preview changes
 *   php scripts/migrate_asset_tags.php --apply        # Execute changes
 *
 * What this does:
 * 1. Creates fleet_tag_config table (per-company prefix configuration)
 * 2. Renames 16 vehicle asset_tags from license plates to FDT-01 through FDT-16
 * 3. Updates vehicle_assignments.asset_tag to match
 * 4. Clears API cache
 *
 * ALWAYS run --dry-run first.
 */

define('BASE_PATH', __DIR__ . '/..');
define('SRC_PATH', BASE_PATH . '/src');
define('CONFIG_PATH', BASE_PATH . '/config');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';

$dryRun = true;
foreach ($argv as $arg) {
    if ($arg === '--apply') $dryRun = false;
    if ($arg === '--dry-run') $dryRun = true;
}

$mode = $dryRun ? 'DRY RUN' : 'APPLY';
echo "=== Asset Tag Migration ({$mode}) ===\n\n";

// ============================================================
// STEP 1: Create fleet_tag_config table
// ============================================================
echo "--- Step 1: fleet_tag_config table ---\n";

$tableExists = $pdo->query("SHOW TABLES LIKE 'fleet_tag_config'")->rowCount() > 0;

if ($tableExists) {
    echo "  Table already exists — skipping\n";
} else {
    echo "  Creating fleet_tag_config table\n";
    if (!$dryRun) {
        $pdo->exec("
            CREATE TABLE fleet_tag_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                prefix VARCHAR(20) NOT NULL,
                next_number INT NOT NULL DEFAULT 1,
                zero_pad INT NOT NULL DEFAULT 2,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "  Created\n";
    } else {
        echo "  [DRY RUN] Would create table\n";
    }
}

// Seed config for Company 1
$seedExists = false;
if ($tableExists) {
    $seedExists = $pdo->query("SELECT 1 FROM fleet_tag_config WHERE company_id = 1")->rowCount() > 0;
}

if (!$seedExists) {
    echo "  Seeding: Company 1 → prefix 'FDT-', next_number 17, zero_pad 2\n";
    if (!$dryRun) {
        $pdo->exec("INSERT INTO fleet_tag_config (company_id, prefix, next_number, zero_pad) VALUES (1, 'FDT-', 17, 2)");
        echo "  Seeded\n";
    } else {
        echo "  [DRY RUN] Would seed\n";
    }
} else {
    echo "  Seed already exists for Company 1\n";
}
echo "\n";

// ============================================================
// STEP 2: Map vehicles — current plate tags → FDT-## unit numbers
// ============================================================
echo "--- Step 2: Rename asset_tags in Snipe-IT ---\n";

// The mapping comes from Pete's spreadsheet: Unit # → current plate (asset_tag)
// We extracted unit numbers from the notes field during onboarding
$assets = snipeit_request('GET', '/hardware', ['limit' => 100, 'category_id' => 8]);
$vehicles = [];
foreach ($assets['rows'] ?? [] as $a) {
    $notes = $a['notes'] ?? '';
    if (preg_match('/Unit (FDT-(\d+))/', $notes, $m)) {
        $vehicles[] = [
            'snipeit_id' => $a['id'],
            'current_tag' => $a['asset_tag'],
            'new_tag' => $m[1],
            'unit_num' => (int)$m[2],
            'name' => $a['name'],
        ];
    }
}

// Sort by unit number
usort($vehicles, fn($a, $b) => $a['unit_num'] - $b['unit_num']);

echo "  Found " . count($vehicles) . " vehicles with unit numbers in notes\n\n";

$renamed = 0;
$skipped = 0;
$errors = 0;

foreach ($vehicles as $v) {
    $current = $v['current_tag'];
    $new = $v['new_tag'];
    $id = $v['snipeit_id'];

    if ($current === $new) {
        echo "  {$v['name']}: {$current} → already correct\n";
        $skipped++;
        continue;
    }

    echo "  {$v['name']}: {$current} → {$new}\n";

    if (!$dryRun) {
        $result = snipeit_request('PATCH', '/hardware/' . $id, [
            'asset_tag' => $new,
        ]);
        if (($result['status'] ?? '') === 'success') {
            echo "    Snipe-IT: OK\n";
            $renamed++;
        } else {
            echo "    Snipe-IT ERROR: " . json_encode($result['messages'] ?? $result) . "\n";
            $errors++;
            continue;
        }
    } else {
        echo "    [DRY RUN] Would rename in Snipe-IT\n";
        $renamed++;
    }
}

echo "\n  Renamed: {$renamed} | Skipped: {$skipped} | Errors: {$errors}\n\n";

// ============================================================
// STEP 3: Update vehicle_assignments.asset_tag
// ============================================================
echo "--- Step 3: Update vehicle_assignments ---\n";

// Build a map: snipeit_asset_id → new_tag
$tagMap = [];
foreach ($vehicles as $v) {
    $tagMap[$v['snipeit_id']] = $v['new_tag'];
}

$assignments = $pdo->query("SELECT id, asset_id, asset_tag, user_name FROM vehicle_assignments")->fetchAll(PDO::FETCH_ASSOC);

$aUpdated = 0;
foreach ($assignments as $a) {
    $newTag = $tagMap[$a['asset_id']] ?? null;
    if (!$newTag) {
        echo "  Assignment #{$a['id']} ({$a['user_name']}): asset_id {$a['asset_id']} not in vehicle map — skipping\n";
        continue;
    }
    if ($a['asset_tag'] === $newTag) {
        echo "  Assignment #{$a['id']} ({$a['user_name']}): already {$newTag}\n";
        continue;
    }

    echo "  Assignment #{$a['id']} ({$a['user_name']}): {$a['asset_tag']} → {$newTag}\n";
    if (!$dryRun) {
        $pdo->prepare("UPDATE vehicle_assignments SET asset_tag = ? WHERE id = ?")->execute([$newTag, $a['id']]);
        $aUpdated++;
    } else {
        echo "    [DRY RUN] Would update\n";
        $aUpdated++;
    }
}

echo "\n  Assignment tags updated: {$aUpdated}\n\n";

// ============================================================
// STEP 4: Update vehicle names to include unit number
// ============================================================
echo "--- Step 4: Update vehicle names ---\n";

$nUpdated = 0;
foreach ($vehicles as $v) {
    $id = $v['snipeit_id'];
    $currentName = $v['name'];
    $unitTag = $v['new_tag'];

    // If name already contains the unit tag, skip
    if (strpos($currentName, $unitTag) !== false) {
        echo "  {$currentName}: already contains {$unitTag}\n";
        continue;
    }

    // Don't change the name — it follows [Year Make Model] convention
    // The unit number is in the asset_tag now, which is the canonical identifier
    echo "  {$currentName} ({$unitTag}): name kept as-is (unit ID is in asset_tag)\n";
}

echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== Summary ===\n";
echo "fleet_tag_config: " . ($tableExists ? 'existed' : 'created') . "\n";
echo "Asset tags renamed: {$renamed}\n";
echo "Assignment rows updated: {$aUpdated}\n";
echo "Errors: {$errors}\n";

if (!$dryRun) {
    echo "\nClearing API cache...\n";
    array_map('unlink', glob(CONFIG_PATH . '/cache/*.json'));
    echo "Done.\n";
}
