<?php
/**
 * Fleet Management - Home Page
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';

$active = 'index.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FDT Fleet Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.3.2">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <style>
        .feature-card {
            transition: all 0.2s ease;
            border: 1px solid #e0e0e0;
            height: 100%;
        }
        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .feature-card .btn {
            margin-top: auto;
        }
        .staff-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header text-center">
            <h1>Fleet Management</h1>
            <p class="text-muted">Book vehicles, manage reservations, and track fleet status</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <!-- User Info -->
        <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-light rounded">
            <div>
                <span class="text-muted">Logged in as:</span> 
                <strong><?= h($userName) ?></strong> 
                <span class="text-muted">(<?= h($userEmail) ?>)</span>
                <?php if ($isStaff): ?>
                    <span class="badge bg-primary ms-2">Staff</span>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <span class="badge bg-danger ms-2">Admin</span>
                <?php endif; ?>
            </div>
            <a href="logout" class="btn btn-outline-secondary btn-sm">Log out</a>
        </div>

        <!-- Main Actions for All Users -->
        <div class="row g-4 mb-4">
            <!-- Book Vehicle -->
            <div class="col-md-6 col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="feature-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-calendar-plus"></i>
                        </div>
                        <h5 class="card-title">Book a Vehicle</h5>
                        <p class="card-text text-muted">Reserve a vehicle for your upcoming trip. Select dates, pickup location, and destination.</p>
                        <a href="vehicle_reserve" class="btn btn-primary mt-auto">
                            <i class="bi bi-plus-circle me-2"></i>New Booking
                        </a>
                    </div>
                </div>
            </div>

            <!-- My Reservations -->
            <div class="col-md-6 col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="feature-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <h5 class="card-title">My Reservations</h5>
                        <p class="card-text text-muted">View your upcoming and past reservations. Check status and cancel bookings if needed.</p>
                        <a href="my_bookings" class="btn btn-outline-info mt-auto">
                            <i class="bi bi-eye me-2"></i>View Reservations
                        </a>
                    </div>
                </div>
            </div>

            <!-- Scan QR / Quick Action -->
            <div class="col-md-6 col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="feature-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-qr-code-scan"></i>
                        </div>
                        <h5 class="card-title">Scan QR Code</h5>
                        <p class="card-text text-muted">Scan vehicle QR code for quick checkout or return. Automatically detects the right action.</p>
                        <a href="scan" class="btn btn-outline-success mt-auto">
                            <i class="bi bi-qr-code me-2"></i>Open Scanner
                        </a>
                    </div>
                </div>
            </div>

            <!-- Vehicle Catalogue -->
            <div class="col-md-6 col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="feature-icon bg-secondary bg-opacity-10 text-secondary">
                            <i class="bi bi-truck"></i>
                        </div>
                        <h5 class="card-title">Vehicle Catalogue</h5>
                        <p class="card-text text-muted">Browse available vehicles, view details, specifications, and current availability status.</p>
                        <a href="vehicle_catalogue" class="btn btn-outline-secondary mt-auto">
                            <i class="bi bi-grid me-2"></i>Browse Vehicles
                        </a>
                    </div>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="col-md-6 col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="feature-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <h5 class="card-title">Dashboard</h5>
                        <p class="card-text text-muted">View fleet overview, today's schedule, and quick stats on vehicle availability.</p>
                        <a href="dashboard" class="btn btn-outline-warning mt-auto">
                            <i class="bi bi-graph-up me-2"></i>View Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($isStaff): ?>
            <!-- Approvals (Staff Only) -->
            <div class="col-md-6 col-lg-4">
                <div class="card feature-card h-100 border-primary">
                    <div class="card-body d-flex flex-column">
                        <div class="feature-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-check-square"></i>
                        </div>
                        <h5 class="card-title">Approvals <span class="badge bg-primary">Staff</span></h5>
                        <p class="card-text text-muted">Review and approve pending vehicle reservation requests from users.</p>
                        <a href="approval" class="btn btn-primary mt-auto">
                            <i class="bi bi-check-circle me-2"></i>Review Requests
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($isStaff): ?>
        <!-- Staff Section -->
        <div class="staff-section">
            <h5 class="mb-3"><i class="bi bi-shield-check me-2"></i>Staff Tools</h5>
            <div class="row g-3">
                <div class="col-md-4 col-lg-3">
                    <a href="reservations" class="btn btn-outline-dark w-100">
                        <i class="bi bi-calendar-range me-2"></i>All Reservations
                    </a>
                </div>
                <div class="col-md-4 col-lg-3">
                    <a href="maintenance" class="btn btn-outline-dark w-100">
                        <i class="bi bi-wrench me-2"></i>Maintenance
                    </a>
                </div>
                <div class="col-md-4 col-lg-3">
                    <a href="reports" class="btn btn-outline-dark w-100">
                        <i class="bi bi-bar-chart me-2"></i>Reports
                    </a>
                </div>
                <?php if ($isAdmin): ?>
                <div class="col-md-4 col-lg-3">
                    <a href="activity_log" class="btn btn-outline-danger w-100">
                        <i class="bi bi-gear me-2"></i>Admin
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Help -->
        <div class="mt-4 p-3 bg-light rounded text-center">
            <i class="bi bi-question-circle me-2"></i>
            Need help or something is missing? Please contact the Fleet Administrator.
        </div>

    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
