<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Read-only tracking attention counts, limited to recent confirmed, uncancelled packages. */
final class ShippingMonitor
{
    public static function tracking(\PDO $db,int $now, int $limit=50): array
    {
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Invalid tracking monitor limit.');
        $sql="SELECT o.order_id,o.provider,o.created_at,p.tracking_number,t.checked_at,t.attempted_at,
            CASE
                WHEN t.last_result='error' THEN 'failed'
                WHEN t.last_result='pending' AND t.attempted_at>0 AND t.attempted_at<=:interrupted THEN 'interrupted'
                WHEN t.checked_at IS NULL AND o.created_at<=:first_due THEN 'awaiting_first'
                WHEN t.checked_at IS NOT NULL AND t.checked_at<=:stale THEN 'stale'
                ELSE NULL
            END AS issue
            FROM shipping_label_operations o JOIN shipping_label_packages p ON p.operation_id=o.id
            LEFT JOIN shipping_tracking t ON t.tracking_number=p.tracking_number
            LEFT JOIN shipping_label_cancellations c ON c.operation_id=o.id
            WHERE o.state='ready' AND o.created_at>=:recent AND c.operation_id IS NULL";
        $params=[':interrupted'=>$now-900,':first_due'=>$now-1800,':stale'=>$now-86400,':recent'=>$now-120*86400];
        $summary=$db->prepare('SELECT issue,COUNT(*) count,MAX(checked_at) latest FROM ('.$sql.') GROUP BY issue');
        $summary->execute($params);
        $result=['packages'=>0,'attention'=>0,'failed'=>0,'interrupted'=>0,'awaiting_first'=>0,'stale'=>0,
            'last_success_at'=>null,'rows'=>[]];
        foreach ($summary->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result['packages']+=(int)$row['count'];
            if ($row['issue']!==null) {
                $result[$row['issue']]=(int)$row['count'];
                $result['attention']+=(int)$row['count'];
            }
            if ($row['latest']!==null) $result['last_success_at']=max($result['last_success_at'] ?? 0,(int)$row['latest']);
        }
        $rows=$db->prepare('SELECT * FROM ('.$sql.') WHERE issue IS NOT NULL
            ORDER BY COALESCE(checked_at,created_at),order_id,tracking_number LIMIT :row_limit');
        foreach ($params as $key=>$value) $rows->bindValue($key,$value,\PDO::PARAM_INT);
        $rows->bindValue(':row_limit',$limit,\PDO::PARAM_INT);
        $rows->execute();
        $result['rows']=$rows->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
    }
}
