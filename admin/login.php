<?php
require_once __DIR__ . '/auth.php';

$auth = new AdminAuth();

// Handle login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($auth->login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Flip and Strip</title>
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
    <link rel="stylesheet" href="css/admin-style.css">
    <style>
        body {
            background: linear-gradient(135deg, #db0335 0%, #242629 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            max-width: 450px;
            margin: 0 auto;
        }
        [data-theme="dark"] body {
            background: linear-gradient(135deg, #a00228 0%, #1a1a1a 100%);
        }
        [data-theme="dark"] .card {
            background-color: #2a2a2a;
            color: #e0e0e0;
        }
        [data-theme="dark"] .text-muted {
            color: #aaa !important;
        }
        [data-theme="dark"] .text-white-50 {
            color: rgba(255, 255, 255, 0.6) !important;
        }
    </style>
</head>
<body>
    <!-- Theme toggle button -->
    <button class="theme-toggle" id="themeToggle" tabindex="0" aria-label="Toggle theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    
    <div class="container">
        <div class="login-card">
            <div class="card shadow-lg border-0">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <img src="../gallery/FLIPANDSTRIP.COM_d00a_018a.jpg" alt="Flip and Strip" class="rounded-circle mb-3" style="width: 100px;">
                        <h3 class="fw-bold">Admin Login</h3>
                        <p class="text-muted">Flip and Strip Administration</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control" name="username" required autofocus>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-danger w-100 py-2">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </button>
                    </form>
                    
                    <div class="text-center mt-4">
                        <a href="../index.php" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Website
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../public/js/theme-toggle.js"></script>
    <!-- PWA Installer Script -->
    <script src="/admin/js/pwa-installer.js"></script>
</body>
</html>
