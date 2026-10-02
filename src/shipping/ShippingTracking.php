<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Private per-package status cache with a cross-worker polling lease. */
final class ShippingTracking
{
    private \PDO $db;

    public static function install(\PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS shipping_tracking (
            tracking_number TEXT PRIMARY KEY REFERENCES shipping_label_packages(tracking_number),
            provider TEXT NOT NULL CHECK(provider IN ('usps','ups')),
            status_code TEXT,
            status_text TEXT,
            checked_at INTEGER,
            attempted_at INTEGER NOT NULL DEFAULT 0,
            next_attempt_at INTEGER NOT NULL DEFAULT 0,
            last_result TEXT NOT NULL DEFAULT 'pending' CHECK(last_result IN ('pending','ok','error'))
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS shipping_tracking_due ON shipping_tracking(next_attempt_at)');
        $columns=array_column($db->query('PRAGMA table_info(shipping_tracking)')->fetchAll(\PDO::FETCH_ASSOC),'name');
        if (!in_array('carrier_environment',$columns,true)) {
            $db->exec("ALTER TABLE shipping_tracking ADD COLUMN carrier_environment TEXT
                CHECK(carrier_environment IN ('sandbox','production'))");
        }
    }

    public function __construct(\PDO $privateDb)
    {
        $this->db=$privateDb;
        $this->db->query('SELECT tracking_number FROM shipping_tracking LIMIT 1');
    }

    public function due(array $providers,int $limit=20): array
    {
        $providers=array_values(array_unique($providers));
        if (!$providers || count($providers)>2 || array_diff($providers,['usps','ups'])
            || $limit<1 || $limit>50) throw new \InvalidArgumentException('Invalid tracking selection.');
        $marks=implode(',',array_fill(0,count($providers),'?'));
        $sql="SELECT p.tracking_number,o.provider FROM shipping_label_packages p
            JOIN shipping_label_operations o ON o.id=p.operation_id
            LEFT JOIN shipping_label_cancellations c ON c.operation_id=o.id
            LEFT JOIN shipping_tracking t ON t.tracking_number=p.tracking_number
            WHERE o.state='ready' AND c.operation_id IS NULL
                AND o.created_at>=? AND o.provider IN ($marks)
                AND COALESCE(t.next_attempt_at,0)<=?
            ORDER BY COALESCE(t.next_attempt_at,0),o.created_at,p.shipment_package_index LIMIT ?";
        $stmt=$this->db->prepare($sql);
        $values=array_merge([time()-120*86400],$providers,[time(),$limit]);
        foreach ($values as $i=>$value) $stmt->bindValue($i+1,$value,is_int($value)?\PDO::PARAM_INT:\PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function claim(string $tracking): bool
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $insert=$this->db->prepare("INSERT OR IGNORE INTO shipping_tracking
                (tracking_number,provider) SELECT p.tracking_number,o.provider
                FROM shipping_label_packages p JOIN shipping_label_operations o ON o.id=p.operation_id
                LEFT JOIN shipping_label_cancellations c ON c.operation_id=o.id
                WHERE p.tracking_number=? AND o.state='ready' AND c.operation_id IS NULL");
            $insert->execute([$tracking]);
            $now=time();
            $update=$this->db->prepare("UPDATE shipping_tracking SET attempted_at=?,next_attempt_at=?,last_result='pending'
                WHERE tracking_number=? AND next_attempt_at<=? AND EXISTS (
                    SELECT 1 FROM shipping_label_packages p
                    JOIN shipping_label_operations o ON o.id=p.operation_id
                    LEFT JOIN shipping_label_cancellations c ON c.operation_id=o.id
                    WHERE p.tracking_number=shipping_tracking.tracking_number
                        AND o.state='ready' AND c.operation_id IS NULL)");
            $update->execute([$now,$now+900,$tracking,$now]);
            $claimed=$update->rowCount()===1;
            $this->db->exec('COMMIT');
            return $claimed;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function save(string $tracking,array $status,?string $environment=null): void
    {
        if ($environment!==null && !in_array($environment,['sandbox','production'],true)) {
            throw new \InvalidArgumentException('Invalid tracking environment.');
        }
        $text=$status['status_text'] ?? null;
        $code=$status['status_code'] ?? null;
        if (!is_string($text) || $text==='' || strlen($text)>160
            || ($code!==null && (!is_string($code) || strlen($code)>60))) {
            throw new \InvalidArgumentException('Invalid tracking status.');
        }
        $now=time();
        $stmt=$this->db->prepare("UPDATE shipping_tracking SET status_text=?,status_code=?,
            checked_at=?,next_attempt_at=?,carrier_environment=?,last_result='ok'
            WHERE tracking_number=? AND last_result='pending'");
        $stmt->execute([$text,$code,$now,$now+1800,$environment,$tracking]);
        if ($stmt->rowCount()!==1) throw new \RuntimeException('Tracking status could not be saved.');
    }

    public function fail(string $tracking,int $retryAfter=900): void
    {
        $delay=max(900,min(86400,$retryAfter));
        $stmt=$this->db->prepare("UPDATE shipping_tracking SET last_result='error',next_attempt_at=?
            WHERE tracking_number=? AND last_result='pending'");
        $stmt->execute([time()+$delay,$tracking]);
    }

    public function find(string $tracking): ?array
    {
        $stmt=$this->db->prepare('SELECT status_code,status_text,checked_at,attempted_at,last_result
            FROM shipping_tracking WHERE tracking_number=?');
        $stmt->execute([$tracking]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function health(): array
    {
        $result=['initialized'=>true,'packages'=>0,'with_status'=>0,'last_attempt_failed'=>0];
        foreach ($this->db->query('SELECT last_result,COUNT(*) count FROM shipping_tracking GROUP BY last_result') as $row) {
            $result['packages']+=(int)$row['count'];
            if ($row['last_result']==='error') $result['last_attempt_failed']+=(int)$row['count'];
        }
        $result['with_status']=(int)$this->db->query('SELECT COUNT(*) FROM shipping_tracking WHERE checked_at IS NOT NULL')->fetchColumn();
        return $result;
    }
}
