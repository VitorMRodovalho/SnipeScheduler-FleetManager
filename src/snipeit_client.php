<?php
// snipeit_client.php
//
// Thin client for talking to the Snipe-IT API.
// Uses config.php for base URL, API token and SSL verification settings.
//
// Exposes:
//   - get_bookable_models($page, $search, $categoryId, $sort, $perPage, $allowedCategoryIds)
//   - get_model_categories()
//   - get_model($id)
//   - get_model_hardware_count($modelId)

require_once __DIR__ . '/bootstrap.php';

$config       = load_config();
$snipeConfig  = $config['snipeit'] ?? [];

$snipeBaseUrl   = rtrim($snipeConfig['base_url'] ?? '', '/');
$snipeApiToken  = $snipeConfig['api_token'] ?? '';
$snipeVerifySsl = !empty($snipeConfig['verify_ssl']);
$cacheTtl       = isset($config['app']['api_cache_ttl_seconds'])
    ? max(0, (int)$config['app']['api_cache_ttl_seconds'])
    : 60;
$cacheDir       = CONFIG_PATH . '/cache';

$limit = 200;

function snipeit_cache_path(string $key): string
{
    global $cacheDir;
    return rtrim($cacheDir, '/\\') . '/' . $key . '.json';
}

function snipeit_cache_get(string $key, int $ttl)
{
    $path = snipeit_cache_path($key);
    if ($ttl <= 0 || !is_file($path)) {
        return null;
    }
    $age = time() - (int)@filemtime($path);
    if ($age > $ttl) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function snipeit_cache_set(string $key, array $data): void
{
    global $cacheDir;
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $path = snipeit_cache_path($key);
    @file_put_contents($path, json_encode($data), LOCK_EX);
}

/**
 * Core HTTP wrapper for Snipe-IT API.
 *
 * @param string $method   HTTP method (GET, POST, etc.)
 * @param string $endpoint Relative endpoint, e.g. "models" or "models/5"
 * @param array  $params   Query/body params
 * @return array           Decoded JSON response
 * @throws Exception       On HTTP or decode errors
 */
function snipeit_request(string $method, string $endpoint, array $params = []): array
{
    global $snipeBaseUrl, $snipeApiToken, $snipeVerifySsl, $cacheTtl;

    if ($snipeBaseUrl === '' || $snipeApiToken === '') {
        throw new Exception('Snipe-IT API is not configured (missing base_url or api_token).');
    }

    $url = $snipeBaseUrl . '/api/v1/' . ltrim($endpoint, '/');

    $method = strtoupper($method);
    $cacheKey = null;

    // Simple GET cache to reduce repeated hits
    if ($method === 'GET' && $cacheTtl > 0) {
        $cacheKey = sha1($url . '|' . json_encode($params));
        $cached = snipeit_cache_get($cacheKey, $cacheTtl);
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = curl_init();
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $snipeApiToken,
    ];

    if ($method === 'GET') {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => $snipeVerifySsl,
        CURLOPT_SSL_VERIFYHOST => $snipeVerifySsl ? 2 : 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Error talking to Snipe-IT API: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);

    if ($httpCode >= 400) {
        $msg = $decoded['message'] ?? $raw;
        throw new Exception('Snipe-IT API returned HTTP ' . $httpCode . ': ' . $msg);
    }

    if (!is_array($decoded)) {
        throw new Exception('Invalid JSON from Snipe-IT API');
    }

    if ($cacheKey !== null && $cacheTtl > 0) {
        snipeit_cache_set($cacheKey, $decoded);
    }

    return $decoded;
}

/**
 * Fetch **all** matching models from Snipe-IT,
 * then sort them as requested, then paginate locally.
 *
 * Sort options:
 *   - manu_asc / manu_desc      (manufacturer)
 *   - name_asc / name_desc      (model name)
 *   - units_asc / units_desc    (assets_count)
 *
 * @param int         $page
 * @param string      $search
 * @param int|null    $categoryId
 * @param string|null $sort
 * @param int         $perPage
 * @param array       $allowedCategoryIds Optional allowlist; if provided, only models in these category IDs are returned.
 * @return array                  ['total' => X, 'rows' => [...]]
 * @throws Exception
 */
function get_bookable_models(
    int $page = 1,
    string $search = '',
    ?int $categoryId = null,
    ?string $sort = null,
    int $perPage = 50,
    array $allowedCategoryIds = []
): array {
    $page    = max(1, $page);
    $perPage = max(1, $perPage);
    $allowedMap = [];
    foreach ($allowedCategoryIds as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $allowedMap[(int)$cid] = true;
        }
    }

    // If an allowlist exists and the requested category is not allowed, clear it to avoid wasted calls.
    $effectiveCategory = $categoryId;
    if (!empty($allowedMap) && $categoryId !== null && !isset($allowedMap[$categoryId])) {
        $effectiveCategory = null;
    }

    $limit  = 200; // per-API-call limit
    $allRows = [];

    $offset = 0;
    // Pull pages from Snipe-IT until we have everything.
    do {
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        if ($search !== '') {
            $params['search'] = $search;
        }

        if (!empty($effectiveCategory)) {
            $params['category_id'] = $effectiveCategory;
        }

        $chunk = snipeit_request('GET', 'models', $params);

        if (!isset($chunk['rows']) || !is_array($chunk['rows'])) {
            break;
        }

        $rows    = $chunk['rows'];
        $allRows = array_merge($allRows, $rows);

        $fetchedThisCall = count($rows);
        $offset += $limit;

        // Stop if we didn't get a full page (end of data).
        if ($fetchedThisCall < $limit) {
            break;
        }
    } while (true);

    // Filter by requestable flag (Snipe-IT uses 'requestable' on models)
    $allRows = array_values(array_filter($allRows, function ($row) {
        return !empty($row['requestable']);
    }));

    // Apply optional category allowlist (overrides requestable-only default scope)
    if (!empty($allowedMap)) {
        $allRows = array_values(array_filter($allRows, function ($row) use ($allowedMap) {
            $cid = isset($row['category']['id']) ? (int)$row['category']['id'] : 0;
            return $cid > 0 && isset($allowedMap[$cid]);
        }));
    }

    // Determine total after filtering
    $total = count($allRows);

    // Sort full set client-side according to requested sort
    $sort = $sort ?? '';

    usort($allRows, function ($a, $b) use ($sort) {
        $nameA  = $a['name'] ?? '';
        $nameB  = $b['name'] ?? '';
        $manA   = $a['manufacturer']['name'] ?? '';
        $manB   = $b['manufacturer']['name'] ?? '';
        $unitsA = isset($a['assets_count']) ? (int)$a['assets_count'] : 0;
        $unitsB = isset($b['assets_count']) ? (int)$b['assets_count'] : 0;

        switch ($sort) {
            case 'manu_asc':
                return strcasecmp($manA, $manB);
            case 'manu_desc':
                return strcasecmp($manB, $manA);

            case 'name_desc':
                return strcasecmp($nameB, $nameA);
            case 'name_asc':
            case '':
                return strcasecmp($nameA, $nameB);

            case 'units_asc':
                if ($unitsA === $unitsB) {
                    return strcasecmp($nameA, $nameB);
                }
                return ($unitsA <=> $unitsB);

            case 'units_desc':
                if ($unitsA === $unitsB) {
                    return strcasecmp($nameA, $nameB);
                }
                return ($unitsB <=> $unitsA);

            default:
                return strcasecmp($nameA, $nameB);
        }
    });

    // Local pagination
    $offsetLocal = ($page - 1) * $perPage;
    $rowsPage    = array_slice($allRows, $offsetLocal, $perPage);

    return [
        'total' => $total,
        'rows'  => $rowsPage,
    ];
}

