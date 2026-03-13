<?php
/**
 * BL-006: Database-driven Configurable Inspection Checklist
 * Supports Quick (existing Snipe-IT fields), Full (DB-driven checklist), and Off modes.
 *
 * Profiles, categories, and items are managed via checklist_admin.php.
 * Safety-critical items trigger warnings during checkout.
 *
 * @since v2.0.0  Hardcoded checklist
 * @since v2.2.0  Database-driven with profiles, safety-critical items
 */

/**
 * Hardcoded fallback checklist (used when DB tables don't exist yet).
 * Kept for backward compatibility with old inspection_responses.
 */
const INSPECTION_CHECKLIST = [
    'general' => [
        'label' => 'General',
        'icon'  => 'bi-card-checklist',
        'items' => [
            'valid_registration'   => 'Valid registration document present',
            'insurance_card'       => 'Insurance card / proof of insurance present',
            'vehicle_cleanliness'  => 'Vehicle exterior and interior reasonably clean',
        ],
    ],
    'tires' => [
        'label' => 'Tires',
        'icon'  => 'bi-circle',
        'items' => [
            'tire_fl_condition' => 'Front-Left tire condition and inflation',
            'tire_fr_condition' => 'Front-Right tire condition and inflation',
            'tire_rl_condition' => 'Rear-Left tire condition and inflation',
            'tire_rr_condition' => 'Rear-Right tire condition and inflation',
        ],
    ],
    'interior' => [
        'label' => 'Interior',
        'icon'  => 'bi-car-front',
        'items' => [
            'seatbelts'          => 'All seatbelts functional',
            'mirrors_interior'   => 'Rearview and side mirrors adjusted/intact',
            'dashboard_warnings' => 'No dashboard warning lights illuminated',
            'horn'               => 'Horn functional',
            'wipers'             => 'Windshield wipers functional',
            'climate_control'    => 'Climate control / AC / heater operational',
            'interior_clean'     => 'Interior clean and free of debris',
            'gauges_functional'  => 'All gauges and indicators functional',
            'charging_ports'     => 'USB / charging ports functional',
        ],
    ],
    'lights' => [
        'label' => 'Lights & Signals',
        'icon'  => 'bi-lightbulb',
        'items' => [
            'headlights_low'  => 'Headlights — low beam',
            'headlights_high' => 'Headlights — high beam',
            'tail_lights'     => 'Tail lights',
            'brake_lights'    => 'Brake lights',
            'turn_signals'    => 'Turn signals (left and right)',
            'hazard_lights'   => 'Hazard / emergency flashers',
            'reverse_lights'  => 'Reverse lights',
        ],
    ],
    'mechanical' => [
        'label' => 'Mechanical',
        'icon'  => 'bi-gear',
        'items' => [
            'brakes'       => 'Brakes responsive, no unusual noise',
            'steering'     => 'Steering responsive, no play',
            'engine_sound' => 'Engine starts and runs smoothly',
            'transmission' => 'Transmission shifts smoothly',
            'fluid_levels' => 'Visible fluid levels normal (oil, coolant, washer)',
            'leaks'        => 'No visible fluid leaks under vehicle',
        ],
    ],
    'windows' => [
        'label' => 'Windows & Glass',
        'icon'  => 'bi-window',
        'items' => [
            'windshield'   => 'Windshield — no cracks or chips',
            'side_windows' => 'Side windows — intact, open/close properly',
            'rear_window'  => 'Rear window — intact, clear visibility',
        ],
    ],
    'emergency' => [
        'label' => 'Emergency Equipment',
        'icon'  => 'bi-shield-exclamation',
        'items' => [
            'fire_extinguisher'  => 'Fire extinguisher present and charged',
            'first_aid_kit'      => 'First aid kit present and stocked',
            'safety_triangle'    => 'Safety triangle / reflective warning device',
            'emergency_contacts' => 'Emergency contact info / accident instruction sheet',
        ],
    ],
    'equipment' => [
        'label' => 'Other Equipment',
        'icon'  => 'bi-box-seam',
        'items' => [
            'spare_tire'    => 'Spare tire present and inflated',
            'gps_nav'       => 'GPS / navigation system functional (if equipped)',
            'vehicle_binder'=> 'Vehicle binder / documentation folder present',
            'fuel_level'    => 'Fuel level adequate for trip',
            'parking_brake' => 'Parking brake engages and releases properly',
        ],
    ],
    'overall' => [
        'label' => 'Overall Assessment',
        'icon'  => 'bi-chat-text',
        'items' => [
            'overall_comments' => 'Additional comments or observations',
        ],
    ],
];

