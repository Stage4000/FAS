<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin PWA Test Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0"><i class="fas fa-check-circle me-2"></i>Admin PWA Test</h4>
                    </div>
                    <div class="card-body">
                        <p class="lead">This page helps verify the PWA setup for the admin panel.</p>
                        
                        <h5 class="mt-4">Checklist:</h5>
                        <div class="list-group">
                            <div class="list-group-item">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-file-code text-success me-3"></i>
                                    <div>
                                        <strong>Manifest.json</strong>
                                        <div id="manifestStatus" class="text-muted">Checking...</div>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-cog text-success me-3"></i>
                                    <div>
                                        <strong>Service Worker</strong>
                                        <div id="swStatus" class="text-muted">Checking...</div>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-mobile-alt text-success me-3"></i>
                                    <div>
                                        <strong>PWA Installable</strong>
                                        <div id="installStatus" class="text-muted">Checking...</div>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-lock text-success me-3"></i>
                                    <div>
                                        <strong>HTTPS/Localhost</strong>
                                        <div id="httpsStatus" class="text-muted">Checking...</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info mt-4">
                            <strong><i class="fas fa-info-circle me-2"></i>Note:</strong>
                            This test page should only be used for verification during development.
                            Delete this file before deploying to production.
                        </div>

                        <div class="mt-3">
                            <a href="index.php" class="btn btn-danger">
                                <i class="fas fa-arrow-left me-2"></i>Back to Admin
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Check manifest
        fetch('/admin/manifest.json')
            .then(response => {
                if (response.ok) {
                    return response.json();
                }
                throw new Error(`HTTP ${response.status}`);
            })
            .then(manifest => {
                document.getElementById('manifestStatus').innerHTML = 
                    '<span class="text-success">✓ Loaded successfully</span> - ' + 
                    manifest.name + ' (' + manifest.icons.length + ' icons)';
            })
            .catch(error => {
                document.getElementById('manifestStatus').innerHTML = 
                    '<span class="text-danger">✗ Failed: ' + error.message + '</span>';
            });

        // Check service worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistration('/admin/')
                .then(registration => {
                    if (registration) {
                        document.getElementById('swStatus').innerHTML = 
                            '<span class="text-success">✓ Registered</span> - Scope: ' + registration.scope;
                    } else {
                        document.getElementById('swStatus').innerHTML = 
                            '<span class="text-warning">⚠ Not registered yet</span> - Will register on admin login';
                    }
                })
                .catch(error => {
                    document.getElementById('swStatus').innerHTML = 
                        '<span class="text-danger">✗ Error: ' + error.message + '</span>';
                });
        } else {
            document.getElementById('swStatus').innerHTML = 
                '<span class="text-danger">✗ Service Workers not supported</span>';
        }

        // Check if installable
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            document.getElementById('installStatus').innerHTML = 
                '<span class="text-success">✓ PWA is installable</span>';
        });

        // Check for already installed
        window.addEventListener('load', () => {
            if (window.matchMedia('(display-mode: standalone)').matches) {
                document.getElementById('installStatus').innerHTML = 
                    '<span class="text-success">✓ Already installed and running as PWA</span>';
            } else {
                setTimeout(() => {
                    if (!deferredPrompt) {
                        document.getElementById('installStatus').innerHTML = 
                            '<span class="text-info">ℹ Not installable yet - may need to visit admin twice or already installed</span>';
                    }
                }, 2000);
            }
        });

        // Check HTTPS
        const isSecure = window.location.protocol === 'https:' || 
                        window.location.hostname === 'localhost' || 
                        window.location.hostname === '127.0.0.1';
        document.getElementById('httpsStatus').innerHTML = isSecure ? 
            '<span class="text-success">✓ Secure context (' + window.location.protocol + ')</span>' :
            '<span class="text-danger">✗ Insecure - PWA requires HTTPS or localhost</span>';
    </script>
</body>
</html>