/**
 * Fetch all model categories from Snipe-IT.
 * Always returned A–Z by name (client-side sort).
 *
 * @return array
 * @throws Exception
 */
function get_model_categories(): array
{
    $params = [
        'limit' => 500,
    ];

    $data = snipeit_request('GET', 'categories', $params);

    if (!isset($data['rows']) || !is_array($data['rows'])) {
        return [];
    }

    $rows = $data['rows'];
    // Keep only categories that have at least one requestable model if API returns requestable_count
    $rows = array_values(array_filter($rows, function ($row) {
        if (isset($row['requestable_count']) && is_numeric($row['requestable_count'])) {
            return (int)$row['requestable_count'] > 0;
        }
        return true;
    }));

    usort($rows, function ($a, $b) {
        $na = $a['name'] ?? '';
        $nb = $b['name'] ?? '';
        return strcasecmp($na, $nb);
    });

    return $rows;
}

/**
 * Fetch a single model by ID.
 *
 * @param int $modelId
 * @return array
 * @throws Exception
 */
function get_model(int $modelId): array
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Invalid model ID');
    }

    return snipeit_request('GET', 'models/' . $modelId);
}

/**
 * Get the number of hardware assets for a given model.
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */

function get_model_hardware_count(int $modelId): int
{
    $model = get_model($modelId);

    if (isset($model['assets_count']) && is_numeric($model['assets_count'])) {
        return (int)$model['assets_count'];
    }

    if (isset($model['assets_count_total']) && is_numeric($model['assets_count_total'])) {
        return (int)$model['assets_count_total'];
    }

    return 0;
}

/**
 * Find a single asset by asset_tag.
 *
 * This uses the /hardware endpoint with a search, then looks for an
 * exact asset_tag match. It does NOT rely on /hardware/bytag so it
 * stays compatible across Snipe-IT versions.
 *
 * @param string $tag
 * @return array
 * @throws Exception if no or ambiguous match
 */
function find_asset_by_tag(string $tag): array
{
    $tagTrim = trim($tag);
    if ($tagTrim === '') {
        throw new InvalidArgumentException('Asset tag cannot be empty.');
    }

    // Search hardware with a small limit
    $params = [
        'search' => $tagTrim,
        'limit'  => 50,
    ];

    $data = snipeit_request('GET', 'hardware', $params);
    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No assets found in Snipe-IT matching tag '{$tagTrim}'.");
    }

    // Look for an exact asset_tag match (case-insensitive)
    $exactMatches = [];
    foreach ($data['rows'] as $row) {
        $rowTag = $row['asset_tag'] ?? '';
        if (strcasecmp(trim($rowTag), $tagTrim) === 0) {
            $exactMatches[] = $row;
        }
    }

    if (count($exactMatches) === 1) {
        return $exactMatches[0];
    }

    if (count($exactMatches) > 1) {
        throw new Exception("Multiple assets found with asset_tag '{$tagTrim}'. Please disambiguate in Snipe-IT.");
    }

    // No exact matches, but we got some approximate results
    // You can choose to accept the first or to treat as "not found".
    // Here we treat as not found to avoid wrong checkouts.
    throw new Exception("No exact asset_tag match for '{$tagTrim}' in Snipe-IT.");
}

/**
 * Search assets by tag or name (Snipe-IT hardware search).
 *
 * @param string $query
 * @param int $limit
 * @param bool $requestableOnly
 * @return array
 * @throws Exception
 */
function search_assets(string $query, int $limit = 20, bool $requestableOnly = false): array
{
    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $params = [
        'search' => $q,
        'limit'  => max(1, min(50, $limit)),
    ];

    $data = snipeit_request('GET', 'hardware', $params);
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];

    $rows = array_values(array_filter($rows, function ($row) use ($requestableOnly) {
        $tag = $row['asset_tag'] ?? '';
        if ($tag === '') {
            return false;
        }
        if ($requestableOnly && empty($row['requestable'])) {
            return false;
        }
        return true;
    }));

    return $rows;
}

/**
 * List hardware assets for a given model.
 *
 * @param int $modelId
 * @param int $maxResults
 * @return array
 * @throws Exception
 */
function list_assets_by_model(int $modelId, int $maxResults = 300): array
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }

    $all    = [];
    $limit  = min(200, max(1, $maxResults));
    $offset = 0;

    do {
        $params = [
            'model_id' => $modelId,
            'limit'    => $limit,
            'offset'   => $offset,
        ];

        $chunk = snipeit_request('GET', 'hardware', $params);
        $rows  = isset($chunk['rows']) && is_array($chunk['rows']) ? $chunk['rows'] : [];

        $all    = array_merge($all, $rows);
        $count  = count($rows);
        $offset += $limit;

        if ($count < $limit || count($all) >= $maxResults) {
            break;
        }
    } while (true);

    return $all;
}

/**
 * Count requestable assets for a model (asset-level requestable flag).
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */
function count_requestable_assets_by_model(int $modelId): int
{
    static $cache = [];
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }
    if (isset($cache[$modelId])) {
        return $cache[$modelId];
    }

    $assets = list_assets_by_model($modelId, 500);
    $count  = 0;

    foreach ($assets as $a) {
        if (!empty($a['requestable'])) {
            $count++;
        }
    }

    $cache[$modelId] = $count;
    return $count;
}

/**
 * Count how many assets for a model are currently checked out/assigned.
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */
function count_checked_out_assets_by_model(int $modelId): int
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM checked_out_asset_cache
         WHERE model_id = :model_id
    ");
    $stmt->execute([':model_id' => $modelId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Find a single Snipe-IT user by email or name.
 *
 * Uses /users?search=... and tries to reduce to a single match:
 *  - If exactly one row, returns it.
 *  - If multiple rows and one has an exact email match (case-insensitive),
 *    returns that.
 *  - Otherwise throws an exception listing how many matches there were.
 *
 * @param string $query
 * @return array
 * @throws Exception
 */
function find_single_user_by_email_or_name(string $query): array
{
    $q = trim($query);
    if ($q === '') {
        throw new InvalidArgumentException('User search query cannot be empty.');
    }

    $params = [
        'search' => $q,
        'limit'  => 20,
    ];

    $data = snipeit_request('GET', 'users', $params);

    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No Snipe-IT users found matching '{$q}'.");
    }

    $rows = $data['rows'];

    // If exactly one result, use it
    if (count($rows) === 1) {
        return $rows[0];
    }

    // Try to find exact email match
    $exactEmailMatches = [];
    $exactNameMatches  = [];
    $qLower = strtolower($q);
    foreach ($rows as $row) {
        $email = $row['email'] ?? '';
        $name  = $row['name'] ?? ($row['username'] ?? '');
        if ($email !== '' && strtolower(trim($email)) === $qLower) {
            $exactEmailMatches[] = $row;
        }
        if ($name !== '' && strtolower(trim($name)) === $qLower) {
            $exactNameMatches[] = $row;
        }
    }

    if (count($exactEmailMatches) === 1) {
        return $exactEmailMatches[0];
    }
    if (count($exactNameMatches) === 1) {
        return $exactNameMatches[0];
    }

    // Multiple matches, ambiguous
    $count = count($rows);
    throw new Exception("{$count} users matched '{$q}' in Snipe-IT; please refine (e.g. use full email).");
}

/**
 * Find a Snipe-IT user by email or name, returning candidates on ambiguity.
 *
 * @param string $query
 * @return array{user: ?array, candidates: array}
 * @throws Exception
 */
function find_user_by_email_or_name_with_candidates(string $query): array
{
    $q = trim($query);
    if ($q === '') {
        throw new InvalidArgumentException('User search query cannot be empty.');
    }

    $params = [
        'search' => $q,
        'limit'  => 20,
    ];

    $data = snipeit_request('GET', 'users', $params);

    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No Snipe-IT users found matching '{$q}'.");
    }

    $rows = $data['rows'];

    if (count($rows) === 1) {
        return ['user' => $rows[0], 'candidates' => []];
    }

    $exactEmailMatches = [];
    $exactNameMatches  = [];
    $qLower = strtolower($q);
    foreach ($rows as $row) {
        $email = $row['email'] ?? '';
        $name  = $row['name'] ?? ($row['username'] ?? '');
        if ($email !== '' && strtolower(trim($email)) === $qLower) {
            $exactEmailMatches[] = $row;
        }
        if ($name !== '' && strtolower(trim($name)) === $qLower) {
            $exactNameMatches[] = $row;
        }
    }

    if (count($exactEmailMatches) === 1) {
        return ['user' => $exactEmailMatches[0], 'candidates' => []];
    }
    if (count($exactNameMatches) === 1) {
        return ['user' => $exactNameMatches[0], 'candidates' => []];
    }

    $candidates = $rows;
    if (!empty($exactEmailMatches)) {
        $candidates = $exactEmailMatches;
    } elseif (!empty($exactNameMatches)) {
        $candidates = $exactNameMatches;
    }

    return ['user' => null, 'candidates' => $candidates];
}

