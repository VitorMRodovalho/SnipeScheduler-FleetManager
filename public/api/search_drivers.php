<?php
/**
 * Driver Search AJAX API
 * Typeahead endpoint for "Book on Behalf" driver lookup
 *
 * GET /api/search_drivers.php?q=john
 * Returns: [{ name, email }] — max 10 results
 *
 * Staff/Admin only. Searches users table by name or email.
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';

header('Content-Type: application/json');

// Staff or Admin only
$isStaff = !empty($currentUser['is_staff']) || !empty($currentUser['is_admin']);
if (!$isStaff) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$query = trim($_GET['q'] ?? '');

// Minimum 2 characters to search
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $searchTerm = '%' . $query . '%';
    $stmt = $pdo->prepare("
        SELECT name, email
        FROM users
        WHERE (name LIKE :q1 OR email LIKE :q2)
        ORDER BY name ASC
        LIMIT 10
    ");
    $stmt->execute([':q1' => $searchTerm, ':q2' => $searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($results);
} catch (Exception $e) {
    error_log('search_drivers.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
