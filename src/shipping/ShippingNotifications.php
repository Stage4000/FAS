<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Transactional package-status digests. Stores hashes and state, never email bodies. */
final class ShippingNotifications
{
    private \PDO $db;
    private \PDO $orders;
    private array $config;
    private $clock;

    public static function install(\PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS shipping_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            digest TEXT NOT NULL,
            state TEXT NOT NULL CHECK(state IN ('queued','submitted','accepted','review','suppressed')),
            created_at INTEGER NOT NULL,
            attempted_at INTEGER,
            finished_at INTEGER,
            UNIQUE(order_id,digest)
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS shipping_notifications_queue ON shipping_notifications(state,id)');
        $db->exec('CREATE TABLE IF NOT EXISTS shipping_notification_scans(order_id INTEGER PRIMARY KEY,checked_at INTEGER NOT NULL)');
        $db->exec("CREATE TABLE IF NOT EXISTS shipping_notification_resolutions (
            notification_id INTEGER PRIMARY KEY REFERENCES shipping_notifications(id),
            previous_state TEXT NOT NULL,
            outcome TEXT NOT NULL CHECK(outcome IN ('accepted','suppressed')),
            actor_id INTEGER,
            source TEXT NOT NULL CHECK(source IN ('admin','cli')),
            resolved_at INTEGER NOT NULL
        )");
    }

    public function __construct(\PDO $privateDb,\PDO $orders,array $config,?callable $clock=null)
    {
        $this->db=$privateDb;
        $this->orders=$orders;
        $this->config=$config;
        $this->clock=$clock ?? static fn()=>time();
        $this->db->query('SELECT id FROM shipping_notifications LIMIT 1');
    }

    public static function ready(array $config): bool
    {
        $mail=$config['notifications'] ?? [];
        return ($mail['enabled'] ?? false)===true && ($mail['delivery_verified'] ?? false)===true
            && is_int($mail['not_before'] ?? null) && $mail['not_before']>0
            && self::email($mail['from_email'] ?? null) && self::email($mail['reply_to'] ?? null);
    }