// ── Category icon map (for DB-driven categories) ──
const CHECKLIST_CATEGORY_ICONS = [
    'General'              => 'bi-card-checklist',
    'Tires'                => 'bi-circle',
    'Interior'             => 'bi-car-front',
    'Lights & Signals'     => 'bi-lightbulb',
    'Mechanical'           => 'bi-gear',
    'Windows & Glass'      => 'bi-window',
    'Emergency Equipment'  => 'bi-shield-exclamation',
    'Other Equipment'      => 'bi-box-seam',
    'Overall Assessment'   => 'bi-chat-text',
];

/**
 * Get the current inspection mode from system_settings.
 * @return string 'quick'|'full'|'off'
 */
function get_inspection_mode($pdo): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'inspection_mode' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    return in_array($val, ['quick', 'full', 'off'], true) ? $val : 'quick';
}

// ── Database-driven checklist functions ──

/**
 * Check if checklist_profiles table exists.
 */
function checklist_tables_exist(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $pdo->query("SELECT 1 FROM checklist_profiles LIMIT 1");
        $exists = true;
    } catch (\Throwable $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Get the checklist profile for a specific vehicle (by asset data).
 * Looks up by model_id, then category_id, then falls back to default profile.
 *
 * @param PDO   $pdo
 * @param int   $assetId   Snipe-IT asset ID
 * @param array|null $assetData Pre-fetched asset data (optional, to avoid extra API call)
 * @return array|null  ['profile_id'=>int, 'profile_name'=>string, 'categories'=>[...]]
 */
function get_checklist_for_vehicle(PDO $pdo, int $assetId, ?array $assetData = null): ?array
{
    static $cache = [];
    if (isset($cache[$assetId])) return $cache[$assetId];

    if (!checklist_tables_exist($pdo)) {
        return null; // Fall back to hardcoded
    }

    // Get model_id from asset data
    if ($assetData === null) {
        require_once SRC_PATH . '/snipeit_client.php';
        $assetData = get_asset($assetId);
    }
    $modelId = (int)($assetData['model']['id'] ?? 0);
    $categoryId = (int)($assetData['category']['id'] ?? $assetData['model']['category_id'] ?? 0);

    // 1. Check assignments: model_id match
    $profileId = null;
    if ($modelId > 0) {
        $stmt = $pdo->prepare("
            SELECT p.id FROM checklist_profiles p
            JOIN checklist_profile_assignments a ON a.profile_id = p.id
            WHERE a.snipeit_model_id = ? AND p.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$modelId]);
        $profileId = $stmt->fetchColumn() ?: null;
    }

    // 2. Check assignments: category_id match
    if (!$profileId && $categoryId > 0) {
        $stmt = $pdo->prepare("
            SELECT p.id FROM checklist_profiles p
            JOIN checklist_profile_assignments a ON a.profile_id = p.id
            WHERE a.snipeit_category_id = ? AND p.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$categoryId]);
        $profileId = $stmt->fetchColumn() ?: null;
    }

    // 3. Fallback: default profile
    if (!$profileId) {
        $stmt = $pdo->prepare("SELECT id FROM checklist_profiles WHERE is_default = 1 AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $profileId = $stmt->fetchColumn() ?: null;
    }

    if (!$profileId) {
        $cache[$assetId] = null;
        return null;
    }

    $result = load_checklist_profile($pdo, (int)$profileId);
    $cache[$assetId] = $result;
    return $result;
}

/**
 * Load a full checklist profile with categories and items.
 */
