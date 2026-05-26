<?php
/**
 * Cached eBay seller rating data access.
 */

namespace FAS\Models;

class EbaySellerRating
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function saveCache(array $data): bool
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "INSERT INTO ebay_seller_rating_cache (
                        store_name,
                        seller_name,
                        feedback_score,
                        positive_feedback_percent,
                        store_url,
                        last_fetched_at,
                        last_error,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                    ON CONFLICT(store_name) DO UPDATE SET
                        seller_name = excluded.seller_name,
                        feedback_score = excluded.feedback_score,
                        positive_feedback_percent = excluded.positive_feedback_percent,
                        store_url = excluded.store_url,
                        last_fetched_at = excluded.last_fetched_at,
                        last_error = excluded.last_error,
                        updated_at = datetime('now')";
        } else {
            $sql = "INSERT INTO ebay_seller_rating_cache (
                        store_name,
                        seller_name,
                        feedback_score,
                        positive_feedback_percent,
                        store_url,
                        last_fetched_at,
                        last_error,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        seller_name = VALUES(seller_name),
                        feedback_score = VALUES(feedback_score),
                        positive_feedback_percent = VALUES(positive_feedback_percent),
                        store_url = VALUES(store_url),
                        last_fetched_at = VALUES(last_fetched_at),
                        last_error = VALUES(last_error),
                        updated_at = NOW()";
        }

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            $data['store_name'],
            $data['seller_name'] ?? null,
            $data['feedback_score'] ?? null,
            $data['positive_feedback_percent'] ?? null,
            $data['store_url'] ?? null,
            $data['last_fetched_at'] ?? null,
            $data['last_error'] ?? null,
        ]);
    }

    public function getLatest(string $storeName): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT *
             FROM ebay_seller_rating_cache
             WHERE store_name = ?
             LIMIT 1"
        );
        $stmt->execute([$storeName]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function recordRefreshFailure(string $storeName, string $errorMessage, ?string $storeUrl = null): bool
    {
        $existing = $this->getLatest($storeName);

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE ebay_seller_rating_cache
                 SET last_error = ?,
                     updated_at = " . $this->currentTimestampExpression() . "
                 WHERE store_name = ?"
            );

            return $stmt->execute([$errorMessage, $storeName]);
        }

        return $this->saveCache([
            'store_name' => $storeName,
            'seller_name' => null,
            'feedback_score' => null,
            'positive_feedback_percent' => null,
            'store_url' => $storeUrl,
            'last_fetched_at' => null,
            'last_error' => $errorMessage,
        ]);
    }

    private function currentTimestampExpression(): string
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        return $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
    }
}