/**
 * Check out a single asset to a Snipe-IT user by ID.
 *
 * Uses POST /hardware/{id}/checkout
 *
 * @param int         $assetId
 * @param int         $userId
 * @param string      $note
 * @param string|null $expectedCheckin ISO datetime string for expected checkin
 * @return void
 * @throws Exception
 */
function checkout_asset_to_user(int $assetId, int $userId, string $note = '', ?string $expectedCheckin = null): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID for checkout.');
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user ID for checkout.');
    }

    $payload = [
        'checkout_to_type' => 'user',
        // Snipe-IT checkout expects these for user checkouts
        'checkout_to_id'   => $userId,
        'assigned_user'    => $userId,
    ];

    if ($note !== '') {
        $payload['note'] = $note;
    }
    if (!empty($expectedCheckin)) {
        $payload['expected_checkin'] = $expectedCheckin;
    }

    // Snipe-IT may also support expected_checkin, etc., but we
    // keep it simple here.
    $resp = snipeit_request('POST', 'hardware/' . $assetId . '/checkout', $payload);

    // Basic sanity check: API should report success
    $status = $resp['status'] ?? 'success';

    // Flatten any messages into a readable string
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';

    // Treat missing status as success unless we spotted explicit error messages
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT checkout did not succeed: ' . $message);
    }
}

/**
 * Update the expected check-in date for an asset.
 *
 * @param int    $assetId
 * @param string $expectedDate ISO date (YYYY-MM-DD)
 * @return void
 * @throws Exception
 */
function update_asset_expected_checkin(int $assetId, string $expectedDate): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID.');
    }
    $expectedDate = trim($expectedDate);
    if ($expectedDate === '') {
        throw new InvalidArgumentException('Expected check-in date cannot be empty.');
    }

    $payload = [
        'expected_checkin' => $expectedDate,
    ];

    $resp = snipeit_request('PATCH', 'hardware/' . $assetId, $payload);
    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Failed to update expected check-in: ' . $message);
    }
}

/**
 * Check in a single asset in Snipe-IT by ID.
 *
 * @param int    $assetId
 * @param string $note
 * @return void
 * @throws Exception
 */
function checkin_asset(int $assetId, string $note = ''): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID for checkin.');
    }

    $payload = [];
    if ($note !== '') {
        $payload['note'] = $note;
    }

    $resp = snipeit_request('POST', 'hardware/' . $assetId . '/checkin', $payload);

    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT checkin did not succeed: ' . $message);
    }
}

/**
 * Fetch checked-out assets (requestable only) directly from Snipe-IT.
 *
 * @param bool $overdueOnly
 * @param int $maxResults Safety cap for total hardware rows fetched (0 to use config)
 * @return array
 * @throws Exception
 */
function fetch_checked_out_assets_from_snipeit(bool $overdueOnly = false, int $maxResults = 0): array
{
    if ($maxResults <= 0) {
        $maxResults = PHP_INT_MAX;
    }
    $all = [];
    $limit = min(200, $maxResults);
    $offset = 0;

    do {
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        $data = snipeit_request('GET', 'hardware', $params);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        if (empty($rows)) {
            break;
        }
        $all = array_merge($all, $rows);
        $count = count($rows);
        $offset += $limit;

        if ($count < $limit || count($all) >= $maxResults) {
            break;
        }
    } while (true);

    $now = time();
    $filtered = [];
    foreach ($all as $row) {
        // Only requestable assets
        if (empty($row['requestable'])) {
            continue;
        }

        // Consider "checked out" if assigned_to/user is present
        $assigned = $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? '');
        if ($assigned === '') {
            continue;
        }

        // Normalize date fields
        $lastCheckout = $row['last_checkout'] ?? '';
        if (is_array($lastCheckout)) {
            $lastCheckout = $lastCheckout['datetime'] ?? ($lastCheckout['date'] ?? '');
        }
        $expectedCheckin = $row['expected_checkin'] ?? '';
        if (is_array($expectedCheckin)) {
            $expectedCheckin = $expectedCheckin['datetime'] ?? ($expectedCheckin['date'] ?? '');
        }

        // Overdue check
        if ($overdueOnly) {
            // If Snipe-IT returns only a date (no time), treat it as due by end-of-day rather than midnight.
            $normalizedExpected = $expectedCheckin;
            if (is_string($expectedCheckin) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expectedCheckin)) {
                $normalizedExpected = $expectedCheckin . ' 23:59:59';
            }
            $expTs = $normalizedExpected ? strtotime($normalizedExpected) : null;
            if (!$expTs || $expTs > $now) {
                continue;
            }
        }

        $row['_last_checkout_norm']   = $lastCheckout;
        $row['_expected_checkin_norm'] = $expectedCheckin;

        $filtered[] = $row;
    }

    return $filtered;
}

/**
 * Fetch checked-out assets from the local cache table.
 *
 * @param bool $overdueOnly
 * @return array
 * @throws Exception
 */