function load_checklist_profile(PDO $pdo, int $profileId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM checklist_profiles WHERE id = ?");
    $stmt->execute([$profileId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) return null;

    $catStmt = $pdo->prepare("
        SELECT * FROM checklist_categories
        WHERE profile_id = ? AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $catStmt->execute([$profileId]);
    $categories = [];

    while ($cat = $catStmt->fetch(PDO::FETCH_ASSOC)) {
        $itemStmt = $pdo->prepare("
            SELECT * FROM checklist_items
            WHERE category_id = ? AND is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        $itemStmt->execute([$cat['id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $categories[] = [
            'id'         => (int)$cat['id'],
            'name'       => $cat['name'],
            'sort_order' => (int)$cat['sort_order'],
            'items'      => array_map(function ($item) {
                return [
                    'id'                => (int)$item['id'],
                    'label'             => $item['label'],
                    'is_safety_critical'=> (bool)$item['is_safety_critical'],
                    'applies_to'        => $item['applies_to'],
                    'sort_order'        => (int)$item['sort_order'],
                ];
            }, $items),
        ];
    }

    return [
        'profile_id'   => (int)$profile['id'],
        'profile_name' => $profile['name'],
        'categories'   => $categories,
    ];
}

/**
 * Get all active checklist profiles (for admin UI).
 */
function get_all_checklist_profiles(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT p.*,
               (SELECT COUNT(*) FROM checklist_categories c WHERE c.profile_id = p.id AND c.is_active = 1) AS category_count,
               (SELECT COUNT(*) FROM checklist_items i JOIN checklist_categories c2 ON i.category_id = c2.id WHERE c2.profile_id = p.id AND i.is_active = 1) AS item_count
        FROM checklist_profiles p
        ORDER BY p.is_default DESC, p.name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Render the full inspection form as a Bootstrap accordion.
 * Now supports DB-driven checklist data.
 *
 * @param string     $type           'checkout' or 'checkin'
 * @param string     $mode           'full' (only renders for full; quick/off handled elsewhere)
 * @param array|null $existingData   Previously saved data (for re-display or checkin comparison)
 * @param array|null $compareData    Checkout data to compare against during checkin
 * @param array|null $checklistData  DB-driven checklist from get_checklist_for_vehicle() (null = use hardcoded)
 */
function render_inspection_form(string $type, string $mode, ?array $existingData = null, ?array $compareData = null, ?array $checklistData = null): string
{
    if ($mode !== 'full') {
        return '';
    }

    // Use DB-driven checklist or fall back to hardcoded
    if ($checklistData !== null && !empty($checklistData['categories'])) {
        return render_inspection_form_db($type, $checklistData, $existingData, $compareData);
    }

    return render_inspection_form_legacy($type, $existingData, $compareData);
}

/**
 * Render from DB-driven checklist data.
 */
function render_inspection_form_db(string $type, array $checklistData, ?array $existingData, ?array $compareData): string
{
    $totalItems = 0;
    foreach ($checklistData['categories'] as $cat) {
        foreach ($cat['items'] as $item) {
            if ($item['applies_to'] === 'both' || $item['applies_to'] === $type) {
                $totalItems++;
            }
        }
    }

    $html = '';
    // Hidden profile reference
    $html .= '<input type="hidden" name="insp_profile_id" value="' . (int)$checklistData['profile_id'] . '">';
    $html .= '<input type="hidden" name="insp_profile_name" value="' . h($checklistData['profile_name']) . '">';

    // Profile name badge
    $html .= '<div class="mb-2"><small class="text-muted">Profile: <strong>' . h($checklistData['profile_name']) . '</strong></small></div>';

    // Global "All OK" button
    $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
    $html .= '<div>';
    $html .= '<span class="badge bg-secondary inspection-progress-badge">0 / ' . $totalItems . ' items checked</span>';
    $html .= '<div class="progress mt-1" style="height:6px;width:200px;"><div class="progress-bar bg-success inspection-progress-bar" style="width:0%"></div></div>';
    $html .= '</div>';
    $html .= '<button type="button" class="btn btn-success btn-sm" id="inspAllOkBtn"><i class="bi bi-check2-all me-1"></i>All OK — No Issues Found</button>';
    $html .= '</div>';

    $accordionId = 'inspAccordion_' . $type;
    $html .= '<div class="accordion" id="' . $accordionId . '">';

    foreach ($checklistData['categories'] as $cat) {
        $catId = $cat['id'];
        $catKey = 'cat_' . $catId;
        $collapseId = 'inspCat_' . $type . '_' . $catId;
        $isOverall = (stripos($cat['name'], 'Overall') !== false);
        $icon = CHECKLIST_CATEGORY_ICONS[$cat['name']] ?? 'bi-check-circle';

        // Filter items by applies_to
        $visibleItems = array_filter($cat['items'], function ($item) use ($type) {
            return $item['applies_to'] === 'both' || $item['applies_to'] === $type;
        });
        $itemCount = count($visibleItems);
        if ($itemCount === 0) continue;

        $html .= '<div class="accordion-item">';
        $html .= '<h2 class="accordion-header">';
        $html .= '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '">';
        $html .= '<i class="' . h($icon) . ' me-2"></i>' . h($cat['name']);
        $html .= ' <span class="badge bg-secondary ms-2 insp-cat-badge" data-cat="' . h($catKey) . '">0/' . $itemCount . '</span>';
        $html .= '</button></h2>';

        $html .= '<div id="' . $collapseId . '" class="accordion-collapse collapse" data-bs-parent="#' . $accordionId . '">';
        $html .= '<div class="accordion-body">';

        if (!$isOverall) {
            $html .= '<button type="button" class="btn btn-outline-success btn-sm mb-3 insp-cat-all-ok" data-cat="' . h($catKey) . '">';
            $html .= '<i class="bi bi-check2-all me-1"></i>All items OK in ' . h($cat['name']) . '</button>';
        }

        foreach ($visibleItems as $item) {
            $fieldName = 'insp_' . $catId . '_' . $item['id'];
            $savedVal = $existingData[$fieldName] ?? '';
            $compareVal = $compareData[$fieldName] ?? null;
            // Also check legacy format keys for backward compatibility
            if (empty($savedVal) && $existingData) {
                foreach ($existingData as $k => $v) {
                    if ($v !== '' && stripos($k, 'insp_') === 0) {
                        // Legacy data uses text keys; no reliable mapping here
                        break;
                    }
                }
            }
            $changed = ($compareVal !== null && $compareVal === 'yes' && $savedVal === 'no');

            $isComments = $isOverall && (stripos($item['label'], 'comment') !== false || stripos($item['label'], 'observation') !== false);

            if ($isComments) {
                $html .= '<div class="mb-3">';
                $html .= '<label class="form-label"><strong>' . h($item['label']) . '</strong></label>';
                $html .= '<textarea name="' . h($fieldName) . '" class="form-control" rows="3" placeholder="Any additional notes...">' . h($savedVal) . '</textarea>';
                $html .= '</div>';
            } else {
                $safetyLabel = '';
                if ($item['is_safety_critical']) {
                    $safetyLabel = ' <span class="text-danger" title="Safety Critical">*</span> <small class="text-danger">(Safety Critical)</small>';
                }

                $html .= '<div class="mb-2 insp-item' . ($changed ? ' border-start border-3 border-danger ps-2' : '') . '" data-cat="' . h($catKey) . '" data-item="' . h($fieldName) . '"' . ($item['is_safety_critical'] ? ' data-safety-critical="1"' : '') . '>';
                $html .= '<div class="d-flex align-items-center flex-wrap gap-2">';
                $html .= '<span class="flex-grow-1">' . h($item['label']) . $safetyLabel . '</span>';

                foreach (['yes' => 'Yes', 'no' => 'No', 'na' => 'N/A'] as $val => $label) {
                    $checked = ($savedVal === $val) ? ' checked' : '';
                    $radioId = $fieldName . '_' . $val;
                    $btnClass = $val === 'yes' ? 'btn-outline-success' : ($val === 'no' ? 'btn-outline-danger' : 'btn-outline-secondary');
                    $html .= '<input type="radio" class="btn-check insp-radio" name="' . h($fieldName) . '" id="' . h($radioId) . '" value="' . h($val) . '" autocomplete="off"' . $checked . '>';
                    $html .= '<label class="btn ' . $btnClass . ' btn-sm px-3" for="' . h($radioId) . '">' . $label . '</label>';
                }

                if ($changed) {
                    $html .= '<span class="badge bg-danger ms-1">Changed</span>';
                }

                $html .= '</div></div>';
            }
        }

        $html .= '</div></div></div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Render from legacy hardcoded checklist (backward compatibility).
 */
function render_inspection_form_legacy(string $type, ?array $existingData, ?array $compareData): string
{
    $checklist = INSPECTION_CHECKLIST;
    $totalItems = 0;
    foreach ($checklist as $cat) {
        $totalItems += count($cat['items']);
    }

    $html = '';

    $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
    $html .= '<div>';
    $html .= '<span class="badge bg-secondary inspection-progress-badge">0 / ' . $totalItems . ' items checked</span>';
    $html .= '<div class="progress mt-1" style="height:6px;width:200px;"><div class="progress-bar bg-success inspection-progress-bar" style="width:0%"></div></div>';
    $html .= '</div>';
    $html .= '<button type="button" class="btn btn-success btn-sm" id="inspAllOkBtn"><i class="bi bi-check2-all me-1"></i>All OK — No Issues Found</button>';
    $html .= '</div>';

    $accordionId = 'inspAccordion_' . $type;
    $html .= '<div class="accordion" id="' . $accordionId . '">';

    foreach ($checklist as $catKey => $cat) {
        $collapseId = 'inspCat_' . $type . '_' . $catKey;
        $isOverall = ($catKey === 'overall');
        $itemCount = count($cat['items']);

        $html .= '<div class="accordion-item">';
        $html .= '<h2 class="accordion-header">';
        $html .= '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '">';
        $html .= '<i class="' . h($cat['icon']) . ' me-2"></i>' . h($cat['label']);
        $html .= ' <span class="badge bg-secondary ms-2 insp-cat-badge" data-cat="' . h($catKey) . '">0/' . $itemCount . '</span>';
        $html .= '</button></h2>';

        $html .= '<div id="' . $collapseId . '" class="accordion-collapse collapse" data-bs-parent="#' . $accordionId . '">';
        $html .= '<div class="accordion-body">';

        if (!$isOverall) {
            $html .= '<button type="button" class="btn btn-outline-success btn-sm mb-3 insp-cat-all-ok" data-cat="' . h($catKey) . '">';
            $html .= '<i class="bi bi-check2-all me-1"></i>All items OK in ' . h($cat['label']) . '</button>';
        }

        foreach ($cat['items'] as $itemKey => $itemLabel) {
            $fieldName = 'insp_' . $catKey . '_' . $itemKey;
            $savedVal = $existingData[$fieldName] ?? '';
            $compareVal = $compareData[$fieldName] ?? null;
            $changed = ($compareVal !== null && $compareVal === 'yes' && $savedVal === 'no');

            if ($isOverall && $itemKey === 'overall_comments') {
                $html .= '<div class="mb-3">';
                $html .= '<label class="form-label"><strong>' . h($itemLabel) . '</strong></label>';
                $html .= '<textarea name="' . h($fieldName) . '" class="form-control" rows="3" placeholder="Any additional notes...">' . h($savedVal) . '</textarea>';
                $html .= '</div>';
            } else {
                $html .= '<div class="mb-2 insp-item' . ($changed ? ' border-start border-3 border-danger ps-2' : '') . '" data-cat="' . h($catKey) . '" data-item="' . h($fieldName) . '">';
                $html .= '<div class="d-flex align-items-center flex-wrap gap-2">';
                $html .= '<span class="flex-grow-1">' . h($itemLabel) . '</span>';

                foreach (['yes' => 'Yes', 'no' => 'No', 'na' => 'N/A'] as $val => $label) {
                    $checked = ($savedVal === $val) ? ' checked' : '';
                    $radioId = $fieldName . '_' . $val;
                    $btnClass = $val === 'yes' ? 'btn-outline-success' : ($val === 'no' ? 'btn-outline-danger' : 'btn-outline-secondary');
                    $html .= '<input type="radio" class="btn-check insp-radio" name="' . h($fieldName) . '" id="' . h($radioId) . '" value="' . h($val) . '" autocomplete="off"' . $checked . '>';
                    $html .= '<label class="btn ' . $btnClass . ' btn-sm px-3" for="' . h($radioId) . '">' . $label . '</label>';
                }

                if ($changed) {
                    $html .= '<span class="badge bg-danger ms-1">Changed</span>';
                }

                $html .= '</div></div>';
            }
        }

        $html .= '</div></div></div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Render JavaScript for inspection form interactivity.
 */
function render_inspection_js(string $mode): string
{
    if ($mode !== 'full') {
        return '';
    }

    return <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function() {
    const radios = document.querySelectorAll('.insp-radio');
    const allOkBtn = document.getElementById('inspAllOkBtn');
    const catAllOkBtns = document.querySelectorAll('.insp-cat-all-ok');

    function updateProgress() {
        const allItems = document.querySelectorAll('.insp-item');
        let total = allItems.length;
        let checked = 0;
        const catCounts = {};

        allItems.forEach(function(item) {
            const cat = item.dataset.cat;
            if (!catCounts[cat]) catCounts[cat] = {total: 0, done: 0};
            catCounts[cat].total++;
            const name = item.dataset.item;
            const selected = document.querySelector('input[name="' + name + '"]:checked');
            if (selected) { checked++; catCounts[cat].done++; }
        });

        const pct = total > 0 ? Math.round((checked / total) * 100) : 0;
        const bar = document.querySelector('.inspection-progress-bar');
        const badge = document.querySelector('.inspection-progress-badge');
        if (bar) bar.style.width = pct + '%';
        if (badge) badge.textContent = checked + ' / ' + total + ' items checked';

        Object.keys(catCounts).forEach(function(cat) {
            const b = document.querySelector('.insp-cat-badge[data-cat="' + cat + '"]');
            if (b) {
                b.textContent = catCounts[cat].done + '/' + catCounts[cat].total;
                b.className = 'badge ms-2 insp-cat-badge ' +
                    (catCounts[cat].done === catCounts[cat].total ? 'bg-success' : 'bg-secondary');
            }
        });
    }

    radios.forEach(function(r) { r.addEventListener('change', updateProgress); });

    if (allOkBtn) {
        allOkBtn.addEventListener('click', function() {
            if (!confirm('Mark ALL inspection items as OK (Yes)?')) return;
            document.querySelectorAll('.insp-item').forEach(function(item) {
                const name = item.dataset.item;
                const yesRadio = document.querySelector('input[name="' + name + '"][value="yes"]');
                if (yesRadio) yesRadio.checked = true;
            });
            updateProgress();
        });
    }

    catAllOkBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const cat = this.dataset.cat;
            document.querySelectorAll('.insp-item[data-cat="' + cat + '"]').forEach(function(item) {
                const name = item.dataset.item;
                const yesRadio = document.querySelector('input[name="' + name + '"][value="yes"]');
                if (yesRadio) yesRadio.checked = true;
            });
            updateProgress();
        });
    });

    updateProgress();
});
</script>
JS;
}

/**
 * Save full inspection response to DB.
 * Now includes checklist profile metadata for traceability.
 */
function save_inspection_response(PDO $pdo, int $reservationId, string $type, string $inspectorEmail, array $data, ?array $checklistData = null): int
{
    // Enrich with profile metadata
    if ($checklistData) {
        $data['_profile_id'] = $checklistData['profile_id'];
        $data['_profile_name'] = $checklistData['profile_name'];
    }

    // Check for safety-critical failures
    if ($checklistData) {
        $safetyFailures = validate_inspection_safety($data, $checklistData);
        if (!empty($safetyFailures)) {
            $data['_safety_critical_failures'] = $safetyFailures;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO inspection_responses (reservation_id, inspection_type, inspector_email, response_data)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE response_data = VALUES(response_data), inspector_email = VALUES(inspector_email)
    ");
    $stmt->execute([$reservationId, $type, $inspectorEmail, json_encode($data)]);
    return (int)$pdo->lastInsertId();
}

/**
 * Retrieve saved inspection response.
 */
function get_inspection_response(PDO $pdo, int $reservationId, string $type): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM inspection_responses WHERE reservation_id = ? AND inspection_type = ? LIMIT 1");
    $stmt->execute([$reservationId, $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['response_data'] = json_decode($row['response_data'] ?? '{}', true) ?: [];
    return $row;
}

/**
 * Validate safety-critical items. Returns array of failed item labels.
 */
function validate_inspection_safety(array $data, ?array $checklistData): array
{
    if (!$checklistData || empty($checklistData['categories'])) return [];

    $failures = [];
    foreach ($checklistData['categories'] as $cat) {
        foreach ($cat['items'] as $item) {
            if (!$item['is_safety_critical']) continue;
            $fieldName = 'insp_' . $cat['id'] . '_' . $item['id'];
            $val = $data[$fieldName] ?? '';
            if ($val === 'no') {
                $failures[] = $item['label'];
            }
        }
    }
    return $failures;
}

/**
 * Validate inspection completeness. Returns array of warnings (empty = all OK).
 * Handles both legacy (hardcoded) and DB-driven formats.
 */
function validate_inspection_response(array $data, string $mode, ?array $checklistData = null): array
{
    if ($mode !== 'full') return [];

    $warnings = [];
    $noItems = [];

    if ($checklistData && !empty($checklistData['categories'])) {
        // DB-driven
        foreach ($checklistData['categories'] as $cat) {
            foreach ($cat['items'] as $item) {
                $fieldName = 'insp_' . $cat['id'] . '_' . $item['id'];
                if (($data[$fieldName] ?? '') === 'no') {
                    $noItems[] = $cat['name'] . ': ' . $item['label'];
                }
            }
        }
    } else {
        // Legacy hardcoded
        foreach (INSPECTION_CHECKLIST as $catKey => $cat) {
            if ($catKey === 'overall') continue;
            foreach ($cat['items'] as $itemKey => $itemLabel) {
                $fieldName = 'insp_' . $catKey . '_' . $itemKey;
                if (($data[$fieldName] ?? '') === 'no') {
                    $noItems[] = $cat['label'] . ': ' . $itemLabel;
                }
            }
        }
    }

    if (!empty($noItems)) {
        $warnings[] = count($noItems) . ' item(s) marked as deficient: ' . implode('; ', array_slice($noItems, 0, 5));
        if (count($noItems) > 5) {
            $warnings[0] .= ' (and ' . (count($noItems) - 5) . ' more)';
        }
    }
    return $warnings;
}

/**
 * Render JavaScript for safety-critical checkout warning.
 * Intercepts form submit to check for safety failures.
 */
function render_safety_critical_js(): string
{
    return <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (!form) return;

    // Find safety-critical items
    const safetyItems = document.querySelectorAll('.insp-item[data-safety-critical="1"]');
    if (safetyItems.length === 0) return;

    // Create the safety warning modal
    const modalHtml = `
    <div class="modal fade" id="safetyWarningModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Safety-Critical Issues Found</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="safetyFailureList"></div>
            <div class="alert alert-danger mt-3 mb-0">
              <strong>These items are safety-critical.</strong> You should NOT operate this vehicle with these deficiencies.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i>Go Back
            </button>
            <button type="button" class="btn btn-danger" id="safetyProceedBtn">
              <i class="bi bi-exclamation-triangle me-1"></i>Proceed Anyway (acknowledge risk)
            </button>
          </div>
        </div>
      </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    let safetyAcknowledged = false;

    window._checkSafetyCritical = function() {
        if (safetyAcknowledged) return true;

        const failures = [];
        safetyItems.forEach(function(item) {
            const name = item.dataset.item;
            const selected = document.querySelector('input[name="' + name + '"]:checked');
            if (selected && selected.value === 'no') {
                const label = item.querySelector('.flex-grow-1');
                failures.push(label ? label.textContent.replace(/\s*\*\s*\(Safety Critical\)/, '').trim() : name);
            }
        });

        if (failures.length === 0) return true;

        const listHtml = '<ul class="mb-0">' + failures.map(f => '<li><strong>' + f + ':</strong> <span class="text-danger">FAILED</span></li>').join('') + '</ul>';
        document.getElementById('safetyFailureList').innerHTML = listHtml;

        // Add hidden input for acknowledged risk
        const modal = new bootstrap.Modal(document.getElementById('safetyWarningModal'));
        modal.show();
        return false;
    };

    document.getElementById('safetyProceedBtn')?.addEventListener('click', function() {
        safetyAcknowledged = true;
        // Add hidden field to indicate risk acknowledgement
        let ackInput = form.querySelector('input[name="acknowledged_safety_risk"]');
        if (!ackInput) {
            ackInput = document.createElement('input');
            ackInput.type = 'hidden';
            ackInput.name = 'acknowledged_safety_risk';
            form.appendChild(ackInput);
        }
        ackInput.value = '1';
        bootstrap.Modal.getInstance(document.getElementById('safetyWarningModal')).hide();
        form.requestSubmit();
    });
});
</script>
JS;
}
