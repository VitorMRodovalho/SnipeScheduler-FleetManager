<?php
/**
 * Multi-Entity Fleet Partitioning
 * Filters vehicles by company assignment when multiple companies exist.
 *
 * When disabled or single-company: zero changes to behavior.
 * Fleet Admin / Super Admin always see all companies.
 * Users with no company assigned see all vehicles (backward compatible).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/snipeit_client.php';

/**
 * Check if multi-company mode is active.
 *
 * system_settings key: 'multi_company_mode'
 *   'auto' (default) — enabled when Snipe-IT has >1 company
 *   'on'             — always enabled
 *   'off'            — always disabled
 *
 * @param PDO $pdo Database connection
 * @return bool
 */
function is_multi_company_enabled(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute(['multi_company_mode']);
    $mode = $stmt->fetchColumn() ?: 'auto';

    if ($mode === 'off') {
        return false;
    }

    if ($mode === 'on') {
        return true;
    }

    // Auto mode: check how many companies exist in Snipe-IT
    $companies = get_all_companies();
    return count($companies) > 1;
}

/**
 * Return the user's company array from session data.
 *
 * @param array $sessionUser $_SESSION['user']
 * @return array|null Company array {id, name} or null
 */
function get_user_company(array $sessionUser): ?array
{
    return $sessionUser['company'] ?? null;
}

/**
 * Return array of company IDs the user can access.
 *
 * - Fleet Admin / Super Admin: all companies (full fleet visibility)
 * - Regular users: their assigned company only
 * - Users with no company: empty array (means "see all" — backward compatible)
 *
 * @param array $sessionUser $_SESSION['user']
 * @return int[] Company IDs, or empty array for "no filtering"
 */
function get_user_company_ids(array $sessionUser): array
{
    // Admins always see all companies
    if (!empty($sessionUser['is_admin']) || !empty($sessionUser['is_super_admin'])) {
        return []; // empty = no filtering = full fleet
    }

    $companyId = $sessionUser['company_id'] ?? null;

    // No company assigned — don't lock them out
    if (!$companyId) {
        return [];
    }

    return [(int)$companyId];
}

/**
 * Filter an array of Snipe-IT assets by company ID.
 *
 * @param array $assets   Array of asset rows from Snipe-IT API
 * @param int[] $companyIds Allowed company IDs. Empty = return all (no filtering).
 * @return array Filtered assets
 */
function filter_assets_by_company(array $assets, array $companyIds): array
{
    if (empty($companyIds)) {
        return $assets;
    }

    return array_values(array_filter($assets, function ($asset) use ($companyIds) {
        $assetCompanyId = $asset['company']['id'] ?? null;
        // Assets with no company are visible to everyone
        if ($assetCompanyId === null) {
            return true;
        }
        return in_array((int)$assetCompanyId, $companyIds, true);
    }));
}

/**
 * Generate a compact company badge HTML for an asset.
 * Uses Snipe-IT company tag_color and notes as source of truth.
 * - notes = badge text (abbreviation)
 * - tag_color = badge background color
 * Returns empty string if multi-company is disabled or asset has no company.
 */
