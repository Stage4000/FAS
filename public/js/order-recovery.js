/* Retain only payment references, never card or customer details. */
(() => {
    'use strict';
    const key = 'fas_paypal_recovery_v1';
    let reference = null;
    let retryAt = 0;
    let timer;
    let busy = false;
    try {
        const saved = JSON.parse(sessionStorage.getItem(key) || 'null');
        if (saved && typeof saved.paypal_order_id === 'string' && typeof saved.paypal_transaction_id === 'string'
            && Number.isInteger(saved.order_id) && saved.order_id > 0) {
            reference = saved;
            retryAt = Number(saved.retry_at) || 0;
        }
    } catch (_) {}
    function source() {
        return JSON.stringify([window.getCheckoutMode?.(), (window.getCheckoutItems?.() || [])
            .map(item => [String(item.id), Number(item.quantity)]).sort((a,b) => a[0].localeCompare(b[0]))]);
    }
    function persist() {
        try {
            if (reference) sessionStorage.setItem(key, JSON.stringify({...reference, retry_at: retryAt}));
            else sessionStorage.removeItem(key);
        } catch (_) { /* The current tab still retains the reference in memory. */ }
    }
    function render() {
        const root = document.getElementById('order-recovery');
        if (!root) return;
        const summary = document.getElementById('checkout-summary-regular');
        const pendingSummary = document.getElementById('checkout-pending-summary');
        const instructions = document.getElementById('paypal-instructions');
        const paymentTitle = document.getElementById('checkout-payment-title');
        const guidance = document.getElementById('checkout-guidance-card');
        const formColumn = document.getElementById('checkout-form-column');
        const paymentColumn = document.getElementById('checkout-payment-column');
        if (guidance) guidance.style.display = reference ? 'none' : '';
        if (formColumn) formColumn.style.display = reference ? 'none' : '';
        if (paymentColumn) {
            paymentColumn.style.width = reference ? '100%' : '';
            paymentColumn.style.maxWidth = reference ? '750px' : '';
            paymentColumn.style.margin = reference ? '0 auto' : '';
        }
        if (summary) summary.style.display = reference ? 'none' : '';
        if (pendingSummary) {
            pendingSummary.hidden = !reference;
            pendingSummary.style.display = reference ? '' : 'none';
        }
        if (instructions) instructions.style.display = reference ? 'none' : '';
        if (paymentTitle) paymentTitle.textContent = reference ? 'Payment confirmation' : 'Choose how to pay';
        root.hidden = !reference;
        if (!reference) return;
        ['checkout-form','paypal-button-container','applepay-payment'].forEach(id => {
            const node = document.getElementById(id);
            if (node) node.inert = true;
        });
        const remaining = Math.max(0, Math.ceil((retryAt - Date.now()) / 1000));
        root.querySelector('[data-recovery-message]').textContent =
            'Your payment needs order confirmation. Reference: ' + reference.paypal_order_id +
            '. Do not start another payment.' + (remaining ? ' Try again in ' + remaining + ' seconds.' : '');
        root.querySelector('button').disabled = busy || remaining > 0;
        clearTimeout(timer);
        if (remaining) timer = setTimeout(render, 1000);
    }
    async function retry() {
        if (!reference || busy || Date.now() < retryAt) return;
        busy = true; render();
        try {
            await window.completeOrder(reference.paypal_order_id, reference.paypal_transaction_id, reference.order_id);
        } finally { busy = false; render(); }
    }
    window.FASOrderRecovery = {
        pending: () => !!reference,
        canStart() {
            if (reference) { render(); return false; }
            if (Date.now() < retryAt) {
                alert('Please wait ' + Math.ceil((retryAt-Date.now())/1000) + ' seconds before trying again.');
                return false;
            }
            return true;
        },
        begin(paypalId, transactionId, orderId) {
            const order = String(paypalId);
            const capture = typeof transactionId === 'string' ? transactionId : '';
            const localId = Number(orderId);
            if (!reference) reference = {paypal_order_id: order, paypal_transaction_id: capture,
                order_id: localId, source: source()};
            else if (reference.paypal_order_id === order && reference.order_id === localId
                && !reference.paypal_transaction_id && capture) {
                reference.paypal_transaction_id = capture;
            }
            persist(); render();
        },
        wait(response) {
            retryAt = Date.now() + Math.max(1, Math.min(86400, Number(response.headers?.get('Retry-After')) || 30)) * 1000;
            persist(); render();
        },
        sameSource: () => !reference || reference.source === source(),
        clear() { reference = null; retryAt = 0; persist(); clearTimeout(timer); render(); },
        render
    };
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('order-recovery')?.querySelector('button').addEventListener('click', retry);
        render();
    });
})();
