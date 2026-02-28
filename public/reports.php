<?php
/**
 * Fleet Reports
 * Usage history, maintenance costs, compliance status
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active = 'reports.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// Only staff can access
if (!$isStaff) {
    header('Location: dashboard.php');
    exit;
}

$report = $_GET['report'] ?? 'summary';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$assetFilter = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : null;
$userFilter = isset($_GET['user_email']) ? trim($_GET['user_email']) : null;

// Get all assets for filter dropdown
$allAssets = get_requestable_assets(100, null);
$assetList = is_array($allAssets) ? $allAssets : [];

// Get unique users for filter dropdown
$stmt = $pdo->query("SELECT DISTINCT user_name, user_email FROM reservations ORDER BY user_name");
$userList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportReportCSV($pdo, $report, $dateFrom, $dateTo, $assetFilter, $userFilter, $assetList);
    exit;
}

/**
 * Export report to CSV
 */
function exportReportCSV($pdo, $report, $dateFrom, $dateTo, $assetFilter, $userFilter, $assetList) {
    $filename = "fleet_report_{$report}_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    if ($report === 'usage') {
        fputcsv($output, ['Date', 'Vehicle', 'Tag', 'User', 'Status', 'Checkout Time', 'Checkin Time', 'Duration (hrs)']);
        
        $sql = "SELECT r.*, 
                TIMESTAMPDIFF(HOUR, r.start_datetime, COALESCE(r.end_datetime, NOW())) as duration_hours
                FROM reservations r 
                WHERE DATE(r.start_datetime) BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        
        if ($assetFilter) {
            $sql .= " AND r.asset_id = ?";
            $params[] = $assetFilter;
        }
        if ($userFilter) {
            $sql .= " AND r.user_email = ?";
            $params[] = $userFilter;
        }
        $sql .= " ORDER BY r.start_datetime DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                date('Y-m-d', strtotime($row['start_datetime'])),
                $row['asset_name_cache'],
                '',
                $row['user_name'],
                $row['status'],
                date('Y-m-d H:i', strtotime($row['start_datetime'])),
                $row['end_datetime'] ? date('Y-m-d H:i', strtotime($row['end_datetime'])) : '',
                $row['duration_hours']
            ]);
        }
} elseif ($report === 'maintenance') {
        fputcsv($output, ['Date', 'Vehicle', 'Tag', 'Type', 'Mileage', 'Provider', 'Cost', 'Logged By']);
        
        $allMaintenances = get_maintenances(500);
        foreach ($allMaintenances as $m) {
            $completionDate = $m['completion_date']['date'] ?? $m['start_date']['date'] ?? null;
            if (!$completionDate) continue;
            
            $mDate = substr($completionDate, 0, 10);
            if ($mDate < $dateFrom || $mDate > $dateTo) continue;
            if ($assetFilter && ($m['asset']['id'] ?? 0) != $assetFilter) continue;
            
            // Extract mileage from notes
            $notes = $m['notes'] ?? '';
            $mileage = 0;
            if (preg_match('/Mileage at service:\s*([\d,]+)/i', $notes, $matches)) {
                $mileage = (int)str_replace(',', '', $matches[1]);
            }
            
            fputcsv($output, [
                $mDate,
                $m['asset']['name'] ?? 'Unknown',
                $m['asset']['asset_tag'] ?? '',
                ucfirst($m['asset_maintenance_type'] ?? 'Uncategorized'),
                $mileage ? number_format($mileage) : '',
                $m['supplier']['name'] ?? '',
                ($m['cost'] ?? 0) > 0 ? '$' . number_format($m['cost'], 2) : '',
                $m['user_id']['name'] ?? $m['created_by']['name'] ?? ''
            ]);
        }
    }

    
    fclose($output);
}

// Get report data based on selected report
$reportData = [];

