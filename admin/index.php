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
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
                <h1 class="mb-4" data-aos="fade-down">Dashboard</h1>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="100">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Total Products</h6>
                                        <h3 class="mb-0">0</h3>
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
                                        <h6 class="mb-0">Never</h6>
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
                                <li>eBay requires date ranges to be less than 120 days apart</li>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
            
            if (daysDiff > 120) {
                alert('Date range must be less than 120 days (eBay requirement)');
                return false;
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
            fetch(`../api/ebay-sync.php?key=fas_sync_key_2026&start_date=${startDate}&end_date=${endDate}`)
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
                        statusDiv.innerHTML = `
                            <div class="alert alert-success">
                                <strong>Sync Completed!</strong><br>
                                Processed: ${data.processed}<br>
                                Added: ${data.added}<br>
                                Updated: ${data.updated}<br>
                                Failed: ${data.failed}
                            </div>
                        `;
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
                    
                    // Reload page after a successful sync to update stats
                    if (statusDiv.querySelector('.alert-success')) {
                        setTimeout(() => location.reload(), 3000);
                    }
                });
        });
    </script>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
