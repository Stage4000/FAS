<?php
declare(strict_types=1);
namespace FAS\Payments;

/** One immutable FAS order and one idempotent PayPal order per payment attempt. */
final class ApplePayService
{
    private \PDO $db;
    private WalletPayPalGateway $paypal;
    private $priceResolver;
    private $couponResolver;
    private string $environment;

    public function __construct(\PDO $db, WalletPayPalGateway $paypal, callable $priceResolver,
        callable $couponResolver, string $environment)
    {
        $this->db = $db;
        $this->paypal = $paypal;
        $this->priceResolver = $priceResolver;
        $this->couponResolver = $couponResolver;
        $this->environment = $environment;
        $db->exec('PRAGMA busy_timeout = 5000');
    }

    private function run(string $sql, array $values = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt;
    }
    private function attempt(string $id, ?string $owner = null): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new CheckoutProblem('invalid_reference', 'Invalid payment reference.', 400);
        }
        $a = $this->run('SELECT * FROM applepay_attempts WHERE id = ?', [$id])->fetch(\PDO::FETCH_ASSOC);
        if (!$a || ($owner !== null && !hash_equals($a['owner_hash'], $owner))) {
            throw new CheckoutProblem('not_found', 'Payment reference not found for this checkout session.', 404);
        }
        if ($a['environment'] !== $this->environment) {
            throw new CheckoutProblem('environment_changed', 'Payment environment changed. Contact support.');
        }
        return $a;
    }
    private function transaction(callable $work)
    {
        $this->db->beginTransaction();
        try {
            // The first operation is a write, obtaining SQLite's writer lock before any read.
            $this->db->exec('UPDATE applepay_attempts SET updated_at = updated_at WHERE 0');
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    private function request(array $input): array
    {
        if (!is_array($input['items'] ?? null) || !is_array($input['address'] ?? null)) {
            throw new CheckoutProblem('invalid_input', 'Invalid checkout details.', 400);
        }
        $email = ApplePayContext::text($input['email'] ?? '', 254);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new CheckoutProblem('invalid_email', 'Enter a valid email address.', 400);
        }
        $index = $input['shipping_index'] ?? null;
        if ((!is_int($index) && !is_string($index)) || !preg_match('/^(0|[1-9][0-9]{0,2})$/D', (string)$index)) {
            throw new CheckoutProblem('invalid_shipping', 'Choose a shipping method.', 400);
        }
        return [
            'cart' => ApplePayContext::cart($input['items']),
            'address' => ApplePayContext::address($input['address']),
            'email' => $email,
            'name' => ApplePayContext::text($input['first_name'] ?? '', 100) . ' '
                . ApplePayContext::text($input['last_name'] ?? '', 100),
            'phone' => ApplePayContext::text($input['phone'] ?? '', 40, false),
            'notes' => ApplePayContext::text($input['notes'] ?? '', 2000, false),
            'coupon' => strtoupper(ApplePayContext::text($input['coupon_code'] ?? '', 100, false)),
            'shipping_quote' => ApplePayContext::text($input['shipping_quote'] ?? '', 32),
            'shipping_index' => (int)$index,
            'expected_total' => ApplePayContext::cents($input['expected_total'] ?? null),
        ];
    }

    public function create(string $id, string $owner, array $input, ?array $quote): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new CheckoutProblem('invalid_reference', 'Invalid payment reference.', 400);
        }
        $request = $this->request($input);
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        $existing = $this->run('SELECT id FROM applepay_attempts WHERE id = ?', [$id])->fetchColumn();
        if ($existing) {
            $a = $this->attempt($id, $owner);
            if (!hash_equals($a['request_hash'], $hash)) {
                throw new CheckoutProblem('changed_checkout', 'This payment attempt belongs to different checkout details.');
            }
            return $this->ensurePayPalOrder($a);
        }
        if (!$quote || ($quote['expires'] ?? 0) < time()
            || ($quote['cart'] ?? []) !== $request['cart']
            || ($quote['address'] ?? []) !== $request['address']) {
            throw new CheckoutProblem('shipping_expired', 'Your cart or address changed. Calculate shipping again.');
        }
        $rate = $quote['rates'][$request['shipping_index']] ?? null;
        if (!is_array($rate)) {
            throw new CheckoutProblem('invalid_shipping', 'Choose a current shipping method.');
        }
        $shipping = ApplePayContext::catalogCents($rate['total_charge'] ?? null);
        $subtotal = 0;
        $items = [];
        foreach ($request['cart'] as $productId => $qty) {
            $p = $this->run('SELECT * FROM products WHERE id = ?', [$productId])->fetch(\PDO::FETCH_ASSOC);
            if (!$p || empty($p['is_active']) || empty($p['show_on_website']) || (int)$p['quantity'] < $qty) {
                throw new CheckoutProblem('unavailable', 'An item is no longer available. Review your cart.');
            }
            $unit = ApplePayContext::catalogCents(($this->priceResolver)($p));
            $items[] = ['product_id' => $productId, 'product_name' => $p['name'],
                'product_sku' => $p['sku'] ?? '', 'quantity' => $qty, 'unit_cents' => $unit];
            $subtotal += $unit * $qty;
        }
        // FAS currently uses zero tax. This deliberately does not introduce a new tax policy.
        $discount = $request['coupon'] === '' ? 0 : ($this->couponResolver)($request['coupon'], $subtotal);
        if (!is_int($discount) || $discount < 0 || $discount > $subtotal) {
            throw new CheckoutProblem('invalid_coupon', 'The coupon discount is invalid. Remove it and try again.');
        }
        $total = $subtotal + $shipping - $discount;
        if ($total < 1 || $total !== $request['expected_total']) {
            throw new CheckoutProblem('total_changed', 'The current price or discount changed. Refresh your cart and recalculate shipping before paying.');
        }
        ApplePayContext::money($total);
        $now = time();
        $this->transaction(function () use ($id, $owner, $hash, $request, $items, $subtotal, $shipping, $discount, $total, $rate, $now) {
            // Per-session abuse limit; deployment should also rate-limit this endpoint at the proxy.
            $count = $this->run('SELECT COUNT(*) FROM applepay_attempts WHERE owner_hash = ? AND created_at > ?',
                [$owner, $now - 3600])->fetchColumn();
            if ((int)$count >= 20) {
                throw new CheckoutProblem('rate_limited', 'Too many payment attempts. Please contact support.', 429);
            }
            $number = 'FAS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $notes = $request['notes'] . "\nApple Pay via PayPal. Shipping: "
                . (string)($rate['courier_name'] ?? '') . ' / ' . (string)($rate['service_name'] ?? '');
            $this->run('INSERT INTO orders (order_number,customer_email,customer_name,customer_phone,shipping_address,
                subtotal,shipping_cost,tax_amount,discount_code,discount_amount,total_amount,payment_method,payment_status,order_status,notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$number, $request['email'], $request['name'], $request['phone'],
                json_encode($request['address'], JSON_THROW_ON_ERROR), $subtotal / 100, $shipping / 100, 0,
                $request['coupon'] ?: null, $discount / 100, $total / 100, 'applepay', 'pending', 'pending', $notes]);
            $orderId = (int)$this->db->lastInsertId();
            foreach ($items as $item) {
                $this->run('INSERT INTO order_items (order_id,product_id,product_name,product_sku,quantity,unit_price,total_price) VALUES (?,?,?,?,?,?,?)',
                    [$orderId, $item['product_id'], $item['product_name'], $item['product_sku'], $item['quantity'],
                    $item['unit_cents'] / 100, $item['unit_cents'] * $item['quantity'] / 100]);
            }
            $amount = static fn(int $n): array => ['currency_code' => 'USD', 'value' => ApplePayContext::money($n)];
            $breakdown = ['item_total' => $amount($subtotal), 'shipping' => $amount($shipping), 'tax_total' => $amount(0)];
            if ($discount > 0) {
                $breakdown['discount'] = $amount($discount);
            }
            $a = $request['address'];
            $address = ['address_line_1' => $a['address1'], 'admin_area_2' => $a['city'], 'admin_area_1' => $a['state'],
                'postal_code' => $a['zip'], 'country_code' => $a['country']];
            if ($a['address2'] !== '') {
                $address['address_line_2'] = $a['address2'];
            }
            $payload = ['intent' => 'CAPTURE', 'purchase_units' => [[
                'reference_id' => 'default', 'custom_id' => 'FAS-APPLEPAY-' . $id, 'invoice_id' => $number,
                'description' => 'Flip and Strip order ' . $number,
                'amount' => $amount($total) + ['breakdown' => $breakdown],
                'shipping' => ['name' => ['full_name' => $request['name']], 'address' => $address],
            ]]];
            $this->run('INSERT INTO applepay_attempts (id,owner_hash,request_hash,environment,order_id,payload,status,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?)', [$id, $owner, $hash, $this->environment, $orderId,
                json_encode($payload, JSON_THROW_ON_ERROR), 'creating', $now, $now]);
        });
        return $this->ensurePayPalOrder($this->attempt($id, $owner));
    }

    private function ensurePayPalOrder(array $a): array
    {
        if (in_array($a['status'], ['abandoned', 'rejected', 'paid', 'review'], true)) {
            return $this->result($a, $a['status']);
        }
        if (!in_array($a['status'], ['creating', 'created'], true)) {
            return $this->status($a['id'], $a['owner_hash']);
        }
        if (time() - (int)$a['created_at'] > ApplePayContext::ATTEMPT_TTL) {
            throw new CheckoutProblem('attempt_expired', 'This payment attempt expired. Check its payment status before trying again.');
        }
        $payload = json_decode($a['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (empty($a['paypal_order_id'])) {
            $data = $this->paypal->create($payload, 'apc-' . $a['id']);
            $id = $data['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[A-Z0-9]{6,32}$/D', $id)) {
                throw new PayPalFailure('INVALID_ORDER_ID', 502);
            }
            // Save the provider reference before further validation so recovery never creates another order.
            $this->run('UPDATE applepay_attempts SET paypal_order_id = ?, updated_at = ? WHERE id = ?', [$id, time(), $a['id']]);
            $this->run('UPDATE orders SET paypal_order_id = ? WHERE id = ?', [$id, $a['order_id']]);
            $a = $this->attempt($a['id']);
        } else {
            $data = $this->paypal->get($a['paypal_order_id']);
        }
        // The create response may be minimal; a full order is required before returning to the browser.
        if (empty($data['purchase_units'][0]['amount'])) {
            $data = $this->paypal->get($a['paypal_order_id']);
        }
        $merchant = $this->verifyOrder($a, $data, false);
        $this->run("UPDATE applepay_attempts SET merchant_id = ?, status = 'created', updated_at = ? WHERE id = ? AND status IN ('creating','created')",
            [$merchant, time(), $a['id']]);
        return $this->result($this->attempt($a['id']), 'created');
    }

    /** Verify provider-owned order identity, total, currency, invoice, payee and shipping. */
    private function verifyOrder(array $a, array $data, bool $requireApple): string
    {
        $p = json_decode($a['payload'], true, 512, JSON_THROW_ON_ERROR)['purchase_units'][0];
        $units = $data['purchase_units'] ?? [];
        $u = $units[0] ?? [];
        $merchant = $u['payee']['merchant_id'] ?? '';
        if (($data['id'] ?? '') !== $a['paypal_order_id'] || ($data['intent'] ?? '') !== 'CAPTURE'
            || count($units) !== 1 || ($u['custom_id'] ?? '') !== $p['custom_id']
            || ($u['invoice_id'] ?? '') !== $p['invoice_id'] || ($u['amount']['currency_code'] ?? '') !== 'USD'
            || ApplePayContext::cents($u['amount']['value'] ?? null) !== ApplePayContext::cents($p['amount']['value'])
            || !is_string($merchant) || $merchant === ''
            || (!empty($a['merchant_id']) && !hash_equals($a['merchant_id'], $merchant))
            || ($requireApple && !isset($data['payment_source']['apple_pay']))) {
            throw new CheckoutProblem('verification_failed', 'Payment verification needs review. Do not pay again; contact support.');
        }
        $clean = static fn($s): string => strtoupper(trim(preg_replace('/\s+/u', ' ', (string)$s)));
        foreach ($p['shipping']['address'] as $key => $value) {
            if ($clean($value) !== $clean($u['shipping']['address'][$key] ?? '')) {
                throw new CheckoutProblem('verification_failed', 'The payment shipping address needs review. Do not pay again.');
            }
        }
        return $merchant;
    }

    public function capture(string $id, string $owner): array
    {
        $a = $this->attempt($id, $owner);
        if (in_array($a['status'], ['paid', 'review', 'abandoned', 'rejected'], true)) {
            return $this->result($a, $a['status']);
        }
        if (empty($a['paypal_order_id'])) {
            throw new CheckoutProblem('not_ready', 'The payment order is not ready. Check payment status.');
        }
        $data = $this->paypal->get($a['paypal_order_id']);
        $this->verifyOrder($a, $data, ($data['status'] ?? '') === 'APPROVED' || !empty($data['purchase_units'][0]['payments']['captures']));
        if (!empty($data['purchase_units'][0]['payments']['captures']) || ($data['status'] ?? '') !== 'APPROVED') {
            return $this->settle($a, $data);
        }
        if (time() - (int)$a['created_at'] > ApplePayContext::ATTEMPT_TTL) {
            throw new CheckoutProblem('attempt_expired', 'This payment needs manual review. Do not pay again; contact support.');
        }
        // Do not issue a second in-flight capture, even with the same idempotency key.
        if ($a['status'] === 'capturing' && time() - (int)$a['updated_at'] < 45) {
            return $this->result($a, 'pending');
        }
        $claimed = $this->transaction(function () use ($a) {
            $fresh = $this->attempt($a['id']);
            if (in_array($fresh['status'], ['paid', 'review', 'abandoned', 'rejected'], true)
                || ($fresh['status'] === 'capturing' && time() - (int)$fresh['updated_at'] < 45)) {
                return false;
            }
            $items = $this->run('SELECT oi.quantity, p.quantity AS available, p.is_active FROM order_items oi
                LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?', [$a['order_id']])->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($items as $item) {
                if (empty($item['is_active']) || (int)$item['available'] < (int)$item['quantity']) {
                    throw new CheckoutProblem('unavailable', 'An item sold before payment. Your payment has not been captured; contact support.');
                }
            }
            $this->run("UPDATE applepay_attempts SET status = 'capturing', capture_requested_at = COALESCE(capture_requested_at, ?), updated_at = ? WHERE id = ?",
                [time(), time(), $a['id']]);
            return true;
        });
        if (!$claimed) {
            $fresh = $this->attempt($id);
            return $this->result($fresh, $fresh['status'] === 'capturing' ? 'pending' : $fresh['status']);
        }
        // A timeout leaves 'capturing' persisted. Recovery GETs the same PayPal order first.
        $capture = $this->paypal->capture($a['paypal_order_id'], 'apx-' . $a['id']);
        return $this->settle($this->attempt($id), $capture);
    }

    /** Read-only at PayPal: never creates or captures a payment. Also used by the CLI reconciler. */
    public function status(string $id, ?string $owner): array
    {
        $a = $this->attempt($id, $owner);
        if (in_array($a['status'], ['paid', 'review', 'abandoned', 'rejected'], true)) {
            return $this->result($a, $a['status']);
        }
        if (empty($a['paypal_order_id'])) {
            return $this->result($a, 'unpaid');
        }
        $data = $this->paypal->get($a['paypal_order_id']);
        $this->verifyOrder($a, $data, !empty($data['purchase_units'][0]['payments']['captures']));
        return $this->settle($a, $data);
    }

    /** Stop this attempt only when our server has never requested a capture. */
    public function abandon(string $id, string $owner): array
    {
        return $this->transaction(function () use ($id, $owner) {
            $a = $this->attempt($id, $owner);
            if ($a['capture_requested_at'] !== null || in_array($a['status'], ['paid', 'review'], true)) {
                return $this->result($a, 'pending');
            }
            $this->run("UPDATE applepay_attempts SET status = 'abandoned', updated_at = ? WHERE id = ?", [time(), $id]);
            $this->run("UPDATE orders SET payment_status = 'failed', order_status = 'cancelled' WHERE id = ? AND payment_status = 'pending'", [$a['order_id']]);
            return $this->result($this->attempt($id), 'abandoned');
        });
    }

    private function settle(array $a, array $data): array
    {
        if (($data['id'] ?? '') !== $a['paypal_order_id'] || count($data['purchase_units'] ?? []) !== 1) {
            throw new CheckoutProblem('verification_failed', 'Payment verification needs review. Do not pay again.');
        }
        $captures = $data['purchase_units'][0]['payments']['captures'] ?? [];
        if (count($captures) > 1) {
            throw new CheckoutProblem('verification_failed', 'Multiple captures need review. Do not pay again.');
        }
        if (!$captures) {
            if (($data['status'] ?? '') === 'VOIDED') {
                return $this->reject($a);
            }
            return $this->result($a, $a['capture_requested_at'] !== null ? 'pending' : 'unpaid');
        }
        $c = $captures[0];
        if (in_array($c['status'] ?? '', ['DECLINED', 'DENIED', 'FAILED'], true)) {
            return $this->reject($a);
        }
        if (($c['status'] ?? '') !== 'COMPLETED') {
            return $this->result($a, 'pending');
        }
        $expected = json_decode($a['payload'], true, 512, JSON_THROW_ON_ERROR)['purchase_units'][0];
        if (($data['status'] ?? '') !== 'COMPLETED' || empty($c['id'])
            || ($c['amount']['currency_code'] ?? '') !== 'USD'
            || ApplePayContext::cents($c['amount']['value'] ?? null) !== ApplePayContext::cents($expected['amount']['value'])
            || (isset($c['final_capture']) && $c['final_capture'] !== true)) {
            throw new CheckoutProblem('verification_failed', 'Captured payment details need review. Do not pay again; contact support.');
        }
        return $this->finalize($a, (string)$c['id']);
    }

    private function reject(array $a): array
    {
        return $this->transaction(function () use ($a) {
            $fresh = $this->attempt($a['id']);
            if (in_array($fresh['status'], ['paid', 'review'], true)) {
                return $this->result($fresh, $fresh['status']);
            }
            $this->run("UPDATE applepay_attempts SET status = 'rejected', updated_at = ? WHERE id = ?", [time(), $a['id']]);
            $this->run("UPDATE orders SET payment_status = 'failed', order_status = 'cancelled' WHERE id = ?", [$a['order_id']]);
            return $this->result($this->attempt($a['id']), 'rejected');
        });
    }

    private function finalize(array $a, string $captureId): array
    {
        return $this->transaction(function () use ($a, $captureId) {
            $a = $this->attempt($a['id']);
            if (in_array($a['status'], ['paid', 'review'], true)) {
                if ($a['capture_id'] !== $captureId) {
                    throw new CheckoutProblem('verification_failed', 'Capture reference mismatch. Contact support.');
                }
                return $this->result($a, $a['status']);
            }
            $order = $this->run('SELECT * FROM orders WHERE id = ?', [$a['order_id']])->fetch(\PDO::FETCH_ASSOC);
            $expected = json_decode($a['payload'], true, 512, JSON_THROW_ON_ERROR)['purchase_units'][0];
            if (!$order || $order['payment_method'] !== 'applepay' || $order['payment_status'] !== 'pending'
                || ApplePayContext::catalogCents($order['total_amount']) !== ApplePayContext::cents($expected['amount']['value'])) {
                throw new CheckoutProblem('verification_failed', 'The local order changed and needs payment review. Do not pay again.');
            }
            $items = $this->run('SELECT oi.*, p.quantity AS available, p.is_active FROM order_items oi
                LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?', [$order['id']])->fetchAll(\PDO::FETCH_ASSOC);
            $review = !$items;
            foreach ($items as $item) {
                $review = $review || empty($item['is_active']) || (int)$item['available'] < (int)$item['quantity'];
            }
            if (!$review) {
                foreach ($items as $item) {
                    $stmt = $this->run("UPDATE products SET quantity = quantity - ?, updated_at = datetime('now') WHERE id = ? AND quantity >= ? AND is_active = 1",
                        [(int)$item['quantity'], $item['product_id'], (int)$item['quantity']]);
                    if ($stmt->rowCount() !== 1) {
                        throw new \RuntimeException('Inventory update failed under checkout lock');
                    }
                }
                if (!empty($order['discount_code'])) {
                    $this->run('UPDATE coupons SET times_used = times_used + 1 WHERE code = ?', [$order['discount_code']]);
                }
            }
            $notes = $order['notes'] ?? '';
            if ($review) {
                $notes .= "\n[APPLE PAY REVIEW REQUIRED] Payment captured; inventory changed. Do not fulfill until reconciled. Capture: " . $captureId;
            }
            // Even a stock conflict records the captured funds. It never becomes a new payment retry.
            $this->run("UPDATE orders SET payment_status = 'completed', order_status = ?, paypal_order_id = ?, paypal_transaction_id = ?, notes = ?, updated_at = datetime('now') WHERE id = ?",
                [$review ? 'pending' : 'processing', $a['paypal_order_id'], $captureId, $notes, $order['id']]);
            $state = $review ? 'review' : 'paid';
            $this->run('UPDATE applepay_attempts SET capture_id = ?, status = ?, updated_at = ? WHERE id = ?', [$captureId, $state, time(), $a['id']]);
            return $this->result($this->attempt($a['id']), $state);
        });
    }

    private function result(array $a, string $state): array
    {
        $p = json_decode($a['payload'], true, 512, JSON_THROW_ON_ERROR)['purchase_units'][0];
        return ['ok' => true, 'state' => $state, 'attempt_id' => $a['id'], 'order_id' => (int)$a['order_id'],
            'order_number' => $p['invoice_id'], 'paypal_order_id' => $a['paypal_order_id'],
            'amount' => $p['amount']['value'], 'currency' => 'USD',
            'can_abandon' => $a['capture_requested_at'] === null && !in_array($state, ['paid', 'review'], true)];
    }
}
