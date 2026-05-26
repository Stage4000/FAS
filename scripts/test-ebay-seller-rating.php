<?php

declare(strict_types=1);

final class FakePdo
{
    public array $rows = [];

    public function prepare(string $sql): FakeStatement
    {
        return new FakeStatement($this, $sql);
    }

    public function getAttribute(int $attribute)
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'sqlite';
        }

        return null;
    }
}

final class FakeStatement
{
    private FakePdo $pdo;
    private string $sql;
    private ?array $result = null;

    public function __construct(FakePdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool
    {
        if (stripos($this->sql, 'INSERT INTO ebay_seller_rating_cache') !== false) {
            $row = [
                'store_name' => $params[0],
                'seller_name' => $params[1],
                'feedback_score' => $params[2],
                'positive_feedback_percent' => $params[3],
                'store_url' => $params[4],
                'last_fetched_at' => $params[5],
                'last_error' => $params[6],
            ];
            $this->pdo->rows[$params[0]] = array_merge($this->pdo->rows[$params[0]] ?? [], $row);
            return true;
        }

        if (stripos($this->sql, 'SELECT *') !== false) {
            $this->result = $this->pdo->rows[$params[0]] ?? null;
            return true;
        }

        if (stripos($this->sql, 'UPDATE ebay_seller_rating_cache') !== false) {
            if (!isset($this->pdo->rows[$params[1]])) {
                return false;
            }

            $this->pdo->rows[$params[1]]['last_error'] = $params[0];
            return true;
        }

        return false;
    }

    public function fetch(int $fetchStyle = 0)
    {
        return $this->result;
    }
}

$root = dirname(__DIR__);
$modelPath = $root . '/src/models/EbaySellerRating.php';

if (!file_exists($modelPath)) {
    fwrite(STDERR, "FAIL: EbaySellerRating model file is missing.\n");
    exit(1);
}

require_once $modelPath;

if (!class_exists(\FAS\Models\EbaySellerRating::class)) {
    fwrite(STDERR, "FAIL: FAS\\Models\\EbaySellerRating class is missing.\n");
    exit(1);
}

$db = new FakePdo();
$model = new \FAS\Models\EbaySellerRating($db);

if (!method_exists($model, 'saveCache')) {
    fwrite(STDERR, "FAIL: saveCache method is missing.\n");
    exit(1);
}

if (!method_exists($model, 'getLatest')) {
    fwrite(STDERR, "FAIL: getLatest method is missing.\n");
    exit(1);
}

if ($model->getLatest('moto800') !== null) {
    fwrite(STDERR, "FAIL: getLatest should return null when cache is empty.\n");
    exit(1);
}

$saved = $model->saveCache([
    'store_name' => 'moto800',
    'seller_name' => 'Moto 800',
    'feedback_score' => 431,
    'positive_feedback_percent' => 100.0,
    'store_url' => 'https://www.ebay.com/str/moto800',
    'last_fetched_at' => '2026-05-26 12:00:00',
    'last_error' => null,
]);

if ($saved !== true) {
    fwrite(STDERR, "FAIL: saveCache should return true on successful insert.\n");
    exit(1);
}

$latest = $model->getLatest('moto800');

if ($latest === null) {
    fwrite(STDERR, "FAIL: getLatest should return the cached seller rating.\n");
    exit(1);
}

if ((int) $latest['feedback_score'] !== 431) {
    fwrite(STDERR, "FAIL: feedback_score was not persisted correctly.\n");
    exit(1);
}

if ((float) $latest['positive_feedback_percent'] !== 100.0) {
    fwrite(STDERR, "FAIL: positive_feedback_percent was not persisted correctly.\n");
    exit(1);
}

$updated = $model->saveCache([
    'store_name' => 'moto800',
    'seller_name' => 'Moto800 Store',
    'feedback_score' => 432,
    'positive_feedback_percent' => 99.8,
    'store_url' => 'https://www.ebay.com/str/moto800',
    'last_fetched_at' => '2026-05-27 12:00:00',
    'last_error' => 'temporary upstream issue',
]);

if ($updated !== true) {
    fwrite(STDERR, "FAIL: saveCache should return true on successful update.\n");
    exit(1);
}

$latest = $model->getLatest('moto800');

if ($latest['seller_name'] !== 'Moto800 Store') {
    fwrite(STDERR, "FAIL: seller_name should be updated on upsert.\n");
    exit(1);
}

if ((int) $latest['feedback_score'] !== 432) {
    fwrite(STDERR, "FAIL: feedback_score should be updated on upsert.\n");
    exit(1);
}

if ($latest['last_error'] !== 'temporary upstream issue') {
    fwrite(STDERR, "FAIL: last_error should be persisted for operational debugging.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: seller rating cache model behaves as expected.\n");
