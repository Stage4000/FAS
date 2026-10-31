<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ImageQueuePromotionSchema.php';

/** Durable SQLite outbox. No automatic migration, fetching, encoding, or activation. */
final class ImageOptimizationQueue
{
    public function __construct(private \PDO $db)
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('Image queue requires the application SQLite connection.');
        }
        if ((int)$db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('Image queue requires exception error mode.');
        }
    }

    /** Call inside the SAME transaction as the successful catalog change. */
    public function enqueueInTransaction(int $productId, string $eventKey, int $now): int
    {
        self::time($now);
        if (!$this->db->inTransaction()) throw new \LogicException('Catalog/outbox transaction required.');
        if ($productId < 1 || !preg_match('/^[a-f0-9]{32}$/D', $eventKey)) throw new \InvalidArgumentException('Invalid event identity.');
        $read = $this->db->prepare('SELECT id,is_active,show_on_website,image_url,images FROM products WHERE id=?');
        $read->execute([$productId]);
        $product = $read->fetch(\PDO::FETCH_ASSOC);
        if (!$product) throw new \DomainException('Catalog product not found.');
        ImageQueuePromotionSchema::requireReady($this->db);
        $plan = ImageOptimizationPlan::forProduct($product);
        $insert = $this->db->prepare("INSERT INTO image_optimization_jobs(event_key,work_key,product_id,intent_json,state,available_at,created_at) VALUES(?,?,?,?,'pending',?,?) ON CONFLICT(event_key,work_key) DO NOTHING");
        $count = 0;
        foreach ($plan['jobs'] as $intent) {
            $insert->execute([$eventKey,$intent['work_key'],$productId,json_encode($intent, JSON_THROW_ON_ERROR),$now,$now]);
            $count += $insert->rowCount();
        }
        return $count;
    }

    /** One claim per call, at most five attempts. Stale owners lose their fencing token. */
    public function claim(int $now, int $leaseSeconds = 120): ?array
    {
        self::time($now);
        if ($leaseSeconds < 1 || $leaseSeconds > 300 || $this->db->inTransaction()) throw new \InvalidArgumentException('Invalid claim context.');
        $this->db->beginTransaction();
        try {
            ImageQueuePromotionSchema::requireReady($this->db);
            $expire = $this->db->prepare("UPDATE image_optimization_jobs SET state='quarantined',failure='lease_exhausted',lease_token=NULL,lease_until=NULL WHERE state='leased' AND lease_until<=? AND attempts>=5 AND NOT EXISTS (SELECT 1 FROM image_optimization_activations a WHERE a.job_id=image_optimization_jobs.id AND a.state IN ('prepared','conflict'))");
            $expire->execute([$now]);
            $read = $this->db->prepare("SELECT * FROM image_optimization_jobs WHERE attempts<5 AND NOT EXISTS (SELECT 1 FROM image_optimization_activations a WHERE a.job_id=image_optimization_jobs.id AND a.state IN ('prepared','conflict')) AND ((state IN ('pending','retry') AND available_at<=?) OR (state='leased' AND lease_until<=?)) ORDER BY id LIMIT 1");
            $read->execute([$now,$now]);
            $row = $read->fetch(\PDO::FETCH_ASSOC);
            if (!$row) { $this->db->commit(); return null; }
            try {
                if (strlen($row['intent_json']) > 16384) throw new \UnexpectedValueException('Oversized intent.');
                $intent = json_decode($row['intent_json'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($intent) || !self::validIntent($intent, $row)) {
                    throw new \UnexpectedValueException('Corrupt intent.');
                }
            } catch (\JsonException | \UnexpectedValueException $e) {
                $bad = $this->db->prepare("UPDATE image_optimization_jobs SET state='quarantined',failure='invalid_intent',lease_token=NULL,lease_until=NULL WHERE id=?");
                $bad->execute([$row['id']]);
                $this->db->commit();
                return null; // Bounded call; next claim can proceed past the quarantined row.
            }
            $token = bin2hex(random_bytes(24));
            $write = $this->db->prepare("UPDATE image_optimization_jobs SET state='leased',attempts=attempts+1,lease_token=?,lease_until=? WHERE id=? AND attempts=?");
            $write->execute([$token,$now+$leaseSeconds,$row['id'],$row['attempts']]);
            if ($write->rowCount() !== 1) throw new \RuntimeException('Image claim lost.');
            $this->db->commit();
            return ['id'=>(int)$row['id'],'token'=>$token,'attempt'=>(int)$row['attempts']+1,'lease_until'=>$now+$leaseSeconds,'intent'=>$intent];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Read-only check; not an atomic receipt-activation permission. */
    public function current(array $claim, int $now): bool
    {
        return $this->currentIntent($claim, $now) !== null;
    }

    /** Always returns stored intent, never caller-supplied intent fields. */
    public function currentIntent(array $claim, int $now): ?array
    {
        self::time($now);
        $row = $this->owned($claim, $now);
        if (!$row) return null;
        $read = $this->db->prepare('SELECT id,is_active,show_on_website,image_url,images FROM products WHERE id=?');
        $read->execute([$row['product_id']]);
        $product = $read->fetch(\PDO::FETCH_ASSOC);
        $intent = json_decode($row['intent_json'], true, 16, JSON_THROW_ON_ERROR);
        return $product && is_array($intent) && self::validIntent($intent, $row) && ImageOptimizationPlan::isCurrent($intent, $product) ? $intent : null;
    }

    public function finish(array $claim, int $now, string $state, ?string $failure = null): bool
    {
        self::time($now);
        if (!in_array($state,['done','superseded','failed'],true)) throw new \InvalidArgumentException('Invalid outcome.');
        ImageQueuePromotionSchema::requireReady($this->db);
        $row = $this->owned($claim,$now);
        if (!$row) return false;
        $available = $now;
        if ($state === 'failed') {
            $decision = ImageOptimizationPlan::afterFailure((int)$row['attempts'],$now,$failure ?? '');
            $state = $decision['state']; $available = $decision['next_attempt_at'] ?? $now;
        } elseif ($failure !== null) throw new \InvalidArgumentException('Failure only valid for failed work.');
        $write = $this->db->prepare('UPDATE image_optimization_jobs SET state=?,available_at=?,failure=?,lease_token=NULL,lease_until=NULL WHERE id=? AND state=\'leased\' AND lease_token=? AND lease_until>? AND NOT EXISTS (SELECT 1 FROM image_optimization_activations a WHERE a.job_id=image_optimization_jobs.id AND a.state IN (\'prepared\',\'conflict\'))');
        $write->execute([$state,$available,$failure,$claim['id'],$claim['token'],$now]);
        return $write->rowCount() === 1;
    }

    private function owned(array $claim, int $now): array|false
    {
        if (!is_int($claim['id'] ?? null) || $claim['id'] < 1 || !is_string($claim['token'] ?? null) || !preg_match('/^[a-f0-9]{48}$/D',$claim['token'])) throw new \InvalidArgumentException('Invalid lease identity.');
        $read = $this->db->prepare("SELECT * FROM image_optimization_jobs WHERE id=? AND state='leased' AND lease_token=? AND lease_until>?");
        $read->execute([$claim['id'],$claim['token'],$now]);
        return $read->fetch(\PDO::FETCH_ASSOC);
    }
    private static function validIntent(array $intent, array $row): bool
    {
        if (array_keys($intent) !== ['version','product_id','source','kind','catalog_binding','work_key']
            || $intent['version'] !== ImageOptimizationPlan::VERSION
            || $intent['product_id'] !== (string)$row['product_id']
            || !is_string($intent['source']) || !is_string($intent['catalog_binding'])
            || !preg_match('/^[a-f0-9]{64}$/D',$intent['catalog_binding'])
            || !is_string($intent['work_key']) || $intent['work_key'] !== $row['work_key']) return false;
        try {
            $synthetic = ImageOptimizationPlan::forProduct(['id'=>$intent['product_id'],'is_active'=>1,'show_on_website'=>1,'image_url'=>$intent['source'],'images'=>[]]);
        } catch (\Throwable $e) { return false; }
        if (count($synthetic['jobs']) !== 1 || $synthetic['jobs'][0]['kind'] !== $intent['kind']) return false;
        return hash_equals(hash('sha256', $intent['version']."\n".$intent['product_id']."\n".$intent['catalog_binding']."\n".$intent['source']), $intent['work_key']);
    }

    private static function time(int $now): void
    {
        if ($now < 1 || $now > PHP_INT_MAX - 86400) throw new \InvalidArgumentException('Invalid queue time.');
    }
}