function list_checked_out_assets(bool $overdueOnly = false): array
{
    global $pdo;
    require_once SRC_PATH . '/db.php';

    $sql = "
        SELECT
            asset_id,
            asset_tag,
            asset_name,
            model_id,
            model_name,
            assigned_to_id,
            assigned_to_name,
            assigned_to_email,
            assigned_to_username,
            status_label,
            last_checkout,
            expected_checkin
        FROM checked_out_asset_cache
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $now = time();
    $results = [];
    foreach ($rows as $row) {
        $expectedCheckin = $row['expected_checkin'] ?? '';
        if ($overdueOnly) {
            $normalizedExpected = $expectedCheckin;
            if (is_string($expectedCheckin) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expectedCheckin)) {
                $normalizedExpected = $expectedCheckin . ' 23:59:59';
            }
            $expTs = $normalizedExpected ? strtotime($normalizedExpected) : null;
            if (!$expTs || $expTs > $now) {
                continue;
            }
        }

        $assigned = [];
        $assignedId = (int)($row['assigned_to_id'] ?? 0);
        if ($assignedId > 0) {
            $assigned['id'] = $assignedId;
        }
        $assignedEmail = $row['assigned_to_email'] ?? '';
        $assignedName = $row['assigned_to_name'] ?? '';
        $assignedUsername = $row['assigned_to_username'] ?? '';
        if ($assignedEmail !== '') {
            $assigned['email'] = $assignedEmail;
        }
        if ($assignedUsername !== '') {
            $assigned['username'] = $assignedUsername;
        }
        if ($assignedName !== '') {
            $assigned['name'] = $assignedName;
        }

        $item = [
            'id' => (int)($row['asset_id'] ?? 0),
            'asset_tag' => $row['asset_tag'] ?? '',
            'name' => $row['asset_name'] ?? '',
            'model' => [
                'id' => (int)($row['model_id'] ?? 0),
                'name' => $row['model_name'] ?? '',
            ],
            'status_label' => $row['status_label'] ?? '',
            'last_checkout' => $row['last_checkout'] ?? '',
            'expected_checkin' => $expectedCheckin,
            '_last_checkout_norm' => $row['last_checkout'] ?? '',
            '_expected_checkin_norm' => $expectedCheckin,
        ];

        if (!empty($assigned)) {
            $item['assigned_to'] = $assigned;
        } elseif ($assignedName !== '') {
            $item['assigned_to_fullname'] = $assignedName;
        }

        $results[] = $item;
    }

    return $results;
}
// ============================================================
// FLEET CUSTOMIZATIONS - Added for Vehicle Management
// ============================================================

/**
 * Get user by email from Snipe-IT
 */
function get_snipeit_user_by_email(string $email): ?array
{
    if (empty($email)) {
        return null;
    }
    
    $result = snipeit_request('GET', '/users', [
        'search' => $email,
        'limit' => 10
    ]);
    
    if (!isset($result['rows']) || empty($result['rows'])) {
        return null;
    }
    
    // Find exact email match
    foreach ($result['rows'] as $user) {
        if (isset($user['email']) && strtolower($user['email']) === strtolower($email)) {
            return $user;
        }
    }
    
    return null;
}

/**
 * Check if user is VIP in Snipe-IT
 */
function is_user_vip_in_snipeit(string $email): bool
{
    $user = get_snipeit_user_by_email($email);
    
    if (!$user) {
        return false;
    }
    
    // Check if user has VIP flag set in Snipe-IT
    return isset($user['vip']) && $user['vip'] === true;
}

/**
 * Get custom fieldset for a model
 */
function get_model_fieldset(int $modelId): ?array
{
    $model = get_model($modelId);
    
    if (!$model || !isset($model['fieldset']) || !$model['fieldset']) {
        return null;
    }
    
    $fieldsetId = $model['fieldset']['id'] ?? null;
    
    if (!$fieldsetId) {
        return null;
    }
    
    // Get fieldset details
    $result = snipeit_request('GET', "/fieldsets/{$fieldsetId}");
    
    if (!isset($result['id'])) {
        return null;
    }
    
    return $result;
}

/**
 * Get custom fields for a model with display settings
 * Returns array with 'checkout_fields', 'checkin_fields', 'all_fields'
 */
function get_model_custom_fields_with_settings(int $modelId): array
{
    $result = [
        'checkout_fields' => [],
        'checkin_fields' => [],
        'audit_fields' => [],
        'all_fields' => []
    ];
    
    $fieldset = get_model_fieldset($modelId);
    
    if (!$fieldset || !isset($fieldset['fields']) || !isset($fieldset['fields']['rows'])) {
        return $result;
    }
    
    foreach ($fieldset['fields']['rows'] as $field) {
        $fieldInfo = [
            'id' => $field['id'] ?? 0,
            'name' => $field['name'] ?? '',
            'db_column' => $field['db_column_name'] ?? $field['db_column'] ?? '',
            'element' => $field['element'] ?? 'text',
            'field_format' => $field['format'] ?? 'ANY',
            'field_values' => $field['field_values'] ?? '',
            'required' => $field['required'] ?? false,
            'show_in_checkout' => !empty($field['show_in_checkoutform']),
            'show_in_checkin' => !empty($field['show_in_requestableform']), // Note: checkin might use different property
            'show_in_audit' => !empty($field['show_in_audit']),
        ];
        
        $result['all_fields'][] = $fieldInfo;
        
        if ($fieldInfo['show_in_checkout']) {
            $result['checkout_fields'][] = $fieldInfo;
        }
        if ($fieldInfo['show_in_checkin']) {
            $result['checkin_fields'][] = $fieldInfo;
        }
        if ($fieldInfo['show_in_audit']) {
            $result['audit_fields'][] = $fieldInfo;
        }
    }
    
    return $result;
}

/**
 * Get asset with custom fields merged with field definitions
 */
function get_asset_with_field_definitions(int $assetId): ?array
{
    $asset = get_asset($assetId);
    
    if (!$asset || !isset($asset['model']['id'])) {
        return $asset;
    }
    
    $modelId = $asset['model']['id'];
    $fieldSettings = get_model_custom_fields_with_settings($modelId);
    
    // Merge current values with field definitions
    $asset['field_definitions'] = $fieldSettings;
    
    // Map current values to field definitions
    if (!empty($asset['custom_fields']) && !empty($fieldSettings['all_fields'])) {
        foreach ($fieldSettings['all_fields'] as &$fieldDef) {
            foreach ($asset['custom_fields'] as $fieldName => $fieldData) {
                $dbCol = $fieldData['field'] ?? '';
                if ($dbCol === $fieldDef['db_column'] || $fieldName === $fieldDef['name']) {
                    $fieldDef['current_value'] = $fieldData['value'] ?? '';
                    break;
                }
            }
        }
        $asset['field_definitions'] = $fieldSettings;
    }
    
    return $asset;
}

/**
 * Get all requestable assets (not just models)
 */
function get_requestable_assets(int $limit = 500, ?int $categoryId = null): array
{
    $cacheKey = 'requestable_assets_' . $limit . '_' . ($categoryId ?? 'all');
    $cached = snipeit_cache_get($cacheKey, 120); // 2 minute cache
    if ($cached !== null) {
        return $cached;
    }
    
    $params = [
        'limit' => $limit,
        'requestable' => 'true',
        'status' => 'Ready to Deploy',
        'sort' => 'name',
        'order' => 'asc'
    ];
    if ($categoryId) {
        $params['category_id'] = $categoryId;
    }
    $result = snipeit_request('GET', '/hardware', $params);
    if (!isset($result['rows'])) {
        return [];
    }
    $assets = $result['rows'];
    snipeit_cache_set($cacheKey, $assets);
    return $assets;
}

/**
 * Get single asset details
 */
function get_asset(int $assetId): ?array
{
    $result = snipeit_request('GET', "/hardware/{$assetId}");
    
    if (!isset($result['id'])) {
        return null;
    }
    
    return $result;
}

/**
 * Get asset with custom fields
 */
function get_asset_with_custom_fields(int $assetId): ?array
{
    $asset = get_asset($assetId);
    
    if (!$asset) {
        return null;
    }
    
    // Custom fields are included in the asset response under 'custom_fields'
    return $asset;
}

/**
 * Update asset with custom field values (for checkout/checkin form data)
 */
function update_asset_custom_fields(int $assetId, array $customFields): bool
{
    if (empty($customFields)) {
        return true;
    }
    
    $result = snipeit_request('PATCH', "/hardware/{$assetId}", $customFields);
    
    return isset($result['status']) && $result['status'] === 'success';
}

/**
 * Get categories that have requestable assets
 */
