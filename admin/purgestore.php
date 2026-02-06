<?php
/**
 * Temporary Testing Utility - Purge eBay Store Products
 * This script removes all eBay-sourced products from the database
 * WARNING: This is for testing only and will permanently delete data
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';

$adminAuth = new AdminAuth();
$adminAuth->requireLogin();

use FAS\Config\Database;

$dbConnection = Database::getInstance()->getConnection();
$operationComplete = false;
$itemsRemoved = 0;
$syncLogsRemoved = 0;
$errorOccurred = '';

// Handle purge request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_purge'])) {
    $confirmationText = $_POST['confirmation'] ?? '';
    
    if ($confirmationText === 'DELETE ALL STORE ITEMS') {
        try {
            // Count items before deletion
            $countQuery = $dbConnection->query("SELECT COUNT(*) as total FROM products WHERE source = 'ebay'");
            $countResult = $countQuery->fetch(PDO::FETCH_ASSOC);
            $itemsRemoved = $countResult['total'];
            
            // Count sync logs before deletion
            $syncLogCountQuery = $dbConnection->query("SELECT COUNT(*) as total FROM ebay_sync_log");
            $syncLogCountResult = $syncLogCountQuery->fetch(PDO::FETCH_ASSOC);
            $syncLogsRemoved = $syncLogCountResult['total'];
            
            // Execute deletion - use transaction for safety
            $dbConnection->beginTransaction();
            
            // Delete eBay products
            $deleteQuery = $dbConnection->prepare("DELETE FROM products WHERE source = ?");
            $deleteQuery->execute(['ebay']);
            
            // Delete sync logs
            $deleteSyncLogQuery = $dbConnection->prepare("DELETE FROM ebay_sync_log");
            $deleteSyncLogQuery->execute();
            
            $dbConnection->commit();
            
            $operationComplete = true;
        } catch (Exception $ex) {
            if ($dbConnection->inTransaction()) {
                $dbConnection->rollBack();
            }
            $errorOccurred = 'Deletion failed: ' . $ex->getMessage();
        }
    } else {
        $errorOccurred = 'Confirmation text did not match. Operation cancelled.';
    }
}

// Get current count
$currentCountQuery = $dbConnection->query("SELECT COUNT(*) as total FROM products WHERE source = 'ebay'");
$currentCount = $currentCountQuery->fetch(PDO::FETCH_ASSOC)['total'];

// Get current sync log count
$currentSyncLogQuery = $dbConnection->query("SELECT COUNT(*) as total FROM ebay_sync_log");
$currentSyncLogCount = $currentSyncLogQuery->fetch(PDO::FETCH_ASSOC)['total'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purge Store Products - Testing Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .danger-zone {
            border: 3px solid #dc3545;
            background-color: #fff5f5;
            padding: 2rem;
            border-radius: 10px;
            margin: 2rem 0;
        }
        .warning-box {
            background-color: #fff3cd;
            border-left: 5px solid #ffc107;
            padding: 1rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card shadow">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0"><i class="fas fa-trash-alt me-2"></i>Store Products Purge Tool</h4>
                    </div>
                    <div class="card-body">
                        
                        <?php if ($operationComplete): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Purge Complete!</strong><br>
                                Successfully removed <?php echo $itemsRemoved; ?> eBay store product(s) from the database.<br>
                                Successfully removed <?php echo $syncLogsRemoved; ?> sync log <?php echo $syncLogsRemoved === 1 ? 'entry' : 'entries'; ?> from ebay_sync_log table.
                            </div>
                            <a href="index.php" class="btn btn-primary">Return to Dashboard</a>
                            <a href="purgestore.php" class="btn btn-secondary">Purge More</a>
                        <?php elseif ($errorOccurred): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Error:</strong> <?php echo htmlspecialchars($errorOccurred); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$operationComplete): ?>
                            <div class="warning-box">
                                <h5><i class="fas fa-exclamation-triangle text-warning me-2"></i>Testing Utility</h5>
                                <p class="mb-0">This tool is designed for testing the eBay sync functionality by clearing imported store products.</p>
                            </div>
                            
                            <div class="danger-zone">
                                <h5 class="text-danger mb-3">
                                    <i class="fas fa-skull-crossbones me-2"></i>DANGER ZONE
                                </h5>
                                
                                <p><strong>Current Status:</strong></p>
                                <ul>
                                    <li>eBay Store Products: <strong><?php echo $currentCount; ?></strong></li>
                                    <li>eBay Sync Log Entries: <strong><?php echo $currentSyncLogCount; ?></strong></li>
                                    <li>Source Filter: <code>source = 'ebay'</code></li>
                                </ul>
                                
                                <div class="alert alert-danger">
                                    <h6>⚠️ WARNING - Irreversible Action</h6>
                                    <p>This will permanently delete all products with source='ebay' from your database AND all sync logs from ebay_sync_log table.</p>
                                    <ul class="mb-0">
                                        <li>Manual products will NOT be affected</li>
                                        <li>Order history remains intact</li>
                                        <li>eBay sync history will be cleared</li>
                                        <li>This action CANNOT be undone</li>
                                    </ul>
                                </div>
                                
                                <?php if ($currentCount > 0): ?>
                                    <form method="POST" onsubmit="return confirmDeletion();">
                                        <div class="mb-3">
                                            <label class="form-label">Type <strong>DELETE ALL STORE ITEMS</strong> to confirm:</label>
                                            <input type="text" 
                                                   class="form-control form-control-lg" 
                                                   name="confirmation" 
                                                   placeholder="Enter confirmation text"
                                                   autocomplete="off"
                                                   required>
                                        </div>
                                        
                                        <button type="submit" 
                                                name="confirm_purge" 
                                                class="btn btn-danger btn-lg">
                                            <i class="fas fa-trash-alt me-2"></i>Purge <?php echo $currentCount; ?> Store Products
                                        </button>
                                        <a href="index.php" class="btn btn-secondary btn-lg">Cancel</a>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        No eBay store products found in the database.
                                    </div>
                                    <a href="index.php" class="btn btn-primary">Return to Dashboard</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                    </div>
                </div>
                
                <div class="text-center mt-3 text-muted">
                    <small>⚙️ Testing Tool - Remove before production deployment</small>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function confirmDeletion() {
            const userInput = document.querySelector('input[name="confirmation"]').value;
            if (userInput !== 'DELETE ALL STORE ITEMS') {
                alert('Confirmation text does not match. Please type exactly: DELETE ALL STORE ITEMS');
                return false;
            }
            return confirm('Are you absolutely sure? This will permanently delete all eBay store products!');
        }
    </script>
</body>
</html>
