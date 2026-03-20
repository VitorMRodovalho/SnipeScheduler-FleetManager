<?php
/**
 * Vehicle Assignments API
 * GET: Returns assignments for asset_id
 * POST: Saves assignments for asset_id (replaces all)
 *
 * Staff/Admin/SuperAdmin only. CSRF protected for POST.
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

header('Content-Type: application/json');

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']);
if (!$isAdmin && !$isStaff) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$assetId = (int)($_GET['asset_id'] ?? $_POST['asset_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($assetId <= 0) {
        echo json_encode(['assignments' => []]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, asset_id, asset_tag, asset_name, snipeit_user_id, user_id,
               user_name, user_email, is_primary, assignment_label, notes, assigned_at
        FROM vehicle_assignments
        WHERE asset_id = ?
        ORDER BY is_primary DESC, user_name ASC
    ");
    $stmt->execute([$assetId]);
    echo json_encode(['assignments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null) {
        $input = $_POST;
    }

    // Support CSRF token from JSON body
    if (!empty($input['csrf_token'])) {
        $_POST['csrf_token'] = $input['csrf_token'];
    }
    csrf_check();

    $assetId = (int)($input['asset_id'] ?? $assetId);
    if ($assetId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing asset_id']);
        exit;
    }

    $assignments = $input['assignments'] ?? [];
    $assetTag = $input['asset_tag'] ?? '';
    $assetName = $input['asset_name'] ?? '';
    $actorId = $_SESSION['user_id'] ?? '';

    // Get vehicle's company from Snipe-IT for cross-entity validation
    require_once SRC_PATH . '/snipeit_client.php';
    $asset = snipeit_request('GET', '/hardware/' . $assetId);
    $vehicleCompanyId = $asset['company']['id'] ?? null;

    // Validate each driver's company matches the vehicle's company
    if ($vehicleCompanyId && !empty($assignments)) {
        $rejectedDrivers = [];
        foreach ($assignments as $a) {
            $driverEmail = $a['user_email'] ?? '';
            if ($driverEmail) {
                $driverUser = get_snipeit_user_by_email($driverEmail);
                $driverCompanyId = $driverUser['company']['id'] ?? null;
                if ($driverCompanyId && $driverCompanyId != $vehicleCompanyId) {
                    $rejectedDrivers[] = ($a['user_name'] ?? $driverEmail) . ' (different company)';
                }
            }
        }
        if (!empty($rejectedDrivers)) {
            http_response_code(422);
            echo json_encode([
                'error' => 'Company mismatch: ' . implode(', ', $rejectedDrivers) . '. Drivers must belong to the same company as the vehicle.'
            ]);
            exit;
        }
    }

    // Get existing assignments for diff logging
    $existingStmt = $pdo->prepare("SELECT user_name, user_email FROM vehicle_assignments WHERE asset_id = ?");
    $existingStmt->execute([$assetId]);
    $existingNames = $existingStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Replace all assignments for this asset
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM vehicle_assignments WHERE asset_id = ?")->execute([$assetId]);

        if (!empty($assignments)) {
            $insertStmt = $pdo->prepare("
                INSERT INTO vehicle_assignments
                    (asset_id, asset_tag, asset_name, snipeit_user_id, user_id, user_name, user_email, is_primary, assignment_label, assigned_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($assignments as $a) {
                $insertStmt->execute([
                    $assetId,
                    $assetTag,
                    $assetName,
                    (int)($a['snipeit_user_id'] ?? 0),
                    (string)($a['user_id'] ?? ''),
                    (string)($a['user_name'] ?? ''),
                    (string)($a['user_email'] ?? ''),
                    !empty($a['is_primary']) ? 1 : 0,
                    (string)($a['assignment_label'] ?? ''),
                    $actorId,
                    (string)($a['notes'] ?? ''),
                ]);
            }
        }

        $pdo->commit();

        // Clear API cache
        array_map('unlink', glob(CONFIG_PATH . '/cache/*.json'));

        // Build new names for log
        $newNames = array_map(fn($a) => $a['user_name'] ?? '', $assignments);
        $logMsg = "Vehicle assignments updated for {$assetName}";
        if (empty($assignments)) {
            $logMsg .= ' (all removed)';
        }

        activity_log_event('vehicle_assignment_changed', $logMsg, [
            'subject_type' => 'vehicle',
            'subject_id' => (string)$assetId,
            'metadata' => [
                'asset_name' => $assetName,
                'previous_drivers' => $existingNames,
                'new_drivers' => $newNames,
            ],
        ]);

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save assignments: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
