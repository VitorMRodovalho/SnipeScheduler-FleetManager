<?php
/**
 * One-time backfill: populate company_name, company_abbr, company_color
 * on existing reservations that have an asset_id but no company data.
 *
 * Usage: php scripts/backfill_company.php
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/company_filter.php';

echo "=== Backfill Company Data on Reservations ===\n\n";

// Fetch all companies metadata once
$allCompanies = get_all_companies();
$companyMap = [];
foreach ($allCompanies as $co) {
    $companyMap[(int)$co['id']] = $co;
}
echo "Loaded " . count($companyMap) . " companies from Snipe-IT.\n";

// Fetch fleet vehicles to build asset->company lookup
echo "Fetching fleet vehicles from Snipe-IT...\n";
$fleetVehicles = get_fleet_vehicles(1000);
$assetCompany = [];
foreach ($fleetVehicles as $v) {
    $aid = (int)($v['id'] ?? 0);
    $coId = (int)($v['company']['id'] ?? 0);
    if ($aid > 0 && $coId > 0) {
        $assetCompany[$aid] = $coId;
    }
}
echo "Mapped " . count($assetCompany) . " assets to companies.\n\n";

// Find reservations needing backfill
$stmt = $pdo->query("
    SELECT id, asset_id
    FROM reservations
    WHERE company_name IS NULL
      AND asset_id > 0
    ORDER BY id
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($rows) . " reservations to backfill.\n";

$updated = 0;
$skipped = 0;

$updateStmt = $pdo->prepare("
    UPDATE reservations
    SET company_name = ?, company_abbr = ?, company_color = ?
    WHERE id = ?
");

foreach ($rows as $row) {
    $resId = (int)$row['id'];
    $assetId = (int)$row['asset_id'];
    $coId = $assetCompany[$assetId] ?? 0;

    if ($coId === 0 || !isset($companyMap[$coId])) {
        $skipped++;
        continue;
    }

    $co = $companyMap[$coId];
    $companyName = $co['name'] ?? '';
    $companyAbbr = trim($co['notes'] ?? '');
    $companyColor = trim($co['tag_color'] ?? '');

    $updateStmt->execute([$companyName, $companyAbbr, $companyColor, $resId]);
    $updated++;
}

echo "\nDone! Updated: {$updated}, Skipped (no company): {$skipped}\n";
