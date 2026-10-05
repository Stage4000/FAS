/* Google Pay via PayPal v5. Wallet tokens are sent only to PayPal's SDK. */
(() => {
    'use strict';
    const options = window.FASGooglePayOptions;
    const root = document.getElementById('googlepay-payment');
    if (!options || !root) return;

    const message = root.querySelector('[data-googlepay-message]');
    const buttonHost = root.querySelector('[data-googlepay-button]');
    const checkButton = root.querySelector('[data-googlepay-check]');
    const stopButton = root.querySelector('[data-googlepay-stop]');
    const finishButton = root.querySelector('[data-googlepay-finish]');
    const storageKey = 'fas_googlepay_pending_v1';
    let googlepay;
    let googleConfig;
    let googleButton;
    let eligible = false;
    let busy = false;
    let pending = null;
    let activeSheet = false;
    let completedResult = null;
    let lastResult = null;
    let paymentsClient;
    let checkoutReady = false;
    let initializing = false;
    let storageAvailable = true;
    let disabledControls = [];
    let retryAt = 0;
    let retryTimer;
    function applyCooldown() {
        const waiting = Date.now() < retryAt || activeSheet;
        [checkButton, stopButton, finishButton].forEach(button => { button.disabled = waiting; });
        clearTimeout(retryTimer);
        if (Date.now() < retryAt) retryTimer = setTimeout(applyCooldown, retryAt - Date.now() + 10);
    }

    function say(text) {
        message.textContent = text;
        message.hidden = text === '';
    }
    function readState() {
        return options.getState();
    }
    function cartKey(items) {
        const quantities = new Map();
        for (const item of items || []) {
            const id = String(item.product_id ?? item.id);
            quantities.set(id, (quantities.get(id) || 0) + Number(item.quantity));
        }
        return JSON.stringify([...quantities.entries()].sort((a, b) => Number(a[0]) - Number(b[0])));
    }
    function addressKey(a) {
        return JSON.stringify(['address1', 'address2', 'city', 'state', 'zip', 'country'].map(key => {
            let value = String(a?.[key] || '').trim();
            if (key === 'state' || key === 'country') value = value.toUpperCase();
            return value;
        }));
    }
    function sourceKey(state) {
        return (state.checkout_mode || 'cart') + ':' + cartKey(state.items);
    }
    function ready(state) {
        return Boolean(state.ready && state.shipping_quote && state.shipping_snapshot
            && addressKey(state.address) === addressKey(state.shipping_snapshot.address)
            && cartKey(state.items) === cartKey(state.shipping_snapshot.items)
            && /^[A-Za-z]{2}$/.test(String(state.address.state).trim())
            && /^\d{5}(?:-\d{4})?$/.test(String(state.address.zip).trim())
            && Number(state.expected_total) > 0);
    }
    function refresh() {
        root.hidden = !eligible && !pending && !options.preview;
        if (!googleButton) return;
        const isReady = eligible && !busy && !window.FASApplePay?.busy?.() && !window.FASOrderRecovery?.pending() && ready(readState());
        googleButton.inert = !isReady;
        googleButton.setAttribute('aria-disabled', isReady ? 'false' : 'true');
        googleButton.style.opacity = isReady ? '1' : '0.45';
        googleButton.style.pointerEvents = isReady ? 'auto' : 'none';
        if (!busy && !isReady && eligible) {
            say(''); // Shipping guidance is shared above all payment methods.
        } else if (!busy && isReady) {
            say(options.preview ? 'Admin-only preview. This uses your configured PayPal environment; live mode charges real money.' : ''); // The shared payment footer covers this in the ready state.
        }
    }
    function lock(value) {
        if (value !== busy) {
            const form = document.getElementById('checkout-form');
            const paypal = document.getElementById('paypal-button-container');
            if (value) {
                disabledControls = [...document.querySelectorAll('#checkout-form input, #checkout-form select, #checkout-form textarea, #checkout-form button, #coupon-code, #apply-coupon-btn, #discount-row button')]
                    .map(node => [node, node.disabled]);
                disabledControls.forEach(([node]) => { node.disabled = true; });
            } else {
                disabledControls.forEach(([node, disabled]) => { node.disabled = disabled; });
                disabledControls = [];
            }
            if (form) form.inert = value;
            if (paypal) {
                paypal.inert = value;
                if (value) {
                    paypal.style.pointerEvents = 'none';
                    paypal.style.opacity = '0.45';
                }
            }
            busy = value;
            if (!value) {
                options.onUnlock?.();
                window.FASGooglePayResumeCheckout?.();
            }
        }
        root.setAttribute('aria-busy', value ? 'true' : 'false');
        refresh();
        window.FASApplePay?.refresh();
    }
    function savePending() {
        sessionStorage.setItem(storageKey, JSON.stringify(pending));
    }
    function clearPending() {
        pending = null;
        sessionStorage.removeItem(storageKey);
        checkButton.hidden = stopButton.hidden = finishButton.hidden = true;
    }
    function timeout(promise, ms) {
        let timer;
        return Promise.race([promise, new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('Payment response timed out. Check payment status.')), ms);
        })]).finally(() => clearTimeout(timer));
    }
    async function api(action, fields = {}) {
        if (Date.now() < retryAt) throw new Error('Please wait ' + Math.ceil((retryAt-Date.now())/1000) + ' seconds before checking this payment again.');
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 25000);
        try {
            const response = await fetch('/api/googlepay.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf },
                body: JSON.stringify({ ...fields, action, attempt_id: pending?.id })
            });
            const data = await response.json();
            if (response.status === 429 || response.status === 503) {
                retryAt = Date.now() + Math.max(1, Math.min(86400, Number(response.headers?.get('Retry-After')) || 30)) * 1000;
                applyCooldown();
            }
            if (!response.ok || !data.ok) {
                const error = new Error(data.error || 'Payment status is unavailable.');
                error.code = data.code;
                throw error;
            }
            return data;
        } finally {
            clearTimeout(timer);
        }
    }
    function showRecovery(text) {
        lock(true);
        root.hidden = false;
        checkButton.hidden = false;
        stopButton.hidden = true;
        finishButton.hidden = true;
        applyCooldown();
        say(`${text} Reference: ${pending?.id || 'unavailable'}. Do not start another payment until this is resolved.`);
    }
    function handleResult(result) {
        lastResult = result;
        if (result.state === 'paid') {
            const sameSource = pending?.source === sourceKey(readState());
            clearPending();
            say(`Payment received. Order ${result.order_number}.`);
            try {
                if (activeSheet) completedResult = { result, sameSource };
                else options.onPaid(result, sameSource);
            } catch (_) {
                say(`Payment received. Order ${result.order_number}. Contact the store if the confirmation page does not open.`);
            }
            return;
        }
        if (result.state === 'review') {
            showRecovery(`Payment received for ${result.order_number}, but the order needs a stock review. Contact the store; do not pay again.`);
            return;
        }
        if (result.state === 'abandoned' || result.state === 'rejected') {
            clearPending();
            lock(false);
            say('This payment attempt is closed without a completed payment. You may choose a payment method again.');
            return;
        }
        showRecovery(`Order ${result.order_number}: payment is not yet confirmed.`);
        stopButton.hidden = !result.can_abandon;
        finishButton.hidden = result.can_abandon || result.state !== 'pending';
        if (result.can_abandon) {
            say(`No capture was requested for ${result.order_number}. Cancel this attempt before choosing another payment method.`);
        }
    }
    async function checkStatus() {
        if (!pending) return;
        checkButton.disabled = true;
        try {
            handleResult(await api('status'));
        } catch (error) {
            if (error.code === 'not_found' && pending?.session === options.sessionFingerprint) {
                // The failed create never recorded an order. No token/capture is sent after that failure.
                clearPending();
                lock(false);
                say('No payment order was created in this checkout session. Review your details and try again.');
            } else {
                showRecovery(error.message);
            }
        } finally {
            checkButton.disabled = false;
            applyCooldown();
        }
    }
    async function recover(action, button) {
        if (!pending) return;
        button.disabled = true;
        try {
            handleResult(await api(action));
        } catch (error) {
            showRecovery(error.message);
        } finally {
            button.disabled = false;
            applyCooldown();
        }
    }
    function requestFields(state) {
        const fields = {};
        for (const key of ['items', 'address', 'email', 'first_name', 'last_name', 'phone', 'notes',
            'coupon_code', 'shipping_quote', 'shipping_index', 'expected_total']) fields[key] = state[key];
        return fields;
    }

    function failure(message) {
        return { transactionState: 'ERROR', error: { intent: 'PAYMENT_AUTHORIZATION',
            reason: 'PAYMENT_DATA_INVALID', message } };
    }
    function notifyPaid(done) {
        try { options.onPaid(done.result, done.sameSource); }
        catch (_) { say(`Payment received. Order ${done.result.order_number}. Contact the store if the confirmation page does not open.`); }
    }
    async function authorize(paymentData, fields, state) {
        // Google can retry this callback within one sheet. Never create a second attempt.
        if (pending || completedResult) return failure('Check this payment on the checkout page before trying again.');
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        pending = { id: [...bytes].map(n => n.toString(16).padStart(2, '0')).join(''),
            source: sourceKey(state), session: options.sessionFingerprint };
        try {
            savePending();
            const created = await api('create', fields);
            if (created.state !== 'created') throw new Error('Check this payment on the checkout page.');
            if (created.amount !== fields.expected_total || created.currency !== 'USD') {
                throw new Error('The total changed. Close Google Pay and check payment status.');
            }
            // Tokenized card data goes only to PayPal; never to FAS, storage or logs.
            const confirmed = await timeout(googlepay.confirmOrder({ orderId: created.paypal_order_id,
                paymentMethodData: paymentData.paymentMethodData }), 20000);
            if (confirmed.status === 'PAYER_ACTION_REQUIRED') {
                await timeout(googlepay.initiatePayerAction({ orderId: created.paypal_order_id }), 20000);
            } else if (confirmed.status !== 'APPROVED') {
                throw new Error('Payment was not approved. Close Google Pay and check this attempt.');
            }
            const captured = await api('capture'); // Server GET verifies approval, source, amount and authentication.
            handleResult(captured);
            return ['paid', 'review'].includes(captured.state)
                ? { transactionState: 'SUCCESS' }
                : failure('Payment needs confirmation. Close Google Pay and check payment status.');
        } catch (error) {
            showRecovery('Payment could not be confirmed. Close Google Pay and check payment status.');
            return failure('Payment could not be confirmed. Close Google Pay and check payment status.');
        }
    }
    function begin() {
        if (busy || pending || !eligible || window.FASOrderRecovery?.pending() || window.FASApplePay?.busy?.()) return;
        const state = JSON.parse(JSON.stringify(readState()));
        if (!ready(state) || !document.getElementById('checkout-form').reportValidity()) return;
        const fields = requestFields(state);
        lastResult = null;
        const request = { apiVersion: 2, apiVersionMinor: 0,
            allowedPaymentMethods: googleConfig.allowedPaymentMethods,
            merchantInfo: googleConfig.merchantInfo,
            transactionInfo: { countryCode: googleConfig.countryCode || 'US', currencyCode: 'USD',
                totalPriceStatus: 'FINAL', totalPrice: fields.expected_total, totalPriceLabel: 'Total' },
            callbackIntents: ['PAYMENT_AUTHORIZATION'] };
        try {
            // Construct and open synchronously in the click event to preserve the user gesture.
            paymentsClient = new google.payments.api.PaymentsClient({ environment: options.environment,
                paymentDataCallbacks: { onPaymentAuthorized: data => authorize(data, fields, state) } });
            activeSheet = true;
            lock(true);
            const sheet = paymentsClient.loadPaymentData(request);
            Promise.resolve(sheet).then(() => {
                activeSheet = false;
                applyCooldown();
                if (completedResult) {
                    const done = completedResult;
                    completedResult = null;
                    notifyPaid(done);
                } else if (pending && lastResult) handleResult(lastResult);
                else if (pending) showRecovery('Check this payment before starting another checkout.');
                else lock(false);
            }).catch(() => {
                activeSheet = false;
                applyCooldown();
                if (completedResult) {
                    const done = completedResult;
                    completedResult = null;
                    notifyPaid(done);
                } else if (pending && lastResult) handleResult(lastResult);
                else if (pending) showRecovery('The payment sheet closed. Check this payment before trying again.');
                else {
                    lock(false);
                    say('Google Pay was closed without a payment. You can try again or choose PayPal.');
                }
            });
        } catch (_) {
            activeSheet = false;
            if (pending) showRecovery('Check this payment before trying again.');
            else { lock(false); say('Google Pay could not open. Please use PayPal or try again.'); }
        }
    }
    async function initializeSdk() {
        if (!checkoutReady || !storageAvailable || initializing || eligible || !options.enabled
            || !window.google?.payments?.api?.PaymentsClient || !window.paypal?.Googlepay) return;
        initializing = true;
        try {
            if (!window.isSecureContext) return;
            googlepay = paypal.Googlepay();
            googleConfig = await timeout(googlepay.config(), 10000);
            if (googleConfig.isEligible === false || !Array.isArray(googleConfig.allowedPaymentMethods)
                || !googleConfig.allowedPaymentMethods.length) return;
            googleConfig.merchantInfo = { ...googleConfig.merchantInfo };
            if (options.merchantId) googleConfig.merchantInfo.merchantId = options.merchantId;
            if (options.environment === 'PRODUCTION' && !googleConfig.merchantInfo.merchantId) return;
            const client = new google.payments.api.PaymentsClient({ environment: options.environment });
            const available = await timeout(client.isReadyToPay({ apiVersion: 2, apiVersionMinor: 0,
                allowedPaymentMethods: googleConfig.allowedPaymentMethods }), 10000);
            if (!available.result) return;
            googleButton = client.createButton({ onClick: begin, buttonColor: 'white', buttonType: 'pay',
                buttonSizeMode: 'fill', buttonRadius: 12,
                allowedPaymentMethods: googleConfig.allowedPaymentMethods });
            buttonHost.replaceChildren(googleButton);
            eligible = true;
        } catch (_) {
            if (!pending && options.preview) say('Google Pay could not initialize. Check PayPal eligibility and Google Pay production setup.');
        } finally {
            initializing = false;
            refresh();
        }
    }
    // Read the saved reference before checkout validates a changed/empty cart or redirects.
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (/^[a-f0-9]{32}$/.test(saved?.id)) pending = saved;
        sessionStorage.setItem('fas_googlepay_storage_check', '1');
        sessionStorage.removeItem('fas_googlepay_storage_check');
    } catch (_) { storageAvailable = false; }
    window.FASGooglePay = { refresh, busy: () => busy || !!pending, pending: () => !!pending };
    window.onGooglePayLoaded = initializeSdk;
    checkButton.addEventListener('click', checkStatus);
    stopButton.addEventListener('click', () => recover('abandon', stopButton));
    finishButton.addEventListener('click', () => recover('capture', finishButton));
    document.getElementById('checkout-form')?.addEventListener('input', refresh);
    document.getElementById('checkout-form')?.addEventListener('change', refresh);
    window.addEventListener('beforeunload', event => {
        if (pending) { event.preventDefault(); event.returnValue = ''; }
    });
    document.addEventListener('fas:checkout-ready', async () => {
        checkoutReady = true;
        if (pending) {
            showRecovery('A previous Google Pay attempt needs its status checked.');
            await checkStatus();
        }
        await initializeSdk();
    }, { once: true });
})();
