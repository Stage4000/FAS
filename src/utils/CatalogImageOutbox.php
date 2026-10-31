<?php
declare(strict_types=1);
namespace FAS\Utils;

require_once __DIR__.'/ImageOptimizationPlan.php';
require_once __DIR__.'/ImageOptimizationQueue.php';
require_once __DIR__.'/ImageQueuePromotionSchema.php';

/** Optional catalog-write adapter. No runtime wiring, network, encoding or activation. */
final class CatalogImageOutbox
{
    public function __construct(private \PDO $db) { ImageQueuePromotionSchema::requireReady($db); }

    public function assertConnection(\PDO $db): void
    {
        if ($this->db !== $db) throw new \LogicException('Catalog and image outbox must share one PDO connection.');
        ImageQueuePromotionSchema::requireReady($db);
    }

    /** Existing IDs; null denotes a new product whose insert returns its ID. */
    public function mutate(?array $ids, callable $write): mixed
    {
        $this->assertConnection($this->db);
        if ($ids !== null) foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('Invalid catalog image target.');
        }
        $joined = $this->db->inTransaction();
        $savepoint = 'image_outbox_'.bin2hex(random_bytes(8));
        if ($joined) $this->db->exec('SAVEPOINT '.$savepoint);
        elseif (!$this->db->beginTransaction()) throw new \RuntimeException('Catalog transaction could not start.');
        try {
            $before = [];
            foreach ($ids ?? [] as $id) $before[$id] = $this->binding($this->read($id));
            $result = $write();
            if ($ids === null) {
                if ($result === false) throw new \RuntimeException('Catalog insert did not succeed.');
                $created = filter_var($result, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($created === false) throw new \RuntimeException('Catalog insert returned no valid identity.');
                $ids = [$created];
            }
            if ($result === false) foreach ($ids as $id) {
                if (($before[$id] ?? null) !== $this->binding($this->read($id))) {
                    throw new \RuntimeException('Failed catalog write changed its image binding.');
                }
            }
            // CAS/no-op false never schedules. A thrown write/queue error never becomes false.
            if ($result !== false) foreach (array_unique($ids) as $id) {
                $after = $this->read($id);
                if (!$after && !array_key_exists($id, $before)) throw new \RuntimeException('Created catalog product disappeared during save.');
                if (!$after && ($before[$id] ?? null) === null) continue;
                if (!$after) throw new \RuntimeException('Catalog product disappeared during save.');
                $binding = $this->binding($after);
                if (($before[$id] ?? null) !== $binding) {
                    // One fresh event for this actual binding transition; unchanged retries create none.
                    (new ImageOptimizationQueue($this->db))->enqueueInTransaction($id, bin2hex(random_bytes(16)), time());
                }
            }
            if ($joined) $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
            elseif (!$this->db->commit()) throw new \RuntimeException('Catalog commit was not confirmed.');
            return $result;
        } catch (\Throwable $error) {
            try {
                if ($joined) {
                    $this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                    $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
                } elseif ($this->db->inTransaction() && !$this->db->rollBack()) {
                    throw new \RuntimeException('Rollback not confirmed.');
                }
            } catch (\Throwable $rollbackError) {
                throw new \RuntimeException('Catalog/outbox outcome uncertain; inspect before any retry.', 0, $rollbackError);
            }
            throw new \RuntimeException('Catalog/image outbox save failed; no success confirmed.', 0, $error);
        }
    }

    private function read(int $id): array|false
    {
        $read=$this->db->prepare('SELECT id,is_active,show_on_website,image_url,images FROM products WHERE id=?');
        $read->execute([$id]);
        return $read->fetch(\PDO::FETCH_ASSOC);
    }

    private function binding(array|false $row): ?string
    {
        if (!$row) return null;
        return ImageOptimizationPlan::forProduct($row)['catalog_binding'];
    }
}
