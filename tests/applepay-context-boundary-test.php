<?php
declare(strict_types=1);

namespace FAS\Tests\ApplePayContextBoundary;

use FAS\Payments\ApplePayContext as C;
use FAS\Payments\CheckoutProblem;

// Pure, deterministic CLI regression cohort. No application bootstrap or providers.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_INT_SIZE < 8) {
    fwrite(STDERR, "SKIP: 64-bit PHP is required for the existing money range.\n");
    exit(2);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$assertions = 0;
$scenarios = 0;
function check(bool $pass, string $why): void
{
    global $assertions;
    $assertions++;
    if (!$pass) { throw new \RuntimeException($why); }
}
function throws(callable $call, string $reason, int $status = 400): void
{
    try {
        $call();
    } catch (CheckoutProblem $e) {
        check($e->reason === $reason, "Expected $reason, got {$e->reason}");
        check($e->httpStatus === $status, "Expected HTTP status $status, got {$e->httpStatus}");
        return;
    }
    throw new \RuntimeException("Expected rejection: $reason");
}
function scenario(string $name, callable $call): void
{
    global $scenarios;
    $call();
    $scenarios++;
    echo "PASS $name\n";
}
function addressFixture(array $changes = []): array
{
    return array_replace([
        'address1' => '100 Fixture Road', 'address2' => '', 'city' => 'Fixture City',
        'state' => 'CA', 'zip' => '00123', 'country' => 'US',
    ], $changes);
}
function permutations(array $rows): \Generator
{
    if (!$rows) { yield []; return; }
    foreach ($rows as $index => $row) {
        $remaining = $rows;
        array_splice($remaining, $index, 1);
        foreach (permutations($remaining) as $tail) {
            yield array_merge([$row], $tail);
        }
    }
}

$includedBefore = get_included_files();
$sessionStatusBefore = function_exists('session_status') ? session_status() : null;
$sessionIdBefore = function_exists('session_id') ? session_id() : null;
$contextPath = realpath(__DIR__.'/../src/payments/ApplePayContext.php');
if ($contextPath === false) { throw new \RuntimeException('Missing ApplePayContext.php'); }
require_once $contextPath;

scenario('single class import has no session or extra dependency effects', static function () use (
    $includedBefore, $contextPath, $sessionStatusBefore, $sessionIdBefore
): void {
    check(array_values(array_diff(get_included_files(), $includedBefore)) === [$contextPath],
        'The pure cohort must import only ApplePayContext.php');
    check((function_exists('session_status') ? session_status() : null) === $sessionStatusBefore,
        'Import must not start or alter a session');
    check((function_exists('session_id') ? session_id() : null) === $sessionIdBefore,
        'Import must not create a session ID');
});

scenario('money exact formatting and cents round trip at boundaries', static function (): void {
    $cases = [
        [0, '0.00'], [1, '0.01'], [9, '0.09'], [10, '0.10'],
        [99, '0.99'], [100, '1.00'], [101, '1.01'], [9999999999, '99999999.99'],
    ];
    foreach ($cases as [$minor, $formatted]) {
        check(C::money($minor) === $formatted, "Exact money format for $minor");
        check(C::cents(C::money($minor)) === $minor, "Money/cents round trip for $minor");
    }
});

scenario('money one-past bounds and public decimal upper bound are rejected', static function (): void {
    throws(static fn() => C::money(-1), 'invalid_amount', 409);
    throws(static fn() => C::money(10000000000), 'invalid_amount', 409);
    throws(static fn() => C::cents('100000000.00'), 'invalid_amount');
    throws(static fn() => C::cents('99999999.999'), 'invalid_amount');
});

scenario('public amounts retain their existing plain decimal grammar', static function (): void {
    foreach ([['0', 0], ['0.0', 0], ['0.00', 0], ['0.1', 10], ['1', 100],
        ['1.2', 120], ['99999999', 9999999900]] as [$input, $minor]) {
        check(C::cents($input) === $minor, "Accepted decimal string: $input");
    }
    foreach (['', '00', '01.00', '.01', '1.', '+1', '-1', '1e2', '1.001',
        ' 1', '1 ', "1\n", '1,00', 'NaN', 1, 1.0, true, null, []] as $bad) {
        throws(static fn() => C::cents($bad), 'invalid_amount');
    }
});

