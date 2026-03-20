<?php
/**
 * Vehicle Assignment Helpers
 * Centralized functions for BL-008 assignment enforcement.
 * Source of truth: vehicle_assignments table (local DB, not Snipe-IT).
 */

/**
 * Get the current assignment mode from system_settings.
 * @return string 'off'|'soft'|'enforced'
 */
function get_assignment_mode(PDO $pdo): string
{
    static $mode = null;
    if ($mode === null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'vehicle_assignment_mode' LIMIT 1");
            $stmt->execute();
            $mode = $stmt->fetchColumn() ?: 'off';
        } catch (Throwable $e) {
            $mode = 'off';
        }
    }
    return $mode;
}

/**
 * Get all vehicle assignments for a specific driver.
 * @param string $userId — OAuth CRC32 user_id (NOT snipeit_user_id)
 * @return array [{asset_id, asset_tag, asset_name, is_primary, assignment_label}, ...]
 */
function get_driver_assignments(PDO $pdo, string $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT asset_id, asset_tag, asset_name, is_primary, assignment_label, notes
            FROM vehicle_assignments
            WHERE user_id = ?
            ORDER BY is_primary DESC, asset_name ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get all assigned asset IDs (for bulk filtering).
 * @return int[] array of asset_id values that have any assignment
 */
function get_all_assigned_asset_ids(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT DISTINCT asset_id FROM vehicle_assignments");
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Check if a specific asset is assigned to a specific driver.
 */
function is_asset_assigned_to_driver(PDO $pdo, int $assetId, string $userId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM vehicle_assignments WHERE asset_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$assetId, $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if a specific asset is assigned to ANY driver.
 */
function is_asset_assigned(PDO $pdo, int $assetId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM vehicle_assignments WHERE asset_id = ? LIMIT 1");
        $stmt->execute([$assetId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get the primary driver name for an assigned asset.
 * @return string|null — null if asset is not assigned (pool)
 */
function get_assigned_driver_name(PDO $pdo, int $assetId): ?string
{
    try {
        $stmt = $pdo->prepare("
            SELECT user_name FROM vehicle_assignments
            WHERE asset_id = ?
            ORDER BY is_primary DESC
            LIMIT 1
        ");
        $stmt->execute([$assetId]);
        $name = $stmt->fetchColumn();
        return $name ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Filter a list of Snipe-IT assets based on assignment rules.
 *
 * Rules when mode = 'enforced':
 *   - If driver has assignment for an asset → include it
 *   - If asset has NO assignments (pool) → include it
 *   - If asset is assigned to OTHER drivers → exclude it
 *   - If isStaffOverride = true → include everything (no filtering)
 *
 * Rules when mode = 'soft':
 *   - Include everything, but mark assigned assets with metadata
 *   - Add ['_assignment_warning' => 'Assigned to {name}'] to each relevant asset
 *
 * Rules when mode = 'off':
 *   - Return assets unchanged
 *
 * @param array $assets — list of Snipe-IT asset arrays (each has 'id' key)
 * @param string $userId — requesting driver's OAuth user_id
 * @param bool $isStaffOverride — true if Staff/Admin is acting (Book on Behalf)
 * @return array filtered/annotated assets
 */
function filter_assets_by_assignment(PDO $pdo, array $assets, string $userId, bool $isStaffOverride = false): array
{
    $mode = get_assignment_mode($pdo);

    if ($mode === 'off' || $isStaffOverride) {
        return $assets;
    }

    // Bulk load assignment data
    $assignedAssetIds = get_all_assigned_asset_ids($pdo);
    $driverAssignments = get_driver_assignments($pdo, $userId);
    $driverAssetIds = array_map('intval', array_column($driverAssignments, 'asset_id'));

    $result = [];
    foreach ($assets as $asset) {
        $assetId = (int)($asset['id'] ?? 0);

        if ($mode === 'enforced') {
            if (in_array($assetId, $driverAssetIds, true)) {
                $result[] = $asset;
            } elseif (!in_array($assetId, $assignedAssetIds, true)) {
                $result[] = $asset;
            }
            // else: assigned to someone else → exclude
        } elseif ($mode === 'soft') {
            if (in_array($assetId, $assignedAssetIds, true) && !in_array($assetId, $driverAssetIds, true)) {
                $assignedTo = get_assigned_driver_name($pdo, $assetId);
                $asset['_assignment_warning'] = $assignedTo ? "Assigned to {$assignedTo}" : 'Assigned to another driver';
            }
            $result[] = $asset;
        }
    }

    return $result;
}
