<?php
declare(strict_types=1);

/** Recent site sessions and browser-reported Tawk activity, grouped by analytics location. */
function fas_security_widget_traffic(PDO $db, ?int $now = null): array
{
    $now = $now ?? time();
    $current = gmdate('Y-m-d H:i:s', $now - 900);
    $previous = gmdate('Y-m-d H:i:s', $now - 1800);
    $until = gmdate('Y-m-d H:i:s', $now);
    $countries = [];
    $sessionSql = "SELECT UPPER(TRIM(COALESCE(cf_country, ''))) AS country,
        SUM(CASE WHEN started_at>=? THEN 1 ELSE 0 END) AS sessions,
        SUM(CASE WHEN started_at<? THEN 1 ELSE 0 END) AS previous_sessions,
        COUNT(DISTINCT CASE WHEN started_at>=? THEN NULLIF(ip_hash, '') END) AS addresses,
        SUM(CASE WHEN started_at>=? AND COALESCE(is_potential_bot,0)=1 THEN 1 ELSE 0 END) AS bot_signals
        FROM analytics_sessions
        WHERE started_at>=? AND started_at<=? AND COALESCE(is_admin_session,0)=0
        GROUP BY UPPER(TRIM(COALESCE(cf_country, '')))";
    $stmt = $db->prepare($sessionSql);
    $stmt->execute([$current,$current,$current,$current,$previous,$until]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = fas_security_traffic_country((string)$row['country']);
        if (!isset($countries[$code])) $countries[$code] = fas_security_traffic_row($code);
        foreach (['sessions','previous_sessions','addresses','bot_signals'] as $key) {
            $countries[$code][$key] += (int)$row[$key];
        }
    }

    $widgetSql = "SELECT UPPER(TRIM(COALESCE(s.cf_country, ''))) AS country,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_widget_loaded' AND e.created_at>=? THEN e.session_id END) AS loads,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_widget_opened' AND e.created_at>=? THEN e.session_id END) AS opens,
        COUNT(DISTINCT CASE WHEN e.event_type='tawk_chat_started' AND e.created_at>=? THEN e.session_id END) AS chats
        FROM analytics_events e
        JOIN analytics_sessions s ON s.session_id=e.session_id
        WHERE e.created_at>=? AND e.created_at<=?
          AND e.event_type IN ('tawk_widget_loaded','tawk_widget_opened','tawk_chat_started')
          AND COALESCE(s.is_admin_session,0)=0
        GROUP BY UPPER(TRIM(COALESCE(s.cf_country, '')))";
    $stmt = $db->prepare($widgetSql);
    $stmt->execute([$current,$current,$current,$previous,$until]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = fas_security_traffic_country((string)$row['country']);
        if (!isset($countries[$code])) $countries[$code] = fas_security_traffic_row($code);
        foreach (['loads','opens','chats'] as $key) {
            $countries[$code][$key] += (int)$row[$key];
        }
    }
    $totals = ['sessions'=>0,'previous_sessions'=>0,'loads'=>0,'opens'=>0,'chats'=>0,'surges'=>0];
    foreach ($countries as &$row) {
        // A sharp increase from several addresses merits review; location alone never blocks traffic.
        $row['surge'] = $row['sessions'] >= 6 && $row['addresses'] >= 3
            && $row['sessions'] >= 3 * max(1, $row['previous_sessions']);
        foreach (['sessions','previous_sessions','loads','opens','chats'] as $key) $totals[$key] += $row[$key];
        if ($row['surge']) $totals['surges']++;
    }
    unset($row);
    usort($countries, static function (array $a, array $b): int {
        return ($b['sessions'] <=> $a['sessions']) ?: strcmp($a['country'], $b['country']);
    });
    return ['totals'=>$totals,'countries'=>array_slice($countries,0,8),'updated_at'=>$until];
}

function fas_security_traffic_country(string $value): string
{
    return preg_match('/^[A-Z]{2}$/D',$value) ? $value : '??';
}

function fas_security_traffic_row(string $code): array
{
    return ['country'=>$code,'sessions'=>0,'previous_sessions'=>0,'addresses'=>0,
        'bot_signals'=>0,'loads'=>0,'opens'=>0,'chats'=>0,'surge'=>false];
}