scenario('cart accepts documented integer and canonical string IDs and quantities', static function (): void {
    foreach ([1, '1', 10, '10', 999999999, '999999999'] as $id) {
        foreach ([1, '999'] as $quantity) {
            $expected = [(int)$id => (int)$quantity];
            check(C::cart([['id' => $id, 'quantity' => $quantity]]) === $expected, 'Legacy id alias');
            check(C::cart([['product_id' => $id, 'quantity' => $quantity]]) === $expected,
                'Product ID and quantity normalize to integer keys and values');
        }
    }
    // Preserve the actual source contract, not a UUID/leading-zero/coercion policy.
    foreach ([0, '0', -1, '-1', '01', '+1', '1.0', 1.0, '1e2', ' 1', '1 ',
        "1\n", '', true, false, null, [], 1000000000, '1000000000'] as $bad) {
        throws(static fn() => C::cart([['product_id' => $bad, 'quantity' => 1]]), 'invalid_cart');
        throws(static fn() => C::cart([['product_id' => 1, 'quantity' => $bad]]), 'invalid_cart');
    }
});

scenario('duplicate quantity aggregation accepts 999 and rejects 1000', static function (): void {
    $accepted = [['product_id' => '2', 'quantity' => '998'], ['id' => 2, 'quantity' => 1]];
    check(C::cart($accepted) === [2 => 999], '998 + 1 is allowed');
    check(C::cart(array_reverse($accepted)) === [2 => 999], 'Allowed duplicate order is irrelevant');
    $rejected = [['id' => '2', 'quantity' => 999], ['product_id' => 2, 'quantity' => '1']];
    throws(static fn() => C::cart($rejected), 'invalid_cart');
    throws(static fn() => C::cart(array_reverse($rejected)), 'invalid_cart');
    check(C::cart([['id' => 2, 'quantity' => 999]]) === [2 => 999], 'Single-row upper quantity');
    throws(static fn() => C::cart([['id' => 2, 'quantity' => 1000]]), 'invalid_cart');
});

scenario('cart input row bound is checked before duplicate aggregation', static function (): void {
    $rows = [];
    $expected = [];
    for ($id = 100; $id >= 1; $id--) {
        $rows[] = ['id' => (string)$id, 'quantity' => 1];
    }
    for ($id = 1; $id <= 100; $id++) { $expected[$id] = 1; }
    check(C::cart($rows) === $expected, '100 distinct input rows accepted and numerically sorted');
    $rows[] = ['id' => 101, 'quantity' => 1];
    throws(static fn() => C::cart($rows), 'invalid_cart');
    check(C::cart(array_fill(0, 100, ['id' => 1, 'quantity' => 1])) === [1 => 100],
        '100 duplicate input rows accepted');
    throws(static fn() => C::cart(array_fill(0, 101, ['id' => 1, 'quantity' => 1])), 'invalid_cart');
    throws(static fn() => C::cart([]), 'invalid_cart');
});

scenario('all 120 cart permutations have identical numeric ordering and preserve input', static function (): void {
    $rows = [
        ['id' => '10', 'quantity' => 2], ['product_id' => 2, 'quantity' => '3'],
        ['product_id' => 10, 'quantity' => '4'], ['id' => '100', 'quantity' => 1],
        ['product_id' => '1', 'quantity' => '2'],
    ];
    $expected = [1 => 2, 2 => 3, 10 => 6, 100 => 1];
    $seen = 0;
    foreach (permutations($rows) as $candidate) {
        $before = $candidate;
        check(C::cart($candidate) === $expected, 'Every permutation has the exact numeric-key order');
        check($candidate === $before, 'Cart normalization must not mutate its input');
        $seen++;
    }
    check($seen === 120, 'The complete five-row permutation set is covered');
});

