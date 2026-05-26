<?php

if (!function_exists('fasGetSellerRatingConfig')) {
    function fasGetSellerRatingConfig(): array
    {
        static $config;

        if ($config !== null) {
            return $config;
        }

        $configPath = __DIR__ . '/../src/config/config.php';
        $loaded = file_exists($configPath) ? require $configPath : [];
        $storeName = trim((string) ($loaded['ebay']['store_name'] ?? ''));

        $config = [
            'store_name' => $storeName,
            'store_url' => $storeName !== '' ? 'https://www.ebay.com/str/' . rawurlencode($storeName) : null,
        ];

        return $config;
    }
}

if (!function_exists('fasGetCachedSellerRating')) {
    function fasGetCachedSellerRating(): ?array
    {
        static $sellerRatingLoaded = false;
        static $sellerRating = null;

        if ($sellerRatingLoaded) {
            return $sellerRating;
        }

        $sellerRatingLoaded = true;

        $config = fasGetSellerRatingConfig();
        if (empty($config['store_name'])) {
            return null;
        }

        try {
            require_once __DIR__ . '/../src/config/Database.php';
            require_once __DIR__ . '/../src/models/EbaySellerRating.php';

            $db = \FAS\Config\Database::getInstance()->getConnection();
            $model = new \FAS\Models\EbaySellerRating($db);
            $sellerRating = $model->getLatest($config['store_name']);

            if ($sellerRating) {
                if (empty($sellerRating['store_url']) && !empty($config['store_url'])) {
                    $sellerRating['store_url'] = $config['store_url'];
                }

                if (empty($sellerRating['seller_name'])) {
                    $sellerRating['seller_name'] = $config['store_name'];
                }

                if ($sellerRating['feedback_score'] === null || $sellerRating['positive_feedback_percent'] === null) {
                    $sellerRating = null;
                }
            }
        } catch (\Throwable $e) {
            error_log('Failed to load cached eBay seller rating: ' . $e->getMessage());
            $sellerRating = null;
        }

        return $sellerRating;
    }
}

if (!function_exists('fasRenderSellerRatingBlock')) {
    function fasRenderSellerRatingBlock(?array $sellerRating, string $variant = 'default'): string
    {
        if (!$sellerRating) {
            return '';
        }

        $sellerName = htmlspecialchars((string) ($sellerRating['seller_name'] ?? ''));
        $feedbackScore = number_format((int) $sellerRating['feedback_score']);
        $positiveFeedbackPercent = number_format((float) $sellerRating['positive_feedback_percent'], 1);
        $storeUrl = htmlspecialchars((string) ($sellerRating['store_url'] ?? '#'));

        ob_start();

        if ($variant === 'footer') {
            ?>
            <div class="mt-3">
                <p class="mb-1 text-white-50">
                    <i class="fas fa-award text-danger me-2"></i>
                    <strong class="text-white"><?php echo $feedbackScore; ?></strong>
                    feedback score
                </p>
                <p class="mb-1 text-white-50">
                    <i class="fas fa-thumbs-up text-danger me-2"></i>
                    <strong class="text-white"><?php echo $positiveFeedbackPercent; ?>%</strong>
                    positive feedback
                </p>
                <p class="mb-0">
                    <a href="<?php echo $storeUrl; ?>" target="_blank" rel="noopener noreferrer" class="text-white text-decoration-none">
                        <i class="fas fa-store me-2"></i><?php echo $sellerName; ?> on eBay
                    </a>
                </p>
            </div>
            <?php
        } elseif ($variant === 'homepage') {
            ?>
            <section class="py-4 bg-dark text-white">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-lg-10">
                            <div class="card bg-transparent border border-danger-subtle shadow-sm">
                                <div class="card-body px-4 py-4">
                                    <div class="row align-items-center g-3">
                                        <div class="col-md-8 text-center text-md-start">
                                            <div class="text-uppercase text-danger fw-semibold small mb-2">Trusted eBay Seller</div>
                                            <h3 class="h4 mb-2"><?php echo $sellerName; ?></h3>
                                            <p class="mb-0 text-white-50">Shop with confidence backed by verified eBay feedback.</p>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="row g-2 text-center">
                                                <div class="col-6">
                                                    <div class="rounded bg-black bg-opacity-25 p-3 h-100">
                                                        <div class="h3 mb-1 text-danger"><?php echo $feedbackScore; ?></div>
                                                        <div class="small text-white-50">Feedback score</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="rounded bg-black bg-opacity-25 p-3 h-100">
                                                        <div class="h3 mb-1 text-danger"><?php echo $positiveFeedbackPercent; ?>%</div>
                                                        <div class="small text-white-50">Positive</div>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <a href="<?php echo $storeUrl; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-light w-100">
                                                        <i class="fas fa-store me-2"></i>Visit Our eBay Store
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <?php
        } else {
            ?>
            <div class="card border-0 mb-4" data-theme-card>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <div class="text-uppercase text-danger fw-semibold small mb-2">Trusted eBay Seller</div>
                            <h6 class="mb-1"><?php echo $sellerName; ?></h6>
                            <a href="<?php echo $storeUrl; ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
                                <i class="fas fa-store me-1"></i>View eBay Store
                            </a>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold"><?php echo $feedbackScore; ?> score</div>
                            <div class="text-muted small"><?php echo $positiveFeedbackPercent; ?>% positive feedback</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }

        return (string) ob_get_clean();
    }
}
