<?php
require_once __DIR__ . '/auth.php';

$auth = new AdminAuth();
$auth->requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Flip and Strip</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
                <h1 class="mb-4">Dashboard</h1>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Total Products</h6>
                                        <h3 class="mb-0">0</h3>
                                    </div>
                                    <div class="text-primary">
                                        <i class="bi bi-box-seam display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Total Orders</h6>
                                        <h3 class="mb-0">0</h3>
                                    </div>
                                    <div class="text-success">
                                        <i class="bi bi-cart-check display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Revenue</h6>
                                        <h3 class="mb-0">$0</h3>
                                    </div>
                                    <div class="text-warning">
                                        <i class="bi bi-currency-dollar display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Last Sync</h6>
                                        <h6 class="mb-0">Never</h6>
                                    </div>
                                    <div class="text-info">
                                        <i class="bi bi-arrow-repeat display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- eBay Sync Section -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>eBay Synchronization</h5>
                    </div>
                    <div class="card-body">
                        <p>Sync products from your eBay store (moto800) to the website database.</p>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> Before syncing, make sure to configure your eBay API credentials in <code>src/config/config.php</code>
                        </div>
                        <button class="btn btn-primary" id="sync-ebay-btn">
                            <i class="bi bi-arrow-repeat me-2"></i>Start eBay Sync
                        </button>
                        <div id="sync-status" class="mt-3"></div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-secondary">
                            No recent activity
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('sync-ebay-btn').addEventListener('click', function() {
            const btn = this;
            const statusDiv = document.getElementById('sync-status');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Syncing...';
            
            statusDiv.innerHTML = '<div class="alert alert-info">Starting eBay synchronization...</div>';
            
            // Call sync API
            fetch('../api/ebay-sync.php?key=fas_sync_key_2026')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        statusDiv.innerHTML = `
                            <div class="alert alert-success">
                                <strong>Sync Completed!</strong><br>
                                Processed: ${data.processed}<br>
                                Added: ${data.added}<br>
                                Updated: ${data.updated}<br>
                                Failed: ${data.failed}
                            </div>
                        `;
                    } else {
                        statusDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <strong>Sync Failed:</strong> ${data.error}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    statusDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <strong>Error:</strong> ${error.message}
                        </div>
                    `;
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i>Start eBay Sync';
                });
        });
    </script>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
