<?php
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$recover=($emailActionPage??'')==='recover';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<title><?= $recover?'Your Saved Cart':'Email Preferences' ?> | Flip and Strip</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>body{background:#f5f5f5;color:#242629}.email-card{max-width:620px;margin:8vh auto;padding:clamp(24px,6vw,48px);border-radius:24px;background:white;box-shadow:0 12px 48px #2426290c}.brand{color:#bd163e;font-weight:800;letter-spacing:.08em}.btn-danger{background:#ce1743;border-color:#ce1743}a{color:#bd163e}</style>
</head><body><main class="container">
<section class="email-card">
    <a href="/" class="brand text-decoration-none">FLIP AND STRIP</a>
    <h1 class="h2 fw-bold mt-4"><?= $recover?'Your saved cart':'Your email preferences' ?></h1>
    <p id="email-action-description"><?= $recover?'Restore available items to your cart. Current prices and quantities apply; shipping is calculated at checkout.':'Use the button below to confirm the choice in your email.' ?></p>
    <button type="button" id="email-action-button" class="btn btn-danger px-4 py-2"><?= $recover?'Restore available items':'Continue' ?></button>
    <p id="email-action-status" class="mt-3 mb-0" role="status" aria-live="polite"></p>
    <a href="/products" class="d-inline-block mt-4">Browse parts</a>
</section></main>
<script src="/public/js/growth.js?v=20260930-1"></script>
<script src="/public/js/email-action.js?v=20260930-1" data-mode="<?= $recover?'recover':'preferences' ?>"></script>
</body></html>
