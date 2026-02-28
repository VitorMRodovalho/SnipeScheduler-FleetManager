<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$active = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// Get filter parameters
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get categories with requestable assets only
$categories = get_categories_with_requestable_assets();

// Get assets based on filters
if (!empty($searchQuery)) {
    $assets = search_requestable_assets($searchQuery, 100);
} elseif ($categoryFilter) {
    $assets = get_requestable_assets(100, $categoryFilter);
} else {
    $assets = get_requestable_assets(100);
}

// Filter out already checked out assets
$availableAssets = array_filter($assets, function($asset) {
    return !isset($asset['assigned_to']) || $asset['assigned_to'] === null;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vehicle Catalogue</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        .vehicle-card { transition: transform 0.2s, box-shadow 0.2s; }
        .vehicle-card:hover { transform: translateY(-5px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="p-4">
    <div class="container">
        <div class="page-shell">
            <?= layout_logo_tag() ?>
            <div class="page-header">
                <h1>Vehicle Fleet Catalogue</h1>
                <p class="text-muted">Browse and reserve available vehicles</p>
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


        <!-- Search and Filter Bar -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Search Vehicle</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Search by name, tag, or VIN..." 
                               value="<?= h($searchQuery) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['name']) ?> (<?= $cat['requestable_count'] ?> vehicles)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <a href="vehicle_catalogue.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results Summary -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted mb-0">
                Showing <?= count($availableAssets) ?> available vehicles
                <?php if ($searchQuery): ?> for "<?= h($searchQuery) ?>"<?php endif; ?>
            </p>
            <div>
                <span class="badge bg-success me-2">
                    <i class="bi bi-check-circle"></i> Available: <?= count($availableAssets) ?>
                </span>
                <span class="badge bg-secondary">
                    <i class="bi bi-clock"></i> Checked Out: <?= count($assets) - count($availableAssets) ?>
                </span>
            </div>
        </div>

        <!-- Vehicle Grid -->
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-4">
            <?php if (empty($availableAssets)): ?>
                <div class="col-12">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        No available vehicles found. Try adjusting your search criteria.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($availableAssets as $asset): ?>
                    <div class="col">
                        <div class="card h-100 vehicle-card">
                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 150px;">
                                <?php if (!empty($asset['image'])): ?>
                                    <img src="image_proxy.php?url=<?= urlencode($asset['image']) ?>" 
                                         alt="<?= h($asset['name']) ?>"
                                         class="img-fluid" style="max-height: 140px; object-fit: contain;">
                                <?php else: ?>
                                    <i class="bi bi-truck text-muted" style="font-size: 4rem;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <h5 class="card-title"><?= h($asset['name']) ?></h5>
                                <p class="card-text">
                                    <small class="text-muted">
                                        <i class="bi bi-tag me-1"></i><?= h($asset['asset_tag']) ?>
                                    </small>
                                </p>
                                <?php if (!empty($asset['model']['name'])): ?>
                                    <p class="card-text mb-1">
                                        <small><i class="bi bi-gear me-1"></i><?= h($asset['model']['name']) ?></small>
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($asset['location']['name'])): ?>
                                    <p class="card-text mb-1">
                                        <small><i class="bi bi-geo-alt me-1"></i><?= h($asset['location']['name']) ?></small>
                                    </p>
                                <?php endif; ?>
                                <span class="badge bg-success mt-2">
                                    <i class="bi bi-check-circle"></i> Available
                                </span>
                            </div>
                            <div class="card-footer bg-transparent">
                                <a href="vehicle_reserve.php?asset_id=<?= $asset['id'] ?>" class="btn btn-primary w-100">
                                    <i class="bi bi-calendar-plus me-1"></i> Reserve Vehicle
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
</div><!-- page-shell -->
    </div>
<?php layout_footer(); ?>