function get_categories_with_requestable_assets(): array
{
    $allCategories = get_model_categories();
    $categoriesWithAssets = [];
    
    foreach ($allCategories as $category) {
        $categoryId = $category['id'];
        
        // Check if category has any requestable assets
        $assets = snipeit_request('GET', '/hardware', [
            'category_id' => $categoryId,
            'requestable' => 'true',
            'limit' => 1
        ]);
        
        if (isset($assets['total']) && $assets['total'] > 0) {
            $category['requestable_count'] = $assets['total'];
            $categoriesWithAssets[] = $category;
        }
    }
    
    return $categoriesWithAssets;
}

/**
 * Search assets by name, tag, or serial
 */
function search_requestable_assets(string $query, int $limit = 50): array
{
    $result = snipeit_request('GET', '/hardware', [
        'search' => $query,
        'requestable' => 'true',
        'limit' => $limit,
        'sort' => 'name',
        'order' => 'asc'
    ]);
    
    if (!isset($result['rows'])) {
        return [];
    }
    
    return $result['rows'];
}

/**
 * Checkout asset with custom field data
 */
function checkout_asset_with_form_data(
    int $assetId, 
    int $userId, 
    array $formData = [], 
    string $note = '', 
    ?string $expectedCheckin = null
): array
{
    // First update custom fields if provided
    if (!empty($formData)) {
        $customFieldsUpdate = [];
        foreach ($formData as $key => $value) {
            // Custom fields in Snipe-IT API use the db column name
            if (strpos($key, '_') === 0) {
                $customFieldsUpdate[$key] = $value;
            }
        }
        
        if (!empty($customFieldsUpdate)) {
            update_asset_custom_fields($assetId, $customFieldsUpdate);
        }
    }
    
    // Then checkout the asset
    try {
        checkout_asset_to_user($assetId, $userId, $note, $expectedCheckin);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Checkin asset with custom field data and maintenance flag
 */
function checkin_asset_with_form_data(
    int $assetId, 
    array $formData = [], 
    bool $maintenanceFlag = false,
    string $maintenanceNotes = '',
    string $note = ''
): array
{
    // First update custom fields if provided
    if (!empty($formData)) {
        $customFieldsUpdate = [];
        foreach ($formData as $key => $value) {
            if (strpos($key, '_') === 0) {
                $customFieldsUpdate[$key] = $value;
            }
        }
        
        if (!empty($customFieldsUpdate)) {
            update_asset_custom_fields($assetId, $customFieldsUpdate);
        }
    }
    
    // Add maintenance note if flagged
    if ($maintenanceFlag && !empty($maintenanceNotes)) {
        $note .= "\n[MAINTENANCE REQUIRED] " . $maintenanceNotes;
    }
    
    // Checkin the asset
    try {
        checkin_asset($assetId, $note);
        
        // If maintenance flagged, update status (you may need to adjust status ID)
        if ($maintenanceFlag) {
            // Get the "Needs Maintenance" status ID from your Snipe-IT
            // This would need to be configured in settings
            // For now, we'll just add a note
        }
        
        return ['success' => true, 'maintenance_flag' => $maintenanceFlag];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
/**
 * Snipe-IT Status IDs for Fleet Vehicles
 */
define('STATUS_VEH_AVAILABLE', 5);
define('STATUS_VEH_IN_SERVICE', 6);
define('STATUS_VEH_OUT_OF_SERVICE', 7);
define('STATUS_VEH_RESERVED', 8);

/**
 * Get pickup locations (parent_id = 9)
 */
function get_pickup_locations(): array
{
    $locations = get_locations();
    $pickups = [];
    foreach ($locations as $loc) {
        if (isset($loc['parent']['id']) && $loc['parent']['id'] == 9) {
            $pickups[] = $loc;
        }
    }
    return $pickups;
}

/**
 * Get field destinations (parent_id = 10)
 */
function get_field_destinations(): array
{
    $locations = get_locations();
    $destinations = [];
    foreach ($locations as $loc) {
        if (isset($loc['parent']['id']) && $loc['parent']['id'] == 10) {
            $destinations[] = $loc;
        }
    }
    return $destinations;
}

/**
 * Update asset status in Snipe-IT
 */
function update_asset_status(int $assetId, int $statusId): bool
{
    $config = require CONFIG_PATH . '/config.php';
    $baseUrl = rtrim($config['snipeit']['base_url'], '/');
    $token = $config['snipeit']['api_token'];

    $ch = curl_init($baseUrl . '/api/v1/hardware/' . $assetId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['status_id' => $statusId]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($result, true);
    return isset($data['status']) && $data['status'] === 'success';
}

/**
 * Update asset location in Snipe-IT
 */
function update_asset_location(int $assetId, int $locationId): bool
{
    $config = require CONFIG_PATH . '/config.php';
    $baseUrl = rtrim($config['snipeit']['base_url'], '/');
    $token = $config['snipeit']['api_token'];

    $ch = curl_init($baseUrl . '/api/v1/hardware/' . $assetId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['rtd_location_id' => $locationId]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($result, true);
    return isset($data['status']) && $data['status'] === 'success';
}


/**
 * Get all maintenance records from Snipe-IT
 */
function get_maintenances(int $limit = 100, int $assetId = null): array
{
    $url = '/maintenances?limit=' . $limit;
    if ($assetId) {
        $url = '/hardware/' . $assetId . '/maintenances?limit=' . $limit;
    }
    $response = snipeit_request('GET', $url);
    return $response['rows'] ?? [];
}

/**
 * Get maintenance records for a specific asset
 */
function get_asset_maintenances(int $assetId, int $limit = 50): array
{
    $response = snipeit_request('GET', '/hardware/' . $assetId . '/maintenances?limit=' . $limit);
    return $response['rows'] ?? [];
}

/**
 * Create a maintenance record in Snipe-IT
 */
function create_maintenance(array $data): ?array
{
    $payload = [
        'asset_id' => $data['asset_id'],
        'supplier_id' => $data['supplier_id'] ?? null,
        'asset_maintenance_type' => $data['asset_maintenance_type'] ?? 'Maintenance',
        'title' => $data['title'] ?? 'Scheduled Maintenance',
        'start_date' => $data['start_date'] ?? date('Y-m-d'),
        'completion_date' => $data['completion_date'] ?? $data['start_date'] ?? date('Y-m-d'),
        'cost' => $data['cost'] ?? 0,
        'is_warranty' => $data['is_warranty'] ?? 0,
        'notes' => $data['notes'] ?? '',
    ];
    
    $response = snipeit_request('POST', '/maintenances', $payload);
    
    if (isset($response['status']) && $response['status'] === 'error') {
        error_log('Snipe-IT create maintenance error: ' . json_encode($response));
        return null;
    }
    
    return $response['payload'] ?? $response;
}

/**
 * Get maintenance types available in Snipe-IT
 */
function get_maintenance_types(): array
{
    // Snipe-IT default maintenance types
    return [
        'Maintenance' => 'Preventive Maintenance',
        'Repair' => 'Repair',
        'Upgrade' => 'Upgrade',
        'PAT Test' => 'PAT Test',
        'Calibration' => 'Calibration',
        'Software Support' => 'Software Support',
        'Hardware Support' => 'Hardware Support',
    ];
}

/**
 * Get suppliers from Snipe-IT (for service providers)
 */
function get_suppliers(): array
{
    $response = snipeit_request('GET', '/suppliers?limit=100');
    return $response['rows'] ?? [];
}
/**
 * Get all users from Snipe-IT
 */
function get_snipeit_users(int $limit = 100, string $search = ''): array
{
    $url = '/users?limit=' . $limit;
    if ($search) {
        $url .= '&search=' . urlencode($search);
    }
    $response = snipeit_request('GET', $url);
    return $response['rows'] ?? [];
}

/**
 * Get a single user from Snipe-IT
 */
function get_snipeit_user(int $userId): ?array
{
    $response = snipeit_request('GET', '/users/' . $userId);
    return $response['id'] ? $response : null;
}
/**
 * Create a new user in Snipe-IT
 */
function create_snipeit_user(array $data): ?array
{
    $password = $data['password'] ?? 'TempPass' . rand(1000, 9999) . '!';
    
    $payload = [
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'] ?? '',
        'username' => $data['username'] ?? $data['email'],
        'email' => $data['email'],
        'password' => $password,
        'password_confirmation' => $password,
        'activated' => isset($data['activated']) ? (bool)$data['activated'] : true,
        'groups' => $data['groups'] ?? SNIPEIT_GROUP_DRIVERS,
        'vip' => isset($data['vip']) ? (bool)$data['vip'] : false,
        'notes' => $data['notes'] ?? 'Created via SnipeScheduler',
    ];
    
    $response = snipeit_request('POST', '/users', $payload);
    
    if (isset($response['status']) && $response['status'] === 'error') {
        error_log('Snipe-IT create user error: ' . json_encode($response));
        return null;
    }
    
    if (isset($response['status']) && $response['status'] === 'success') {
        return $response['payload'] ?? $response;
    }
    
    return $response;
}

/**
 * Update a user in Snipe-IT
 */
function update_snipeit_user(int $userId, array $data): ?array
{
    $payload = [];
    
    if (isset($data['first_name'])) $payload['first_name'] = $data['first_name'];
    if (isset($data['last_name'])) $payload['last_name'] = $data['last_name'];
    if (isset($data['email'])) $payload['email'] = $data['email'];
    if (isset($data['activated'])) $payload['activated'] = $data['activated'];
    if (isset($data['groups'])) $payload['groups'] = $data['groups'];
    if (isset($data['notes'])) $payload['notes'] = $data['notes'];
    
    $response = snipeit_request('PATCH', '/users/' . $userId, $payload);
    
    if (isset($response['status']) && $response['status'] === 'error') {
        error_log('Snipe-IT update user error: ' . json_encode($response));
        return null;
    }
    
    return $response['payload'] ?? $response;
}

/**
 * Deactivate a user in Snipe-IT
 */
function deactivate_snipeit_user(int $userId): bool
{
    $response = update_snipeit_user($userId, ['activated' => false]);
    return $response !== null;
}

/**
 * Activate a user in Snipe-IT
 */
function activate_snipeit_user(int $userId): bool
{
    $response = update_snipeit_user($userId, ['activated' => true]);
    return $response !== null;
}

/**
 * Get Snipe-IT groups
 */
function get_snipeit_groups(): array
{
    $response = snipeit_request('GET', '/groups');
    return $response['rows'] ?? [];
}

// Group ID constants for fleet management
define('SNIPEIT_GROUP_DRIVERS', 2);
define('SNIPEIT_GROUP_FLEET_STAFF', 3);
define('SNIPEIT_GROUP_FLEET_ADMIN', 4);


/**
 * Get user permissions from Snipe-IT groups
 * Returns array with is_admin, is_staff, is_vip, snipeit_id
 */
function get_user_permissions_from_snipeit(string $email): array
{
    $result = [
        'snipeit_id' => null,
        'is_super_admin' => false,  // Group 1 - Admins (full system access)
        'is_admin' => false,         // Group 4 - Fleet Admin
        'is_staff' => false,         // Group 3 - Fleet Staff
        'is_vip' => false,
        'groups' => [],
        'exists' => false,
    ];
    
    $user = get_snipeit_user_by_email($email);
    
    if (!$user) {
        return $result;
    }
    
    $result['exists'] = true;
    $result['snipeit_id'] = $user['id'] ?? null;
    $result['is_vip'] = !empty($user['vip']);
    
    // Get user groups
    $userGroups = [];
    if (isset($user['groups']['rows'])) {
        foreach ($user['groups']['rows'] as $group) {
            $userGroups[] = (int)$group['id'];
            $result['groups'][] = $group['name'];
        }
    }
    
    // Check group permissions
    // Admins (group 1) = Super Admin (full system access including Settings)
    if (in_array(1, $userGroups)) {
        $result['is_super_admin'] = true;
        $result['is_admin'] = true;
        $result['is_staff'] = true;
    }
    
    // Fleet Admin (group 4) = is_admin + is_staff
    if (in_array(SNIPEIT_GROUP_FLEET_ADMIN, $userGroups)) {
        $result['is_admin'] = true;
        $result['is_staff'] = true;
    }
    
    // Fleet Staff (group 3) = is_staff
    if (in_array(SNIPEIT_GROUP_FLEET_STAFF, $userGroups)) {
        $result['is_staff'] = true;
    }
    
    // Drivers (group 2) = basic user (can book vehicles)
    // No special permissions needed, just needs to exist
    
    return $result;
}


/**
 * Sync user name from OAuth to Snipe-IT
 */
function sync_user_name_to_snipeit(int $snipeitId, string $firstName, string $lastName): bool
{
    if ($snipeitId <= 0) {
        return false;
    }
    
    $result = update_snipeit_user($snipeitId, [
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);
    
    return $result !== null;
}
/**
 * Set VI"smart API" in a few critical areas—specifically through the use of hardcoded custom database columns (e.g., _snipeit_vin_5) and hardcoded environment-specific strings (e.g., "Amtrak", "B&P").

If you deploy this code to a different Snipe-IT instance, the custom field IDs will change (e.g., _snipeit_vin_5 might become _snipeit_vin_12), and the API calls will fail. Additionally, fetching all records (like limit=100) and filtering them in PHP is inefficient; it is much better to let Snipe-IT's API do the filtering.P status for a user in Snipe-IT
 */
function set_user_vip_status(int $userId, bool $isVip): bool
{
    $response = snipeit_request('PATCH', '/users/' . $userId, [
        'vip' => $isVip ? '1' : '0',
    ]);
    
    if (isset($response['status']) && $response['status'] === 'error') {
        error_log('Snipe-IT set VIP error: ' . json_encode($response));
        return false;
    }
    
    return true;
}

// ============================================================
// VEHICLE MANAGEMENT - Smart API with Dynamic Field Mapping
// ============================================================

/**
 * Get custom fields mapping for a category/fieldset
 * Returns array like: ['vin' => '_snipeit_vin_5', 'license_plate' => '_snipeit_license_plate_9']
 * This dynamically discovers field mappings from Snipe-IT
 */
function get_custom_fields_mapping(?int $fieldsetId = null): array
{
    static $cache = [];
    
    $cacheKey = $fieldsetId ?? 'default';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    $mapping = [];
    
    // If no fieldset specified, try to get from fleet models
    if (!$fieldsetId) {
        $fieldsetId = get_fleet_fieldset_id();
    }
    
    if (!$fieldsetId) {
        return $mapping;
    }
    
    // Get fieldset with fields
    $response = snipeit_request('GET', '/fieldsets/' . $fieldsetId);
    
    if (!isset($response['fields']['rows'])) {
        return $mapping;
    }
    
    foreach ($response['fields']['rows'] as $field) {
        $name = $field['name'] ?? '';
        $dbColumn = $field['db_column_name'] ?? $field['db_column'] ?? '';
        
        if ($name && $dbColumn) {
            // Create a normalized key from field name
            $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
            $key = preg_replace('/_+/', '_', $key); // Remove multiple underscores
            $key = trim($key, '_');
            
            $mapping[$key] = $dbColumn;
            
            // Map specific field names to canonical keys
            // Order matters - more specific matches first
            $fieldNameLower = strtolower($name);
            
            if (strpos($fieldNameLower, 'current mileage') !== false || $fieldNameLower === 'current_mileage') {
                $mapping['current_mileage'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'last maintenance mileage') !== false) {
                $mapping['last_maintenance_mileage'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'last maintenance date') !== false) {
                $mapping['last_maintenance_date'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'maintenance interval miles') !== false) {
                $mapping['maintenance_interval_miles'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'maintenance interval days') !== false) {
                $mapping['maintenance_interval_days'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'oil change') !== false) {
                $mapping['last_oil_change_miles'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'tire rotation') !== false) {
                $mapping['last_tire_rotation_miles'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'insurance') !== false) {
                $mapping['insurance_expiry'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'registration') !== false) {
                $mapping['registration_expiry'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'vin') !== false && strlen($fieldNameLower) < 10) {
                $mapping['vin'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'license plate') !== false || strpos($fieldNameLower, 'plate') !== false) {
                $mapping['license_plate'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'vehicle year') !== false || $fieldNameLower === 'year') {
                $mapping['vehicle_year'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'visual inspection') !== false) {
                $mapping['visual_inspection'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'checkout time') !== false) {
                $mapping['checkout_time'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'return time') !== false && strpos($fieldNameLower, 'expected') === false) {
                $mapping['return_time'] = $dbColumn;
            } elseif (strpos($fieldNameLower, 'expected return') !== false) {
                $mapping['expected_return_time'] = $dbColumn;
            }
        }
    }
    
    $cache[$cacheKey] = $mapping;
    return $mapping;
}

/**
 * Map user-friendly field names to Snipe-IT db_column names
 */
function map_custom_fields(array $data, ?int $fieldsetId = null): array
{
    $mapping = get_custom_fields_mapping($fieldsetId);
    $result = [];
    
    foreach ($data as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        
        $normalizedKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $key));
        
        if (isset($mapping[$normalizedKey])) {
            $result[$mapping[$normalizedKey]] = $value;
        } elseif (isset($mapping[$key])) {
            $result[$mapping[$key]] = $value;
        } elseif (strpos($key, '_snipeit_') === 0) {
            // Already a db_column format
            $result[$key] = $value;
        }
    }
    
    return $result;
}

/**
 * Get all manufacturers from Snipe-IT
 */
function get_manufacturers(int $limit = 100, string $search = ''): array
{
    $url = '/manufacturers?limit=' . $limit;
    if ($search) {
        $url .= '&search=' . urlencode($search);
    }
    $response = snipeit_request('GET', $url);
    return $response['rows'] ?? [];
}

/**
 * Find manufacturer by exact name (API-level search)
 */
function find_manufacturer_by_name(string $name): ?array
{
    if (empty(trim($name))) {
        return null;
    }
    
    $response = snipeit_request('GET', '/manufacturers?search=' . urlencode($name) . '&limit=5');
    $rows = $response['rows'] ?? [];
    
    foreach ($rows as $m) {
        if (strcasecmp(trim($m['name']), trim($name)) === 0) {
            return $m;
        }
    }
    
    return null;
}

/**
 * Create a new manufacturer in Snipe-IT
 */
function create_manufacturer(string $name): ?array
{
    if (empty(trim($name))) {
        return null;
    }
    
    $response = snipeit_request('POST', '/manufacturers', [
        'name' => trim($name),
    ]);
    
    if (isset($response['status']) && $response['status'] === 'error') {
        error_log('Snipe-IT create manufacturer error: ' . json_encode($response));
        return null;
    }
    
    return $response['payload'] ?? $response;
}

/**
 * Get or create manufacturer (prevents duplicates using API search)
 */
function get_or_create_manufacturer(string $name): ?array
{
    $existing = find_manufacturer_by_name($name);
    if ($existing) {
        return $existing;
    }
    return create_manufacturer($name);
}

/**
 * Get all asset models from Snipe-IT
 */
function get_models(int $limit = 100, string $search = '', ?int $categoryId = null): array
{
    $url = '/models?limit=' . $limit;
    if ($search) {
        $url .= '&search=' . urlencode($search);
    }
    if ($categoryId) {
        $url .= '&category_id=' . $categoryId;
    }
    $response = snipeit_request('GET', $url);
    return $response['rows'] ?? [];
}

/**
 * Find model by exact name (API-level search)
 */
function find_model_by_name(string $name, ?int $categoryId = null): ?array
{
    if (empty(trim($name))) {
        return null;
    }
    
    $url = '/models?search=' . urlencode($name) . '&limit=5';
    if ($categoryId) {
        $url .= '&category_id=' . $categoryId;
    }
    
    $response = snipeit_request('GET', $url);
    $rows = $response['rows'] ?? [];
    
    foreach ($rows as $m) {
        if (strcasecmp(trim($m['name']), trim($name)) === 0) {
            return $m;
        }
    }
    
    return null;
}

/**
 * Get fleet vehicle models only (filtered by Fleet Vehicles category)
 */
function get_fleet_models(int $limit = 100): array
{
    $categoryId = get_fleet_category_id();
    if (!$categoryId) {
        return [];
    }
    return get_models($limit, '', $categoryId);
}

/**
 * Create a new asset model in Snipe-IT
 */
function create_model(array $data): ?array
{
    if (empty($data['name']) || empty($data['manufacturer_id'])) {
        error_log('Snipe-IT create model error: name and manufacturer_id are required');
        return null;
    }
    
    $categoryId = $data['category_id'] ?? get_fleet_category_id();
    $fieldsetId = $data['fieldset_id'] ?? get_fleet_fieldset_id();
    
    if (!$categoryId) {
        error_log('Snipe-IT create model error: No category ID found');
        return null;
    }
    
    $payload = [
        'name' => $data['name'],
        'manufacturer_id' => (int)$data['manufacturer_id'],
        'category_id' => (int)$categoryId,
    ];
    
    if (!empty($data['model_number'])) {
        $payload['model_number'] = $data['model_number'];
    }
    
    if ($fieldsetId) {
        $payload['fieldset_id'] = (int)$fieldsetId;
    }
    
    $response = snipeit_request('POST', '/models', $payload);
    
    if (isset($response['status']) && $response['status'] === 'error') {
        error_log('Snipe-IT create model error: ' . json_encode($response));
        return null;
    }
    
    return $response['payload'] ?? $response;
}

/**
 * Get or create model (prevents duplicates using API search)
 */
function get_or_create_model(string $name, int $manufacturerId, string $modelNumber = ''): ?array
{
    $categoryId = get_fleet_category_id();
    $existing = find_model_by_name($name, $categoryId);
    
    if ($existing) {
        return $existing;
    }
    
    return create_model([
        'name' => $name,
        'manufacturer_id' => $manufacturerId,
        'model_number' => $modelNumber,
    ]);
}

/**
 * Get all categories from Snipe-IT
 */
function get_categories(int $limit = 100, string $search = ''): array
{
    $cacheKey = 'categories_' . $limit . '_' . md5($search);
    $cached = snipeit_cache_get($cacheKey, 600); // 10 minute cache
    if ($cached !== null) {
        return $cached;
    }
    
    $url = '/categories?limit=' . $limit;
    if ($search) {
        $url .= '&search=' . urlencode($search);
    }
    $response = snipeit_request('GET', $url);
    $result = $response['rows'] ?? [];
    snipeit_cache_set($cacheKey, $result);
    return $result;
}

/**
 * Get the Fleet Vehicles category ID (cached, configurable)
 */
function get_fleet_category_id(): ?int
{
    static $categoryId = null;
    
    if ($categoryId !== null) {
        return $categoryId;
    }
    
    // Check config first
    if (defined('SNIPEIT_FLEET_CATEGORY_ID')) {
        $categoryId = SNIPEIT_FLEET_CATEGORY_ID;
        return $categoryId;
    }
    
    // Search via API
    $response = snipeit_request('GET', '/categories?search=Fleet&limit=10');
    $categories = $response['rows'] ?? [];
    
    foreach ($categories as $cat) {
        if (stripos($cat['name'], 'Fleet') !== false) {
            $categoryId = (int)$cat['id'];
            return $categoryId;
        }
    }
    
    return null;
}

/**
 * Get the Fleet Vehicle fieldset ID (cached, configurable)
 */
function get_fleet_fieldset_id(): ?int
{
    static $fieldsetId = null;
    
    if ($fieldsetId !== null) {
        return $fieldsetId;
    }
    
    // Check config first
    if (defined('SNIPEIT_FLEET_FIELDSET_ID')) {
        $fieldsetId = SNIPEIT_FLEET_FIELDSET_ID;
        return $fieldsetId;
    }
    
    // Search via API
    $response = snipeit_request('GET', '/fieldsets?limit=50');
    $fieldsets = $response['rows'] ?? [];
    
    foreach ($fieldsets as $fs) {
        if (stripos($fs['name'], 'Fleet') !== false || stripos($fs['name'], 'Vehicle') !== false) {
            $fieldsetId = (int)$fs['id'];
            return $fieldsetId;
        }
    }
    
    return null;
}

/**
 * Get all status labels from Snipe-IT
 */
function get_status_labels(string $search = ''): array
{
    $cacheKey = 'status_labels_' . md5($search);
    $cached = snipeit_cache_get($cacheKey, 600); // 10 minute cache
    if ($cached !== null) {
        return $cached;
    }
    
    $url = '/statuslabels?limit=100';
    if ($search) {
        $url .= '&search=' . urlencode($search);
    }
    $response = snipeit_request('GET', $url);
    $result = $response['rows'] ?? [];
    snipeit_cache_set($cacheKey, $result);
    return $result;
}

/**
 * Get deployable status labels (for available vehicles)
 */
function get_deployable_status_labels(): array
{
    $labels = get_status_labels();
    return array_filter($labels, fn($l) => !empty($l['type']) && $l['type'] === 'deployable');
}

/**
 * Get the VEH-Available status ID (cached, configurable)
 */
function get_veh_available_status_id(): ?int
{
    static $statusId = null;
    
    if ($statusId !== null) {
        return $statusId;
    }
    
    // Check config first
    if (defined('SNIPEIT_VEH_AVAILABLE_STATUS_ID')) {
        $statusId = SNIPEIT_VEH_AVAILABLE_STATUS_ID;
        return $statusId;
    }
    
    // Search via API
    $labels = get_status_labels('VEH-Available');
    foreach ($labels as $label) {
        if (stripos($label['name'], 'VEH-Available') !== false) {
            $statusId = (int)$label['id'];
            return $statusId;
        }
    }
    
    // Fallback: first deployable status
    $deployable = get_deployable_status_labels();
    if (!empty($deployable)) {
        $first = reset($deployable);
        $statusId = (int)$first['id'];
        return $statusId;
    }
    
    return null;
}

/**
 * Get all locations from Snipe-IT
 */
function get_locations(int $limit = 100, string $search = ''): array
{
    $cacheKey = 'locations_' . $limit . '_' . md5($search);
    $cached = snipeit_cache_get($cacheKey, 300); // 5 minute cache
    if ($cached !== null) {
        return $cached;
    }
    
    $url = '/locations?limit=' . $limit;
    if ($search) {
        $url .= '&search=' . urlencode($search);
    }
    $response = snipeit_request('GET', $url);
    $result = $response['rows'] ?? [];
    
    snipeit_cache_set($cacheKey, $result);
    return $result;
}

/**
 * Get all companies from Snipe-IT
 */
function get_companies(string $search = ''): array
{
    $url = '/companies?limit=100';
    if ($search) {
        $url .= '&search=' . urlencode($search);
    }
    $response = snipeit_request('GET', $url);
    return $response['rows'] ?? [];
}

/**
 * Create a new vehicle (hardware asset) in Snipe-IT
 * Uses dynamic field mapping - pass user-friendly field names
 * 
 * @param array $data [
 *   'model_id' => (required),
 *   'asset_tag' => (optional, auto-generated if empty),
 *   'name' => (optional),
 *   'status_id' => (optional, defaults to VEH-Available),
 *   'location_id' => (optional),
 *   'company_id' => (optional),
 *   'serial' => (optional),
 *   'notes' => (optional),
 *   // Custom fields with user-friendly names:
 *   'vin' => '...',
 *   'license_plate' => '...',
 *   'vehicle_year' => '...',
 *   'current_mileage' => '...',
 *   etc.
 * ]
 */
function create_vehicle(array $data): ?array
{
    // Required fields
    if (empty($data['model_id'])) {
        error_log('Snipe-IT create vehicle error: model_id is required');
        return null;
    }
    
    // Build base payload
    $payload = [
        'model_id' => (int)$data['model_id'],
        'status_id' => (int)($data['status_id'] ?? get_veh_available_status_id()),
        'requestable' => true,
    ];
    
    // Optional standard fields
    if (!empty($data['asset_tag'])) {
        $payload['asset_tag'] = $data['asset_tag'];
    }
    if (!empty($data['name'])) {
        $payload['name'] = $data['name'];
    }
    if (!empty($data['serial'])) {
        $payload['serial'] = $data['serial'];
    }
    if (!empty($data['location_id'])) {
        $payload['rtd_location_id'] = (int)$data['location_id'];
    }
    if (!empty($data['company_id'])) {
        $payload['company_id'] = (int)$data['company_id'];
    }
    if (!empty($data['notes'])) {
        $payload['notes'] = $data['notes'];
    }
    
    // Map custom fields dynamically
    $customFieldKeys = [
        'vin', 'license_plate', 'vehicle_year', 'current_mileage',
        'last_oil_change_miles', 'last_tire_rotation_miles',
        'insurance_expiry', 'registration_expiry',
        'maintenance_interval_miles', 'maintenance_interval_days',
        'last_maintenance_date', 'last_maintenance_mileage',
    ];
    
    $customData = [];
    foreach ($customFieldKeys as $key) {
        if (isset($data[$key]) && $data[$key] !== '') {
            $customData[$key] = $data[$key];
        }
    }
    
    if (!empty($customData)) {
        $mappedFields = map_custom_fields($customData);
        $payload = array_merge($payload, $mappedFields);
    }
    
    $response = snipeit_request('POST', '/hardware', $payload);
    
    if (isset($response['status']) && $response['status'] === 'error') {
        error_log('Snipe-IT create vehicle error: ' . json_encode($response));
        return null;
    }
    
    return $response['payload'] ?? $response;
}

/**
 * Get fleet vehicles (hardware assets in Fleet Vehicles category)
 */
function get_fleet_vehicles(int $limit = 100, ?int $statusId = null): array
{
    $categoryId = get_fleet_category_id();
    
    $url = '/hardware?limit=' . $limit;
    if ($categoryId) {
        $url .= '&category_id=' . $categoryId;
    }
    if ($statusId) {
        $url .= '&status_id=' . $statusId;
    }
    
    $response = snipeit_request('GET', $url);
    return $response['rows'] ?? [];
}
