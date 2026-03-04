<?php
/**
 * Business Day Service
 * Core calculation engine for business day logic.
 *
 * Provides functions to:
 * - Determine if a date is a business day (respects weekday toggles + holidays)
 * - Add N business days to a date
 * - Get all non-business dates in a range (for calendar UI)
 * - Calculate earliest booking date after an existing reservation
 *
 * @since v1.3.5
 */

/**
 * Get business day configuration from system_settings.
 * Cached per-request via static variable.
 *
 * @param PDO $pdo
 * @return array [
 *   'buffer'    => int (business days between reservations),
 *   'days'      => ['monday' => bool, 'tuesday' => bool, ...],
 *   'redirect_overdue_minutes' => int,
 *   'redirect_lookahead_hours' => int,
 * ]
 */
function get_business_day_config(PDO $pdo): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'business_day%' OR setting_key LIKE 'redirect_%'");
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $config = [
        'buffer' => (int)($rows['business_day_buffer'] ?? 2),
        'days' => [
            'monday'    => (bool)($rows['business_days_monday'] ?? 1),
            'tuesday'   => (bool)($rows['business_days_tuesday'] ?? 1),
            'wednesday' => (bool)($rows['business_days_wednesday'] ?? 1),
            'thursday'  => (bool)($rows['business_days_thursday'] ?? 1),
            'friday'    => (bool)($rows['business_days_friday'] ?? 1),
            'saturday'  => (bool)($rows['business_days_saturday'] ?? 0),
            'sunday'    => (bool)($rows['business_days_sunday'] ?? 0),
        ],
        'redirect_overdue_minutes' => (int)($rows['redirect_overdue_minutes'] ?? 30),
        'redirect_lookahead_hours' => (int)($rows['redirect_lookahead_hours'] ?? 24),
    ];

    return $config;
}

/**
 * Get active holidays within a date range.
 *
 * @param PDO $pdo
 * @param string $fromDate Y-m-d
 * @param string $toDate Y-m-d
 * @return array of holiday dates as Y-m-d strings (keyed by date for fast lookup)
 */
