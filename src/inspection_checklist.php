<?php
/**
 * BL-006: Configurable Inspection Checklist
 * Supports Quick (existing Snipe-IT fields), Full (50-item checklist), and Off modes.
 *
 * @since v2.1.0
 */

/**
 * Full 50-item inspection checklist organized by category.
 * Each item has a unique key, label, and category assignment.
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

/**
 * Render the full 50-item inspection form as a Bootstrap accordion.
 *
 * @param string     $type         'checkout' or 'checkin'
 * @param string     $mode         'full' (only renders for full; quick/off handled elsewhere)
 * @param array|null $existingData Previously saved data (for re-display or checkin comparison)
 * @param array|null $compareData  Checkout data to compare against during checkin
 */
function render_inspection_form(string $type, string $mode, ?array $existingData = null, ?array $compareData = null): string
{
    if ($mode !== 'full') {
        return '';
    }

    $checklist = INSPECTION_CHECKLIST;
    $totalItems = 0;
    foreach ($checklist as $cat) {
        $totalItems += count($cat['items']);
    }

    $html = '';

    // Global "All OK" button
    $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
    $html .= '<div>';
    $html .= '<span class="badge bg-secondary inspection-progress-badge">0 / ' . $totalItems . ' items checked</span>';
    $html .= '<div class="progress mt-1" style="height:6px;width:200px;"><div class="progress-bar bg-success inspection-progress-bar" style="width:0%"></div></div>';
    $html .= '</div>';
    $html .= '<button type="button" class="btn btn-success btn-sm" id="inspAllOkBtn"><i class="bi bi-check2-all me-1"></i>All OK — No Issues Found</button>';
    $html .= '</div>';

    // Accordion
    $accordionId = 'inspAccordion_' . $type;
    $html .= '<div class="accordion" id="' . $accordionId . '">';

    $catIndex = 0;
    foreach ($checklist as $catKey => $cat) {
        $catIndex++;
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

        // Per-category All OK
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
                // Free text
                $html .= '<div class="mb-3">';
                $html .= '<label class="form-label"><strong>' . h($itemLabel) . '</strong></label>';
                $html .= '<textarea name="' . h($fieldName) . '" class="form-control" rows="3" placeholder="Any additional notes...">' . h($savedVal) . '</textarea>';
                $html .= '</div>';
            } else {
                $html .= '<div class="mb-2 insp-item' . ($changed ? ' border-start border-3 border-danger ps-2' : '') . '" data-cat="' . h($catKey) . '" data-item="' . h($fieldName) . '">';
                $html .= '<div class="d-flex align-items-center flex-wrap gap-2">';
                $html .= '<span class="flex-grow-1">' . h($itemLabel) . '</span>';

                // Yes / No / N/A radio buttons — large touch targets
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

        $html .= '</div></div></div>'; // accordion-body, collapse, item
    }

    $html .= '</div>'; // accordion

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

        // Update global progress
        const pct = total > 0 ? Math.round((checked / total) * 100) : 0;
        const bar = document.querySelector('.inspection-progress-bar');
        const badge = document.querySelector('.inspection-progress-badge');
        if (bar) bar.style.width = pct + '%';
        if (badge) badge.textContent = checked + ' / ' + total + ' items checked';

        // Update per-category badges
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

    // Global All OK
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

    // Per-category All OK
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

    // Initial count
    updateProgress();
});
</script>
JS;
}

/**
 * Save full inspection response to DB.
 */
function save_inspection_response(PDO $pdo, int $reservationId, string $type, string $inspectorEmail, array $data): int
{
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
 * Validate inspection completeness. Returns array of warnings (empty = all OK).
 * Not enforced — just advisory.
 */
function validate_inspection_response(array $data, string $mode): array
{
    if ($mode !== 'full') return [];

    $warnings = [];
    $noItems = [];
    foreach (INSPECTION_CHECKLIST as $catKey => $cat) {
        if ($catKey === 'overall') continue;
        foreach ($cat['items'] as $itemKey => $itemLabel) {
            $fieldName = 'insp_' . $catKey . '_' . $itemKey;
            $val = $data[$fieldName] ?? '';
            if ($val === 'no') {
                $noItems[] = $cat['label'] . ': ' . $itemLabel;
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
