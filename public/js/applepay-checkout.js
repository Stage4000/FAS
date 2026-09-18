/* Apple Pay via PayPal v5. Wallet tokens are sent only to PayPal's SDK. */
(() => {
    'use strict';
    const options = window.FASApplePayOptions;
    const root = document.getElementById('applepay-payment');
    if (!options || !root) return;

    const message = root.querySelector('[data-applepay-message]');
    const buttonHost = root.querySelector('[data-applepay-button]');
    const checkButton = root.querySelector('[data-applepay-check]');
    const stopButton = root.querySelector('[data-applepay-stop]');
    const finishButton = root.querySelector('[data-applepay-finish]');
    const storageKey = 'fas_applepay_pending_v1';
    let applepay;
    let appleConfig;
    let appleButton;
    let eligible = false;
    let busy = false;
    let pending = null;
    let session = null;
    let disabledControls = [];

    function say(text) {
        message.textContent = text;
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
        if (!appleButton) return;
        const isReady = eligible && !busy && ready(readState());
        appleButton.inert = !isReady;
        appleButton.setAttribute('aria-disabled', isReady ? 'false' : 'true');
        appleButton.style.opacity = isReady ? '1' : '0.45';
        appleButton.style.pointerEvents = isReady ? 'auto' : 'none';
        if (!busy && !isReady && eligible) {
            say('Complete your details and calculate shipping for this address. Use a two-letter state and ZIP code.');
        } else if (!busy && isReady) {
            say(options.preview ? 'Admin-only preview. This uses your configured PayPal environment; live mode charges real money.' : 'Pay securely with Apple Pay, processed by PayPal.');
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
            if (!value) options.onUnlock?.();
        }
        root.setAttribute('aria-busy', value ? 'true' : 'false');
        refresh();
    }
    function savePending() {
        sessionStorage.setItem(storageKey, JSON.stringify(pending));
    }
    function clearPending() {
        pending = null;
        sessionStorage.removeItem(storageKey);
        checkButton.hidden = stopButton.hidden = finishButton.hidden = true;
    }
    function completeSheet(success) {
        if (!session) return;
        try {
            session.completePayment(success ? ApplePaySession.STATUS_SUCCESS : ApplePaySession.STATUS_FAILURE);
        } catch (_) { /* The device may already have closed/timed out the sheet. */ }
        session = null;
    }
    function timeout(promise, ms) {
        let timer;
        return Promise.race([promise, new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('Payment response timed out. Check payment status.')), ms);
        })]).finally(() => clearTimeout(timer));
    }
    async function api(action, fields = {}) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 25000);
        try {
            const response = await fetch('/api/applepay.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf },
                body: JSON.stringify({ ...fields, action, attempt_id: pending?.id })
            });
            const data = await response.json();
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
        say(`${text} Reference: ${pending?.id || 'unavailable'}. Do not start another payment until this is resolved.`);
    }
    function handleResult(result) {
        if (result.state === 'paid') {
            completeSheet(true);
            const sameSource = pending?.source === sourceKey(readState());
            clearPending();
            say(`Payment received. Order ${result.order_number}.`);
            try { options.onPaid(result, sameSource); } catch (_) {
                say(`Payment received. Order ${result.order_number}. Contact the store if the confirmation page does not open.`);
            }
            return;
        }
        if (result.state === 'review') {
            completeSheet(true); // Funds really were captured; do not call this a declined payment.
            showRecovery(`Payment received for ${result.order_number}, but the order needs a stock review. Contact the store; do not pay again.`);
            return;
        }
        completeSheet(false);
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
        }
    }
    function requestFields(state) {
        const fields = {};
        for (const key of ['items', 'address', 'email', 'first_name', 'last_name', 'phone', 'notes',
            'coupon_code', 'shipping_quote', 'shipping_index', 'expected_total']) fields[key] = state[key];
        return fields;
    }
    function begin() {
        const state = JSON.parse(JSON.stringify(readState()));
        if (busy || !eligible || !ready(state)) return;
        const form = document.getElementById('checkout-form');
        if (!form.reportValidity()) return;
        // This snapshot cannot change while the sheet is open.
        const fields = requestFields(state);
        const request = {
            countryCode: appleConfig.countryCode,
            merchantCapabilities: appleConfig.merchantCapabilities,
            supportedNetworks: appleConfig.supportedNetworks,
            currencyCode: 'USD',
            requiredBillingContactFields: ['postalAddress'],
            // Address and shipping remain on FAS; do not solicit another shipping address in the sheet.
            total: { label: options.displayName, type: 'final', amount: fields.expected_total }
        };
        try {
            // No await or network request before constructing/beginning the session: user gesture required.
            session = new ApplePaySession(4, request);
            session.onvalidatemerchant = async event => {
                try {
                    const validated = await timeout(applepay.validateMerchant({
                        validationUrl: event.validationURL, displayName: options.displayName
                    }), 20000);
                    session?.completeMerchantValidation(validated.merchantSession);
                } catch (_) {
                    try { session?.abort(); } catch (_) {}
                    session = null;
                    if (!pending) lock(false);
                    say('Apple Pay merchant validation failed. Use PayPal or contact the store. No payment was submitted.');
                }
            };
            session.oncancel = () => {
                session = null;
                if (!pending) {
                    lock(false);
                    say('Apple Pay was cancelled. Your checkout is unchanged.');
                } else {
                    showRecovery('The payment sheet closed while payment was being processed.');
                }
            };
            session.onpaymentauthorized = async event => {
                const bytes = new Uint8Array(16);
                crypto.getRandomValues(bytes);
                pending = { id: [...bytes].map(n => n.toString(16).padStart(2, '0')).join(''), source: sourceKey(state), session: options.sessionFingerprint };
                try {
                    // Persist only a reference. Never persist or log event.payment/token/billingContact.
                    savePending();
                    const created = await api('create', fields);
                    if (created.state !== 'created') {
                        handleResult(created);
                        return;
                    }
                    if (created.amount !== fields.expected_total || created.currency !== 'USD') {
                        throw new Error('The server total changed. Check payment status before trying again.');
                    }
                    await timeout(applepay.confirmOrder({ orderId: created.paypal_order_id,
                        token: event.payment.token, billingContact: event.payment.billingContact }), 20000);
                    const captured = await api('capture');
                    handleResult(captured);
                } catch (error) {
                    completeSheet(false);
                    showRecovery(error.message || 'Payment could not be confirmed.');
                }
            };
            lock(true);
            session.begin();
        } catch (_) {
            session = null;
            lock(false);
            say('Apple Pay could not open. Use PayPal or try a compatible browser/device.');
        }
    }
    async function initialize() {
        try {
            const raw = sessionStorage.getItem(storageKey);
            if (raw) {
                const saved = JSON.parse(raw);
                if (/^[a-f0-9]{32}$/.test(saved?.id)) pending = saved;
            }
            sessionStorage.setItem('fas_applepay_storage_check', '1');
            sessionStorage.removeItem('fas_applepay_storage_check');
        } catch (_) {
            root.hidden = !options.preview;
            say('Apple Pay needs browser session storage for payment recovery. Use PayPal in this browser.');
            return;
        }
        if (pending) {
            showRecovery('A previous Apple Pay attempt needs its status checked.');
            await checkStatus();
        }
        try {
            if (!window.isSecureContext || !window.ApplePaySession || !window.paypal?.Applepay
                || !ApplePaySession.supportsVersion(4) || !ApplePaySession.canMakePayments()) {
                if (!pending) {
                    root.hidden = !options.preview;
                    say('Apple Pay is not available on this device/browser. Standard PayPal is still available.');
                }
                return;
            }
            applepay = paypal.Applepay();
            appleConfig = await timeout(applepay.config(), 10000);
            if (!appleConfig.isEligible) {
                if (!pending) {
                    root.hidden = !options.preview;
                    say('PayPal reports this checkout is not eligible for Apple Pay.');
                }
                return;
            }
            eligible = true;
            appleButton = document.createElement('apple-pay-button');
            appleButton.setAttribute('buttonstyle', 'black');
            appleButton.setAttribute('type', 'buy');
            appleButton.setAttribute('locale', 'en-US');
            appleButton.style.setProperty('--apple-pay-button-width', '100%');
            appleButton.style.setProperty('--apple-pay-button-height', '44px');
            appleButton.addEventListener('click', begin);
            buttonHost.replaceChildren(appleButton);
            refresh();
        } catch (_) {
            if (!pending) {
                root.hidden = !options.preview;
                say('Apple Pay could not initialize. Standard PayPal is still available.');
            }
        }
    }
    window.FASApplePay = { refresh };
    checkButton.addEventListener('click', checkStatus);
    stopButton.addEventListener('click', () => recover('abandon', stopButton));
    finishButton.addEventListener('click', () => recover('capture', finishButton));
    document.getElementById('checkout-form')?.addEventListener('input', refresh);
    document.getElementById('checkout-form')?.addEventListener('change', refresh);
    window.addEventListener('beforeunload', event => {
        if (pending) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    document.addEventListener('fas:checkout-ready', initialize, { once: true });
})();
