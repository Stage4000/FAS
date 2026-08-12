(function () {
    const endpoint = '/api/shipping-estimate.php';

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

    async function handleEstimatorSubmit(event) {
        event.preventDefault();

        const form = event.currentTarget;
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

        const originalLabel = submitButton ? submitButton.innerHTML : '';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Estimating';
        }
        setResult(resultContainer, 'loading', 'Checking live shipping estimate...');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items, address })
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Unable to estimate shipping.');
            }

            setResult(resultContainer, 'success', buildEstimateSummary(data));
            updateSummaryTargets(form, data);

            if (window.fasAnalytics && typeof window.fasAnalytics.track === 'function') {
                window.fasAnalytics.track('shipping_estimate_returned', {
                    source: mode,
                    destination_state: address.state,
                    destination_zip_prefix: address.zip.slice(0, 3),
                    free_items_count: Number(data.free_shipping?.free_items_count || 0),
                    rated_items_count: Number(data.free_shipping?.rated_items_count || 0),
                    lowest_rate: Number(data.lowest_rate?.total_charge || 0)
                });
            }
        } catch (error) {
            setResult(resultContainer, 'error', escapeHtml(error.message || 'Shipping estimate is unavailable. Final shipping is calculated at checkout.'));
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalLabel;
            }
        }
    }

    function initShippingEstimators() {
        document.querySelectorAll('[data-shipping-estimator]').forEach(form => {
            form.addEventListener('submit', handleEstimatorSubmit);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShippingEstimators);
    } else {
        initShippingEstimators();
    }
})();
