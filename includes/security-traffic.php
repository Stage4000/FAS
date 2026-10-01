<?php
declare(strict_types=1);

/** Recent browser-reported Tawk widget activity, grouped by the site's analytics location. */
function fas_security_widget_traffic(PDO $db, ?int $now = null): array
{
    $now = $now ?? time();
    $current = gmdate('Y-m-d H:i:s', $now - 900);
    $previous = gmdate('Y-m-d H:i:s', $now - 1800);
    $until = gmdate('Y-m-d H:i:s', $now);
    $sql = "SELECT UPPER(TRIM(COALESCE(s.cf_country, ''))) AS country,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_widget_loaded' AND e.created_at>=? THEN e.session_id END) AS loads,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_widget_loaded' AND e.created_at<? THEN e.session_id END) AS previous_loads,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_widget_loaded' AND e.created_at>=? THEN NULLIF(s.ip_hash, '') END) AS addresses,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_widget_loaded' AND e.created_at>=? AND COALESCE(s.is_potential_bot,0)=1 THEN e.session_id END) AS bot_signals,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_widget_opened' AND e.created_at>=? THEN e.session_id END) AS opens,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_chat_started' AND e.created_at>=? THEN e.session_id END) AS chats
        FROM analytics_events e
        JOIN analytics_sessions s ON s.session_id=e.session_id
        WHERE e.created_at>=? AND e.created_at<=?
          AND e.event_type IN ('tawk_widget_loaded','tawk_widget_opened','tawk_chat_started')
          AND COALESCE(s.is_admin_session,0)=0
        GROUP BY UPPER(TRIM(COALESCE(s.cf_country, '')))";
    $stmt = $db->prepare($sql);
    $stmt->execute([$current,$current,$current,$current,$current,$current,$previous,$until]);
    $countries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = preg_match('/^[A-Z]{2}$/D', (string)$row['country']) ? $row['country'] : '??';
        if (!isset($countries[$code])) {
            $countries[$code] = ['country'=>$code,'loads'=>0,'previous_loads'=>0,'addresses'=>0,
                'bot_signals'=>0,'opens'=>0,'chats'=>0,'surge'=>false];
        }
        foreach (['loads','previous_loads','addresses','bot_signals','opens','chats'] as $key) {
            $countries[$code][$key] += (int)$row[$key];
        }
    }
    $totals = ['loads'=>0,'previous_loads'=>0,'opens'=>0,'chats'=>0,'surges'=>0];
    foreach ($countries as &$row) {
        // A sharp increase from several addresses merits review; location alone never blocks traffic.
        $row['surge'] = $row['loads'] >= 6 && $row['addresses'] >= 3
            && $row['loads'] >= 3 * max(1, $row['previous_loads']);
        foreach (['loads','previous_loads','opens','chats'] as $key) $totals[$key] += $row[$key];
        if ($row['surge']) $totals['surges']++;
    }
    unset($row);
    usort($countries, static function (array $a, array $b): int {
        return ($b['loads'] <=> $a['loads']) ?: strcmp($a['country'], $b['country']);
    });
    return ['totals'=>$totals,'countries'=>array_slice($countries,0,8),'updated_at'=>$until];
}