scenario('address trimming, leading-zero ZIPs, and optional line two are preserved', static function (): void {
    $input = addressFixture([
        'address1' => ' 100 Fixture Road ', 'address2' => " \t ", 'city' => ' Fixture City ',
        'state' => ' ca ', 'zip' => ' 00123 ', 'country' => ' us ',
    ]);
    $before = $input;
    check(C::address($input) === addressFixture(), 'Trim all fields; uppercase state and country');
    check($input === $before, 'Address normalization must not mutate its input');
    check(C::address(addressFixture(['zip' => '00123-0001']))['zip'] === '00123-0001',
        'ZIP+4 keeps leading zeroes in both components');
    $withoutLineTwo = addressFixture();
    unset($withoutLineTwo['address2']);
    check(C::address($withoutLineTwo) === addressFixture(), 'Omitted optional line two becomes empty');
    check(C::address(addressFixture(['state' => 'zz']))['state'] === 'ZZ',
        'Existing state validation is two ASCII letters, not geographic membership');
});

scenario('address byte limits apply after trimming', static function (): void {
    foreach (['address1' => 300, 'address2' => 300, 'city' => 120] as $field => $max) {
        $atLimit = str_repeat('A', $max);
        check(C::address(addressFixture([$field => $atLimit]))[$field] === $atLimit,
            "$field accepts its exact byte limit");
        check(C::address(addressFixture([$field => ' '.$atLimit.' ']))[$field] === $atLimit,
            "$field trims before measuring bytes");
        throws(static fn() => C::address(addressFixture([$field => $atLimit.'A'])), 'invalid_input');
    }
    foreach (['address1', 'city', 'state', 'zip', 'country'] as $field) {
        throws(static fn() => C::address(addressFixture([$field => '   '])), 'invalid_input');
    }
});

scenario('address lexical and type boundaries retain exact rejection reasons', static function (): void {
    foreach ([
        ['state', 'C', 'invalid_address'], ['state', 'CAL', 'invalid_input'],
        ['state', 'C1', 'invalid_address'], ['country', 'U', 'invalid_address'],
        ['country', 'USA', 'invalid_input'], ['country', 'CA', 'invalid_address'],
        ['zip', '0123', 'invalid_address'], ['zip', '001234', 'invalid_address'],
        ['zip', '001230001', 'invalid_address'], ['zip', '00123-00012', 'invalid_input'],
        ['zip', '0012A', 'invalid_address'], ['zip', '00123 0001', 'invalid_address'],
    ] as [$field, $bad, $reason]) {
        throws(static fn() => C::address(addressFixture([$field => $bad])), $reason);
    }
    foreach (['address1', 'address2', 'city', 'state', 'zip', 'country'] as $field) {
        throws(static fn() => C::address(addressFixture([$field => 1])), 'invalid_input');
    }
    foreach (["\x00", "\x01", "\x08", "\x0B", "\x0C", "\x0E", "\x1F"] as $control) {
        throws(static fn() => C::address(addressFixture(['address1' => 'A'.$control.'B'])), 'invalid_input');
    }
});

scenario('ordinary Unicode city values obey existing byte limits', static function (): void {
    foreach (['Montréal', 'São Paulo', 'Québec'] as $city) {
        check(C::address(addressFixture(['city' => $city]))['city'] === $city,
            'Ordinary Unicode city text is preserved');
    }
    $atLimit = str_repeat('é', 60);
    check(strlen($atLimit) === 120, 'UTF-8 fixture has exactly 120 bytes');
    check(C::address(addressFixture(['city' => $atLimit]))['city'] === $atLimit,
        '120-byte Unicode city is accepted');
    throws(static fn() => C::address(addressFixture(['city' => $atLimit.'é'])), 'invalid_input');
});

scenario('pure cohort leaves session state and dependency set unchanged', static function () use (
    $includedBefore, $contextPath, $sessionStatusBefore, $sessionIdBefore
): void {
    check(array_values(array_diff(get_included_files(), $includedBefore)) === [$contextPath],
        'No additional source files were loaded');
    check((function_exists('session_status') ? session_status() : null) === $sessionStatusBefore,
        'Pure calls must not start or alter a session');
    check((function_exists('session_id') ? session_id() : null) === $sessionIdBefore,
        'Pure calls must not create a session ID');
});

restore_error_handler();
echo "PASS $scenarios scenarios; $assertions assertions. Pure context only; no sessions started, database calls, or provider calls.\n";
