<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';

$auth = new AdminAuth();
$auth->requireLogin();

use FAS\Config\Database;
use FAS\Models\Product;

// Load config to get the sync API key
$configFile = __DIR__ . '/../src/config/config.php';
$config = file_exists($configFile) ? require $configFile : [];
$syncApiKey = $config['security']['sync_api_key'] ?? 'fas_sync_key_2026';

// Get database connection
$pdo = Database::getInstance()->getConnection();

// Get product count
$productModel = new Product($pdo);
$totalProducts = $productModel->getCountAll();

// Get last sync timestamp
$lastSyncStmt = $pdo->query("SELECT last_sync_timestamp FROM ebay_sync_log WHERE status = 'completed' AND last_sync_timestamp IS NOT NULL ORDER BY last_sync_timestamp DESC LIMIT 1");
$lastSyncRow = $lastSyncStmt->fetch(PDO::FETCH_ASSOC);
// Convert timestamp to ISO 8601 format for JavaScript compatibility
$lastSyncTimestamp = null;
if ($lastSyncRow && $lastSyncRow['last_sync_timestamp']) {
    try {
        $dt = new DateTime($lastSyncRow['last_sync_timestamp']);
        $lastSyncTimestamp = $dt->format('c'); // ISO 8601 format (e.g., 2026-02-11T18:30:00+00:00)
    } catch (Exception $e) {
        error_log('Failed to parse last_sync_timestamp: ' . $e->getMessage());
        $lastSyncTimestamp = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Flip and Strip</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="manifest" href="/admin/manifest.json">
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#db0335">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FAS Admin">
    <link rel="apple-touch-icon" href="/gallery/favicons/favicon-180x180.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
                <h1 class="mb-4" data-aos="fade-down">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </h1>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="100">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Total Products</h6>
                                        <h3 class="mb-0"><?php echo number_format($totalProducts); ?></h3>
                                    </div>
                                    <div class="text-primary">
                                        <i class="fas fa-box display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="200">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Total Orders</h6>
                                        <h3 class="mb-0">0</h3>
                                    </div>
                                    <div class="text-success">
                                        <i class="fas fa-shopping-cart display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="300">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Revenue</h6>
                                        <h3 class="mb-0">$0</h3>
                                    </div>
                                    <div class="text-warning">
                                        <i class="fas fa-dollar-sign display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="400">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Last Sync</h6>
                                        <h6 class="mb-0" id="last-sync-time" data-timestamp="<?php echo htmlspecialchars($lastSyncTimestamp ?? ''); ?>">
                                            <?php echo $lastSyncTimestamp ? 'Loading...' : 'Never'; ?>
                                        </h6>
                                    </div>
                                    <div class="text-info">
                                        <i class="fas fa-sync-alt display-4"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- eBay Sync Section -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-sync-alt me-2"></i>eBay Synchronization</h5>
                    </div>
                    <div class="card-body">
                        <p>Sync products from your eBay store (moto800) to the website database.</p>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Before syncing:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Configure your eBay API credentials in <a href="settings.php" class="alert-link">Settings</a></li>
                                <li>Be aware of eBay rate limits (5,000 calls/day). If you hit a rate limit, wait 5-10 minutes before retrying.</li>
                                <li>Date ranges over 120 days are automatically split into multiple 120-day chunks</li>
                            </ul>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label for="start-date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start-date" 
                                       value="<?php echo date('Y-m-d', strtotime('-120 days')); ?>"
                                       max="<?php echo date('Y-m-d'); ?>">
                                <small class="text-muted">Fetch listings from this date</small>
                            </div>
                            <div class="col-md-6">
                                <label for="end-date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end-date" 
                                       value="<?php echo date('Y-m-d'); ?>"
                                       max="<?php echo date('Y-m-d'); ?>">
                                <small class="text-muted">Fetch listings until this date</small>
                            </div>
                        </div>
                        
                        <button class="btn btn-primary" id="sync-ebay-btn">
                            <i class="fas fa-sync-alt me-2"></i>Start eBay Sync
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

    <script>
        // Convert last sync timestamp to local time
        const lastSyncElement = document.getElementById('last-sync-time');
        if (lastSyncElement) {
            const timestamp = lastSyncElement.getAttribute('data-timestamp');
            if (timestamp) {
                try {
                    const date = new Date(timestamp);
                    // Check if date is valid
                    if (isNaN(date.getTime())) {
                        console.error('Invalid date:', timestamp);
                        lastSyncElement.textContent = 'Invalid date';
                    } else {
                        const options = { 
                            month: 'short', 
                            day: 'numeric', 
                            year: 'numeric', 
                            hour: 'numeric', 
                            minute: '2-digit',
                            hour12: true 
                        };
                        lastSyncElement.textContent = date.toLocaleString('en-US', options);
                    }
                } catch (error) {
                    console.error('Error parsing date:', error);
                    lastSyncElement.textContent = 'Error';
                }
            }
        }
        
        // Date range validation
        const startDateInput = document.getElementById('start-date');
        const endDateInput = document.getElementById('end-date');
        
        function validateDateRange() {
            const startDate = new Date(startDateInput.value);
            const endDate = new Date(endDateInput.value);
            const daysDiff = Math.floor((endDate - startDate) / (1000 * 60 * 60 * 24));
            
            if (daysDiff < 0) {
                alert('End date must be after start date');
                return false;
            }
            
            // Note: Date ranges over 120 days are automatically split into 120-day chunks by the API
            if (daysDiff > 120) {
                const chunks = Math.ceil((daysDiff + 1) / 120);
                if (!confirm(`This date range spans ${daysDiff} days and will be automatically split into ${chunks} chunks of 120 days each. Continue?`)) {
                    return false;
                }
            }
            
            return true;
        }
        
        startDateInput.addEventListener('change', validateDateRange);
        endDateInput.addEventListener('change', validateDateRange);
        
        document.getElementById('sync-ebay-btn').addEventListener('click', function() {
            const btn = this;
            const statusDiv = document.getElementById('sync-status');
            
            // Validate date range
            if (!validateDateRange()) {
                return;
            }
            
            const startDate = startDateInput.value;
            const endDate = endDateInput.value;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Syncing...';
            
            statusDiv.innerHTML = '<div class="alert alert-info">Starting eBay synchronization...</div>';
            
            // Call sync API with date parameters
            fetch(`../api/ebay-sync.php?key=<?php echo htmlspecialchars($syncApiKey, ENT_QUOTES, 'UTF-8'); ?>&start_date=${startDate}&end_date=${endDate}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => {
                            throw new Error(err.message || 'HTTP error ' + response.status);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        let syncDetails = `
                            <div class="alert alert-success">
                                <strong>Sync Completed!</strong><br>
                                Processed: ${data.processed}<br>
                                Added: ${data.added}<br>
                                Updated: ${data.updated}<br>
                                Failed: ${data.failed}`;
                        
                        // Show additional info for multi-range syncs
                        if (data.date_ranges_processed) {
                            syncDetails += `<br>Date Ranges: ${data.date_ranges_processed}`;
                            if (data.date_ranges_empty > 0) {
                                syncDetails += ` (${data.date_ranges_empty} had no items)`;
                            }
                        }
                        
                        if (data.message) {
                            syncDetails += `<br><small class="text-muted">${data.message}</small>`;
                        }
                        
                        syncDetails += `</div>`;
                        statusDiv.innerHTML = syncDetails;
                    } else if (data.error) {
                        let helpLink = '';
                        if (data.help) {
                            helpLink = `<br><small><a href="${data.help}" class="alert-link" target="_blank">Click here for help</a></small>`;
                        }
                        statusDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <strong>Sync Failed:</strong><br>
                                ${data.message || data.error}
                                ${helpLink}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    statusDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <strong>Error:</strong> ${error.message}<br>
                            <small>Please check your eBay API credentials in <a href="settings.php" class="alert-link">Settings</a>.</small>
                        </div>
                    `;
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Start eBay Sync';
                });
        });
    </script>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