function get_company_badge($asset, $pdo = null): string
{
    static $enabled = null;
    static $companyMeta = [];

    if ($enabled === null) {
        $enabled = $pdo ? is_multi_company_enabled($pdo) : true;
        if ($enabled) {
            // Cache company metadata (tag_color, notes) from API
            $companies = get_all_companies();
            foreach ($companies as $co) {
                $companyMeta[(int)$co['id']] = $co;
            }
        }
    }
    if (!$enabled) return '';

    $company = $asset['company'] ?? null;
    if (!$company || empty($company['name'])) return '';

    $companyId = (int)($company['id'] ?? 0);
    $meta = $companyMeta[$companyId] ?? [];

    // Badge text: use notes field, fallback to abbreviation extraction
    $abbr = trim($meta['notes'] ?? '');
    if ($abbr === '') {
        $name = $company['name'];
        if (preg_match('/\(([A-Z0-9]{1,5})\)\s*$/', $name, $m)) {
            $abbr = $m[1];
        } else {
            $words = preg_split('/[\s&]+/', $name);
            $abbr = '';
            foreach ($words as $w) {
                $w = trim($w);
                if ($w !== '' && ctype_alpha($w[0])) $abbr .= strtoupper($w[0]);
                if (strlen($abbr) >= 3) break;
            }
        }
    }
    if ($abbr === '') return '';

    // Badge color: use tag_color field, fallback to theme colors
    $bgColor = trim($meta['tag_color'] ?? '');
    if ($bgColor !== '' && $bgColor[0] === '#') {
        // Determine text color based on brightness
        $hex = ltrim($bgColor, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
        $textColor = $brightness > 128 ? '#000' : '#fff';
        return ' <span class="badge ms-1" style="font-size:0.7em;background-color:' . h($bgColor) . ';color:' . $textColor . ';" title="' . h($company['name']) . '">' . h($abbr) . '</span>';
    }

    // Fallback: Bootstrap color cycle
    $colors = ['info', 'primary', 'success', 'warning', 'secondary'];
    $color = $colors[$companyId % count($colors)];
    $textClass = $color === 'warning' ? 'text-dark' : 'text-white';
    return ' <span class="badge bg-' . $color . ' ' . $textClass . ' ms-1" style="font-size:0.7em;" title="' . h($company['name']) . '">' . h($abbr) . '</span>';
}

/**
 * Generate a company badge from a DB row (reservation or similar).
 * Reads company_abbr and company_color stored at booking time.
 * Returns empty string if data is missing.
 */
function get_company_badge_from_row(array $row): string
{
    $abbr  = trim($row['company_abbr'] ?? '');
    $color = trim($row['company_color'] ?? '');
    $name  = trim($row['company_name'] ?? '');

    if ($abbr === '') {
        return '';
    }

    if ($color !== '' && $color[0] === '#') {
        $hex = ltrim($color, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
        $textColor = $brightness > 128 ? '#000' : '#fff';
        return ' <span class="badge ms-1" style="font-size:0.7em;background-color:' . h($color) . ';color:' . $textColor . ';" title="' . h($name) . '">' . h($abbr) . '</span>';
    }

    // Fallback: Bootstrap muted badge
    return ' <span class="badge bg-secondary text-white ms-1" style="font-size:0.7em;" title="' . h($name) . '">' . h($abbr) . '</span>';
}

/**
 * Fetch all companies from Snipe-IT with 5-minute cache.
 *
 * @return array Array of ['id' => int, 'name' => string]
 */
function get_all_companies(): array
{
    static $cached = null;
    static $cachedAt = 0;

    $ttl = 300; // 5 minutes

    if ($cached !== null && (time() - $cachedAt) < $ttl) {
        return $cached;
    }

    // Also check filesystem cache
    $cacheFile = (defined('CONFIG_PATH') ? CONFIG_PATH : __DIR__ . '/../config') . '/cache/companies_list.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) {
            $cached = $data;
            $cachedAt = time();
            return $cached;
        }
    }

    $rows = get_companies();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id'        => (int)($row['id'] ?? 0),
            'name'      => (string)($row['name'] ?? ''),
            'tag_color' => (string)($row['tag_color'] ?? ''),
            'notes'     => (string)($row['notes'] ?? ''),
        ];
    }

    // Write cache
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, json_encode($result), LOCK_EX);

    $cached = $result;
    $cachedAt = time();

    return $result;
}

/**
 * Get the multi-company mode setting value.
 *
 * @param PDO $pdo
 * @return string 'auto', 'on', or 'off'
 */
function get_multi_company_mode(PDO $pdo): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute(['multi_company_mode']);
    $mode = $stmt->fetchColumn();
    return in_array($mode, ['auto', 'on', 'off'], true) ? $mode : 'auto';
}
