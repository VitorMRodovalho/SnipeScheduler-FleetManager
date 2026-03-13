<?php
/**
 * Fleet Reports
 * Usage history, maintenance costs, compliance status, driver analytics
 *
 * Epic 3 v2: Full CXO UX overhaul
 *   1. Summary: active/pending/completion rate/total miles, fleet pulse
 *   2. Usage: summary cards, status filter, footer totals
 *   3. Utilization: fleet average, completion rate, idle callout
 *   4. Maintenance: footer totals, type filter
 *   5. Driver: summary cards, user filter, row highlighting
 *   6. Compliance: summary bar, worst-first sort, action links
 *   Cross-cutting: active filter label, empty state guidance, print stylesheet
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/company_filter.php';

$active = 'reports.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    header('Location: dashboard');
    exit;
}

$report = $_GET['report'] ?? 'summary';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$queryDateFrom = $dateFrom ?: "2020-01-01";
$queryDateTo = $dateTo ?: date("Y-m-d");
$assetFilter = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : null;
$userFilter = isset($_GET['user_email']) ? trim($_GET['user_email']) : null;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : null;
$maintTypeFilter = isset($_GET['maint_type']) ? trim($_GET['maint_type']) : null;

// Build display period label
$periodLabel = 'All Time';
if ($dateFrom && $dateTo) {
    $periodLabel = date('M j, Y', strtotime($dateFrom)) . ' – ' . date('M j, Y', strtotime($dateTo));
} elseif ($dateFrom) {
    $periodLabel = 'Since ' . date('M j, Y', strtotime($dateFrom));
} elseif ($dateTo) {
    $periodLabel = 'Through ' . date('M j, Y', strtotime($dateTo));
}

// Multi-entity fleet filtering
$multiCompany = is_multi_company_enabled($pdo);
$userCompanyIds = $multiCompany ? get_user_company_ids($currentUser) : [];

// Get all assets for filter dropdown
$allAssets = get_requestable_assets(100, null);
$assetList = is_array($allAssets) ? $allAssets : [];

// Apply company filtering
if (!empty($userCompanyIds)) {
    $assetList = filter_assets_by_company($assetList, $userCompanyIds);
}

// Get unique users for filter dropdown
$stmt = $pdo->query("SELECT DISTINCT user_name, user_email FROM reservations ORDER BY user_name");
$userList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper: extract mileage from checkout/checkin form data
function extractTripMileage(array $coData, array $ciData): array {
    $coMi = null; $ciMi = null;
    foreach ($coData as $k => $v) { if (stripos($k, 'current_mileage') !== false && $v !== '') $coMi = (int)$v; }
    foreach ($ciData as $k => $v) { if (stripos($k, 'current_mileage') !== false && $v !== '') $ciMi = (int)$v; }
    $tripMi = ($coMi !== null && $ciMi !== null) ? max(0, $ciMi - $coMi) : null;
    return ['checkout' => $coMi, 'checkin' => $ciMi, 'trip' => $tripMi];
}

// =========================================================================
// CSV Export handler
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportReportCSV($pdo, $report, $queryDateFrom, $queryDateTo, $assetFilter, $userFilter, $statusFilter, $maintTypeFilter, $assetList);
    exit;
}

function exportReportCSV($pdo, $report, $queryDateFrom, $queryDateTo, $assetFilter, $userFilter, $statusFilter, $maintTypeFilter, $assetList) {
    $filename = "fleet_report_{$report}_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');

    if ($report === 'usage') {
        fputcsv($output, ['Date', 'Vehicle', 'Tag', 'User', 'Status', 'Checkout Time', 'Checkin Time', 'Mi Out', 'Mi In', 'Trip Miles', 'Duration (hrs)']);

        $sql = "SELECT r.*, TIMESTAMPDIFF(HOUR, r.start_datetime, COALESCE(r.end_datetime, NOW())) as duration_hours
                FROM reservations r WHERE DATE(r.start_datetime) BETWEEN ? AND ?";
        $params = [$queryDateFrom, $queryDateTo];
        if ($assetFilter) { $sql .= " AND r.asset_id = ?"; $params[] = $assetFilter; }
        if ($userFilter) { $sql .= " AND r.user_email = ?"; $params[] = $userFilter; }
        if ($statusFilter) { $sql .= " AND r.status = ?"; $params[] = $statusFilter; }
        $sql .= " ORDER BY r.start_datetime DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $coData = json_decode($row['checkout_form_data'] ?? '{}', true) ?: [];
            $ciData = json_decode($row['checkin_form_data'] ?? '{}', true) ?: [];
            $mi = extractTripMileage($coData, $ciData);
            fputcsv($output, [
                date('Y-m-d', strtotime($row['start_datetime'])),
                $row['asset_name_cache'], '', $row['user_name'], $row['status'],
                date('Y-m-d H:i', strtotime($row['start_datetime'])),
                $row['end_datetime'] ? date('Y-m-d H:i', strtotime($row['end_datetime'])) : '',
                $mi['checkout'] !== null ? $mi['checkout'] : '',
                $mi['checkin'] !== null ? $mi['checkin'] : '',
                $mi['trip'] !== null ? $mi['trip'] : '',
                $row['duration_hours']
            ]);
        }

    } elseif ($report === 'maintenance') {
        fputcsv($output, ['Date', 'Vehicle', 'Tag', 'Type', 'Title', 'Mileage', 'Provider', 'Cost', 'Logged By']);
        $allMaintenances = get_maintenances(500);
        foreach ($allMaintenances as $m) {
            $completionDate = $m['completion_date']['date'] ?? $m['start_date']['date'] ?? null;
            if (!$completionDate) continue;
            $mDate = substr($completionDate, 0, 10);
            if ($mDate < $queryDateFrom || $mDate > $queryDateTo) continue;
            if ($assetFilter && ($m['asset']['id'] ?? 0) != $assetFilter) continue;
            $mType = $m['asset_maintenance_type'] ?? 'Maintenance';
            if ($maintTypeFilter && $mType !== $maintTypeFilter) continue;
            $notes = $m['notes'] ?? '';
            $mileage = 0;
            if (preg_match('/Mileage at service:\s*([\d,]+)/i', $notes, $matches)) {
                $mileage = (int)str_replace(',', '', $matches[1]);
            }
            fputcsv($output, [
                $mDate, $m['asset']['name'] ?? 'Unknown', $m['asset']['asset_tag'] ?? '',
                ucfirst($mType), $m['title'] ?? '',
                $mileage ? number_format($mileage) : '',
                $m['supplier']['name'] ?? '',
                ($m['cost'] ?? 0) > 0 ? '$' . number_format($m['cost'], 2) : '',
                $m['user_id']['name'] ?? $m['created_by']['name'] ?? ''
            ]);
        }

    } elseif ($report === 'compliance') {
        fputcsv($output, ['Vehicle', 'Tag', 'Insurance Expiry', 'Insurance Status', 'Insurance Days', 'Registration Expiry', 'Registration Status', 'Registration Days', 'Miles Since Service', 'Maintenance Status']);
        foreach ($assetList as $asset) {
            $cf = $asset['custom_fields'] ?? [];
            $insExp = $cf['Insurance Expiry']['value'] ?? null;
            $regExp = $cf['Registration Expiry']['value'] ?? null;
            $curMi = (int)($cf['Current Mileage']['value'] ?? 0);
            $lastMi = (int)($cf['Last Maintenance Mileage']['value'] ?? 0);
            $milesSince = $curMi - $lastMi;
            $insDays = $insExp ? (int)((strtotime($insExp) - time()) / 86400) : null;
            $regDays = $regExp ? (int)((strtotime($regExp) - time()) / 86400) : null;
            $insStatus = $insDays === null ? 'Unknown' : ($insDays < 0 ? 'EXPIRED' : ($insDays <= 30 ? 'Warning' : 'OK'));
            $regStatus = $regDays === null ? 'Unknown' : ($regDays < 0 ? 'EXPIRED' : ($regDays <= 30 ? 'Warning' : 'OK'));
            $mntStatus = $milesSince >= 7500 ? 'DUE' : ($milesSince >= 7000 ? 'Warning' : 'OK');
            fputcsv($output, [
                $asset['name'], $asset['asset_tag'],
                $insExp ?: 'N/A', $insStatus, $insDays !== null ? $insDays : 'N/A',
                $regExp ?: 'N/A', $regStatus, $regDays !== null ? $regDays : 'N/A',
                number_format($milesSince), $mntStatus
            ]);
        }

    } elseif ($report === 'utilization') {
        fputcsv($output, ['Vehicle', 'Tag', 'Total Reservations', 'Completed', 'Completion Rate', 'Hours Used', 'Utilization Rate (%)']);
        $totalDays = max(1, (strtotime($queryDateTo) - strtotime($queryDateFrom)) / 86400);
        foreach ($assetList as $asset) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(TIMESTAMPDIFF(HOUR, start_datetime, LEAST(end_datetime, NOW()))) as hours FROM reservations WHERE asset_id=? AND DATE(start_datetime) BETWEEN ? AND ?");
            $stmt->execute([$asset['id'], $queryDateFrom, $queryDateTo]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            $hours = (int)($s['hours'] ?? 0);
            $util = ($totalDays * 10) > 0 ? min(100, round(($hours / ($totalDays * 10)) * 100)) : 0;
            $cr = ($s['total'] ?? 0) > 0 ? round((($s['completed'] ?? 0) / $s['total']) * 100) : 0;
            fputcsv($output, [$asset['name'], $asset['asset_tag'], (int)($s['total'] ?? 0), (int)($s['completed'] ?? 0), $cr . '%', $hours, $util]);
        }

    } elseif ($report === 'driver') {
        // Load training settings
        $trainStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'training_validity_months'");
        $trainStmt->execute();
        $trainValidityMonths = (int)($trainStmt->fetchColumn() ?: 12);

        fputcsv($output, ['Driver', 'Email', 'Total Trips', 'Completed', 'Cancelled', 'Missed', 'Completion Rate', 'Total Miles', 'Avg Miles/Trip', 'Total Hours', 'Maintenance Flags', 'Training Completed', 'Training Date', 'Training Expiry', 'Training Status']);
        $stmt = $pdo->prepare("SELECT user_name, user_email, COUNT(*) as total_trips, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN status='missed' THEN 1 ELSE 0 END) as missed, SUM(CASE WHEN maintenance_flag=1 THEN 1 ELSE 0 END) as maint_flags, SUM(TIMESTAMPDIFF(HOUR, start_datetime, COALESCE(end_datetime, NOW()))) as total_hours FROM reservations WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY user_email, user_name ORDER BY total_trips DESC");
        $stmt->execute([$queryDateFrom, $queryDateTo]);
        $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Batch: fetch all mileage data in one query
        $mileAllStmt = $pdo->prepare("SELECT user_email, checkout_form_data, checkin_form_data FROM reservations WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ?");
        $mileAllStmt->execute([$queryDateFrom, $queryDateTo]);
        $mileageByDriver = [];
        while ($r = $mileAllStmt->fetch(PDO::FETCH_ASSOC)) {
            $mi = extractTripMileage(json_decode($r['checkout_form_data'] ?? '{}', true) ?: [], json_decode($r['checkin_form_data'] ?? '{}', true) ?: []);
            if ($mi['trip'] !== null) {
                $email = $r['user_email'];
                if (!isset($mileageByDriver[$email])) { $mileageByDriver[$email] = ['miles' => 0, 'trips' => 0]; }
                $mileageByDriver[$email]['miles'] += $mi['trip'];
                $mileageByDriver[$email]['trips']++;
            }
        }

        // Batch: fetch all training data in one query
        $trainingStmt = $pdo->query("SELECT email, training_completed, training_date FROM users");
        $trainingByEmail = [];
        while ($t = $trainingStmt->fetch(PDO::FETCH_ASSOC)) {
            $trainingByEmail[$t['email']] = $t;
        }

        foreach ($drivers as $d) {
            $dMileage = $mileageByDriver[$d['user_email']] ?? ['miles' => 0, 'trips' => 0];
            $totalMiles = $dMileage['miles'];
            $tripCount = $dMileage['trips'];
            $cr = $d['total_trips'] > 0 ? round(($d['completed'] / $d['total_trips']) * 100) : 0;

            $tUser = $trainingByEmail[$d['user_email']] ?? null;
            $tCompleted = $tUser ? (int)$tUser['training_completed'] : 0;
            $tDate = $tUser['training_date'] ?? null;
            $tExpiry = $tDate ? date('Y-m-d', strtotime($tDate . " + {$trainValidityMonths} months")) : null;
            $tDays = $tExpiry ? (int)((strtotime($tExpiry) - time()) / 86400) : null;
            $tStatus = !$tCompleted ? 'Not completed' : ($tExpiry === null ? 'No date' : ($tDays < 0 ? 'EXPIRED' : ($tDays <= 30 ? 'Expiring' : 'Valid')));

            fputcsv($output, [$d['user_name'], $d['user_email'], $d['total_trips'], $d['completed'], $d['cancelled'], $d['missed'], $cr . '%', number_format($totalMiles), $tripCount > 0 ? number_format(round($totalMiles / $tripCount)) : 0, (int)($d['total_hours'] ?? 0), $d['maint_flags'], $tCompleted ? 'Yes' : 'No', $tDate ? date('Y-m-d', strtotime($tDate)) : '', $tExpiry ?: '', $tStatus]);
        }
    }
    fclose($output);
}

// =========================================================================
// Build report data
// =========================================================================
$reportData = [];

if ($report === 'summary') {
    // Reservation statistics
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'maintenance_required' THEN 1 ELSE 0 END) as maintenance
        FROM reservations
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$queryDateFrom, $queryDateTo]);
    $reportData['reservation_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // Completion rate
    $total = (int)($reportData['reservation_stats']['total'] ?? 0);
    $completed = (int)($reportData['reservation_stats']['completed'] ?? 0);
    $cancelled = (int)($reportData['reservation_stats']['cancelled'] ?? 0);
    $actionable = $total - $cancelled; // exclude cancelled from rate calc
    $reportData['completion_rate'] = $actionable > 0 ? round(($completed / $actionable) * 100) : 0;

    // Total miles driven across fleet
    $stmt = $pdo->prepare("SELECT checkout_form_data, checkin_form_data FROM reservations WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$queryDateFrom, $queryDateTo]);
    $fleetMiles = 0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mi = extractTripMileage(
            json_decode($r['checkout_form_data'] ?? '{}', true) ?: [],
            json_decode($r['checkin_form_data'] ?? '{}', true) ?: []
        );
        if ($mi['trip'] !== null) $fleetMiles += $mi['trip'];
    }
    $reportData['fleet_miles'] = $fleetMiles;

    // Reservations by vehicle
    $stmt = $pdo->prepare("
        SELECT asset_name_cache, asset_id, company_abbr, company_color, company_name,
               COUNT(*) as count,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM reservations
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND asset_name_cache IS NOT NULL AND asset_name_cache != ''
        GROUP BY asset_id, asset_name_cache, company_abbr, company_color, company_name ORDER BY count DESC
    ");
    $stmt->execute([$queryDateFrom, $queryDateTo]);
    $reportData['by_vehicle'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Reservations by user
    $stmt = $pdo->prepare("
        SELECT user_name, user_email, COUNT(*) as count,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM reservations WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY user_email, user_name ORDER BY count DESC LIMIT 10
    ");
    $stmt->execute([$queryDateFrom, $queryDateTo]);
    $reportData['by_user'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Maintenance costs (single pass)
    $allMaintenances = get_maintenances(500);
    $totalServices = 0; $totalCost = 0;
    $typeStats = []; $costByVehicle = [];

    foreach ($allMaintenances as $m) {
        $completionDate = $m['completion_date']['date'] ?? $m['start_date']['date'] ?? null;
        if (!$completionDate) continue;
        $mDate = substr($completionDate, 0, 10);
        if ($mDate < $queryDateFrom || $mDate > $queryDateTo) continue;

        $cost = floatval($m['cost'] ?? 0);
        $type = $m['asset_maintenance_type'] ?? 'Maintenance';
        $vehicleId = $m['asset']['id'] ?? 0;

        $totalServices++; $totalCost += $cost;

        if (!isset($typeStats[$type])) $typeStats[$type] = ['count' => 0, 'total_cost' => 0];
        $typeStats[$type]['count']++; $typeStats[$type]['total_cost'] += $cost;

        if (!isset($costByVehicle[$vehicleId])) $costByVehicle[$vehicleId] = ['name' => $m['asset']['name'] ?? 'Unknown', 'count' => 0, 'total_cost' => 0];
        $costByVehicle[$vehicleId]['count']++; $costByVehicle[$vehicleId]['total_cost'] += $cost;
    }

    $reportData['maintenance_costs'] = [
        'total_services' => $totalServices, 'total_cost' => $totalCost,
        'avg_cost' => $totalServices > 0 ? $totalCost / $totalServices : 0,
    ];
    $reportData['maintenance_by_type'] = [];
    foreach ($typeStats as $type => $stats) {
        $reportData['maintenance_by_type'][] = ['maintenance_type' => $type, 'count' => $stats['count'], 'total_cost' => $stats['total_cost']];
    }
    usort($reportData['maintenance_by_type'], fn($a, $b) => $b['count'] - $a['count']);
    uasort($costByVehicle, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);
    $reportData['cost_by_vehicle'] = array_slice($costByVehicle, 0, 5, true);

} elseif ($report === 'usage') {
    // Usage report with summary aggregation
    $sql = "SELECT r.*, TIMESTAMPDIFF(HOUR, r.start_datetime, COALESCE(r.end_datetime, NOW())) as duration_hours
            FROM reservations r WHERE DATE(r.start_datetime) BETWEEN ? AND ?";
    $params = [$queryDateFrom, $queryDateTo];
    if ($assetFilter) { $sql .= " AND r.asset_id = ?"; $params[] = $assetFilter; }
    if ($userFilter) { $sql .= " AND r.user_email = ?"; $params[] = $userFilter; }
    if ($statusFilter) { $sql .= " AND r.status = ?"; $params[] = $statusFilter; }
    $sql .= " ORDER BY r.start_datetime DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawUsage = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregation for summary cards
    $usageSummary = ['total' => 0, 'completed' => 0, 'total_miles' => 0, 'total_hours' => 0, 'trips_with_miles' => 0];

    foreach ($rawUsage as &$row) {
        $coData = json_decode($row['checkout_form_data'] ?? '{}', true) ?: [];
        $ciData = json_decode($row['checkin_form_data'] ?? '{}', true) ?: [];

        $row['actual_checkout'] = (!empty($coData['checkout_date']) && !empty($coData['checkout_time']))
            ? $coData['checkout_date'] . ' ' . $coData['checkout_time'] : $row['start_datetime'];

        if (!empty($ciData['return_date']) && !empty($ciData['return_time'])) {
            $row['actual_checkin'] = $ciData['return_date'] . ' ' . $ciData['return_time'];
        } elseif (in_array($row['status'], ['completed', 'maintenance_required'])) {
            $row['actual_checkin'] = $row['end_datetime'];
        } else {
            $row['actual_checkin'] = null;
        }

        $row['actual_hours'] = ($row['actual_checkout'] && $row['actual_checkin'])
            ? max(0, round((strtotime($row['actual_checkin']) - strtotime($row['actual_checkout'])) / 3600, 1))
            : $row['duration_hours'];

        $mi = extractTripMileage($coData, $ciData);
        $row['checkout_mileage'] = $mi['checkout'];
        $row['checkin_mileage'] = $mi['checkin'];
        $row['trip_miles'] = $mi['trip'];

        // Accumulate summary
        $usageSummary['total']++;
        if ($row['status'] === 'completed') $usageSummary['completed']++;
        if ($mi['trip'] !== null) { $usageSummary['total_miles'] += $mi['trip']; $usageSummary['trips_with_miles']++; }
        $usageSummary['total_hours'] += (float)$row['actual_hours'];
    }
    unset($row);

    $usageSummary['avg_miles'] = $usageSummary['trips_with_miles'] > 0
        ? round($usageSummary['total_miles'] / $usageSummary['trips_with_miles']) : 0;
    $usageSummary['avg_hours'] = $usageSummary['total'] > 0
        ? round($usageSummary['total_hours'] / $usageSummary['total'], 1) : 0;

    $reportData['usage'] = $rawUsage;
    $reportData['usage_summary'] = $usageSummary;

} elseif ($report === 'maintenance') {
    // Maintenance report with type filter
    $allMaintenances = get_maintenances(500);
    $reportData['maintenance'] = [];
    $reportData['total_cost'] = 0;
    $costPerVehicle = [];
    $reportData['available_types'] = [];

    foreach ($allMaintenances as $m) {
        $completionDate = $m['completion_date']['date'] ?? $m['start_date']['date'] ?? null;
        if (!$completionDate) continue;
        $mDate = substr($completionDate, 0, 10);
        if ($mDate < $queryDateFrom || $mDate > $queryDateTo) continue;
        if ($assetFilter && ($m['asset']['id'] ?? 0) != $assetFilter) continue;

        $mType = $m['asset_maintenance_type'] ?? 'Maintenance';

        // Track available types for filter dropdown
        if (!in_array($mType, $reportData['available_types'])) {
            $reportData['available_types'][] = $mType;
        }

        if ($maintTypeFilter && $mType !== $maintTypeFilter) continue;

        $cost = floatval($m['cost'] ?? 0);
        $reportData['total_cost'] += $cost;

        $notes = $m['notes'] ?? '';
        $mileage = 0;
        if (preg_match('/Mileage at service:\s*([\d,]+)/i', $notes, $matches)) {
            $mileage = (int)str_replace(',', '', $matches[1]);
        }

        $vehicleId = $m['asset']['id'] ?? 0;
        $vehicleName = $m['asset']['name'] ?? 'Unknown';
        $vehicleTag = $m['asset']['asset_tag'] ?? '';

        if (!isset($costPerVehicle[$vehicleId])) {
            $costPerVehicle[$vehicleId] = ['name' => $vehicleName, 'tag' => $vehicleTag, 'total_cost' => 0, 'service_count' => 0, 'max_mileage' => 0];
        }
        $costPerVehicle[$vehicleId]['total_cost'] += $cost;
        $costPerVehicle[$vehicleId]['service_count']++;
        if ($mileage > 0) $costPerVehicle[$vehicleId]['max_mileage'] = max($costPerVehicle[$vehicleId]['max_mileage'], $mileage);

        $reportData['maintenance'][] = [
            'id' => $m['id'], 'asset_id' => $vehicleId, 'asset_name' => $vehicleName, 'asset_tag' => $vehicleTag,
            'maintenance_type' => $mType, 'title' => $m['title'] ?? '', 'service_date' => $mDate,
            'cost' => $cost, 'mileage' => $mileage, 'supplier' => $m['supplier']['name'] ?? '',
            'notes' => $notes, 'logged_by' => $m['user_id']['name'] ?? $m['created_by']['name'] ?? '',
        ];
    }

    foreach ($costPerVehicle as &$cpv) {
        $cpv['cost_per_mile'] = ($cpv['max_mileage'] > 0 && $cpv['total_cost'] > 0) ? $cpv['total_cost'] / $cpv['max_mileage'] : 0;
    }
    unset($cpv);
    uasort($costPerVehicle, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);
    $reportData['cost_per_vehicle'] = $costPerVehicle;
    sort($reportData['available_types']);

} elseif ($report === 'compliance') {
    // Compliance with scoring for sort
    $reportData['vehicles'] = [];
    $reportData['summary'] = ['total' => 0, 'fully_compliant' => 0, 'warnings' => 0, 'expired' => 0];

    foreach ($assetList as $asset) {
        $cf = $asset['custom_fields'] ?? [];
        $insuranceExpiry = $cf['Insurance Expiry']['value'] ?? null;
        $registrationExpiry = $cf['Registration Expiry']['value'] ?? null;
        $lastMaintenanceDate = $cf['Last Maintenance Date']['value'] ?? null;
        $currentMileage = (int)($cf['Current Mileage']['value'] ?? 0);
        $lastMaintenanceMileage = (int)($cf['Last Maintenance Mileage']['value'] ?? 0);

        $insuranceDays = $insuranceExpiry ? (int)((strtotime($insuranceExpiry) - time()) / 86400) : null;
        $registrationDays = $registrationExpiry ? (int)((strtotime($registrationExpiry) - time()) / 86400) : null;
        $milesSinceService = $currentMileage - $lastMaintenanceMileage;

        $insStatus = $insuranceDays === null ? 'unknown' : ($insuranceDays < 0 ? 'expired' : ($insuranceDays <= 30 ? 'warning' : 'ok'));
        $regStatus = $registrationDays === null ? 'unknown' : ($registrationDays < 0 ? 'expired' : ($registrationDays <= 30 ? 'warning' : 'ok'));
        $mntStatus = $milesSinceService >= 7500 ? 'due' : ($milesSinceService >= 7000 ? 'warning' : 'ok');

        // Score for sorting: lower = worse
        $issueCount = 0;
        if (in_array($insStatus, ['expired', 'unknown'])) $issueCount += 3;
        elseif ($insStatus === 'warning') $issueCount += 1;
        if (in_array($regStatus, ['expired', 'unknown'])) $issueCount += 3;
        elseif ($regStatus === 'warning') $issueCount += 1;
        if ($mntStatus === 'due') $issueCount += 3;
        elseif ($mntStatus === 'warning') $issueCount += 1;

        // Summary counts
        $reportData['summary']['total']++;
        $isFullyCompliant = ($insStatus === 'ok' && $regStatus === 'ok' && $mntStatus === 'ok');
        $hasExpired = in_array('expired', [$insStatus, $regStatus]) || $mntStatus === 'due';
        $hasWarning = in_array('warning', [$insStatus, $regStatus, $mntStatus]);

        if ($isFullyCompliant) $reportData['summary']['fully_compliant']++;
        elseif ($hasExpired) $reportData['summary']['expired']++;
        elseif ($hasWarning) $reportData['summary']['warnings']++;

        $reportData['vehicles'][] = [
            'asset' => $asset,
            'insurance_expiry' => $insuranceExpiry, 'insurance_days' => $insuranceDays, 'insurance_status' => $insStatus,
            'registration_expiry' => $registrationExpiry, 'registration_days' => $registrationDays, 'registration_status' => $regStatus,
            'last_maintenance' => $lastMaintenanceDate, 'miles_since_service' => $milesSinceService, 'maintenance_status' => $mntStatus,
            'issue_count' => $issueCount,
        ];
    }

    // Sort worst first
    usort($reportData['vehicles'], fn($a, $b) => $b['issue_count'] <=> $a['issue_count']);

} elseif ($report === 'utilization') {
    $reportData['vehicles'] = [];
    $totalDays = max(1, (strtotime($queryDateTo) - strtotime($queryDateFrom)) / 86400);

    foreach ($assetList as $asset) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_reservations,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                   SUM(TIMESTAMPDIFF(HOUR, start_datetime, LEAST(end_datetime, NOW()))) as total_hours
            FROM reservations WHERE asset_id = ? AND DATE(start_datetime) BETWEEN ? AND ?
        ");
        $stmt->execute([$asset['id'], $queryDateFrom, $queryDateTo]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalRes = (int)($stats['total_reservations'] ?? 0);
        $completedRes = (int)($stats['completed'] ?? 0);
        $totalHours = (int)($stats['total_hours'] ?? 0);
        $availableHours = $totalDays * 10;
        $utilizationRate = $availableHours > 0 ? min(100, round(($totalHours / $availableHours) * 100)) : 0;
        $completionRate = $totalRes > 0 ? round(($completedRes / $totalRes) * 100) : 0;

        $reportData['vehicles'][] = [
            'asset' => $asset,
            'total_reservations' => $totalRes, 'completed' => $completedRes,
            'total_hours' => $totalHours, 'utilization_rate' => $utilizationRate,
            'completion_rate' => $completionRate,
        ];
    }

    usort($reportData['vehicles'], fn($a, $b) => $b['utilization_rate'] <=> $a['utilization_rate']);

    // Fleet averages
    $fleetUtilSum = array_sum(array_column($reportData['vehicles'], 'utilization_rate'));
    $fleetCount = count($reportData['vehicles']);
    $reportData['fleet_avg_util'] = $fleetCount > 0 ? round($fleetUtilSum / $fleetCount) : 0;
    $reportData['idle_count'] = count(array_filter($reportData['vehicles'], fn($v) => $v['utilization_rate'] === 0 && $v['total_reservations'] === 0));

} elseif ($report === 'driver') {
    // Load training settings
    $trainReqStmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('training_required', 'training_validity_months')");
    $trainReqStmt->execute();
    $trainSettings = $trainReqStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $trainingRequired = (int)($trainSettings['training_required'] ?? 0);
    $trainingValidityMonths = (int)($trainSettings['training_validity_months'] ?? 12);

    $sql = "SELECT user_name, user_email,
            COUNT(*) as total_trips,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed,
            SUM(CASE WHEN maintenance_flag = 1 THEN 1 ELSE 0 END) as maint_flags,
            SUM(TIMESTAMPDIFF(HOUR, start_datetime, COALESCE(end_datetime, NOW()))) as total_hours
        FROM reservations WHERE DATE(created_at) BETWEEN ? AND ?";
    $driverParams = [$queryDateFrom, $queryDateTo];
    if ($userFilter) { $sql .= " AND user_email = ?"; $driverParams[] = $userFilter; }
    $sql .= " GROUP BY user_email, user_name ORDER BY total_trips DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($driverParams);
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $driverSummary = ['total_drivers' => 0, 'total_miles' => 0, 'total_trips' => 0, 'fleet_completion' => 0,
                      'training_valid' => 0, 'training_expiring' => 0, 'training_expired' => 0, 'training_none' => 0];

    // Batch: fetch all mileage data in one query
    $mileAllStmt = $pdo->prepare("SELECT user_email, checkout_form_data, checkin_form_data FROM reservations WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?");
    $mileAllStmt->execute([$queryDateFrom, $queryDateTo]);
    $mileageByDriver = [];
    while ($r = $mileAllStmt->fetch(PDO::FETCH_ASSOC)) {
        $mi = extractTripMileage(json_decode($r['checkout_form_data'] ?? '{}', true) ?: [], json_decode($r['checkin_form_data'] ?? '{}', true) ?: []);
        if ($mi['trip'] !== null) {
            $email = $r['user_email'];
            if (!isset($mileageByDriver[$email])) { $mileageByDriver[$email] = ['miles' => 0, 'trips' => 0]; }
            $mileageByDriver[$email]['miles'] += $mi['trip'];
            $mileageByDriver[$email]['trips']++;
        }
    }

    // Batch: fetch all training data in one query
    $trainingStmt = $pdo->query("SELECT email, training_completed, training_date FROM users");
    $trainingByEmail = [];
    while ($t = $trainingStmt->fetch(PDO::FETCH_ASSOC)) {
        $trainingByEmail[$t['email']] = $t;
    }

    foreach ($drivers as &$d) {
        // Mileage enrichment (from batch)
        $dMileage = $mileageByDriver[$d['user_email']] ?? ['miles' => 0, 'trips' => 0];
        $d['total_miles'] = $dMileage['miles'];
        $d['avg_miles'] = $dMileage['trips'] > 0 ? round($dMileage['miles'] / $dMileage['trips']) : 0;
        $d['completion_rate'] = $d['total_trips'] > 0 ? round(($d['completed'] / $d['total_trips']) * 100) : 0;

        // Training enrichment (from batch)
        $tUser = $trainingByEmail[$d['user_email']] ?? null;

        $d['training_completed'] = $tUser ? (int)$tUser['training_completed'] : 0;
        $d['training_date'] = $tUser['training_date'] ?? null;
        $d['training_expiry'] = $d['training_date']
            ? date('Y-m-d', strtotime($d['training_date'] . " + {$trainingValidityMonths} months"))
            : null;
        $d['training_days'] = $d['training_expiry']
            ? (int)((strtotime($d['training_expiry']) - time()) / 86400)
            : null;

        // Training status
        if (!$d['training_completed']) {
            $d['training_status'] = 'none';
            $driverSummary['training_none']++;
        } elseif ($d['training_expiry'] === null) {
            $d['training_status'] = 'none';
            $driverSummary['training_none']++;
        } elseif ($d['training_days'] < 0) {
            $d['training_status'] = 'expired';
            $driverSummary['training_expired']++;
        } elseif ($d['training_days'] <= 30) {
            $d['training_status'] = 'expiring';
            $driverSummary['training_expiring']++;
        } else {
            $d['training_status'] = 'valid';
            $driverSummary['training_valid']++;
        }

        $driverSummary['total_miles'] += $totalMiles;
        $driverSummary['total_trips'] += (int)$d['total_trips'];
    }
    unset($d);

    $driverSummary['total_drivers'] = count($drivers);
    $driverSummary['avg_trips'] = $driverSummary['total_drivers'] > 0 ? round($driverSummary['total_trips'] / $driverSummary['total_drivers'], 1) : 0;
    $totalCompleted = array_sum(array_column($drivers, 'completed'));
    $totalCancelled = array_sum(array_column($drivers, 'cancelled'));
    $actionableTrips = $driverSummary['total_trips'] - $totalCancelled;
    $driverSummary['fleet_completion'] = $actionableTrips > 0 ? round(($totalCompleted / $actionableTrips) * 100) : 0;
    $driverSummary['training_required'] = $trainingRequired;

    $reportData['drivers'] = $drivers;
    $reportData['driver_summary'] = $driverSummary;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fleet Reports</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.5.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <style>
        .report-card { transition: all 0.2s; cursor: pointer; }
        .report-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .report-card.active { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05); }
        .stat-box { background: #f8f9fa; border-radius: 8px; padding: 15px; text-align: center; }
        .stat-box .number { font-size: 2rem; font-weight: bold; }
        .stat-box-sm { background: #f8f9fa; border-radius: 8px; padding: 10px; text-align: center; }
        .stat-box-sm .number { font-size: 1.5rem; font-weight: bold; }
        .progress-thin { height: 8px; }
        .status-ok { color: #198754; }
        .status-warning { color: #ffc107; }
        .status-expired, .status-due { color: #dc3545; }
        .status-unknown { color: #6c757d; }
        .idle-callout { border-left: 4px solid #dc3545; background: #fff5f5; }
        .row-warn { background: rgba(255, 193, 7, 0.08); }
        .period-label { font-size: 0.8rem; }
        @media print {
            .nav, .nav-tabs, .page-header p, .report-card, .btn, form, .top-bar { display: none !important; }
            .page-shell { padding: 0 !important; }
            .card { border: 1px solid #ddd !important; break-inside: avoid; }
            table { font-size: 0.85rem; }
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Fleet Reports</h1>
            <p class="text-muted">Analytics and insights for fleet management</p>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <!-- Report Selection -->
        <div class="row g-3 mb-4">
            <?php
            $reports = [
                'summary' => ['icon' => 'bi-pie-chart', 'color' => 'text-primary', 'label' => 'Summary'],
                'usage' => ['icon' => 'bi-calendar-check', 'color' => 'text-info', 'label' => 'Usage'],
                'utilization' => ['icon' => 'bi-graph-up', 'color' => 'text-success', 'label' => 'Utilization'],
                'maintenance' => ['icon' => 'bi-wrench', 'color' => 'text-warning', 'label' => 'Maintenance'],
                'driver' => ['icon' => 'bi-person-badge', 'color' => 'text-secondary', 'label' => 'Driver'],
                'compliance' => ['icon' => 'bi-shield-check', 'color' => 'text-danger', 'label' => 'Compliance'],
            ];
            foreach ($reports as $key => $r): ?>
            <div class="col-6 col-md">
                <a href="?report=<?= $key ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="card report-card h-100 text-decoration-none <?= $report === $key ? 'active' : '' ?>">
                    <div class="card-body text-center py-2">
                        <i class="bi <?= $r['icon'] ?> <?= $r['color'] ?>" style="font-size: 1.8rem;"></i>
                        <div class="mt-1"><strong><?= $r['label'] ?></strong></div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Date Filters -->
        <?php if ($report !== 'compliance'): ?>
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="report" value="<?= h($report) ?>">
                    <div class="col-12 mb-2">
                        <div class="btn-group btn-group-sm" role="group">
                            <?php
                            $presets = [
                                '' => ['label' => 'All Time', 'from' => '', 'to' => ''],
                                'this_month' => ['label' => 'This Month', 'from' => date('Y-m-01'), 'to' => date('Y-m-d')],
                                'last_month' => ['label' => 'Last Month', 'from' => date('Y-m-01', strtotime('-1 month')), 'to' => date('Y-m-t', strtotime('-1 month'))],
                                'last_90' => ['label' => 'Last 90 Days', 'from' => date('Y-m-d', strtotime('-90 days')), 'to' => date('Y-m-d')],
                                'ytd' => ['label' => 'YTD', 'from' => date('Y-01-01'), 'to' => date('Y-m-d')],
                                'last_year' => ['label' => 'Last Year', 'from' => date('Y-01-01', strtotime('-1 year')), 'to' => date('Y-12-31', strtotime('-1 year'))],
                            ];
                            $currentPreset = '';
                            foreach ($presets as $key => $p) { if ($dateFrom === $p['from'] && $dateTo === $p['to']) $currentPreset = $key; }
                            foreach ($presets as $key => $p):
                                $activeClass = ($currentPreset === $key) ? 'btn-primary' : 'btn-outline-secondary';
                            ?>
                            <a href="?report=<?= h($report) ?>&date_from=<?= $p['from'] ?>&date_to=<?= $p['to'] ?><?= $assetFilter ? '&asset_id='.$assetFilter : '' ?><?= $userFilter ? '&user_email='.urlencode($userFilter) : '' ?>"
                               class="btn <?= $activeClass ?>"><?= $p['label'] ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                    </div>
                    <?php if (in_array($report, ['usage', 'maintenance'])): ?>
                    <div class="col-md-2">
                        <label class="form-label">Vehicle</label>
                        <select name="asset_id" class="form-select">
                            <option value="">All Vehicles</option>
                            <?php foreach ($assetList as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $assetFilter == $a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if (in_array($report, ['usage', 'driver'])): ?>
                    <div class="col-md-2">
                        <label class="form-label">User</label>
                        <select name="user_email" class="form-select">
                            <option value="">All Users</option>
                            <?php foreach ($userList as $u): ?>
                                <option value="<?= h($u['user_email']) ?>" <?= $userFilter === $u['user_email'] ? 'selected' : '' ?>><?= h($u['user_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if ($report === 'usage'): ?>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <?php foreach (['completed', 'confirmed', 'pending', 'missed', 'cancelled', 'maintenance_required'] as $s): ?>
                                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if ($report === 'maintenance'): ?>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select name="maint_type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach (['Maintenance', 'Repair', 'Upgrade', 'Calibration'] as $mt): ?>
                                <option value="<?= $mt ?>" <?= $maintTypeFilter === $mt ? 'selected' : '' ?>><?= $mt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter"></i></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Period label -->
        <?php if ($report !== 'compliance'): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="period-label text-muted">
                <i class="bi bi-calendar3 me-1"></i><?= h($periodLabel) ?>
                <?php if ($assetFilter): ?> | Vehicle: <?= h($assetList[array_search($assetFilter, array_column($assetList, 'id'))]['name'] ?? '#' . $assetFilter) ?><?php endif; ?>
                <?php if ($userFilter): ?> | User: <?= h($userFilter) ?><?php endif; ?>
                <?php if ($statusFilter): ?> | Status: <?= ucfirst($statusFilter) ?><?php endif; ?>
                <?php if ($maintTypeFilter): ?> | Type: <?= h($maintTypeFilter) ?><?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- SUMMARY                                                          -->
        <!-- ================================================================ -->
        <?php if ($report === 'summary'): ?>
            <?php $rs = $reportData['reservation_stats']; ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md">
                    <div class="stat-box"><div class="number text-primary"><?= $rs['total'] ?? 0 ?></div><div class="text-muted">Total</div></div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat-box"><div class="number text-success"><?= $rs['completed'] ?? 0 ?></div><div class="text-muted">Completed</div></div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat-box"><div class="number text-info"><?= $rs['active'] ?? 0 ?></div><div class="text-muted">Active</div></div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat-box"><div class="number text-warning"><?= ($rs['pending'] ?? 0) ?></div><div class="text-muted">Pending</div></div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat-box"><div class="number text-danger"><?= $rs['missed'] ?? 0 ?></div><div class="text-muted">Missed</div></div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat-box"><div class="number text-secondary"><?= $rs['cancelled'] ?? 0 ?></div><div class="text-muted">Cancelled</div></div>
                </div>
            </div>

            <!-- Pulse row -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-box-sm">
                        <div class="number <?= $reportData['completion_rate'] >= 80 ? 'text-success' : ($reportData['completion_rate'] >= 50 ? 'text-warning' : 'text-danger') ?>"><?= $reportData['completion_rate'] ?>%</div>
                        <small class="text-muted">Completion Rate</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box-sm">
                        <div class="number text-primary"><?= number_format($reportData['fleet_miles']) ?></div>
                        <small class="text-muted">Total Miles Driven</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box-sm">
                        <div class="number text-info"><?= $rs['maintenance'] ?? 0 ?></div>
                        <small class="text-muted">Maintenance Flags</small>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-truck me-2"></i>Reservations by Vehicle</h6></div>
                        <div class="card-body p-0">
                            <?php if (empty($reportData['by_vehicle'])): ?>
                                <p class="text-muted text-center py-4">No data for this period. Try a wider date range.</p>
                            <?php else: ?>
                                <table class="table table-sm mb-0">
                                    <thead class="table-light"><tr><th>Vehicle</th><th class="text-center">Total</th><th class="text-center">Completed</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($reportData['by_vehicle'] as $row): ?>
                                        <tr><td><?= h($row['asset_name_cache']) ?><?= get_company_badge_from_row($row) ?></td><td class="text-center"><?= $row['count'] ?></td><td class="text-center text-success"><?= $row['completed'] ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-people me-2"></i>Top Users</h6></div>
                        <div class="card-body p-0">
                            <?php if (empty($reportData['by_user'])): ?>
                                <p class="text-muted text-center py-4">No data for this period. Try a wider date range.</p>
                            <?php else: ?>
                                <table class="table table-sm mb-0">
                                    <thead class="table-light"><tr><th>User</th><th class="text-center">Total</th><th class="text-center">Completed</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($reportData['by_user'] as $row): ?>
                                        <tr><td><?= h($row['user_name']) ?></td><td class="text-center"><?= $row['count'] ?></td><td class="text-center text-success"><?= $row['completed'] ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-2">
                <div class="col-md-4">
                    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="bi bi-wrench me-2"></i>Maintenance Costs</h6></div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-4"><div class="h4 text-primary"><?= $reportData['maintenance_costs']['total_services'] ?></div><small class="text-muted">Services</small></div>
                                <div class="col-4"><div class="h4 text-success">$<?= number_format($reportData['maintenance_costs']['total_cost'], 2) ?></div><small class="text-muted">Total</small></div>
                                <div class="col-4"><div class="h4 text-info">$<?= number_format($reportData['maintenance_costs']['avg_cost'], 2) ?></div><small class="text-muted">Avg</small></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="bi bi-tools me-2"></i>By Type</h6></div>
                        <div class="card-body p-0">
                            <?php if (empty($reportData['maintenance_by_type'])): ?><p class="text-muted text-center py-4">No data</p>
                            <?php else: ?><table class="table table-sm mb-0"><tbody>
                                <?php foreach ($reportData['maintenance_by_type'] as $row): ?>
                                <tr><td><?= ucfirst(str_replace('_', ' ', $row['maintenance_type'])) ?></td><td class="text-center"><?= $row['count'] ?></td><td class="text-end">$<?= number_format($row['total_cost'], 2) ?></td></tr>
                                <?php endforeach; ?></tbody></table><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="bi bi-currency-dollar me-2"></i>Cost by Vehicle (Top 5)</h6></div>
                        <div class="card-body p-0">
                            <?php if (empty($reportData['cost_by_vehicle'])): ?><p class="text-muted text-center py-4">No data</p>
                            <?php else: ?><table class="table table-sm mb-0"><tbody>
                                <?php foreach ($reportData['cost_by_vehicle'] as $cpv): ?>
                                <tr><td><?= h($cpv['name']) ?></td><td class="text-center"><?= $cpv['count'] ?> svc</td><td class="text-end">$<?= number_format($cpv['total_cost'], 2) ?></td></tr>
                                <?php endforeach; ?></tbody></table><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <!-- ================================================================ -->
        <!-- USAGE                                                            -->
        <!-- ================================================================ -->
        <?php elseif ($report === 'usage'): ?>
            <?php $us = $reportData['usage_summary']; ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-primary"><?= $us['total'] ?></div><small class="text-muted">Trips</small></div></div>
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-success"><?= $us['completed'] ?></div><small class="text-muted">Completed</small></div></div>
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-info"><?= number_format($us['total_miles']) ?></div><small class="text-muted">Total Miles</small></div></div>
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-secondary"><?= number_format($us['avg_miles']) ?></div><small class="text-muted">Avg Mi/Trip</small></div></div>
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-warning"><?= number_format($us['total_hours'], 0) ?></div><small class="text-muted">Total Hours</small></div></div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Usage Report</h5>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($reportData['usage'])): ?>
                        <p class="text-muted text-center py-4">No usage data for this period. Try adjusting your filters or date range.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Date</th><th>Vehicle</th><th>User</th><th>Status</th><th>Checkout</th><th>Checkin</th><th class="text-end">Mi Out</th><th class="text-end">Mi In</th><th class="text-end">Trip Mi</th><th class="text-center">Hours</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData['usage'] as $row):
                                        $sc = ['completed'=>'success','confirmed'=>'info','pending'=>'warning','missed'=>'danger','cancelled'=>'secondary','maintenance_required'=>'warning'][$row['status']] ?? 'secondary';
                                    ?>
                                    <tr>
                                        <td class="text-nowrap"><?= date('M j, Y', strtotime($row['start_datetime'])) ?></td>
                                        <td><strong><?= h($row['asset_name_cache'] ?: 'Vehicle #' . $row['asset_id']) ?><?= get_company_badge_from_row($row) ?></strong></td>
                                        <td><?= h($row['user_name']) ?></td>
                                        <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($row['status']) ?></span></td>
                                        <td><?= $row['actual_checkout'] ? date('M j, g:i A', strtotime($row['actual_checkout'])) : '-' ?></td>
                                        <td><?= $row['actual_checkin'] ? date('M j, g:i A', strtotime($row['actual_checkin'])) : '-' ?></td>
                                        <td class="text-end"><?= $row['checkout_mileage'] !== null ? number_format($row['checkout_mileage']) : '-' ?></td>
                                        <td class="text-end"><?= $row['checkin_mileage'] !== null ? number_format($row['checkin_mileage']) : '-' ?></td>
                                        <td class="text-end fw-bold"><?= $row['trip_miles'] !== null ? number_format($row['trip_miles']) : '-' ?></td>
                                        <td class="text-center"><?= $row['actual_hours'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td colspan="6" class="text-end">Totals (<?= $us['total'] ?> trips):</td>
                                        <td></td><td></td>
                                        <td class="text-end"><?= number_format($us['total_miles']) ?> mi</td>
                                        <td class="text-center"><?= number_format($us['total_hours'], 0) ?>h</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ================================================================ -->
        <!-- UTILIZATION                                                      -->
        <!-- ================================================================ -->
        <?php elseif ($report === 'utilization'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-box-sm">
                        <div class="number <?= $reportData['fleet_avg_util'] >= 40 ? 'text-success' : ($reportData['fleet_avg_util'] >= 20 ? 'text-warning' : 'text-danger') ?>"><?= $reportData['fleet_avg_util'] ?>%</div>
                        <small class="text-muted">Fleet Avg Utilization</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box-sm">
                        <div class="number text-primary"><?= count($reportData['vehicles']) ?></div>
                        <small class="text-muted">Vehicles in Fleet</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box-sm">
                        <div class="number <?= $reportData['idle_count'] > 0 ? 'text-danger' : 'text-success' ?>"><?= $reportData['idle_count'] ?></div>
                        <small class="text-muted">Idle Vehicles (0% / No Bookings)</small>
                    </div>
                </div>
            </div>

            <?php if ($reportData['idle_count'] > 0): ?>
            <div class="idle-callout rounded p-3 mb-4">
                <strong class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= $reportData['idle_count'] ?> vehicle<?= $reportData['idle_count'] > 1 ? 's have' : ' has' ?> zero utilization</strong>
                <span class="text-muted ms-2">— consider reviewing fleet allocation or removing from the active pool.</span>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Vehicle Utilization</h5>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Vehicle</th><th class="text-center">Reservations</th><th class="text-center">Completed</th><th class="text-center">Completion</th><th class="text-center">Hours</th><th>Utilization</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['vehicles'] as $item):
                                $isIdle = $item['utilization_rate'] === 0 && $item['total_reservations'] === 0;
                            ?>
                            <tr class="<?= $isIdle ? 'table-danger' : '' ?>">
                                <td>
                                    <strong><?= h($item['asset']['name']) ?></strong><br>
                                    <small class="text-muted"><?= h($item['asset']['asset_tag']) ?></small>
                                    <?= $isIdle ? '<br><span class="badge bg-danger">Idle</span>' : '' ?>
                                </td>
                                <td class="text-center"><?= $item['total_reservations'] ?></td>
                                <td class="text-center text-success"><?= $item['completed'] ?></td>
                                <td class="text-center">
                                    <span class="<?= $item['completion_rate'] < 70 && $item['total_reservations'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= $item['completion_rate'] ?>%</span>
                                </td>
                                <td class="text-center"><?= $item['total_hours'] ?></td>
                                <td style="width: 200px;">
                                    <div class="d-flex align-items-center">
                                        <div class="progress progress-thin flex-grow-1 me-2">
                                            <?php $bc = $item['utilization_rate'] >= 70 ? 'success' : ($item['utilization_rate'] >= 40 ? 'warning' : 'danger'); ?>
                                            <div class="progress-bar bg-<?= $bc ?>" style="width: <?= $item['utilization_rate'] ?>%"></div>
                                        </div>
                                        <span class="text-muted small"><?= $item['utilization_rate'] ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ================================================================ -->
        <!-- MAINTENANCE                                                      -->
        <!-- ================================================================ -->
        <?php elseif ($report === 'maintenance'): ?>
            <?php if (!empty($reportData['cost_per_vehicle'])): ?>
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-currency-dollar me-2"></i>Cost per Vehicle</h5></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Vehicle</th><th class="text-center">Services</th><th class="text-end">Total Cost</th><th class="text-end">Cost/Mile</th></tr></thead>
                        <tbody>
                            <?php foreach ($reportData['cost_per_vehicle'] as $cpv): ?>
                            <tr>
                                <td><strong><?= h($cpv['name']) ?></strong><br><small class="text-muted"><?= h($cpv['tag']) ?></small></td>
                                <td class="text-center"><?= $cpv['service_count'] ?></td>
                                <td class="text-end">$<?= number_format($cpv['total_cost'], 2) ?></td>
                                <td class="text-end"><?= $cpv['cost_per_mile'] > 0 ? '$' . number_format($cpv['cost_per_mile'], 4) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-wrench me-2"></i>Maintenance Report
                        <?php if ($reportData['total_cost'] > 0): ?><span class="badge bg-success ms-2">Total: $<?= number_format($reportData['total_cost'], 2) ?></span><?php endif; ?>
                    </h5>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($reportData['maintenance'])): ?>
                        <p class="text-muted text-center py-4">No maintenance records for this period<?= $maintTypeFilter ? " with type \"" . h($maintTypeFilter) . "\"" : '' ?>. Try adjusting filters.</p>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Date</th><th>Vehicle</th><th>Type / Title</th><th class="text-center">Mileage</th><th>Provider</th><th class="text-end">Cost</th><th>Logged By</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['maintenance'] as $row): ?>
                                <tr>
                                    <td class="text-nowrap"><?= date('M j, Y', strtotime($row['service_date'])) ?></td>
                                    <td><strong><?= h($row['asset_name']) ?></strong><br><small class="text-muted"><?= h($row['asset_tag']) ?></small></td>
                                    <td>
                                        <span class="badge bg-secondary"><?= ucfirst(str_replace('_', ' ', $row['maintenance_type'])) ?></span>
                                        <?php if ($row['title']): ?><br><small class="text-muted"><?= h($row['title']) ?></small><?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= $row['mileage'] ? number_format($row['mileage']) . ' mi' : '-' ?></td>
                                    <td><?= h($row['supplier'] ?? '-') ?></td>
                                    <td class="text-end"><?= $row['cost'] > 0 ? '$' . number_format($row['cost'], 2) : '-' ?></td>
                                    <td><?= h($row['logged_by'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">Total (<?= count($reportData['maintenance']) ?> records):</td>
                                    <td class="text-end text-success">$<?= number_format($reportData['total_cost'], 2) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ================================================================ -->
        <!-- DRIVER                                                           -->
        <!-- ================================================================ -->
        <?php elseif ($report === 'driver'): ?>
            <?php $ds = $reportData['driver_summary']; ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-primary"><?= $ds['total_drivers'] ?></div><small class="text-muted">Active Drivers</small></div></div>
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-info"><?= number_format($ds['total_miles']) ?></div><small class="text-muted">Total Miles</small></div></div>
                <div class="col-6 col-md"><div class="stat-box-sm"><div class="number text-secondary"><?= $ds['avg_trips'] ?></div><small class="text-muted">Avg Trips/Driver</small></div></div>
                <div class="col-6 col-md">
                    <div class="stat-box-sm">
                        <div class="number <?= $ds['fleet_completion'] >= 80 ? 'text-success' : ($ds['fleet_completion'] >= 50 ? 'text-warning' : 'text-danger') ?>"><?= $ds['fleet_completion'] ?>%</div>
                        <small class="text-muted">Fleet Completion Rate</small>
                    </div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat-box-sm">
                        <div class="number <?= ($ds['training_expired'] + $ds['training_expiring']) > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= $ds['training_valid'] ?><small class="text-muted">/<?= $ds['total_drivers'] ?></small>
                        </div>
                        <small class="text-muted">Training Valid
                            <?php if (!$ds['training_required']): ?><span class="badge bg-secondary ms-1" style="font-size:0.65rem;">Off</span><?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>

            <?php if ($ds['training_expired'] > 0 || $ds['training_expiring'] > 0): ?>
            <div class="idle-callout rounded p-3 mb-4">
                <?php if ($ds['training_expired'] > 0): ?>
                    <strong class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= $ds['training_expired'] ?> driver<?= $ds['training_expired'] > 1 ? 's' : '' ?> with expired training</strong>
                    <?php if ($ds['training_required']): ?><span class="text-muted ms-2">— blocked from booking</span><?php endif; ?>
                    <br>
                <?php endif; ?>
                <?php if ($ds['training_expiring'] > 0): ?>
                    <strong class="text-warning"><i class="bi bi-clock me-1"></i><?= $ds['training_expiring'] ?> driver<?= $ds['training_expiring'] > 1 ? 's' : '' ?> with training expiring within 30 days</strong>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Driver Analytics</h5>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($reportData['drivers'])): ?>
                        <p class="text-muted text-center py-4">No driver data for this period. Try a wider date range.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Driver</th><th class="text-center">Trips</th><th class="text-center">Completed</th><th class="text-center">Missed</th><th class="text-center">Cancelled</th><th class="text-end">Total Miles</th><th class="text-end">Avg Mi/Trip</th><th class="text-center">Hours</th><th class="text-center">Maint. Flags</th><th>Training</th><th>Completion</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData['drivers'] as $d):
                                        $warnRow = ($d['completion_rate'] < 70 && $d['total_trips'] >= 3) || $d['missed'] >= 2;
                                    ?>
                                    <tr class="<?= $warnRow ? 'row-warn' : '' ?>">
                                        <td><strong><?= h($d['user_name']) ?></strong><br><small class="text-muted"><?= h($d['user_email']) ?></small></td>
                                        <td class="text-center"><?= $d['total_trips'] ?></td>
                                        <td class="text-center text-success"><?= $d['completed'] ?></td>
                                        <td class="text-center <?= $d['missed'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= $d['missed'] ?></td>
                                        <td class="text-center"><?= $d['cancelled'] ?></td>
                                        <td class="text-end"><?= $d['total_miles'] > 0 ? number_format($d['total_miles']) : '-' ?></td>
                                        <td class="text-end"><?= $d['avg_miles'] > 0 ? number_format($d['avg_miles']) : '-' ?></td>
                                        <td class="text-center"><?= (int)($d['total_hours'] ?? 0) ?></td>
                                        <td class="text-center"><?= $d['maint_flags'] > 0 ? '<span class="badge bg-warning text-dark">' . $d['maint_flags'] . '</span>' : '0' ?></td>
                                        <td class="text-center text-nowrap">
                                            <?php if ($d['training_status'] === 'expired'): ?>
                                                <i class="bi bi-x-circle-fill text-danger"></i><br>
                                                <small class="text-danger">Expired <?= abs($d['training_days']) ?>d ago</small>
                                            <?php elseif ($d['training_status'] === 'expiring'): ?>
                                                <i class="bi bi-exclamation-triangle-fill text-warning"></i><br>
                                                <small class="text-warning"><?= $d['training_days'] ?>d left</small>
                                            <?php elseif ($d['training_status'] === 'valid'): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i><br>
                                                <small class="text-success"><?= date('M j, Y', strtotime($d['training_expiry'])) ?></small>
                                            <?php else: ?>
                                                <i class="bi bi-question-circle text-muted"></i><br>
                                                <small class="text-muted">Not recorded</small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="width: 130px;">
                                            <div class="d-flex align-items-center">
                                                <div class="progress progress-thin flex-grow-1 me-2">
                                                    <?php $cc = $d['completion_rate'] >= 80 ? 'success' : ($d['completion_rate'] >= 50 ? 'warning' : 'danger'); ?>
                                                    <div class="progress-bar bg-<?= $cc ?>" style="width: <?= $d['completion_rate'] ?>%"></div>
                                                </div>
                                                <span class="text-muted small"><?= $d['completion_rate'] ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ================================================================ -->
        <!-- COMPLIANCE                                                       -->
        <!-- ================================================================ -->
        <?php elseif ($report === 'compliance'): ?>
            <?php $cs = $reportData['summary']; ?>
            <!-- Summary bar -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-box-sm"><div class="number text-primary"><?= $cs['total'] ?></div><small class="text-muted">Total Vehicles</small></div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box-sm"><div class="number text-success"><?= $cs['fully_compliant'] ?></div><small class="text-muted">Fully Compliant</small></div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box-sm"><div class="number text-warning"><?= $cs['warnings'] ?></div><small class="text-muted">Warnings</small></div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box-sm"><div class="number text-danger"><?= $cs['expired'] ?></div><small class="text-muted">Expired / Overdue</small></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Fleet Compliance Status</h5>
                    <div class="d-flex gap-2">
                        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
                        <small class="text-muted align-self-center">Sorted: worst first</small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Vehicle</th><th class="text-center">Insurance</th><th class="text-center">Registration</th><th class="text-center">Maintenance</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['vehicles'] as $item):
                                $hasIssue = $item['issue_count'] >= 3;
                            ?>
                            <tr class="<?= $hasIssue ? 'table-danger' : ($item['issue_count'] > 0 ? '' : '') ?>">
                                <td>
                                    <strong><?= h($item['asset']['name']) ?></strong><br>
                                    <small class="text-muted"><?= h($item['asset']['asset_tag']) ?></small>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['insurance_status'] === 'ok'): ?>
                                        <i class="bi bi-check-circle-fill status-ok"></i><br><small><?= date('M j, Y', strtotime($item['insurance_expiry'])) ?></small><br><small class="text-success"><?= $item['insurance_days'] ?>d</small>
                                    <?php elseif ($item['insurance_status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i><br><small class="text-warning"><?= $item['insurance_days'] ?> days</small>
                                    <?php elseif ($item['insurance_status'] === 'expired'): ?>
                                        <i class="bi bi-x-circle-fill status-expired"></i><br><small class="text-danger">EXPIRED</small>
                                    <?php else: ?>
                                        <i class="bi bi-question-circle status-unknown"></i><br><small class="text-muted">Unknown</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['registration_status'] === 'ok'): ?>
                                        <i class="bi bi-check-circle-fill status-ok"></i><br><small><?= date('M j, Y', strtotime($item['registration_expiry'])) ?></small><br><small class="text-success"><?= $item['registration_days'] ?>d</small>
                                    <?php elseif ($item['registration_status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i><br><small class="text-warning"><?= $item['registration_days'] ?> days</small>
                                    <?php elseif ($item['registration_status'] === 'expired'): ?>
                                        <i class="bi bi-x-circle-fill status-expired"></i><br><small class="text-danger">EXPIRED</small>
                                    <?php else: ?>
                                        <i class="bi bi-question-circle status-unknown"></i><br><small class="text-muted">Unknown</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['maintenance_status'] === 'ok'): ?>
                                        <i class="bi bi-check-circle-fill status-ok"></i><br><small><?= number_format($item['miles_since_service']) ?> mi</small>
                                    <?php elseif ($item['maintenance_status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i><br><small class="text-warning"><?= number_format($item['miles_since_service']) ?> mi</small>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill status-due"></i><br><small class="text-danger">DUE</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['issue_count'] > 0): ?>
                                        <a href="maintenance?tab=log&asset_id=<?= $item['asset']['id'] ?>" class="btn btn-sm btn-outline-warning" title="Log service"><i class="bi bi-wrench"></i></a>
                                    <?php else: ?>
                                        <span class="text-success"><i class="bi bi-check-lg"></i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
