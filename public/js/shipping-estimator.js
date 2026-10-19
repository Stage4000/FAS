(function () {
const endpoint = '/api/shipping-estimate.php';
const estimateStorageKey = 'flipandstrip_shipping_estimate';
const estimateMaxAgeMs = 4 * 60 * 60 * 1000;
const estimatorStates = new WeakMap();

    function formatMoney(value) {
        const amount = Number(value || 0);
        return `$${amount.toFixed(2)}`;
    }

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = value || '';
        return element.innerHTML;
    }

function getAddressFromForm(form) {
const formData = new FormData(form);
return {
address1: 'Shipping estimate',
city: String(formData.get('city') || '').trim(),
state: String(formData.get('state') || '').trim(),
zip: String(formData.get('zip') || '').trim(),
country: 'US'
};
}

function summarizeEstimateItems(items) {
return items.map(item => ({
id: String(item.id || item.product_id || ''),
product_id: String(item.product_id || item.id || ''),
sku: String(item.sku || ''),
name: String(item.name || ''),
quantity: Number(item.quantity || 1),
price: Number(item.price || 0)
}));
}

function saveEstimateForCheckout(mode, address, items, data) {
const payload = {
mode,
address,
items: summarizeEstimateItems(items),
result: {
lowest_rate: data.lowest_rate || null,
free_shipping: data.free_shipping || null,
rates_count: Array.isArray(data.rates) ? data.rates.length : 0
},
created_at: Date.now(),
expires_at: Date.now() + estimateMaxAgeMs
};

try {
localStorage.setItem(estimateStorageKey, JSON.stringify(payload));
payload.saved = true;
} catch (error) {}

return payload.saved ? payload : null;
}

function getLatestEstimate() {
try {
const payload = JSON.parse(localStorage.getItem(estimateStorageKey) || 'null');
if (!payload || !payload.expires_at || Date.now() > Number(payload.expires_at)) {
localStorage.removeItem(estimateStorageKey);
return null;
}
return payload;
} catch (error) {
return null;
}
}

    function getProductEstimateItems(form) {
        const source = document.querySelector(form.dataset.productSource || '.add-to-cart');
        const quantityInput = document.querySelector(form.dataset.quantitySource || '#quantity-input');

        if (!source) {
            return [];
        }

        return [{
            id: source.dataset.id,
            product_id: source.dataset.id,
            name: source.dataset.name || '',
            sku: source.dataset.sku || '',
            price: Number(source.dataset.price || 0),
            quantity: Math.max(1, Number(quantityInput ? quantityInput.value : 1) || 1),
            weight: Number(source.dataset.weight || 1),
            length: Number(source.dataset.length || 10),
            width: Number(source.dataset.width || 10),
            height: Number(source.dataset.height || 10),
            free_shipping: source.dataset.freeShipping === '1'
        }];
    }

    function getCartEstimateItems() {
        if (!window.cart || !Array.isArray(window.cart.cart)) {
            return [];
        }

        return window.cart.cart.map(item => ({
            id: item.id,
            product_id: item.id,
            name: item.name || '',
            sku: item.sku || '',
            price: Number(item.price || 0),
            quantity: Math.max(1, Number(item.quantity || 1)),
            weight: Number(item.weight || 1),
            length: Number(item.length || 10),
            width: Number(item.width || 10),
            height: Number(item.height || 10),
            free_shipping: item.free_shipping === true
        }));
    }

    function buildEstimateSummary(data) {
        const freeShipping = data.free_shipping || {};
        const lowestRate = data.lowest_rate || (Array.isArray(data.rates) && data.rates.length ? data.rates[0] : null);
        const freeCount = Number(freeShipping.free_items_count || 0);
        const ratedCount = Number(freeShipping.rated_items_count || 0);
        const missingData = Array.isArray(freeShipping.missing_shipping_data) ? freeShipping.missing_shipping_data : [];
        let html = '';

        if (lowestRate) {
            const rateAmount = Number(lowestRate.total_charge || 0);
            const serviceName = escapeHtml(lowestRate.service_name || lowestRate.courier_name || 'Shipping');
            const deliveryText = escapeHtml(lowestRate.delivery_time_text || 'Delivery timing shown at checkout');
            html += `
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-semibold">${rateAmount <= 0 ? 'Free Shipping' : `Estimated from ${formatMoney(rateAmount)}`}</div>
                        <div class="small text-muted">${serviceName} · ${deliveryText}</div>
                    </div>
                    <span class="badge ${rateAmount <= 0 ? 'bg-success' : 'bg-danger'}">${formatMoney(rateAmount)}</span>
                </div>
            `;
        }

        if (freeCount > 0 || ratedCount > 0) {
            html += '<div class="d-flex flex-wrap gap-2 mt-3">';
            if (freeCount > 0) {
                html += `<span class="badge bg-success"><i class="fas fa-truck-fast me-1"></i>${freeCount} item${freeCount === 1 ? '' : 's'} free-shipping eligible</span>`;
            }
            if (ratedCount > 0) {
                html += `<span class="badge bg-light text-dark border">${ratedCount} item${ratedCount === 1 ? '' : 's'} need carrier rate</span>`;
            }
            html += '</div>';
        }

        if (freeShipping.destination_eligible === false) {
            html += '<div class="small text-warning mt-3"><i class="fas fa-circle-info me-1"></i>Free shipping only applies within the continental US.</div>';
        }

        if (missingData.length > 0) {
            html += '<div class="small text-muted mt-3">Some items use fallback package dimensions until final checkout.</div>';
        }

        if (data.message) {
            html += `<div class="small text-muted mt-2">${escapeHtml(data.message)}</div>`;
        }

        return html || '<div class="small text-muted">No shipping estimate returned.</div>';
    }

    function updateSummaryTargets(form, data) {
        const lowestRate = data.lowest_rate || (Array.isArray(data.rates) && data.rates.length ? data.rates[0] : null);
        const shippingTarget = form.dataset.shippingTarget ? document.querySelector(form.dataset.shippingTarget) : null;
        const totalTarget = form.dataset.totalTarget ? document.querySelector(form.dataset.totalTarget) : null;
        const rateAmount = lowestRate ? Number(lowestRate.total_charge || 0) : null;

        if (shippingTarget && rateAmount !== null) {
            shippingTarget.textContent = rateAmount <= 0 ? 'Free' : `From ${formatMoney(rateAmount)}`;
            shippingTarget.classList.toggle('text-success', rateAmount <= 0);
            shippingTarget.classList.toggle('text-danger', rateAmount > 0);
            shippingTarget.classList.remove('text-muted');
        }

        if (totalTarget && rateAmount !== null && window.cart && typeof window.cart.getTotal === 'function') {
            totalTarget.textContent = formatMoney(window.cart.getTotal() + rateAmount);
            totalTarget.closest('.shipping-estimate-total-row')?.classList.remove('d-none');
        }
    }

    function setResult(container, type, content) {
        if (!container) {
            return;
        }

        const className = type === 'error'
            ? 'alert alert-warning border-0 small mb-0'
            : type === 'loading'
                ? 'alert alert-light border small mb-0'
                : 'alert alert-success border-0 small mb-0';

        container.innerHTML = `<div class="${className}">${content}</div>`;
    }

    function getEstimatorState(form) {
        if (!estimatorStates.has(form)) {
            estimatorStates.set(form, { version: 0, pending: false, hasEstimate: false, controller: null, label: null });
        }
        return estimatorStates.get(form);
    }

    function estimateContext(form) {
        const mode = form.dataset.estimateMode || 'cart';
        return JSON.stringify({
            address: getAddressFromForm(form),
            items: mode === 'product' ? getProductEstimateItems(form) : getCartEstimateItems()
        });
    }

    function invalidateEstimate(form, reason = '') {
        const state = getEstimatorState(form);
        const wasActive = state.pending || state.hasEstimate;
        state.version += 1;
        state.controller?.abort();
        state.controller = null;
        state.pending = false;
        state.hasEstimate = false;
        try { localStorage.removeItem(estimateStorageKey); } catch (error) {}

        const shippingTarget = form.dataset.shippingTarget ? document.querySelector(form.dataset.shippingTarget) : null;
        const totalTarget = form.dataset.totalTarget ? document.querySelector(form.dataset.totalTarget) : null;
        if (shippingTarget) {
            shippingTarget.textContent = window.cart?.cart?.length === 0 ? 'No items' : 'Estimate below';
            shippingTarget.classList.add('text-muted');
            shippingTarget.classList.remove('text-success', 'text-danger');
        }
        totalTarget?.closest('.shipping-estimate-total-row')?.classList.add('d-none');
        const button = form.querySelector('[type="submit"]');
        if (button && state.label !== null) {
            button.innerHTML = state.label;
            button.disabled = false;
        }
        state.label = null;
        const result = document.querySelector(form.dataset.resultTarget);
        if (wasActive && reason) setResult(result, 'error', reason);
        else if (result) result.innerHTML = '';
    }

    async function handleEstimatorSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        invalidateEstimate(form);
        const state = getEstimatorState(form);
        const version = state.version;
        const resultContainer = document.querySelector(form.dataset.resultTarget);
        const submitButton = form.querySelector('[type="submit"]');
        const mode = form.dataset.estimateMode || 'cart';
        const items = mode === 'product' ? getProductEstimateItems(form) : getCartEstimateItems();
        const address = getAddressFromForm(form);
        if (items.length === 0) {
            setResult(resultContainer, 'error', 'Add an item before estimating shipping.');
            return;
        }
        if (!address.city || !address.state || !address.zip) {
            setResult(resultContainer, 'error', 'Enter destination city, state, and ZIP code.');
            return;
        }
        const context = estimateContext(form);
        state.pending = true;
        state.controller = typeof AbortController === 'function' ? new AbortController() : null;
        if (submitButton) {
            state.label = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Estimating';
        }
        setResult(resultContainer, 'loading', 'Checking live shipping estimate...');
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items, address }),
                ...(state.controller ? { signal: state.controller.signal } : {})
            });
            const data = await response.json();
            if (state.version !== version) return;
            // Also guard programmatic changes that did not dispatch an input/cart event.
            if (estimateContext(form) !== context) {
                invalidateEstimate(form, 'Your items or destination changed. Estimate shipping again.');
                return;
            }
            if (!data.success) throw new Error(data.error || 'Unable to estimate shipping.');
            setResult(resultContainer, 'success', buildEstimateSummary(data));
            updateSummaryTargets(form, data);
            const savedEstimate = saveEstimateForCheckout(mode, address, items, data);
            state.hasEstimate = true;
            if (window.fasAnalytics && typeof window.fasAnalytics.track === 'function') {
                window.fasAnalytics.track('shipping_estimate_returned', {
                    source: mode,
                    destination_state: address.state,
                    destination_zip_prefix: address.zip.slice(0, 3),
                    free_items_count: Number(data.free_shipping?.free_items_count || 0),
                    rated_items_count: Number(data.free_shipping?.rated_items_count || 0),
                    lowest_rate: Number(data.lowest_rate?.total_charge || 0),
                    estimate_saved_for_checkout: !!savedEstimate
                });
            }
        } catch (error) {
            if (state.version !== version) return;
            setResult(resultContainer, 'error', escapeHtml(error.message || 'Shipping estimate is unavailable. Final shipping is calculated at checkout.'));
        } finally {
            if (state.version === version) {
                state.pending = false;
                state.controller = null;
                if (submitButton && state.label !== null) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = state.label;
                }
                state.label = null;
            }
        }
    }

    function initShippingEstimators() {
        document.querySelectorAll('[data-shipping-estimator]').forEach(form => {
            form.addEventListener('submit', handleEstimatorSubmit);
            ['input', 'change'].forEach(event => form.addEventListener(event, () => {
                invalidateEstimate(form, 'Destination changed. Estimate shipping again.');
            }));
            if (form.dataset.estimateMode === 'product') {
                const quantity = document.querySelector(form.dataset.quantitySource || '#quantity-input');
                ['input', 'change'].forEach(event => quantity?.addEventListener(event, () => {
                    invalidateEstimate(form, 'Quantity changed. Estimate shipping again.');
                }));
            } else {
                document.addEventListener('fas:cart-changed', () => {
                    invalidateEstimate(form, 'Cart changed. Estimate shipping again.');
                });
            }
        });
    }

window.fasShippingEstimator = Object.assign({}, window.fasShippingEstimator || {}, {
getLatestEstimate,
estimateStorageKey
});

if (document.readyState === 'loading') {
document.addEventListener('DOMContentLoaded', initShippingEstimators);
} else {
        initShippingEstimators();
    }
})();
