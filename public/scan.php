<?php
/**
 * QR Code Scanner
 * Uses device camera to scan Snipe-IT QR codes
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';

$active = 'scan.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scan QR Code</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.3.2">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        #reader {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        #reader video {
            border-radius: 10px;
        }
        .scan-overlay {
            position: relative;
        }
        .scan-instructions {
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            margin-bottom: 15px;
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Scan QR Code</h1>
            <p class="text-muted">Point your camera at the vehicle's QR code</p>
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
                <a href="logout" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="scan-instructions">
                            <i class="bi bi-qr-code-scan me-2"></i>
                            Position the QR code within the frame
                        </div>
                        <div id="reader"></div>
                        <div id="scan-result" class="mt-3" style="display: none;">
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <span id="result-text">QR Code detected!</span>
                            </div>
                        </div>
                        <div id="scan-error" class="mt-3" style="display: none;">
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <span id="error-text">Error</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-keyboard me-2"></i>Or Enter Manually</h6>
                    </div>
                    <div class="card-body">
                        <form action="quick" method="get" class="row g-2">
                            <div class="col-8">
                                <input type="text" name="tag" class="form-control" placeholder="Asset Tag (e.g., BPTR-VEH-001)">
                            </div>
                            <div class="col-4">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center">
                    <a href="quick" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const html5QrCode = new Html5Qrcode("reader");
    
    const config = {
        fps: 10,
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0
    };

    function onScanSuccess(decodedText, decodedResult) {
        console.log('Scanned:', decodedText);
        
        // Stop scanning
        html5QrCode.stop().then(() => {
            // Extract asset ID from Snipe-IT URL
            // Expected format: https://inventory.amtrakfdt.com/hardware/300
            const match = decodedText.match(/\/hardware\/(\d+)/);
            
            if (match) {
                const assetId = match[1];
                document.getElementById('scan-result').style.display = 'block';
                document.getElementById('result-text').textContent = 'Vehicle found! Redirecting...';
                
                // Redirect to quick action page
                setTimeout(() => {
                    window.location.href = 'quick?asset_id=' + assetId;
                }, 500);
            } else {
                // Try to use the URL directly
                document.getElementById('scan-error').style.display = 'block';
                document.getElementById('error-text').textContent = 'Invalid QR code format. Please try again.';
                
                // Restart scanner after 2 seconds
                setTimeout(() => {
                    document.getElementById('scan-error').style.display = 'none';
                    startScanner();
                }, 2000);
            }
        });
    }

    function onScanError(errorMessage) {
        // Ignore errors during scanning (normal when no QR visible)
    }

    function startScanner() {
        html5QrCode.start(
            { facingMode: "environment" }, // Use back camera
            config,
            onScanSuccess,
            onScanError
        ).catch(err => {
            console.error('Camera error:', err);
            document.getElementById('scan-error').style.display = 'block';
            document.getElementById('error-text').textContent = 'Could not access camera. Please check permissions or enter asset tag manually.';
        });
    }

    startScanner();
});
</script>
<?php layout_footer(); ?>
</body>
</html>
