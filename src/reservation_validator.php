<?php
/**
 * Reservation Validator
 * Enforces reservation controls (min notice, max duration, max concurrent, blackouts)
 */

/**
 * Get reservation control settings
 */
function get_reservation_controls(): array
{
    static $controls = null;
    if ($controls === null) {
        $config = load_config();
        $controls = $config['reservation_controls'] ?? [];
    }
    return [
        'min_notice_hours' => (int)($controls['min_notice_hours'] ?? 0),
        'max_duration_hours' => (int)($controls['max_duration_hours'] ?? 0),
        'max_concurrent_per_user' => (int)($controls['max_concurrent_per_user'] ?? 0),
        'staff_bypass' => (bool)($controls['staff_bypass'] ?? true),
    ];
}

/**
 * Validate a reservation request
 * 
 * @param string $startDatetime Start datetime (Y-m-d H:i:s)
 * @param string $endDatetime End datetime (Y-m-d H:i:s)
 * @param int|null $assetId Asset ID (for blackout check)
 * @param string $userEmail User email (for concurrent check)
 * @param bool $isStaff Is user staff/admin
 * @param PDO $pdo Database connection
 * @return array ['valid' => bool, 'errors' => array]
 */
function validate_reservation(
    string $startDatetime,
    string $endDatetime,
    ?int $assetId,
    string $userEmail,
    bool $isStaff,
    PDO $pdo
): array {
    $controls = get_reservation_controls();
    $errors = [];
    
    // Staff bypass
    if ($isStaff && $controls['staff_bypass']) {
        return ['valid' => true, 'errors' => []];
    }
    
    $start = new DateTime($startDatetime);
    $end = new DateTime($endDatetime);
    $now = new DateTime();
    
    // 1. Minimum notice period
    if ($controls['min_notice_hours'] > 0) {
        $minStart = (clone $now)->modify("+{$controls['min_notice_hours']} hours");
        if ($start < $minStart) {
            $errors[] = "Reservations require at least {$controls['min_notice_hours']} hours advance notice.";
        }
    }
    
    // 2. Maximum duration
    if ($controls['max_duration_hours'] > 0) {
        $durationHours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        if ($durationHours > $controls['max_duration_hours']) {
            $errors[] = "Maximum reservation duration is {$controls['max_duration_hours']} hours.";
        }
    }
    
    // 3. Maximum concurrent reservations
    if ($controls['max_concurrent_per_user'] > 0) {
        $count = count_active_reservations($userEmail, $pdo);
        if ($count >= $controls['max_concurrent_per_user']) {
            $errors[] = "You have reached the maximum of {$controls['max_concurrent_per_user']} active reservations.";
        }
    }
    
    // 4. Blackout slots
    $blackout = check_blackout_conflict($startDatetime, $endDatetime, $assetId, $pdo);
    if ($blackout) {
        $errors[] = "This time slot is blocked: {$blackout['title']}";
        if (!empty($blackout['reason'])) {
            $errors[] = "Reason: {$blackout['reason']}";
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Count active reservations for a user
 */
function count_active_reservations(string $userEmail, PDO $pdo): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations 
        WHERE user_email = ? 
        AND status IN ('pending', 'confirmed', 'approved')
        AND approval_status IN ('pending_approval', 'approved', 'auto_approved')
        AND end_datetime > NOW()
    ");
    $stmt->execute([$userEmail]);
    return (int)$stmt->fetchColumn();
}

/**
 * Check for blackout slot conflicts
 */
function check_blackout_conflict(
    string $startDatetime,
    string $endDatetime,
    ?int $assetId,
    PDO $pdo
): ?array {
    $sql = "
        SELECT * FROM blackout_slots 
        WHERE (
            (start_datetime <= ? AND end_datetime > ?) OR
            (start_datetime < ? AND end_datetime >= ?) OR
            (start_datetime >= ? AND end_datetime <= ?)
        )
        AND (asset_id IS NULL OR asset_id = ?)
        ORDER BY start_datetime ASC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $endDatetime, $startDatetime,  // overlaps start
        $endDatetime, $startDatetime,  // overlaps end
        $startDatetime, $endDatetime,  // contained within
        $assetId
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Get all blackout slots (optionally filtered by date range)
 */
function get_blackout_slots(PDO $pdo, ?string $fromDate = null, ?string $toDate = null): array
{
    $sql = "SELECT * FROM blackout_slots WHERE 1=1";
    $params = [];
    
    if ($fromDate) {
        $sql .= " AND end_datetime >= ?";
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= " AND start_datetime <= ?";
        $params[] = $toDate;
    }
    
    $sql .= " ORDER BY start_datetime ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a blackout slot
 */
function create_blackout_slot(
    string $title,
    string $startDatetime,
    string $endDatetime,
    ?int $assetId,
    string $reason,
    string $createdByName,
    string $createdByEmail,
    PDO $pdo
): int {
    $stmt = $pdo->prepare("
        INSERT INTO blackout_slots 
        (title, start_datetime, end_datetime, asset_id, reason, created_by_name, created_by_email)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $title, $startDatetime, $endDatetime, $assetId, $reason, $createdByName, $createdByEmail
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Delete a blackout slot
 */
function delete_blackout_slot(int $id, PDO $pdo): bool
{
    $stmt = $pdo->prepare("DELETE FROM blackout_slots WHERE id = ?");
    return $stmt->execute([$id]);
}
