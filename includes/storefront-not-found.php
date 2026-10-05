<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';

function fasStorefrontNotFound(): void
{
    http_response_code(404);
    header('X-Robots-Tag: noindex, follow');
    header('Cache-Control: no-store');
    if (!\FAS\Utils\ErrorMonitor::isKnownNonAiCrawler($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        try {
            $monitor = new \FAS\Utils\ErrorMonitor(\FAS\Config\Database::getInstance()->getConnection());
            $monitor->recordNotFound();
        } catch (\Throwable $e) {
            error_log('404 landing logging failed: ' . $e->getMessage());
        }
    }
    $metaTitle = 'Page Not Found | Flip and Strip';
    $metaDescription = 'This page is unavailable. Search the current Flip and Strip inventory or browse all parts.';
    $robotsMeta = 'noindex, follow';
    $structuredData = [];
    require __DIR__ . '/header.php';
    ?>
    <main class="container my-5 py-4">
        <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9 text-center">
                <p class="text-danger fw-bold mb-2">404</p>
                <h1 class="fw-bold">We couldn't find that page</h1>
                <p class="text-muted my-3">The listing or page may no longer be available. Search our current inventory to find another part.</p>
                <form action="/products" method="get" class="my-4 text-start">
                    <label for="missing-page-search" class="form-label">Search parts</label>
                    <div class="input-group">
                        <input id="missing-page-search" type="search" name="search" class="form-control" placeholder="Part name, brand, or part number" required>
                        <button class="btn btn-danger" type="submit">Search</button>
                    </div>
                </form>
                <a href="/products" class="btn btn-outline-danger me-2 mb-2">Browse all parts</a>
                <a href="/contact" class="btn btn-secondary mb-2">Contact us</a>
            </div>
        </div>
    </main>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}
