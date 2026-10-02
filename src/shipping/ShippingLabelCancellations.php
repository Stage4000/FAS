<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingLabelOperations.php';

/** A separate, one-send record for each confirmed carrier shipment's void/refund attempt. */
final class ShippingLabelCancellations
{
    private \PDO $db;

    public function __construct(\PDO $privateDb)
    {
        $this->db=$privateDb;
        $this->db->query('SELECT operation_id FROM shipping_label_cancellations LIMIT 1');
    }

    public function reserve(\PDO $ordersDb,int $orderId,int $packageIndex,int $operatorId): array
    {
        self::requireAdmin($ordersDb,$operatorId);
        $operation=(new ShippingLabelOperations($this->db))->find($orderId,$packageIndex);
        if (!$operation || $operation['state']!=='ready' || empty($operation['tracking_number'])) {
            throw new \RuntimeException('Only a confirmed carrier label can be canceled.');
        }
        $stmt=$ordersDb->prepare('SELECT o.payment_status,s.provider FROM orders o
            JOIN order_shipping s ON s.order_id=o.id WHERE o.id=?');
        $stmt->execute([$orderId]);
        $order=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$order || $order['payment_status']!=='completed'
            || $order['provider']!==$operation['provider']) {
            throw new \RuntimeException('Saved carrier order needs review.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $existing=$this->find($orderId,$packageIndex);
            if ($existing) {
                $this->db->exec('COMMIT');
                return $existing;
            }
            $now=time();
            $insert=$this->db->prepare("INSERT INTO shipping_label_cancellations
                (operation_id,state,operator_id,created_at,updated_at) VALUES (?,'reserved',?,?,?)");
            $insert->execute([(int)$operation['id'],$operatorId,$now,$now]);
            $record=$this->find($orderId,$packageIndex);
            $this->db->exec('COMMIT');
            return $record;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** The caller sends at most one carrier DELETE after this atomic transition. */
    public function markSubmitted(\PDO $ordersDb,int $operationId,int $operatorId): bool
    {
        self::requireAdmin($ordersDb,$operatorId);
        $now=time();
        $stmt=$this->db->prepare("UPDATE shipping_label_cancellations
            SET state='submitted',submitted_at=?,updated_at=?
            WHERE operation_id=? AND operator_id=? AND state='reserved'");
        $stmt->execute([$now,$now,$operationId,$operatorId]);
        return $stmt->rowCount()===1;
    }

    public function finish(int $operationId,array $result): void
    {
        $state=$result['state'] ?? null;
        $reference=$result['carrier_reference'] ?? null;
        if (!in_array($state,['cancelled','refund_pending'],true)
            || ($state==='cancelled' && $reference!==null)
            || ($state==='refund_pending' && (!is_string($reference)
                || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D',$reference)))) {
            throw new \InvalidArgumentException('Invalid carrier cancellation result.');
        }
        $stmt=$this->db->prepare('UPDATE shipping_label_cancellations
            SET state=?,carrier_reference=?,updated_at=? WHERE operation_id=? AND state=\'submitted\'');
        $stmt->execute([$state,$reference,time(),$operationId]);
        if ($stmt->rowCount()!==1) throw new \RuntimeException('Carrier cancellation needs reconciliation.');
    }

    public function markReview(int $operationId): void
    {
        $stmt=$this->db->prepare("UPDATE shipping_label_cancellations SET state='review',updated_at=?
            WHERE operation_id=? AND state='submitted'");
        $stmt->execute([time(),$operationId]);
        if ($stmt->rowCount()!==1) throw new \RuntimeException('Carrier cancellation needs reconciliation.');
    }

    public function find(int $orderId,int $packageIndex): ?array
    {
        $stmt=$this->db->prepare('SELECT c.*,o.order_id,o.package_index,o.provider,o.tracking_number
            FROM shipping_label_cancellations c JOIN shipping_label_operations o ON o.id=c.operation_id
            WHERE o.order_id=? AND o.package_index=?');
        $stmt->execute([$orderId,$packageIndex]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function health(): array
    {
        $states=['reserved'=>0,'submitted'=>0,'cancelled'=>0,'refund_pending'=>0,'review'=>0];
        foreach ($this->db->query('SELECT state,COUNT(*) AS count FROM shipping_label_cancellations GROUP BY state') as $row) {
            $states[$row['state']]=(int)$row['count'];
        }
        return $states;
    }

    /** Record independently verified carrier evidence; never authorize another carrier request. */
    public function reconcile(\PDO $ordersDb,int $orderId,int $packageIndex,int $operatorId,
        string $expectedState,string $outcome,string $tracking,string $reference,bool $verified): void
    {
        self::requireAdmin($ordersDb,$operatorId);
        if (!$verified || !in_array($outcome,['cancelled','refund_pending'],true)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}\z/D',$reference)) {
            throw new \InvalidArgumentException('Confirm the carrier evidence and enter its case or confirmation reference.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $record=$this->find($orderId,$packageIndex);
            if (!$record || $record['state']!==$expectedState || !self::canReconcile($record)
                || $outcome===$record['state']) {
                throw new \DomainException('Cancellation has changed or is not eligible for reconciliation.');
            }
            if (!hash_equals((string)$record['tracking_number'],$tracking)
                || ($outcome==='refund_pending' && $record['provider']!=='usps')) {
                throw new \InvalidArgumentException('Check the tracking number and carrier outcome.');
            }
            $now=time();
            $audit=$this->db->prepare('INSERT INTO shipping_cancellation_resolutions
                (operation_id,previous_state,outcome,previous_reference,evidence_reference,actor_id,resolved_at) VALUES (?,?,?,?,?,?,?)');
            $audit->execute([(int)$record['operation_id'],$record['state'],$outcome,$record['carrier_reference'],$reference,$operatorId,$now]);
            $update=$this->db->prepare('UPDATE shipping_label_cancellations SET state=?,carrier_reference=?,updated_at=?
                WHERE operation_id=? AND state=?');
            $update->execute([$outcome,$reference,$now,(int)$record['operation_id'],$expectedState]);
            if ($update->rowCount()!==1) throw new \DomainException('Cancellation changed during review.');
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function canReconcile(array $record): bool
    {
        return in_array($record['state'],['review','refund_pending'],true)
            || ($record['state']==='submitted' && (int)($record['submitted_at'] ?? 0)>0
                && (int)$record['submitted_at']<=time()-900);
    }

    public function history(int $operationId): array
    {
        $stmt=$this->db->prepare('SELECT previous_state,outcome,previous_reference,evidence_reference,actor_id,resolved_at
            FROM shipping_cancellation_resolutions WHERE operation_id=? ORDER BY id DESC');
        $stmt->execute([$operationId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Read-only queue for carrier void/refund results that need follow-up. */
    public function attention(int $limit=100): array
    {
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Invalid cancellation queue limit.');
        $stmt=$this->db->prepare("SELECT o.order_id,o.package_index,o.provider,o.tracking_number,
            c.state,c.carrier_reference,c.updated_at FROM shipping_label_cancellations c
            JOIN shipping_label_operations o ON o.id=c.operation_id
            WHERE c.state IN ('submitted','review','refund_pending')
            ORDER BY c.updated_at DESC,c.operation_id DESC LIMIT ?");
        $stmt->bindValue(1,$limit,\PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function requireAdmin(\PDO $ordersDb,int $operatorId): void
    {
        if ($operatorId<1) throw new \InvalidArgumentException('Invalid administrator.');
        $stmt=$ordersDb->prepare("SELECT id FROM admin_users WHERE id=? AND role='admin' AND is_active=1");
        $stmt->execute([$operatorId]);
        if (!$stmt->fetchColumn()) throw new \RuntimeException('An active administrator is required.');
    }
}
