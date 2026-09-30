<?php
declare(strict_types=1);
namespace FAS\Security;

final class ClientIp
{
    // https://www.cloudflare.com/ips-v4 and /ips-v6, checked 2026-09-30.
    public const CLOUDFLARE = [
        '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
        '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
        '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
        '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22','2400:cb00::/32',
        '2606:4700::/32','2803:f800::/32','2405:b500::/32','2405:8100::/32',
        '2a06:98c0::/29','2c0f:f248::/32',
    ];

    public static function normalize($ip): string
    {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return '';
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }
        return strtolower(inet_ntop($packed));
    }

    public static function inRanges(string $ip, array $ranges): bool
    {
        $ip = self::normalize($ip);
        if ($ip === '') return false;
        $packed = inet_pton($ip);
        foreach ($ranges as $range) {
            $parts = explode('/', trim($range));
            $network = self::normalize($parts[0]);
            if ($network === '') continue;
            $net = inet_pton($network);
            $bits = $parts[1] ?? (string)(strlen($net) * 8);
            if (!ctype_digit($bits) || (int)$bits > strlen($net)*8 || strlen($packed) !== strlen($net)) continue;
            $bytes = intdiv((int)$bits, 8); $remaining = (int)$bits % 8;
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) continue;
            if (!$remaining || ((ord($packed[$bytes]) ^ ord($net[$bytes])) & (255 << (8-$remaining))) === 0) return true;
        }
        return false;
    }

    public static function proxies(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', getenv('FAS_TRUSTED_PROXY_CIDRS') ?: ''))));
    }

    public static function resolve(array $server): array
    {
        $peer = self::normalize($server['REMOTE_ADDR'] ?? '');
        $trusted = self::inRanges($peer, self::CLOUDFLARE) || self::inRanges($peer, self::proxies());
        if ($trusted) {
            $ip = self::normalize($server['HTTP_CF_CONNECTING_IP'] ?? '');
            // CF pseudo IPv4 preserves the original IPv6 in this separate header.
            if ($ip !== '' && self::inRanges($ip, ['240.0.0.0/4'])) {
                $v6 = self::normalize($server['HTTP_CF_CONNECTING_IPV6'] ?? '');
                if (strpos($v6, ':') !== false) $ip = $v6;
            }
            if ($ip !== '') return ['ip'=>$ip, 'peer'=>$peer, 'source'=>'Trusted Cloudflare header'];
        }
        return ['ip'=>$peer ?: 'unknown', 'peer'=>$peer, 'source'=>'REMOTE_ADDR'];
    }
}
