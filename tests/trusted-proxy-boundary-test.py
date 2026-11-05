#!/usr/bin/env python3
"""Offline ClientIp regression: independent ipaddress oracle, no HTTP/DB/provider calls.

Run: python3 tests/trusted-proxy-boundary-test.py [--php /path/to/php]
Requires Python 3 and PHP CLI. No application bootstrap or generated fixture file.
--emit-corpus prints deterministic JSON without executing PHP for audit/debugging.
"""
import argparse
import ipaddress as ipa
import json
from pathlib import Path
import random
import os
import subprocess
import sys

HERE = Path(__file__).resolve().parent
PIN = '047d6c656570ac1818ba2dc71c87b026b9595fcc'
ALLOWLIST = [
    '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
    '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
    '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
    '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22','2400:cb00::/32',
    '2606:4700::/32','2803:f800::/32','2405:b500::/32','2405:8100::/32',
    '2a06:98c0::/29','2c0f:f248::/32',
]
NETWORKS = [ipa.ip_network(c) for c in ALLOWLIST]
rng = random.Random(0x20261103)
cases = []

def normalized(value):
    if not isinstance(value, str) or '%' in value:
        return ''
    try:
        addr = ipa.ip_address(value)
    except ValueError:
        return ''
    if addr.version == 6 and addr.ipv4_mapped:
        return str(addr.ipv4_mapped)
    # PHP inet_ntop prints non-mapped IPv4-compatible IPv6 with dotted suffix.
    if addr.version == 6 and 0xFFFF < int(addr) <= 0xFFFFFFFF:
        return '::' + str(ipa.IPv4Address(int(addr)))
    return str(addr)

def member(value, networks):
    value = normalized(value)
    return bool(value) and any(ipa.ip_address(value) in n for n in networks)

def add(group, op, args, expected, label):
    cases.append(dict(group=group, op=op, args=args, expected=expected, label=label))

def range_case(ip, ranges, group, label):
    nets = [ipa.ip_network(r, strict=False) for r in ranges]
    add(group, 'inRanges', [ip, ranges], member(ip, nets), label)

