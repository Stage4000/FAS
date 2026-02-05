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
    <title>eBay User Token Guide - Admin Panel</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>

            <h1 class="mb-4"><i class="bi bi-question-circle"></i> How to Obtain eBay User Token</h1>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-1-circle-fill text-primary"></i> Create an eBay Developer Account</h4>
                    <p>First, you need to register as an eBay developer:</p>
                    <ol>
                        <li>Go to <a href="https://developer.ebay.com" target="_blank" class="text-decoration-none">https://developer.ebay.com <i class="bi bi-box-arrow-up-right"></i></a></li>
                        <li>Click "Register" or "Sign in" if you already have an account</li>
                        <li>Complete the registration process</li>
                    </ol>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-2-circle-fill text-primary"></i> Create an Application</h4>
                    <p>Once logged in to the developer portal:</p>
                    <ol>
                        <li>Navigate to "My Account" → "Application Keys"</li>
                        <li>Click "Create a Keyset" or "Get Your Application Keys"</li>
                        <li>Choose "Production Keys" (not Sandbox) for live data</li>
                        <li>Fill in your application details</li>
                        <li>Accept the terms and conditions</li>
                    </ol>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i><strong>Note:</strong> You'll receive:
                        <ul class="mb-0 mt-2">
                            <li><strong>App ID (Client ID)</strong></li>
                            <li><strong>Dev ID</strong></li>
                            <li><strong>Cert ID (Client Secret)</strong></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-3-circle-fill text-primary"></i> Generate User Token</h4>
                    <p>To get your User Token (Auth Token):</p>
                    <ol>
                        <li>In the Developer Portal, go to "Application Keys"</li>
                        <li>Find your application</li>
                        <li>Click "Get a User Token" or "Get Token from eBay via Your Application"</li>
                        <li>You'll be redirected to eBay to grant permissions</li>
                        <li>Sign in with your eBay account (the account that has your store)</li>
                        <li>Grant the requested permissions</li>
                        <li>You'll receive a User Token that's valid for 18 months</li>
                    </ol>
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle me-2"></i><strong>Important:</strong> The User Token is tied to your eBay account and allows the application to access your listings. Keep it secure!
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-4-circle-fill text-primary"></i> Add Token to Settings</h4>
                    <p>Once you have all your credentials:</p>
                    <ol>
                        <li>Go to <a href="settings.php" class="text-decoration-none">Settings <i class="bi bi-arrow-right"></i></a></li>
                        <li>Scroll to the "eBay API Settings" section</li>
                        <li>Fill in:
                            <ul class="mt-2">
                                <li><strong>App ID:</strong> Your Client ID from step 2</li>
                                <li><strong>Cert ID:</strong> Your Client Secret from step 2</li>
                                <li><strong>Dev ID:</strong> Your Developer ID from step 2</li>
                                <li><strong>User Token:</strong> The Auth Token from step 3</li>
                                <li><strong>Store Name:</strong> Your eBay store name (e.g., moto800)</li>
                            </ul>
                        </li>
                        <li>Click "Save Settings"</li>
                    </ol>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-5-circle-fill text-primary"></i> Test Your Integration</h4>
                    <p>After adding your credentials:</p>
                    <ol>
                        <li>Go to <a href="index.php" class="text-decoration-none">Dashboard <i class="bi bi-arrow-right"></i></a></li>
                        <li>Click "Start eBay Sync" button</li>
                        <li>Watch the progress bar as products are imported</li>
                        <li>Check for any errors in the sync log</li>
                    </ol>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4 bg-light">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-link-45deg"></i> Helpful Links</h5>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="bi bi-arrow-right-circle text-primary"></i>
                            <a href="https://developer.ebay.com" target="_blank" class="ms-2">eBay Developer Portal <i class="bi bi-box-arrow-up-right"></i></a>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-arrow-right-circle text-primary"></i>
                            <a href="https://developer.ebay.com/api-docs/static/oauth-auth-code-grant.html" target="_blank" class="ms-2">OAuth Documentation <i class="bi bi-box-arrow-up-right"></i></a>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-arrow-right-circle text-primary"></i>
                            <a href="https://developer.ebay.com/api-docs/static/gs_create-the-ebay-api-keysets.html" target="_blank" class="ms-2">Create API Keys Guide <i class="bi bi-box-arrow-up-right"></i></a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="alert alert-success">
                <h5 class="alert-heading"><i class="bi bi-check-circle me-2"></i>Need Help?</h5>
                <p class="mb-0">If you encounter any issues, please check the eBay Developer documentation or contact eBay Developer Support.</p>
            </div>

            <div class="text-center mb-4">
                <a href="settings.php" class="btn btn-primary btn-lg">
                    <i class="bi bi-arrow-left me-2"></i>Back to Settings
                </a>
            </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