    /** Rotates through recent confirmed orders, coalescing unsent changes into one digest. */
    public function prepare(int $limit=20): array
    {
        self::limit($limit);
        $result=['enabled'=>self::ready($this->config),'scanned'=>0,'queued'=>0,'skipped'=>0];
        if (!$result['enabled']) return $result;
        $stmt=$this->db->prepare("SELECT o.order_id FROM shipping_label_operations o
            LEFT JOIN shipping_notification_scans s ON s.order_id=o.order_id
            WHERE o.state='ready' AND o.created_at>=?
            GROUP BY o.order_id ORDER BY COALESCE(s.checked_at,0),o.order_id LIMIT ?");
        $stmt->bindValue(1,max($this->now()-120*86400,$this->config['notifications']['not_before']),\PDO::PARAM_INT);
        $stmt->bindValue(2,$limit,\PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $orderId) {
            $orderId=(int)$orderId;
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $snapshot=$this->snapshot($orderId);
                $digest=$snapshot['digest'] ?? '';
                $this->run("UPDATE shipping_notifications SET state='suppressed',finished_at=?
                    WHERE order_id=? AND state='queued' AND digest<>?",[$this->now(),$orderId,$digest]);
                $inserted=0;
                if ($snapshot) {
                    $inserted=$this->run("INSERT INTO shipping_notifications
                        (order_id,digest,state,created_at) VALUES (?,?,'queued',?)
                        ON CONFLICT(order_id,digest) DO UPDATE SET state='queued',finished_at=NULL,
                            created_at=excluded.created_at
                        WHERE shipping_notifications.state='suppressed' AND shipping_notifications.attempted_at IS NULL",
                        [$orderId,$digest,$this->now()])->rowCount();
                }
                $this->run('INSERT INTO shipping_notification_scans VALUES (?,?)
                    ON CONFLICT(order_id) DO UPDATE SET checked_at=excluded.checked_at',[$orderId,$this->now()]);
                $this->db->exec('COMMIT');
                $result['scanned']++;
                $result[$inserted ? 'queued' : 'skipped']++;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
                throw $e;
            }
        }
        return $result;
    }

    /** At most one transport attempt per digest; uncertain handoffs require operator review. */
    public function sendBatch(callable $transport,int $limit=20): array
    {
        self::limit($limit);
        $result=['enabled'=>self::ready($this->config),'accepted'=>0,'review'=>0,'suppressed'=>0,'skipped'=>0];
        if (!$result['enabled']) return $result;
        $stmt=$this->db->prepare("SELECT n.* FROM shipping_notifications n WHERE n.state='queued'
            AND NOT EXISTS (SELECT 1 FROM shipping_notifications p WHERE p.order_id=n.order_id
                AND (p.state IN ('submitted','review') OR p.attempted_at>?)) ORDER BY n.id LIMIT ?");
        $stmt->bindValue(1,$this->now()-21600,\PDO::PARAM_INT);
        $stmt->bindValue(2,$limit,\PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id=(int)$row['id']; $orderId=(int)$row['order_id'];
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $snapshot=$this->snapshot($orderId);
                if (!$snapshot || !hash_equals($row['digest'],$snapshot['digest'])) {
                    $this->run("UPDATE shipping_notifications SET state='suppressed',finished_at=?
                        WHERE id=? AND state='queued'",[$this->now(),$id]);
                    $this->db->exec('COMMIT'); $result['suppressed']++; continue;
                }
                $claimed=$this->run("UPDATE shipping_notifications SET state='submitted',attempted_at=?
                    WHERE id=? AND state='queued' AND NOT EXISTS (
                        SELECT 1 FROM shipping_notifications p WHERE p.order_id=?
                        AND (p.state IN ('submitted','review') OR p.attempted_at>?))",
                    [$this->now(),$id,$orderId,$this->now()-21600])->rowCount()===1;
                $this->db->exec('COMMIT');
                if (!$claimed) { $result['skipped']++; continue; }
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
                throw $e;
            }
            // Recheck after the durable claim, immediately before the external handoff.
            try {
                $current=$this->snapshot($orderId);
                if (!$current || !hash_equals($row['digest'],$current['digest'])) {
                    $this->finish($id,'suppressed'); $result['suppressed']++; continue;
                }
                $accepted=$transport($this->message($current))===true;
            } catch (\Throwable $e) {
                $accepted=false; // Never persist transport exception text or retry an ambiguous send.
            }
            $state=$accepted ? 'accepted' : 'review';
            $this->finish($id,$state);
            $result[$state]++;
        }
        return $result;
    }

    public function health(): array
    {
        $result=['initialized'=>true,'enabled'=>self::ready($this->config),
            'queued'=>0,'submitted'=>0,'accepted'=>0,'review'=>0,'suppressed'=>0];
        foreach ($this->db->query('SELECT state,COUNT(*) count FROM shipping_notifications GROUP BY state') as $row) {
            $result[$row['state']]=(int)$row['count'];
        }
        $result['stalled']=(int)$this->run("SELECT COUNT(*) FROM shipping_notifications
            WHERE state='submitted' AND attempted_at<=?",[$this->now()-900])->fetchColumn();
        return $result;
    }

    /** Private operational references only; no recipients or tracking details in CLI output. */
    public function attention(int $limit=20): array
    {
        self::limit($limit);
        $stmt=$this->db->prepare("SELECT id,order_id,state,attempted_at FROM shipping_notifications
            WHERE state='review' OR (state='submitted' AND attempted_at<=?) ORDER BY id LIMIT ?");
        $stmt->bindValue(1,$this->now()-900,\PDO::PARAM_INT);
        $stmt->bindValue(2,$limit,\PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Operator confirms transport-log reconciliation; this never retries the old message. */
    public function resolve(int $id,string $outcome,bool $verified,?int $actorId=null): void
    {
        if ($id<1 || !$verified || !in_array($outcome,['accepted','suppressed'],true)) {
            throw new \InvalidArgumentException('Verify the transport outcome before resolving a notification.');
        }
        if ($actorId!==null) {
            $admin=$this->orders->prepare("SELECT id FROM admin_users WHERE id=? AND role='admin' AND is_active=1");
            $admin->execute([$actorId]);
            if ($actorId<1 || !$admin->fetchColumn()) throw new \RuntimeException('An active administrator is required.');
        } elseif (PHP_SAPI!=='cli') {
            throw new \RuntimeException('An active administrator is required.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=$this->find($id);
            if (!$row || ($row['state']!=='review'
                && !($row['state']==='submitted' && (int)$row['attempted_at']<=$this->now()-900))) {
                throw new \DomainException('Notification is not eligible for reconciliation.');
            }
            $this->run('INSERT INTO shipping_notification_resolutions
                (notification_id,previous_state,outcome,actor_id,source,resolved_at) VALUES (?,?,?,?,?,?)',
                [$id,$row['state'],$outcome,$actorId,$actorId===null?'cli':'admin',$this->now()]);
            $this->run('UPDATE shipping_notifications SET state=?,finished_at=? WHERE id=?',[$outcome,$this->now(),$id]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function find(int $id): ?array
    {
        $row=$this->run('SELECT id,order_id,state,created_at,attempted_at,finished_at
            FROM shipping_notifications WHERE id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function resolution(int $id): ?array
    {
        $row=$this->run('SELECT previous_state,outcome,actor_id,source,resolved_at
            FROM shipping_notification_resolutions WHERE notification_id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function recentResolutions(int $limit=10): array
    {
        self::limit($limit);
        $stmt=$this->db->prepare('SELECT n.id,n.order_id,r.outcome,r.actor_id,r.source,r.resolved_at
            FROM shipping_notification_resolutions r JOIN shipping_notifications n ON n.id=r.notification_id
            ORDER BY r.resolved_at DESC,n.id DESC LIMIT ?');
        $stmt->bindValue(1,$limit,\PDO::PARAM_INT); $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function snapshot(int $orderId): ?array
    {
        $stmt=$this->orders->prepare('SELECT o.order_number,o.customer_email,o.payment_status,
            o.order_status,o.paypal_transaction_id,o.shipping_address,s.provider
            FROM orders o JOIN order_shipping s ON s.order_id=o.id WHERE o.id=?');
        $stmt->execute([$orderId]);
        $order=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$order || $order['payment_status']!=='completed' || empty($order['paypal_transaction_id'])
            || !in_array($order['order_status'],['processing','shipped','delivered'],true)
            || !self::email($order['customer_email']) || !self::text($order['order_number'],100)) return null;
        $rows=$this->run("SELECT o.provider,o.created_at,o.carrier_environment AS label_environment,
                c.operation_id AS cancellation,p.tracking_number,t.status_code,t.status_text,t.checked_at,
                t.last_result,t.carrier_environment AS status_environment
            FROM shipping_label_operations o JOIN shipping_label_packages p ON p.operation_id=o.id
            LEFT JOIN shipping_label_cancellations c ON c.operation_id=o.id
            LEFT JOIN shipping_tracking t ON t.tracking_number=p.tracking_number
            WHERE o.order_id=? AND o.state='ready' ORDER BY p.tracking_number LIMIT 11",[$orderId])->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows || count($rows)>10) return null;
        $packages=[];
        foreach ($rows as $row) {
            // Suppress the whole digest while any confirmed label is cancelled, stale or uncertain.
            $provider=$row['provider'];
            $carrier=$this->config['carriers'][$provider] ?? [];
            if ($row['cancellation']!==null || $provider!==$order['provider']
                || (int)$row['created_at']<$this->config['notifications']['not_before']
                || (int)$row['created_at']<$this->now()-120*86400
                || $row['label_environment']!=='production' || $row['status_environment']!=='production'
                || ($carrier['enabled'] ?? false)!==true || ($carrier['tracking_enabled'] ?? false)!==true
                || ($carrier['environment'] ?? '')!=='production' || ($carrier['production_verified'] ?? false)!==true
                || $row['last_result']!=='ok' || (int)$row['checked_at']<$this->now()-86400
                || (int)$row['checked_at']>$this->now()+60 || !self::text($row['status_text'],160)) return null;
            $pattern=$provider==='usps' ? '/\A[0-9]{20,34}\z/D' : '/\A1Z[A-Z0-9]{16}\z/D';
            if (!preg_match($pattern,$row['tracking_number'])) return null;
            $packages[]=['provider'=>$provider,'tracking'=>$row['tracking_number'],
                'status'=>$row['status_text'],'checked_at'=>(int)$row['checked_at']];
        }
        // Poll timestamps do not turn an unchanged status into another message.
        $states=array_map(static fn($p)=>[$p['provider'],$p['tracking'],$p['status']],$packages);
        $binding=[$orderId,$order['order_number'],$order['customer_email'],
            $order['paypal_transaction_id'],$order['shipping_address'],$states];
        return ['digest'=>hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR)),
            'order_number'=>$order['order_number'],'to'=>$order['customer_email'],'packages'=>$packages];
    }

    private function message(array $snapshot): array
    {
        $body="Tracking update for your Flip and Strip order ".$snapshot['order_number'].".\n\n";
        foreach ($snapshot['packages'] as $package) {
            $url=$package['provider']==='usps'
                ? 'https://tools.usps.com/go/TrackConfirmAction?tLabels='
                : 'https://www.ups.com/track?tracknum=';
            $body.=strtoupper($package['provider']).' tracking: '.$package['tracking']."\n"
                .'Carrier status: '.$package['status']."\n"
                .'Last checked: '.gmdate('Y-m-d H:i',$package['checked_at'])." UTC\n"
                .$url.rawurlencode($package['tracking'])."\n\n";
        }
        $body.="Check the carrier link for the latest information.\n"
            ."Questions about your order? Reply to this email.\n\nFlip and Strip\n";
        return ['to'=>$snapshot['to'],'from'=>$this->config['notifications']['from_email'],
            'reply_to'=>$this->config['notifications']['reply_to'],
            'subject'=>'Tracking update for order '.$snapshot['order_number'],'body'=>$body];
    }

    private function finish(int $id,string $state): void
    {
        $stmt=$this->run("UPDATE shipping_notifications SET state=?,finished_at=? WHERE id=? AND state='submitted'",
            [$state,$this->now(),$id]);
        if ($stmt->rowCount()!==1) throw new \RuntimeException('Notification outcome needs reconciliation.');
    }

    private function run(string $sql,array $values=[]): \PDOStatement
    {
        $stmt=$this->db->prepare($sql); $stmt->execute($values); return $stmt;
    }

    private function now(): int { return (int)($this->clock)(); }
    private static function limit(int $limit): void
    {
        if ($limit<1 || $limit>50) throw new \InvalidArgumentException('Invalid notification batch size.');
    }
    private static function email($value): bool
    {
        return is_string($value) && strlen($value)<=254 && !preg_match('/[\x00-\x20\x7f]/',$value)
            && (bool)filter_var($value,FILTER_VALIDATE_EMAIL);
    }
    private static function text($value,int $max): bool
    {
        return is_string($value) && trim($value)!=='' && strlen($value)<=$max
            && !preg_match('/[\x00-\x1f\x7f]/',$value) && strip_tags($value)===$value;
    }
}