function get_active_holidays(PDO $pdo, string $fromDate, string $toDate): array
{
    $stmt = $pdo->prepare("
        SELECT holiday_date, name
        FROM holidays
        WHERE is_active = 1
          AND holiday_date BETWEEN ? AND ?
        ORDER BY holiday_date ASC
    ");
    $stmt->execute([$fromDate, $toDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $holidays = [];
    foreach ($rows as $row) {
        $holidays[$row['holiday_date']] = $row['name'];
    }
    return $holidays;
}

/**
 * Get all holidays (for admin management UI).
 *
 * @param PDO $pdo
 * @param int|null $year Filter by year (null = all)
 * @return array
 */
function get_all_holidays(PDO $pdo, ?int $year = null): array
{
    $sql = "SELECT * FROM holidays";
    $params = [];

    if ($year !== null) {
        $sql .= " WHERE YEAR(holiday_date) = ?";
        $params[] = $year;
    }

    $sql .= " ORDER BY holiday_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if a specific date is a business day.
 *
 * @param string $date Y-m-d format
 * @param PDO $pdo
 * @return bool true if the date is a working business day
 */
function is_business_day(string $date, PDO $pdo): bool
{
    $config = get_business_day_config($pdo);

    // Check day of week
    $dayOfWeek = strtolower(date('l', strtotime($date)));
    if (empty($config['days'][$dayOfWeek])) {
        return false;
    }

    // Check holidays
    $holidays = get_active_holidays($pdo, $date, $date);
    if (isset($holidays[$date])) {
        return false;
    }

    return true;
}

/**
 * Add N business days to a given date.
 * Skips non-working days (weekends per config) and active holidays.
 *
 * @param string $fromDate Y-m-d format (start date, NOT included in the count)
 * @param int $days Number of business days to add (must be >= 0)
 * @param PDO $pdo
 * @return string Y-m-d of the resulting date
 */
function add_business_days(string $fromDate, int $days, PDO $pdo): string
{
    if ($days <= 0) {
        return $fromDate;
    }

    $config = get_business_day_config($pdo);

    // Pre-fetch holidays for a generous range (buffer * 3 calendar days should be enough)
    $maxCalendarDays = $days * 3 + 30; // generous buffer for weekends + holidays
    $rangeEnd = date('Y-m-d', strtotime($fromDate . " +{$maxCalendarDays} days"));
    $holidays = get_active_holidays($pdo, $fromDate, $rangeEnd);

    $current = new DateTime($fromDate);
    $added = 0;

    while ($added < $days) {
        $current->modify('+1 day');
        $dateStr = $current->format('Y-m-d');
        $dayOfWeek = strtolower($current->format('l'));

        // Skip non-working days
        if (empty($config['days'][$dayOfWeek])) {
            continue;
        }

        // Skip holidays
        if (isset($holidays[$dateStr])) {
            continue;
        }

        $added++;
    }

    return $current->format('Y-m-d');
}

/**
 * Get the next business day on or after the given date.
 * If the given date IS a business day, it returns that date.
 *
 * @param string $date Y-m-d format
 * @param PDO $pdo
 * @return string Y-m-d
 */
function next_business_day_on_or_after(string $date, PDO $pdo): string
{
    $config = get_business_day_config($pdo);
    $rangeEnd = date('Y-m-d', strtotime($date . ' +30 days'));
    $holidays = get_active_holidays($pdo, $date, $rangeEnd);

    $current = new DateTime($date);
    $maxIterations = 30; // safety

    for ($i = 0; $i < $maxIterations; $i++) {
        $dateStr = $current->format('Y-m-d');
        $dayOfWeek = strtolower($current->format('l'));

        if (!empty($config['days'][$dayOfWeek]) && !isset($holidays[$dateStr])) {
            return $dateStr;
        }

        $current->modify('+1 day');
    }

    // Fallback (should never reach here)
    return $date;
}

/**
 * Get all non-business dates in a range.
 * Used by the calendar UI to gray out dates.
 *
 * @param string $fromDate Y-m-d
 * @param string $toDate Y-m-d
 * @param PDO $pdo
 * @return array [
 *   'non_business_dates' => ['2026-03-07' => 'Saturday', '2026-03-08' => 'Sunday', ...],
 *   'holidays' => ['2026-05-25' => 'Memorial Day', ...],
 *   'weekends' => ['2026-03-07' => 'Saturday', ...],
 * ]
 */
function get_non_business_dates(string $fromDate, string $toDate, PDO $pdo): array
{
    $config = get_business_day_config($pdo);
    $holidays = get_active_holidays($pdo, $fromDate, $toDate);

    $nonBusinessDates = [];
    $weekendDates = [];

    $current = new DateTime($fromDate);
    $end = new DateTime($toDate);

    while ($current <= $end) {
        $dateStr = $current->format('Y-m-d');
        $dayOfWeek = strtolower($current->format('l'));

        if (empty($config['days'][$dayOfWeek])) {
            $reason = ucfirst($dayOfWeek);
            $nonBusinessDates[$dateStr] = $reason;
            $weekendDates[$dateStr] = $reason;
        }

        $current->modify('+1 day');
    }

    // Add holidays (these may overlap with weekends)
    foreach ($holidays as $date => $name) {
        $nonBusinessDates[$date] = $name;
    }

    return [
        'non_business_dates' => $nonBusinessDates,
        'holidays' => $holidays,
        'weekends' => $weekendDates,
    ];
}

/**
 * Get blackout dates in a range (from existing blackout_slots table).
 *
 * @param string $fromDate Y-m-d
 * @param string $toDate Y-m-d
 * @param PDO $pdo
 * @param int|null $assetId Filter by specific vehicle (null = global blackouts only)
 * @return array ['2026-03-15' => 'Maintenance Window', ...]
 */
function get_blackout_dates(string $fromDate, string $toDate, PDO $pdo, ?int $assetId = null): array
{
    $sql = "SELECT title, start_datetime, end_datetime FROM blackout_slots
            WHERE end_datetime >= ? AND start_datetime <= ?";
    $params = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];

    if ($assetId !== null) {
        $sql .= " AND (asset_id IS NULL OR asset_id = ?)";
        $params[] = $assetId;
    } else {
        $sql .= " AND asset_id IS NULL";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $blackoutDates = [];
    foreach ($slots as $slot) {
        $start = new DateTime($slot['start_datetime']);
        $end = new DateTime($slot['end_datetime']);
        while ($start <= $end) {
            $blackoutDates[$start->format('Y-m-d')] = $slot['title'];
            $start->modify('+1 day');
        }
    }

    return $blackoutDates;
}

/**
 * Given a vehicle's latest reservation end datetime, calculate the earliest date
 * a new reservation can start on the same vehicle.
 *
 * @param string $existingEndDatetime Y-m-d H:i:s
 * @param PDO $pdo
 * @return string Y-m-d (the earliest allowed start date)
 */
function get_earliest_booking_date(string $existingEndDatetime, PDO $pdo): string
{
    $config = get_business_day_config($pdo);
    $buffer = $config['buffer'];

    // Start counting from the day AFTER the end date
    $endDate = date('Y-m-d', strtotime($existingEndDatetime));

    return add_business_days($endDate, $buffer, $pdo);
}

/**
 * Get the latest active reservation end datetime for a specific vehicle.
 *
 * @param int $assetId Snipe-IT asset ID
 * @param PDO $pdo
 * @return string|null Y-m-d H:i:s or null if no active reservation
 */
function get_vehicle_latest_reservation_end(int $assetId, PDO $pdo): ?string
{
    $stmt = $pdo->prepare("
        SELECT MAX(end_datetime) as latest_end
        FROM reservations
        WHERE asset_id = ?
          AND status IN ('pending', 'confirmed')
          AND approval_status IN ('pending_approval', 'approved', 'auto_approved')
          AND end_datetime > NOW()
    ");
    $stmt->execute([$assetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row['latest_end'] ?? null;
}

/**
 * Determine vehicle availability for a requested date window.
 *
 * @param int $assetId
 * @param int $snipeitStatusId Current Snipe-IT status ID
 * @param string $requestedStartDate Y-m-d
 * @param string $requestedEndDate Y-m-d
 * @param PDO $pdo
 * @return array [
 *   'available'     => bool,
 *   'status'        => 'available_now' | 'available_future' | 'unavailable' | 'out_of_service',
 *   'earliest_date' => string|null (Y-m-d, only if status = 'available_future' or for info),
 *   'reason'        => string (human-readable explanation),
 * ]
 */
function check_vehicle_availability(
    int $assetId,
    int $snipeitStatusId,
    string $requestedStartDate,
    string $requestedEndDate,
    PDO $pdo
): array {
    // Out of service = never bookable
    if (defined('STATUS_VEH_OUT_OF_SERVICE') && $snipeitStatusId == STATUS_VEH_OUT_OF_SERVICE) {
        return [
            'available' => false,
            'status' => 'out_of_service',
            'earliest_date' => null,
            'reason' => 'Vehicle is out of service for maintenance.',
        ];
    }

    // Available now — check for reservation conflicts in the requested window
    if (defined('STATUS_VEH_AVAILABLE') && $snipeitStatusId == STATUS_VEH_AVAILABLE) {
        $conflict = check_reservation_conflict($assetId, $requestedStartDate, $requestedEndDate, $pdo);
        if ($conflict) {
            return [
                'available' => false,
                'status' => 'unavailable',
                'earliest_date' => null,
                'reason' => 'Vehicle has a conflicting reservation in this window.',
            ];
        }
        return [
            'available' => true,
            'status' => 'available_now',
            'earliest_date' => null,
            'reason' => 'Available now.',
        ];
    }

    // Reserved or In Service — check if future booking is possible
    $latestEnd = get_vehicle_latest_reservation_end($assetId, $pdo);
if (!$latestEnd) {
        // Stale Snipe-IT status — Reserved but no active reservation in DB
        // Check for conflicts in case of pending reservations
        $conflict = check_reservation_conflict($assetId, $requestedStartDate, $requestedEndDate, $pdo);
        if ($conflict) {
            return [
                'available' => false,
                'status' => 'unavailable',
                'earliest_date' => null,
                'reason' => 'Vehicle has a conflicting reservation in this window.',
            ];
        }
        return [
            'available' => true,
            'status' => 'available_now',
            'earliest_date' => null,
            'reason' => 'Available now.',
        ];
    }
    $earliestDate = get_earliest_booking_date($latestEnd, $pdo);

    // Check if requested start is on or after the earliest booking date
    if ($requestedStartDate >= $earliestDate) {
        // Also check for conflicts in the requested window
        $conflict = check_reservation_conflict($assetId, $requestedStartDate, $requestedEndDate, $pdo);
        if ($conflict) {
            return [
                'available' => false,
                'status' => 'unavailable',
                'earliest_date' => $earliestDate,
                'reason' => 'Vehicle has a conflicting reservation in this window.',
            ];
        }
        return [
            'available' => true,
            'status' => 'available_future',
            'earliest_date' => $earliestDate,
            'reason' => "Available from {$earliestDate} (after turnaround buffer).",
        ];
    }

    return [
        'available' => false,
        'status' => 'unavailable',
        'earliest_date' => $earliestDate,
        'reason' => "Earliest available date is {$earliestDate}.",
    ];
}

/**
 * Check if a vehicle has any conflicting reservations in a date window.
 *
 * @param int $assetId
 * @param string $startDate Y-m-d
 * @param string $endDate Y-m-d
 * @param PDO $pdo
 * @param int|null $excludeReservationId Exclude a specific reservation (for redirect)
 * @return bool true if conflict exists
 */
function check_reservation_conflict(
    int $assetId,
    string $startDate,
    string $endDate,
    PDO $pdo,
    ?int $excludeReservationId = null
): bool {
    $startDatetime = $startDate . ' 00:00:00';
    $endDatetime = $endDate . ' 23:59:59';

    $sql = "
        SELECT COUNT(*) FROM reservations
        WHERE asset_id = ?
          AND status NOT IN ('cancelled', 'completed', 'rejected', 'missed', 'redirected')
          AND approval_status NOT IN ('rejected')
          AND (
              (start_datetime < ? AND end_datetime > ?)
          )
    ";
    $params = [$assetId, $endDatetime, $startDatetime];

    if ($excludeReservationId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeReservationId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Save business day settings from admin form.
 *
 * @param array $data Form data
 * @param PDO $pdo
 * @return void
 */
function save_business_day_settings(array $data, PDO $pdo): void
{
    $settings = [
        'business_day_buffer'       => max(1, min(10, (int)($data['business_day_buffer'] ?? 2))),
        'business_days_monday'      => !empty($data['business_days_monday']) ? '1' : '0',
        'business_days_tuesday'     => !empty($data['business_days_tuesday']) ? '1' : '0',
        'business_days_wednesday'   => !empty($data['business_days_wednesday']) ? '1' : '0',
        'business_days_thursday'    => !empty($data['business_days_thursday']) ? '1' : '0',
        'business_days_friday'      => !empty($data['business_days_friday']) ? '1' : '0',
        'business_days_saturday'    => !empty($data['business_days_saturday']) ? '1' : '0',
        'business_days_sunday'      => !empty($data['business_days_sunday']) ? '1' : '0',
        'redirect_overdue_minutes'  => max(15, min(240, (int)($data['redirect_overdue_minutes'] ?? 30))),
        'redirect_lookahead_hours'  => max(6, min(72, (int)($data['redirect_lookahead_hours'] ?? 24))),
    ];

    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");

    foreach ($settings as $key => $value) {
        $stmt->execute([$key, (string)$value]);
    }
}

/**
 * Toggle a holiday's active status.
 *
 * @param int $holidayId
 * @param bool $isActive
 * @param PDO $pdo
 * @return bool
 */
function toggle_holiday(int $holidayId, bool $isActive, PDO $pdo): bool
{
    $stmt = $pdo->prepare("UPDATE holidays SET is_active = ?, updated_at = NOW() WHERE id = ?");
    return $stmt->execute([(int)$isActive, $holidayId]);
}

/**
 * Add a custom holiday.
 *
 * @param string $name
 * @param string $date Y-m-d
 * @param PDO $pdo
 * @return int New holiday ID
 */
function add_custom_holiday(string $name, string $date, PDO $pdo): int
{
    $stmt = $pdo->prepare("
        INSERT INTO holidays (name, holiday_date, holiday_type, is_recurring, is_active)
        VALUES (?, ?, 'custom', 0, 1)
        ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1, updated_at = NOW()
    ");
    $stmt->execute([trim($name), $date]);
    return (int)$pdo->lastInsertId();
}

/**
 * Delete a custom holiday (only custom holidays can be deleted; federal ones are toggled).
 *
 * @param int $holidayId
 * @param PDO $pdo
 * @return bool
 */
function delete_custom_holiday(int $holidayId, PDO $pdo): bool
{
    $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ? AND holiday_type = 'custom'");
    return $stmt->execute([$holidayId]);
}