if ($report === 'summary') {
    // Summary statistics
    
    // Total reservations
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
    $stmt->execute([$dateFrom, $dateTo]);
    $reportData['reservation_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Reservations by vehicle
    $stmt = $pdo->prepare("
        SELECT asset_name_cache, asset_id, COUNT(*) as count,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM reservations
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND asset_name_cache IS NOT NULL AND asset_name_cache != ''
        GROUP BY asset_id, asset_name_cache
        ORDER BY count DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $reportData['by_vehicle'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Reservations by user
    $stmt = $pdo->prepare("
        SELECT user_name, user_email, COUNT(*) as count,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM reservations
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY user_email, user_name
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $reportData['by_user'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
// Maintenance costs - from Snipe-IT API
    $allMaintenances = get_maintenances(500);
    $totalServices = 0;
    $totalCost = 0;
    $typeStats = [];
    
    foreach ($allMaintenances as $m) {
        $completionDate = $m['completion_date']['date'] ?? $m['start_date']['date'] ?? null;
        if (!$completionDate) continue;
        
        $mDate = substr($completionDate, 0, 10);
        if ($mDate < $dateFrom || $mDate > $dateTo) continue;
        
        $cost = floatval($m['cost'] ?? 0);
        $type = $m['asset_maintenance_type'] ?? 'Maintenance';
        
        $totalServices++;
        $totalCost += $cost;
        
        if (!isset($typeStats[$type])) {
            $typeStats[$type] = ['count' => 0, 'total_cost' => 0];
        }
        $typeStats[$type]['count']++;
        $typeStats[$type]['total_cost'] += $cost;
    }
    
    $reportData['maintenance_costs'] = [
        'total_services' => $totalServices,
        'total_cost' => $totalCost,
        'avg_cost' => $totalServices > 0 ? $totalCost / $totalServices : 0,
    ];
    
    // Maintenance by type
    $reportData['maintenance_by_type'] = [];
    foreach ($typeStats as $type => $stats) {
        $reportData['maintenance_by_type'][] = [
            'maintenance_type' => $type,
            'count' => $stats['count'],
            'total_cost' => $stats['total_cost'],
        ];
    }
    // Sort by count descending
    usort($reportData['maintenance_by_type'], fn($a, $b) => $b['count'] - $a['count']);// Maintenance costs
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_services,
            SUM(cost) as total_cost,
            AVG(cost) as avg_cost
        FROM maintenance_log
        WHERE service_date BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $reportData['maintenance_costs'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
// Maintenance costs - from Snipe-IT API
    $allMaintenances = get_maintenances(500);
    $totalServices = 0;
    $totalCost = 0;
    $typeStats = [];
    
    foreach ($allMaintenances as $m) {
        $completionDate = $m['completion_date']['date'] ?? $m['start_date']['date'] ?? null;
        if (!$completionDate) continue;
        
        $mDate = substr($completionDate, 0, 10);
        if ($mDate < $dateFrom || $mDate > $dateTo) continue;
        
        $cost = floatval($m['cost'] ?? 0);
        $type = $m['asset_maintenance_type'] ?? 'Maintenance';
        
        $totalServices++;
        $totalCost += $cost;
        
        if (!isset($typeStats[$type])) {
            $typeStats[$type] = ['count' => 0, 'total_cost' => 0];
        }
        $typeStats[$type]['count']++;
        $typeStats[$type]['total_cost'] += $cost;
    }
    
    $reportData['maintenance_costs'] = [
        'total_services' => $totalServices,
        'total_cost' => $totalCost,
        'avg_cost' => $totalServices > 0 ? $totalCost / $totalServices : 0,
    ];
    
    // Maintenance by type
    $reportData['maintenance_by_type'] = [];
    foreach ($typeStats as $type => $stats) {
        $reportData['maintenance_by_type'][] = [
            'maintenance_type' => $type,
            'count' => $stats['count'],
            'total_cost' => $stats['total_cost'],
        ];
    }
    // Sort by count descending
    usort($reportData['maintenance_by_type'], fn($a, $b) => $b['count'] - $a['count']);
    

} elseif ($report === 'usage') {
    // Detailed usage report
    $sql = "SELECT r.*, 
            TIMESTAMPDIFF(HOUR, r.start_datetime, COALESCE(r.end_datetime, NOW())) as duration_hours
            FROM reservations r 
            WHERE DATE(r.start_datetime) BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    
    if ($assetFilter) {
        $sql .= " AND r.asset_id = ?";
        $params[] = $assetFilter;
    }
    if ($userFilter) {
        $sql .= " AND r.user_email = ?";
        $params[] = $userFilter;
    }
    $sql .= " ORDER BY r.start_datetime DESC LIMIT 500";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportData['usage'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report === 'maintenance') {
    // Maintenance history report - from Snipe-IT API
    $allMaintenances = get_maintenances(500);
    $reportData['maintenance'] = [];
    $reportData['total_cost'] = 0;
    
    foreach ($allMaintenances as $m) {
        // Parse completion date
        $completionDate = $m['completion_date']['date'] ?? $m['start_date']['date'] ?? null;
        if (!$completionDate) continue;
        
        $mDate = substr($completionDate, 0, 10); // YYYY-MM-DD
        
        // Filter by date range
        if ($mDate < $dateFrom || $mDate > $dateTo) continue;
        
        // Filter by asset if specified
        if ($assetFilter && ($m['asset']['id'] ?? 0) != $assetFilter) continue;
        
        $cost = floatval($m['cost'] ?? 0);
        $reportData['total_cost'] += $cost;
        
        // Extract mileage from notes (format: "Mileage at service: 1700 mi")
        $notes = $m['notes'] ?? '';
        $mileage = 0;
        if (preg_match('/Mileage at service:\s*([\d,]+)/i', $notes, $matches)) {
            $mileage = (int)str_replace(',', '', $matches[1]);
        }
        
        $reportData['maintenance'][] = [
            'id' => $m['id'],
            'asset_id' => $m['asset']['id'] ?? 0,
            'asset_name' => $m['asset']['name'] ?? 'Unknown',
            'asset_tag' => $m['asset']['asset_tag'] ?? '',
            'maintenance_type' => $m['asset_maintenance_type'] ?? 'Uncategorized',
            'title' => $m['title'] ?? '',
            'service_date' => $mDate,
            'cost' => $cost,
            'mileage' => $mileage,
            'supplier' => $m['supplier']['name'] ?? '',
            'notes' => $notes,
            'logged_by' => $m['user_id']['name'] ?? $m['created_by']['name'] ?? '',
        ];
    }



} elseif ($report === 'compliance') {
    // Compliance status report
    $reportData['vehicles'] = [];
    
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
        
        $reportData['vehicles'][] = [
            'asset' => $asset,
            'insurance_expiry' => $insuranceExpiry,
            'insurance_days' => $insuranceDays,
            'insurance_status' => $insuranceDays === null ? 'unknown' : ($insuranceDays < 0 ? 'expired' : ($insuranceDays <= 30 ? 'warning' : 'ok')),
            'registration_expiry' => $registrationExpiry,
            'registration_days' => $registrationDays,
            'registration_status' => $registrationDays === null ? 'unknown' : ($registrationDays < 0 ? 'expired' : ($registrationDays <= 30 ? 'warning' : 'ok')),
            'last_maintenance' => $lastMaintenanceDate,
            'miles_since_service' => $milesSinceService,
            'maintenance_status' => $milesSinceService >= 7500 ? 'due' : ($milesSinceService >= 7000 ? 'warning' : 'ok')
        ];
    }

} elseif ($report === 'utilization') {
    // Vehicle utilization report
    $reportData['vehicles'] = [];
    
    $totalDays = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400);
    
    foreach ($assetList as $asset) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_reservations,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(TIMESTAMPDIFF(HOUR, start_datetime, LEAST(end_datetime, NOW()))) as total_hours
            FROM reservations
            WHERE asset_id = ?
            AND DATE(start_datetime) BETWEEN ? AND ?
        ");
        $stmt->execute([$asset['id'], $dateFrom, $dateTo]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $totalHours = (int)($stats['total_hours'] ?? 0);
        $availableHours = $totalDays * 10; // Assuming 10 working hours per day
        $utilizationRate = $availableHours > 0 ? min(100, round(($totalHours / $availableHours) * 100)) : 0;
        
        $reportData['vehicles'][] = [
            'asset' => $asset,
            'total_reservations' => (int)($stats['total_reservations'] ?? 0),
            'completed' => (int)($stats['completed'] ?? 0),
            'total_hours' => $totalHours,
            'utilization_rate' => $utilizationRate
        ];
    }
    
    // Sort by utilization rate descending
    usort($reportData['vehicles'], function($a, $b) {
        return $b['utilization_rate'] <=> $a['utilization_rate'];
    });
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
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        .report-card { transition: all 0.2s; cursor: pointer; }
        .report-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .report-card.active { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05); }
        .stat-box { background: #f8f9fa; border-radius: 8px; padding: 15px; text-align: center; }
        .stat-box .number { font-size: 2rem; font-weight: bold; }
        .progress-thin { height: 8px; }
        .status-ok { color: #198754; }
        .status-warning { color: #ffc107; }
        .status-expired, .status-due { color: #dc3545; }
        .status-unknown { color: #6c757d; }
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
        
        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h($userName) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <!-- Report Selection -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md">
                <a href="?report=summary&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="card report-card h-100 text-decoration-none <?= $report === 'summary' ? 'active' : '' ?>">
                    <div class="card-body text-center">
                        <i class="bi bi-pie-chart text-primary" style="font-size: 2rem;"></i>
                        <div class="mt-2"><strong>Summary</strong></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md">
                <a href="?report=usage&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="card report-card h-100 text-decoration-none <?= $report === 'usage' ? 'active' : '' ?>">
                    <div class="card-body text-center">
                        <i class="bi bi-calendar-check text-info" style="font-size: 2rem;"></i>
                        <div class="mt-2"><strong>Usage</strong></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md">
                <a href="?report=utilization&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="card report-card h-100 text-decoration-none <?= $report === 'utilization' ? 'active' : '' ?>">
                    <div class="card-body text-center">
                        <i class="bi bi-graph-up text-success" style="font-size: 2rem;"></i>
                        <div class="mt-2"><strong>Utilization</strong></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md">
                <a href="?report=maintenance&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="card report-card h-100 text-decoration-none <?= $report === 'maintenance' ? 'active' : '' ?>">
                    <div class="card-body text-center">
                        <i class="bi bi-wrench text-warning" style="font-size: 2rem;"></i>
                        <div class="mt-2"><strong>Maintenance</strong></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md">
                <a href="?report=compliance" class="card report-card h-100 text-decoration-none <?= $report === 'compliance' ? 'active' : '' ?>">
                    <div class="card-body text-center">
                        <i class="bi bi-shield-check text-danger" style="font-size: 2rem;"></i>
                        <div class="mt-2"><strong>Compliance</strong></div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Date Filters -->
        <?php if ($report !== 'compliance'): ?>
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="report" value="<?= h($report) ?>">
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                    </div>
                    <?php if (in_array($report, ['usage', 'maintenance'])): ?>
                    <div class="col-md-3">
                        <label class="form-label">Vehicle</label>
                        <select name="asset_id" class="form-select">
                            <option value="">All Vehicles</option>
                            <?php foreach ($assetList as $asset): ?>
                                <option value="<?= $asset['id'] ?>" <?= $assetFilter == $asset['id'] ? 'selected' : '' ?>>
                                    <?= h($asset['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if ($report === 'usage'): ?>
                    <div class="col-md-2">
                        <label class="form-label">User</label>
                        <select name="user_email" class="form-select">
                            <option value="">All Users</option>
                            <?php foreach ($userList as $user): ?>
                                <option value="<?= h($user['user_email']) ?>" <?= $userFilter === $user['user_email'] ? 'selected' : '' ?>>
                                    <?= h($user['user_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-filter"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Report Content -->
        <?php if ($report === 'summary'): ?>
            <!-- Summary Report -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="number text-primary"><?= $reportData['reservation_stats']['total'] ?? 0 ?></div>
                        <div class="text-muted">Total Reservations</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="number text-success"><?= $reportData['reservation_stats']['completed'] ?? 0 ?></div>
                        <div class="text-muted">Completed</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="number text-warning"><?= $reportData['reservation_stats']['missed'] ?? 0 ?></div>
                        <div class="text-muted">Missed</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="number text-secondary"><?= $reportData['reservation_stats']['cancelled'] ?? 0 ?></div>
                        <div class="text-muted">Cancelled</div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-truck me-2"></i>Reservations by Vehicle</h6>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($reportData['by_vehicle'])): ?>
                                <p class="text-muted text-center py-4">No data for this period</p>
                            <?php else: ?>
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Vehicle</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">Completed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reportData['by_vehicle'] as $row): ?>
                                        <tr>
                                            <td><?= h($row['asset_name_cache']) ?></td>
                                            <td class="text-center"><?= $row['count'] ?></td>
                                            <td class="text-center text-success"><?= $row['completed'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-people me-2"></i>Top Users</h6>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($reportData['by_user'])): ?>
                                <p class="text-muted text-center py-4">No data for this period</p>
                            <?php else: ?>
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>User</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">Completed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reportData['by_user'] as $row): ?>
                                        <tr>
                                            <td><?= h($row['user_name']) ?></td>
                                            <td class="text-center"><?= $row['count'] ?></td>
                                            <td class="text-center text-success"><?= $row['completed'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-2">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-wrench me-2"></i>Maintenance Costs</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="h4 text-primary"><?= $reportData['maintenance_costs']['total_services'] ?? 0 ?></div>
                                    <small class="text-muted">Services</small>
                                </div>
                                <div class="col-4">
                                    <div class="h4 text-success">$<?= number_format($reportData['maintenance_costs']['total_cost'] ?? 0, 2) ?></div>
                                    <small class="text-muted">Total Cost</small>
                                </div>
                                <div class="col-4">
                                    <div class="h4 text-info">$<?= number_format($reportData['maintenance_costs']['avg_cost'] ?? 0, 2) ?></div>
                                    <small class="text-muted">Avg Cost</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-tools me-2"></i>Maintenance by Type</h6>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($reportData['maintenance_by_type'])): ?>
                                <p class="text-muted text-center py-4">No maintenance data</p>
                            <?php else: ?>
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <?php foreach ($reportData['maintenance_by_type'] as $row): ?>
                                        <tr>
                                            <td><?= ucfirst(str_replace('_', ' ', $row['maintenance_type'])) ?></td>
                                            <td class="text-center"><?= $row['count'] ?></td>
                                            <td class="text-end">$<?= number_format($row['total_cost'] ?? 0, 2) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($report === 'usage'): ?>
            <!-- Usage Report -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Usage Report</h5>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($reportData['usage'])): ?>
                        <p class="text-muted text-center py-4">No usage data for this period</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Vehicle</th>
                                        <th>User</th>
                                        <th>Status</th>
                                        <th>Checkout</th>
                                        <th>Checkin</th>
                                        <th class="text-center">Hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData['usage'] as $row): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($row['start_datetime'])) ?></td>
                                        <td>
                                            <strong><?= h($row['asset_name_cache'] ?: 'Vehicle #' . $row['asset_id']) ?></strong>
                                        </td>
                                        <td><?= h($row['user_name']) ?></td>
                                        <td>
                                            <?php
                                            $statusClass = [
                                                'completed' => 'success',
                                                'confirmed' => 'info',
                                                'pending' => 'warning',
                                                'missed' => 'danger',
                                                'cancelled' => 'secondary',
                                                'maintenance_required' => 'warning'
                                            ][$row['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $statusClass ?>"><?= ucfirst($row['status']) ?></span>
                                        </td>
                                        <td><?= date('M j, g:i A', strtotime($row['start_datetime'])) ?></td>
                                        <td><?= $row['end_datetime'] ? date('M j, g:i A', strtotime($row['end_datetime'])) : '-' ?></td>
                                        <td class="text-center"><?= $row['duration_hours'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($report === 'utilization'): ?>
            <!-- Utilization Report -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Vehicle Utilization</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vehicle</th>
                                <th class="text-center">Reservations</th>
                                <th class="text-center">Completed</th>
                                <th class="text-center">Hours Used</th>
                                <th>Utilization Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['vehicles'] as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= h($item['asset']['name']) ?></strong><br>
                                    <small class="text-muted"><?= h($item['asset']['asset_tag']) ?></small>
                                </td>
                                <td class="text-center"><?= $item['total_reservations'] ?></td>
                                <td class="text-center text-success"><?= $item['completed'] ?></td>
                                <td class="text-center"><?= $item['total_hours'] ?></td>
                                <td style="width: 200px;">
                                    <div class="d-flex align-items-center">
                                        <div class="progress progress-thin flex-grow-1 me-2">
                                            <?php
                                            $barColor = $item['utilization_rate'] >= 70 ? 'success' : ($item['utilization_rate'] >= 40 ? 'warning' : 'danger');
                                            ?>
                                            <div class="progress-bar bg-<?= $barColor ?>" style="width: <?= $item['utilization_rate'] ?>%"></div>
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

        <?php elseif ($report === 'maintenance'): ?>
            <!-- Maintenance Report -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-wrench me-2"></i>Maintenance Report
                        <?php if ($reportData['total_cost'] > 0): ?>
                            <span class="badge bg-success ms-2">Total: $<?= number_format($reportData['total_cost'], 2) ?></span>
                        <?php endif; ?>
                    </h5>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($reportData['maintenance'])): ?>
                        <p class="text-muted text-center py-4">No maintenance records for this period</p>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th class="text-center">Mileage</th>
                                    <th>Provider</th>
                                    <th class="text-end">Cost</th>
                                    <th>Logged By</th>
                                </tr>
                            </thead>
                            <tbody>
				<?php foreach ($reportData['maintenance'] as $row): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($row['service_date'])) ?></td>
                                    <td>
                                        <strong><?= h($row['asset_name']) ?></strong><br>
                                        <small class="text-muted"><?= h($row['asset_tag']) ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= ucfirst(str_replace('_', ' ', $row['maintenance_type'])) ?></span></td>
                                    <td class="text-center"><?= $row['mileage'] ? number_format($row['mileage']) . ' mi' : '-' ?></td>
                                    <td><?= h($row['supplier'] ?? '-') ?></td>
                                    <td class="text-end"><?= $row['cost'] ? '$' . number_format($row['cost'], 2) : '-' ?></td>
                                    <td><?= h($row['logged_by'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($report === 'compliance'): ?>
            <!-- Compliance Report -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Fleet Compliance Status</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vehicle</th>
                                <th class="text-center">Insurance</th>
                                <th class="text-center">Registration</th>
                                <th class="text-center">Maintenance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['vehicles'] as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= h($item['asset']['name']) ?></strong><br>
                                    <small class="text-muted"><?= h($item['asset']['asset_tag']) ?></small>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['insurance_status'] === 'ok'): ?>
                                        <i class="bi bi-check-circle-fill status-ok"></i>
                                        <br><small><?= date('M j, Y', strtotime($item['insurance_expiry'])) ?></small>
                                    <?php elseif ($item['insurance_status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i>
                                        <br><small class="text-warning"><?= $item['insurance_days'] ?> days</small>
                                    <?php elseif ($item['insurance_status'] === 'expired'): ?>
                                        <i class="bi bi-x-circle-fill status-expired"></i>
                                        <br><small class="text-danger">EXPIRED</small>
                                    <?php else: ?>
                                        <i class="bi bi-question-circle status-unknown"></i>
                                        <br><small class="text-muted">Unknown</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['registration_status'] === 'ok'): ?>
                                        <i class="bi bi-check-circle-fill status-ok"></i>
                                        <br><small><?= date('M j, Y', strtotime($item['registration_expiry'])) ?></small>
                                    <?php elseif ($item['registration_status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i>
                                        <br><small class="text-warning"><?= $item['registration_days'] ?> days</small>
                                    <?php elseif ($item['registration_status'] === 'expired'): ?>
                                        <i class="bi bi-x-circle-fill status-expired"></i>
                                        <br><small class="text-danger">EXPIRED</small>
                                    <?php else: ?>
                                        <i class="bi bi-question-circle status-unknown"></i>
                                        <br><small class="text-muted">Unknown</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['maintenance_status'] === 'ok'): ?>
                                        <i class="bi bi-check-circle-fill status-ok"></i>
                                        <br><small><?= number_format($item['miles_since_service']) ?> mi</small>
                                    <?php elseif ($item['maintenance_status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i>
                                        <br><small class="text-warning"><?= number_format($item['miles_since_service']) ?> mi</small>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill status-due"></i>
                                        <br><small class="text-danger">DUE</small>
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
