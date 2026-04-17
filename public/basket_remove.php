<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

// State-changing endpoints must require POST + CSRF. Any GET trigger
// (img/link/prefetch) from a malicious page could otherwise flush a
// logged-in user's basket.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
csrf_check();

$modelId = (int)($_POST['model_id'] ?? 0);
if ($modelId > 0 && !empty($_SESSION['basket'])) {
    unset($_SESSION['basket'][$modelId]);
}

$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    isset($_SERVER['HTTP_ACCEPT']) &&
    strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
);

if ($isAjax) {
    $basketCount = 0;
    foreach ($_SESSION['basket'] ?? [] as $q) {
        $basketCount += (int)$q;
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'basket_count' => $basketCount]);
    exit;
}

header('Location: basket');
exit;