def boundaries(net):
    low, high = int(net.network_address), int(net.broadcast_address)
    cls = ipa.IPv4Address if net.version == 4 else ipa.IPv6Address
    values = [('first', low), ('last', high), ('midpoint', (low + high)//2)]
    if high > low:
        values.extend([('first-inside', low + 1), ('last-inside', high - 1)])
    if low:
        values.append(('one-before', low - 1))
    if high < (1 << net.max_prefixlen) - 1:
        values.append(('one-after', high + 1))
    return [(label, str(cls(value))) for label, value in values]

for cidr in ALLOWLIST:
    net = ipa.ip_network(cidr)
    for edge, ip in boundaries(net):
        range_case(ip, [cidr], 'fixed-single-boundaries', f'{cidr} {edge}')
        range_case(ip, ALLOWLIST, 'fixed-union-boundaries', f'{cidr} {edge} union')
        if net.version == 4:
            range_case('::ffff:' + ip, [cidr], 'mapped-boundaries', f'{cidr} mapped {edge}')
    for ip in ['0.0.0.0', '255.255.255.255', '::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff']:
        range_case(ip, [cidr], 'fixed-family-separation', f'{cidr} against {ip}')

for version, seed in [(4, '165.90.195.60'), (6, '2001:db8:1234:5678:9abc:def0:1234:5678')]:
    bits = 32 if version == 4 else 128
    cls = ipa.IPv4Address if version == 4 else ipa.IPv6Address
    for prefix in range(bits + 1):
        net = ipa.ip_network(f'{seed}/{prefix}', strict=False)
        cidr = str(net)
        for edge, ip in boundaries(net):
            range_case(ip, [cidr], 'all-prefix-boundaries', f'v{version}/{prefix} {edge}')
            range_case(ip, [f'{seed}/{prefix}'], 'host-bit-networks', f'v{version}/{prefix} unmasked base {edge}')
        for _ in range(12):
            ip = str(cls(rng.getrandbits(bits)))
            range_case(ip, [cidr], 'seeded-membership', f'v{version}/{prefix} random')
        other = '2001:db8::1' if version == 4 else '192.0.2.1'
        range_case(other, [cidr], 'all-prefix-family-separation', f'v{version}/{prefix} other family')
    for _ in range(512):
        host = cls(rng.getrandbits(bits))
        range_case(str(host), [str(host)], 'exact-host-ranges', f'v{version} exact')
        range_case(str(cls(int(host)^1)), [str(host)], 'exact-host-ranges', f'v{version} adjacent exact')

invalid = ['', ' ', '\t192.0.2.1', '192.0.2.1 ', '192.0.2.1\n', '192.0.2.1\x00',
    '192.0.2.1\x00evil', '192.0.2.1:80', '[2001:db8::1]', '[2001:db8::1]:80',
    'fe80::1%eth0', 'fe80::1%1', '192.0.2.1/32', '1.2.3', '127.1', '2130706433',
    '0x7f000001', '0177.0.0.1', '192.000.2.1', '256.1.2.3', '-1.2.3.4',
    '1.2.3.4.5', '2001:::1', '2001:db8::g', '1:2:3:4:5:6:7:8:9',
    '::ffff:256.1.2.3', '192.0.2.1,203.0.113.1', 'for=192.0.2.1',
    '\u00a0192.0.2.1', '１９２.０.２.１', 'unknown', '_hidden', 'null', 'localhost',
    'a'*4096, '\r\nHTTP_CF_CONNECTING_IP: 173.245.48.1']
valid = ['0.0.0.0', '255.255.255.255', '127.0.0.1', '192.0.2.1', '::', '::1',
    '2001:0DB8:0000:0000:0000:0000:0000:0001', 'FFFF:FFFF:FFFF:FFFF:FFFF:FFFF:FFFF:FFFF',
    '::ffff:192.0.2.1', '::FFFF:C000:0201', '0:0:0:0:0:ffff:c000:201',
    '::192.0.2.1', '64:ff9b::c000:201', '::ffff:0:192.0.2.1']
nonstrings = [None, False, True, 0, 1, -1, 3232235777, 1.5, [], ['192.0.2.1'], {'ip':'192.0.2.1'}]
for value in valid + invalid + nonstrings:
    add('normalization', 'normalize', [value], normalized(value), repr(value)[:90])
for value in invalid:
    for ranges in [ALLOWLIST, ['0.0.0.0/0', '::/0'], ['192.0.2.1']]:
        add('malformed-ip-membership', 'inRanges', [value, ranges], False, repr(value)[:90])
for value in ['::ffff:192.0.2.1', '::FFFF:C000:0201', '::192.0.2.1', '64:ff9b::c000:201', '::ffff:0:192.0.2.1']:
    for ranges in [['192.0.2.0/24'], ['::/0'], ['0.0.0.0/0'], ['::ffff:0:0/96'], ['::ffff:192.0.2.0/24']]:
        # Mapped network addresses normalize to IPv4 before interpreting prefix length.
        adapted=[]
        for r in ranges:
            base, prefix = r.split('/')
            base = normalized(base)
            if int(prefix) <= ipa.ip_address(base).max_prefixlen:
                adapted.append(ipa.ip_network(f'{base}/{prefix}', strict=False))
        add('mapped-family-contract', 'inRanges', [value, ranges], member(value, adapted), f'{value} in {ranges}')
for malformed_range in ['', 'garbage', '/24', '192.0.2.0/', '192.0.2.0/-1', '192.0.2.0/+1',
    '192.0.2.0/33', '192.0.2.0/1.5', '192.0.2.0/ 24', '192.0.2.0/2e1',
    '192.0.2.0/'+'9'*80, '2001:db8::/129', '::ffff:192.0.2.0/120', '256.0.0.0/8']:
    for ip in ['192.0.2.1', '2001:db8::1']:
        add('invalid-range-strings', 'inRanges', [ip, [malformed_range]], False, repr(malformed_range))
for ip in valid[:8]:
    range_case(ip, [], 'empty-ranges', f'{ip} empty list')

header_shapes = valid + invalid + nonstrings + ['192.0.2.1, 203.0.113.2', 'for="[2001:db8::1]";proto=https',
    ['192.0.2.1','203.0.113.2'], {'0':'192.0.2.1','1':'203.0.113.2'}]
headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_CF_CONNECTING_IPV6', 'HTTP_X_FORWARDED_FOR',
    'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CLIENT_IP',
    'HTTP_X_CLIENT_IP', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_CF_PSEUDO_IPV4',
    'HTTP_REMOTE_ADDR', 'CF_CONNECTING_IP']

def resolve_expected(server):
    peer = normalized(server.get('REMOTE_ADDR', ''))
    trusted = member(peer, NETWORKS)
    ip = normalized(server.get('HTTP_CF_CONNECTING_IP', '')) if trusted else ''
    if ip and member(ip, [ipa.ip_network('240.0.0.0/4')]):
        v6 = normalized(server.get('HTTP_CF_CONNECTING_IPV6', ''))
        if ':' in v6:
            ip = v6
    return {'ip': ip or peer or 'unknown', 'peer':peer,
            'source': 'Trusted Cloudflare header' if ip else 'REMOTE_ADDR'}

def resolve_case(server, group, label):
    add(group, 'resolve', [server], resolve_expected(server), label)

untrusted_peers = ['192.0.2.20', '2001:db8::20', '::ffff:192.0.2.20',
    '::192.0.2.20', '', None, ['173.245.48.1'], '173.245.48.1:443']
for peer in untrusted_peers:
    for header in headers:
        for value in header_shapes:
            resolve_case({'REMOTE_ADDR':peer, header:value}, 'untrusted-header-shapes', f'{peer!r} {header} {repr(value)[:70]}')
    for value in header_shapes:
        resolve_case(dict(REMOTE_ADDR=peer, **{h:value for h in headers}), 'untrusted-combined-headers', f'{peer!r} combined {repr(value)[:70]}')
for value in header_shapes:
    resolve_case({h:value for h in headers}, 'missing-peer-headers', f'missing peer {repr(value)[:70]}')
for peer in ['173.245.48.1', '2400:cb00::1', '::ffff:173.245.48.1']:
    for header in headers:
        for value in header_shapes:
            resolve_case({'REMOTE_ADDR':peer, header:value}, 'trusted-header-shapes', f'{peer} {header} {repr(value)[:70]}')
    for value in header_shapes:
        resolve_case(dict(REMOTE_ADDR=peer, HTTP_CF_CONNECTING_IP=value,
            HTTP_X_FORWARDED_FOR='8.8.8.8', HTTP_X_REAL_IP='1.1.1.1', HTTP_FORWARDED='for=8.8.4.4'),
            'trusted-conflicting-headers', f'{peer} conflict {repr(value)[:70]}')
    for pseudo in ['239.255.255.255', '240.0.0.0', '240.0.0.1', '255.255.255.255', '::ffff:240.0.0.1', '192.0.2.1', '2001:db8::1']:
        for v6 in header_shapes:
            resolve_case(dict(REMOTE_ADDR=peer, HTTP_CF_CONNECTING_IP=pseudo, HTTP_CF_CONNECTING_IPV6=v6),
                'pseudo-ipv4-boundaries', f'{peer} pseudo {pseudo} v6 {repr(v6)[:70]}')
for cidr in ALLOWLIST:
    for edge, peer in boundaries(ipa.ip_network(cidr)):
        for mapped in [False, True] if ':' not in peer else [False]:
            value = '::ffff:' + peer if mapped else peer
            resolve_case({'REMOTE_ADDR':value, 'HTTP_CF_CONNECTING_IP':'203.0.113.77'},
                'fixed-resolver-boundaries', f'{cidr} {edge} mapped={mapped}')

doc=dict(schema=1, pin=PIN, seed='0x20261103', oracle='Python standard-library ipaddress; mapped addresses normalized to IPv4',
    allowlist=ALLOWLIST, cases=cases)

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=os.environ.get('PHP', 'php'), help='PHP CLI binary, default PHP environment variable or php')
    parser.add_argument('--target', help='Optional isolated ClientIp.php target for local review/negative controls')
    parser.add_argument('--emit-corpus', action='store_true', help='Print deterministic compact JSON, without executing PHP')
    args = parser.parse_args()
    payload = (json.dumps(doc, ensure_ascii=True, separators=(',', ':')) + '\n').encode('ascii')
    if args.emit_corpus:
        sys.stdout.buffer.write(payload)
        raise SystemExit(0)
    command = [args.php, '-d', 'allow_url_fopen=0', '-d',
        'disable_functions=mail,curl_exec,fsockopen,pfsockopen,stream_socket_client,exec,system,shell_exec,passthru,proc_open,popen',
        str(HERE/'fixtures/trusted-proxy-runner.php')]
    if args.target:
        command.append(str(Path(args.target).resolve()))
    try:
        result = subprocess.run(command, input=payload, timeout=30)
    except (OSError, subprocess.TimeoutExpired) as error:
        print(f'Trusted-proxy regression could not run: {error}', file=sys.stderr)
        raise SystemExit(2)
    raise SystemExit(result.returncode)
