<?php

namespace FAS\Utils;

class SavedSearchCapture
{
    private \PDO $db;
    private bool $tableEnsured = false;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS saved_search_leads (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    label VARCHAR(255) DEFAULT NULL,
                    search_url TEXT DEFAULT NULL,
                    search_query VARCHAR(255) DEFAULT NULL,
                    manufacturer VARCHAR(255) DEFAULT NULL,
                    model VARCHAR(255) DEFAULT NULL,
                    category VARCHAR(255) DEFAULT NULL,
                    collection VARCHAR(80) DEFAULT NULL,
                    source_page TEXT DEFAULT NULL,
                    context_json TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_saved_search_created_at (created_at),
                    INDEX idx_saved_search_email (email)
                )"
            );
        } else {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS saved_search_leads (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT NOT NULL,
                    label TEXT DEFAULT NULL,
                    search_url TEXT DEFAULT NULL,
                    search_query TEXT DEFAULT NULL,
                    manufacturer TEXT DEFAULT NULL,
                    model TEXT DEFAULT NULL,
                    category TEXT DEFAULT NULL,
                    collection TEXT DEFAULT NULL,
                    source_page TEXT DEFAULT NULL,
                    context_json TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL
                )"
            );
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_saved_search_created_at ON saved_search_leads(created_at)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_saved_search_email ON saved_search_leads(email)");
        }

        $this->tableEnsured = true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): bool
    {
        $this->ensureTable();

        $stmt = $this->db->prepare(
            "INSERT INTO saved_search_leads (
                email, label, search_url, search_query, manufacturer, model,
                category, collection, source_page, context_json, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        return $stmt->execute([
            strtolower(trim((string)($data['email'] ?? ''))),
            $this->nullableString($data['label'] ?? null, 255),
            $this->nullableString($data['search_url'] ?? null),
            $this->nullableString($data['search_query'] ?? null, 255),
            $this->nullableString($data['manufacturer'] ?? null, 255),
            $this->nullableString($data['model'] ?? null, 255),
            $this->nullableString($data['category'] ?? null, 255),
            $this->nullableString($data['collection'] ?? null, 80),
            $this->nullableString($data['source_page'] ?? null),
            json_encode($data['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function nullableString($value, ?int $maxLength = null): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        if ($maxLength !== null && strlen($text) > $maxLength) {
            return substr($text, 0, $maxLength);
        }
        return $text;
    }
}
