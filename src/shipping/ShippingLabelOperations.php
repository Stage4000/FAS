<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** One private, durable operation per direct-carrier shipment scope. No carrier calls here. */
final class ShippingLabelOperations
{
    public const HANDOFF_DELAY_SECONDS=900;
    private \PDO $db;

    public static function install(\PDO $privateDb): void
    {
        $privateDb->exec("CREATE TABLE IF NOT EXISTS shipping_label_operations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            package_index INTEGER NOT NULL,
            provider TEXT NOT NULL CHECK(provider IN ('usps','ups')),
            service_code TEXT NOT NULL,
            expected_packages INTEGER NOT NULL DEFAULT 0,
            fingerprint TEXT NOT NULL,
            idempotency_key TEXT NOT NULL UNIQUE,
            state TEXT NOT NULL CHECK(state IN ('reserved','submitted','ready','review')),
            shipment_id TEXT,
            tracking_number TEXT,
            billed_cents INTEGER,
            operator_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            submitted_at INTEGER,
            mailing_date TEXT,
            updated_at INTEGER NOT NULL,
            UNIQUE(order_id,package_index)
        )");
        $columns=array_column($privateDb->query('PRAGMA table_info(shipping_label_operations)')->fetchAll(\PDO::FETCH_ASSOC),'name');
        if (!in_array('expected_packages',$columns,true)) {
            // Existing operations cannot be finalized without reconciling their parcel count.
            $privateDb->exec('ALTER TABLE shipping_label_operations ADD COLUMN expected_packages INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('carrier_environment',$columns,true)) {
            // Legacy and unknown environments cannot produce customer notifications.
            $privateDb->exec("ALTER TABLE shipping_label_operations ADD COLUMN carrier_environment TEXT
                CHECK(carrier_environment IN ('sandbox','production'))");
        }
        if (!in_array('mailing_date',$columns,true)) {
            $privateDb->exec('ALTER TABLE shipping_label_operations ADD COLUMN mailing_date TEXT');
        }
        $privateDb->exec('CREATE INDEX IF NOT EXISTS shipping_label_state ON shipping_label_operations(state,updated_at)');
        $privateDb->exec("CREATE TABLE IF NOT EXISTS shipping_label_handoffs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            operation_id INTEGER NOT NULL REFERENCES shipping_label_operations(id),
            previous_operator_id INTEGER NOT NULL,
            new_operator_id INTEGER NOT NULL,
            transferred_at INTEGER NOT NULL
        )");
        $privateDb->exec('CREATE INDEX IF NOT EXISTS shipping_label_handoff_operation ON shipping_label_handoffs(operation_id,id)');
        $privateDb->exec("CREATE TABLE IF NOT EXISTS shipping_label_reprints (
            operation_id INTEGER PRIMARY KEY REFERENCES shipping_label_operations(id),
            state TEXT NOT NULL CHECK(state IN ('submitted','ready','review')),
            operator_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");
        $privateDb->exec("CREATE TABLE IF NOT EXISTS shipping_label_resolutions (
            operation_id INTEGER PRIMARY KEY REFERENCES shipping_label_operations(id),
            previous_state TEXT NOT NULL CHECK(previous_state IN ('submitted','review')),
            evidence_reference TEXT NOT NULL,
            actor_id INTEGER NOT NULL,
            resolved_at INTEGER NOT NULL
        )");
        $privateDb->exec("CREATE TABLE IF NOT EXISTS shipping_label_packages (
            operation_id INTEGER NOT NULL REFERENCES shipping_label_operations(id),
            shipment_package_index INTEGER NOT NULL,
            tracking_number TEXT NOT NULL UNIQUE,
            label_format TEXT NOT NULL CHECK(label_format IN ('pdf','gif')),
            label_sha256 TEXT NOT NULL,
            label_image BLOB NOT NULL,
            PRIMARY KEY(operation_id,shipment_package_index)
        )");
        $privateDb->exec("CREATE TABLE IF NOT EXISTS shipping_label_cancellations (
            operation_id INTEGER PRIMARY KEY REFERENCES shipping_label_operations(id),
            state TEXT NOT NULL CHECK(state IN ('reserved','submitted','cancelled','refund_pending','review')),
            carrier_reference TEXT,
            operator_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            submitted_at INTEGER,
            updated_at INTEGER NOT NULL
        )");
        $privateDb->exec("CREATE TABLE IF NOT EXISTS shipping_cancellation_resolutions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            operation_id INTEGER NOT NULL REFERENCES shipping_label_cancellations(operation_id),
            previous_state TEXT NOT NULL CHECK(previous_state IN ('submitted','review','refund_pending')),
            outcome TEXT NOT NULL CHECK(outcome IN ('cancelled','refund_pending')),
            previous_reference TEXT,
            evidence_reference TEXT NOT NULL,
            actor_id INTEGER NOT NULL,
            resolved_at INTEGER NOT NULL,
            UNIQUE(operation_id,previous_state)
        )");
    }

    public function __construct(\PDO $privateDb)
    {
        $this->db=$privateDb;
        $this->db->query('SELECT id FROM shipping_label_operations LIMIT 1');
        $this->db->query('SELECT operation_id FROM shipping_label_packages LIMIT 1');
    }

    /** Reserve exactly once after a paid order and its immutable parcel selection exist. */
    public function reserve(\PDO $ordersDb,int $orderId,int $packageIndex,int $operatorId): array
    {
        if ($orderId<1 || $packageIndex<0 || $operatorId<1) throw new \InvalidArgumentException('Invalid fulfillment scope.');
        self::requireAdmin($ordersDb,$operatorId);
        $scope=self::scope($ordersDb,$orderId,$packageIndex);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $existing=$this->find($orderId,$packageIndex);
            if ($existing) {
                if (!hash_equals($existing['fingerprint'],$scope['fingerprint'])) {
                    throw new \RuntimeException('The paid order changed after label preparation. Review it before continuing.');
                }
                if ($existing['state']==='reserved' && (int)$existing['operator_id']!==$operatorId) {
                    if (!self::handoffEligible($existing,$operatorId,$now=time())) {
                        throw new \DomainException('Another administrator is preparing this shipment. Try again after the reservation waiting period.');
                    }
                    $update=$this->db->prepare("UPDATE shipping_label_operations SET operator_id=?,updated_at=?
                        WHERE id=? AND state='reserved' AND operator_id=?");
                    $update->execute([$operatorId,$now,(int)$existing['id'],(int)$existing['operator_id']]);
                    if ($update->rowCount()!==1) throw new \RuntimeException('Shipment reservation changed.');
                    $audit=$this->db->prepare('INSERT INTO shipping_label_handoffs
                        (operation_id,previous_operator_id,new_operator_id,transferred_at) VALUES (?,?,?,?)');
                    $audit->execute([(int)$existing['id'],(int)$existing['operator_id'],$operatorId,$now]);
                    $existing=$this->find($orderId,$packageIndex);
                }
                $this->db->exec('COMMIT');
                return $existing;
            }
            $now=time();
            $stmt=$this->db->prepare('INSERT INTO shipping_label_operations
                (order_id,package_index,provider,service_code,expected_packages,fingerprint,idempotency_key,state,operator_id,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$orderId,$packageIndex,$scope['provider'],$scope['service_code'],
                $scope['expected_packages'],$scope['fingerprint'],self::uuid(),'reserved',$operatorId,$now,$now]);
            $record=$this->find($orderId,$packageIndex);
            $this->db->exec('COMMIT');
            return $record;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Returns false if already sent. USPS's named key does not make its label POST idempotent. */
    public function markSubmitted(\PDO $ordersDb,int $orderId,int $packageIndex,int $operatorId,
        ?string $environment=null,?string $mailingDate=null): bool
    {
        if ($environment!==null && !in_array($environment,['sandbox','production'],true)) {
            throw new \InvalidArgumentException('Invalid label environment.');
        }
        if ($mailingDate!==null && (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$mailingDate)
            || !checkdate((int)substr($mailingDate,5,2),(int)substr($mailingDate,8,2),
                (int)substr($mailingDate,0,4)))) {
            throw new \InvalidArgumentException('Invalid mailing date.');
        }
        self::requireAdmin($ordersDb,$operatorId);
        $scope=self::scope($ordersDb,$orderId,$packageIndex);
        $record=$this->find($orderId,$packageIndex);
        if (!$record || (int)$record['operator_id']!==$operatorId
            || !hash_equals($record['fingerprint'],$scope['fingerprint'])) {
            throw new \RuntimeException('Fulfillment scope is missing or changed.');
        }
        $now=time();
        $stmt=$this->db->prepare("UPDATE shipping_label_operations SET state='submitted',submitted_at=?,updated_at=?,carrier_environment=?,mailing_date=?
            WHERE order_id=? AND package_index=? AND state='reserved' AND fingerprint=? AND operator_id=?");
        $stmt->execute([$now,$now,$environment,$mailingDate,$orderId,$packageIndex,$scope['fingerprint'],$operatorId]);
        return $stmt->rowCount()===1;
    }

    public static function reprintEligible(array $operation,?int $now=null): bool
    {
        $now=$now ?? time();
        return ($operation['provider'] ?? null)==='usps'
            && in_array($operation['state'] ?? null,['submitted','review'],true)
            && (int)($operation['expected_packages'] ?? 0)===1
            && (int)($operation['submitted_at'] ?? 0)>0
            && (int)$operation['submitted_at']<=$now-900
            && is_string($operation['mailing_date'] ?? null)
            && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$operation['mailing_date'])===1
            && $operation['mailing_date']>=gmdate('Y-m-d',$now)
            && in_array($operation['carrier_environment'] ?? null,['sandbox','production'],true);
    }

    /** Claim the one allowed USPS reprint request without authorizing another purchase. */
    public function markReprintSubmitted(\PDO $ordersDb,int $orderId,int $packageIndex,
        int $operatorId,string $environment): array
    {
        self::requireAdmin($ordersDb,$operatorId);
        $scope=self::scope($ordersDb,$orderId,$packageIndex);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $operation=$this->find($orderId,$packageIndex);
            if (!$operation || !self::reprintEligible($operation)
                || $operation['carrier_environment']!==$environment
                || !hash_equals($operation['fingerprint'],$scope['fingerprint'])
                || $this->reprint($orderId,$packageIndex)) {
                throw new \RuntimeException('USPS reprint is unavailable or already attempted.');
            }
            $cancel=$this->db->prepare('SELECT 1 FROM shipping_label_cancellations WHERE operation_id=?');
            $cancel->execute([(int)$operation['id']]);
            if ($cancel->fetchColumn()) throw new \RuntimeException('Canceled labels cannot be reprinted.');
            $now=time();
            $stmt=$this->db->prepare("INSERT INTO shipping_label_reprints
                (operation_id,state,operator_id,created_at,updated_at) VALUES (?,'submitted',?,?,?)");
            $stmt->execute([(int)$operation['id'],$operatorId,$now,$now]);
            $this->db->exec('COMMIT');
            return $operation;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function reprint(int $orderId,int $packageIndex): ?array
    {
        $stmt=$this->db->prepare('SELECT r.* FROM shipping_label_reprints r
            JOIN shipping_label_operations o ON o.id=r.operation_id
            WHERE o.order_id=? AND o.package_index=?');
        $stmt->execute([$orderId,$packageIndex]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function markReprintReview(int $orderId,int $packageIndex): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $operation=$this->find($orderId,$packageIndex);
            if (!$operation || !in_array($operation['state'],['submitted','review'],true)) {
                throw new \RuntimeException('USPS reprint needs reconciliation.');
            }
            $now=time();
            $stmt=$this->db->prepare("UPDATE shipping_label_reprints SET state='review',updated_at=?
                WHERE operation_id=? AND state='submitted'");
            $stmt->execute([$now,(int)$operation['id']]);
            if ($stmt->rowCount()!==1) throw new \RuntimeException('USPS reprint needs reconciliation.');
            $stmt=$this->db->prepare("UPDATE shipping_label_operations SET state='review',updated_at=?
                WHERE id=? AND state IN ('submitted','review')");
            $stmt->execute([$now,(int)$operation['id']]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Persist a fully parsed carrier response atomically; failed writes leave it submitted. */
    public function recordReady(int $orderId,int $packageIndex,array $confirmation): void
    {
        $this->persistConfirmation($orderId,$packageIndex,$confirmation,false);
    }

    public function recordReprintReady(int $orderId,int $packageIndex,array $confirmation): void
    {
        $this->persistConfirmation($orderId,$packageIndex,$confirmation,true);
    }

    /** Save a label independently confirmed in the carrier account, without another carrier request. */
    public function reconcileReady(\PDO $ordersDb,int $orderId,int $packageIndex,int $operatorId,
        string $expectedState,array $confirmation,string $evidenceReference,bool $verified): void
    {
        self::requireAdmin($ordersDb,$operatorId);
        if (!$verified || !in_array($expectedState,['submitted','review'],true)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}\z/D',$evidenceReference)) {
            throw new \InvalidArgumentException('Confirm the carrier evidence and enter its reference.');
        }
        $scope=self::scope($ordersDb,$orderId,$packageIndex);
        $this->persistConfirmation($orderId,$packageIndex,$confirmation,false,
            ['scope'=>$scope,'actor_id'=>$operatorId,'expected_state'=>$expectedState,
                'evidence_reference'=>$evidenceReference]);
    }

    public function resolution(int $orderId,int $packageIndex): ?array
    {
        $stmt=$this->db->prepare('SELECT r.* FROM shipping_label_resolutions r
            JOIN shipping_label_operations o ON o.id=r.operation_id
            WHERE o.order_id=? AND o.package_index=?');
        $stmt->execute([$orderId,$packageIndex]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function persistConfirmation(int $orderId,int $packageIndex,array $confirmation,
        bool $reprint,?array $manual=null): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $operation=$this->find($orderId,$packageIndex);
            $reprintTable=$this->db->query("SELECT 1 FROM sqlite_master
                WHERE type='table' AND name='shipping_label_reprints'")->fetchColumn();
            $reprintRow=$operation && $reprintTable ? $this->reprint($orderId,$packageIndex) : null;
            if (!$operation || ($manual!==null
                    ? (!in_array($operation['state'],['submitted','review'],true)
                        || $operation['state']!==$manual['expected_state']
                        || !hash_equals($operation['fingerprint'],$manual['scope']['fingerprint'])
                        || (int)($operation['submitted_at'] ?? 0)>time()-self::HANDOFF_DELAY_SECONDS
                        || (int)($operation['submitted_at'] ?? 0)<1
                        || ($reprintRow && $reprintRow['state']==='submitted'
                            && (int)$reprintRow['created_at']>time()-self::HANDOFF_DELAY_SECONDS))
                    : ($reprint
                        ? (!in_array($operation['state'],['submitted','review'],true)
                            || $operation['provider']!=='usps' || ($reprintRow['state'] ?? null)!=='submitted')
                        : ($operation['state']!=='submitted' || $reprintRow!==null)))
                || (int)$operation['expected_packages']<1 || (int)$operation['expected_packages']>10) {
                throw new \RuntimeException('Shipment confirmation needs reconciliation.');
            }
            if ($manual!==null) {
                $cancel=$this->db->prepare('SELECT 1 FROM shipping_label_cancellations WHERE operation_id=?');
                $cancel->execute([(int)$operation['id']]);
                if ($cancel->fetchColumn()) throw new \RuntimeException('Canceled labels cannot be reconciled as ready.');
                if ($this->resolution($orderId,$packageIndex)) {
                    throw new \RuntimeException('Carrier label was already reconciled.');
                }
            }
            $shipmentId=$confirmation['shipment_id'] ?? null;
            $billedCents=$confirmation['billed_cents'] ?? null;
            $packages=$confirmation['packages'] ?? null;
            if (!is_string($shipmentId) || !is_int($billedCents)
                || $billedCents<1 || $billedCents>99999999
                || !is_array($packages) || !array_is_list($packages)
                || count($packages)!==(int)$operation['expected_packages']) {
                throw new \InvalidArgumentException('Invalid carrier shipment confirmation.');
            }
            $format=$operation['provider']==='usps' ? 'pdf' : 'gif';
            $trackingPattern=$operation['provider']==='usps'
                ? '/\A[0-9]{20,34}\z/D' : '/\A1Z[A-Z0-9]{16}\z/D';
            if (!preg_match($trackingPattern,$shipmentId)) {
                throw new \InvalidArgumentException('Invalid carrier shipment reference.');
            }
            $insert=$this->db->prepare('INSERT INTO shipping_label_packages
                (operation_id,shipment_package_index,tracking_number,label_format,label_sha256,label_image)
                VALUES (?,?,?,?,?,?)');
            $seen=[];
            foreach ($packages as $index=>$package) {
                $tracking=$package['tracking_number'] ?? null;
                $image=$package['label'] ?? null;
                if (!is_string($tracking) || !preg_match($trackingPattern,$tracking)
                    || isset($seen[$tracking]) || ($package['format'] ?? null)!==$format
                    || !is_string($image) || strlen($image)<6 || strlen($image)>6291456
                    || ($format==='pdf' && !str_starts_with($image,'%PDF-'))
                    || ($format==='gif' && !str_starts_with($image,'GIF87a') && !str_starts_with($image,'GIF89a'))) {
                    throw new \InvalidArgumentException('Invalid carrier package confirmation.');
                }
                $seen[$tracking]=true;
                $insert->bindValue(1,(int)$operation['id'],\PDO::PARAM_INT);
                $insert->bindValue(2,$index,\PDO::PARAM_INT);
                $insert->bindValue(3,$tracking);
                $insert->bindValue(4,$format);
                $insert->bindValue(5,hash('sha256',$image));
                $insert->bindValue(6,$image,\PDO::PARAM_LOB);
                $insert->execute();
            }
            if ($packages[0]['tracking_number']!==$shipmentId) {
                throw new \InvalidArgumentException('Carrier shipment and package references disagree.');
            }
            $now=time();
            $stateCheck=$reprint || $manual!==null ? "state IN ('submitted','review')" : "state='submitted'";
            $stmt=$this->db->prepare("UPDATE shipping_label_operations SET state='ready',shipment_id=?,
                tracking_number=?,billed_cents=?,updated_at=? WHERE id=? AND $stateCheck");
            $stmt->execute([$shipmentId,$shipmentId,$billedCents,$now,(int)$operation['id']]);
            if ($stmt->rowCount()!==1) throw new \RuntimeException('Shipment confirmation needs reconciliation.');
            if ($reprint) {
                $stmt=$this->db->prepare("UPDATE shipping_label_reprints SET state='ready',updated_at=?
                    WHERE operation_id=? AND state='submitted'");
                $stmt->execute([$now,(int)$operation['id']]);
                if ($stmt->rowCount()!==1) throw new \RuntimeException('USPS reprint needs reconciliation.');
            }
            if ($manual!==null) {
                $stmt=$this->db->prepare('INSERT INTO shipping_label_resolutions
                    (operation_id,previous_state,evidence_reference,actor_id,resolved_at) VALUES (?,?,?,?,?)');
                $stmt->execute([(int)$operation['id'],$operation['state'],
                    $manual['evidence_reference'],$manual['actor_id'],$now]);
                if ($reprintRow && $reprintRow['state']==='submitted') {
                    $stmt=$this->db->prepare("UPDATE shipping_label_reprints SET state='review',updated_at=?
                        WHERE operation_id=? AND state='submitted'");
                    $stmt->execute([$now,(int)$operation['id']]);
                }
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Label bytes contain delivery details; only an active admin may retrieve them. */
    public function label(\PDO $ordersDb,int $orderId,int $packageIndex,int $shipmentPackageIndex,int $operatorId): array
    {
        if ($shipmentPackageIndex<0 || $operatorId<1) throw new \InvalidArgumentException('Invalid label lookup.');
        self::requireAdmin($ordersDb,$operatorId);
        $stmt=$this->db->prepare("SELECT p.tracking_number,p.label_format,p.label_sha256,p.label_image
            FROM shipping_label_operations o JOIN shipping_label_packages p ON p.operation_id=o.id
            LEFT JOIN shipping_label_cancellations c ON c.operation_id=o.id
            WHERE o.order_id=? AND o.package_index=? AND o.state='ready'
                AND c.operation_id IS NULL AND p.shipment_package_index=?");
        $stmt->execute([$orderId,$packageIndex,$shipmentPackageIndex]);
        $row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['label_image'])
            || !hash_equals($row['label_sha256'],hash('sha256',$row['label_image']))) {
            throw new \RuntimeException('Confirmed label is unavailable.');
        }
        return ['tracking_number'=>$row['tracking_number'],'format'=>$row['label_format'],'label'=>$row['label_image']];
    }

    /** A lost or ambiguous carrier response is never retried automatically. */
    public function markReview(int $orderId,int $packageIndex): void
    {
        $stmt=$this->db->prepare("UPDATE shipping_label_operations SET state='review',updated_at=?
            WHERE order_id=? AND package_index=? AND state='submitted'");
        $stmt->execute([time(),$orderId,$packageIndex]);
        if ($stmt->rowCount()!==1) throw new \RuntimeException('Shipment operation needs reconciliation.');
    }

    public function find(int $orderId,int $packageIndex): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM shipping_label_operations WHERE order_id=? AND package_index=?');
        $stmt->execute([$orderId,$packageIndex]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function handoffEligible(array $operation,int $operatorId,?int $now=null): bool
    {
        return $operatorId>0 && ($operation['state'] ?? null)==='reserved'
            && (int)($operation['operator_id'] ?? 0)!==$operatorId
            && (int)($operation['updated_at'] ?? 0)>0
            && (int)$operation['updated_at']<=($now ?? time())-self::HANDOFF_DELAY_SECONDS;
    }

    public function handoffs(int $orderId,int $packageIndex): array
    {
        $stmt=$this->db->prepare('SELECT h.previous_operator_id,h.new_operator_id,h.transferred_at
            FROM shipping_label_handoffs h JOIN shipping_label_operations o ON o.id=h.operation_id
            WHERE o.order_id=? AND o.package_index=? ORDER BY h.id DESC LIMIT 10');
        $stmt->execute([$orderId,$packageIndex]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function health(): array
    {
        $states=['reserved'=>0,'submitted'=>0,'ready'=>0,'review'=>0];
        foreach ($this->db->query('SELECT state,COUNT(*) AS count FROM shipping_label_operations GROUP BY state') as $row) {
            $states[$row['state']]=(int)$row['count'];
        }
        return $states;
    }

    /** Read-only queue for operations that must be checked with the carrier. */
    public function attention(int $limit=100): array
    {
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Invalid shipment queue limit.');
        $stmt=$this->db->prepare("SELECT order_id,package_index,provider,state,submitted_at,updated_at
            FROM shipping_label_operations WHERE state IN ('submitted','review')
            ORDER BY updated_at DESC,id DESC LIMIT ?");
        $stmt->bindValue(1,$limit,\PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function scope(\PDO $ordersDb,int $orderId,int $packageIndex): array
    {
        $columns=array_column($ordersDb->query('PRAGMA table_info(order_shipping)')->fetchAll(\PDO::FETCH_ASSOC),'name');
        $carrierQuote=in_array('carrier_quote_cents',$columns,true)
            ? 's.carrier_quote_cents' : 'NULL AS carrier_quote_cents';
        $stmt=$ordersDb->prepare('SELECT o.payment_status,o.order_status,o.order_number,o.customer_name,
            o.customer_phone,o.shipping_address,o.paypal_transaction_id,
            s.provider,s.service_code,s.quote_hash,s.origin_json,s.packages_json,s.fulfillment_json,
            '.$carrierQuote.' FROM orders o JOIN order_shipping s ON s.order_id=o.id WHERE o.id=?');
        $stmt->execute([$orderId]);
        $row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['payment_status']!=='completed' || $row['order_status']!=='processing'
            || !in_array($row['provider'],['usps','ups'],true) || !is_string($row['service_code'])
            || $row['service_code']==='' || empty($row['paypal_transaction_id'])) {
            throw new \RuntimeException('Only paid, processing direct-carrier orders can prepare labels.');
        }
        $packages=json_decode((string)$row['packages_json'],true);
        if (!is_array($packages) || !array_is_list($packages) || !$packages || count($packages)>10
            || ($row['provider']==='ups' && $packageIndex!==0)
            || ($row['provider']==='usps' && !array_key_exists($packageIndex,$packages))) {
            throw new \RuntimeException('Invalid direct-carrier package selection.');
        }
        if ($row['provider']==='usps') {
            $options=json_decode((string)$row['fulfillment_json'],true);
            if (!is_array($options) || !array_is_list($options) || count($options)!==count($packages)
                || !isset($options[$packageIndex]['rate_indicator'],$options[$packageIndex]['processing_category'])) {
                throw new \RuntimeException('USPS label options were not saved with the order.');
            }
        }
        $fingerprint=hash('sha256',json_encode([$orderId,$packageIndex,$row],JSON_THROW_ON_ERROR));
        return ['provider'=>$row['provider'],'service_code'=>$row['service_code'],
            'expected_packages'=>$row['provider']==='usps' ? 1 : count($packages),'fingerprint'=>$fingerprint];
    }

    private static function requireAdmin(\PDO $ordersDb,int $operatorId): void
    {
        $admin=$ordersDb->prepare("SELECT id FROM admin_users WHERE id=? AND role='admin' AND is_active=1");
        $admin->execute([$operatorId]);
        if (!$admin->fetchColumn()) throw new \RuntimeException('An active administrator is required.');
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8]=chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
