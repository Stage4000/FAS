// Conservative address autofill for checkout and shipping estimate forms.
(function () {
    'use strict';

    const storageKey = 'fas_shipping_address';
    const endpoint = '/api/address-autofill.php';
    const savedAddressTtlMs = 30 * 24 * 60 * 60 * 1000;
    let isApplyingAddress = false;

    function readJson(storage, key) {
        try {
            const value = storage.getItem(key);
            return value ? JSON.parse(value) : null;
        } catch (error) {
            return null;
        }
    }

    function writeJson(storage, key, value) {
        try {
            storage.setItem(key, JSON.stringify(value));
        } catch (error) {
            // Storage may be unavailable in private browsing modes.
        }
    }

    function cleanText(value, maxLength) {
        return String(value || '')
            .replace(/[^\p{L}\p{N}\s.'-]/gu, '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, maxLength || 120);
    }

    function normalizeState(value) {
        return cleanText(value, 2).toUpperCase();
    }

    function normalizeZip(value) {
        const zip = String(value || '').trim();
        const match = zip.match(/^\d{5}(?:-\d{4})?/);
        return match ? match[0] : '';
    }

    function normalizeAddress(value) {
        if (!value || typeof value !== 'object') {
            return null;
        }

        const address = {
            city: cleanText(value.city, 100),
            state: normalizeState(value.state),
            zip: normalizeZip(value.zip || value.postal_code),
            country: String(value.country || 'US').trim().toUpperCase(),
            source: cleanText(value.source || 'saved', 40),
            saved_at: Number(value.saved_at || Date.now())
        };

        if (address.country !== 'US') {
            return null;
        }

        return address;
    }

    function isCompleteAddress(address) {
        return !!(address && address.city && address.state.length === 2 && address.zip);
    }

    function readSavedAddress() {
        const saved = normalizeAddress(readJson(window.localStorage, storageKey));
        if (!saved || !isCompleteAddress(saved)) {
            return null;
        }

        if (Date.now() - Number(saved.saved_at || 0) > savedAddressTtlMs) {
            return null;
        }

        return saved;
    }

    function findAddressFields(form) {
        return {
            city: form.querySelector('[name="city"], [autocomplete="address-level2"]'),
            state: form.querySelector('[name="state"], [autocomplete="address-level1"]'),
            zip: form.querySelector('[name="zip"], [name="postal_code"], [autocomplete="postal-code"]')
        };
    }

    function addressForms() {
        const forms = Array.from(document.querySelectorAll('[data-address-autofill], [data-shipping-estimator], #checkout-form'));
        return forms.filter((form, index) => {
            const fields = findAddressFields(form);
            return forms.indexOf(form) === index && fields.city && fields.state && fields.zip;
        });
    }

    function setFieldValue(field, value) {
        if (!field || field.value.trim() || !value) {
            return false;
        }

        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function applyAddress(address) {
        const normalized = normalizeAddress(address);
        if (!isCompleteAddress(normalized)) {
            return;
        }

        isApplyingAddress = true;
        addressForms().forEach((form) => {
            const fields = findAddressFields(form);
            const changed = [
                setFieldValue(fields.city, normalized.city),
                setFieldValue(fields.state, normalized.state),
                setFieldValue(fields.zip, normalized.zip)
            ].some(Boolean);

            if (changed) {
                form.dataset.addressAutofilled = normalized.source || 'saved';
            }
        });
        isApplyingAddress = false;
    }

    function collectAddress(form) {
        const fields = findAddressFields(form);
        const address = normalizeAddress({
            city: fields.city ? fields.city.value : '',
            state: fields.state ? fields.state.value : '',
            zip: fields.zip ? fields.zip.value : '',
            country: 'US',
            source: 'user',
            saved_at: Date.now()
        });

        return isCompleteAddress(address) ? address : null;
    }

    function saveAddressFromForm(form) {
        const address = collectAddress(form);
        if (address) {
            writeJson(window.localStorage, storageKey, address);
        }
    }

    function bindAddressSaving() {
        addressForms().forEach((form) => {
            if (form.dataset.addressAutofillBound === '1') {
                return;
            }

            let saveTimer = null;
            const scheduleSave = () => {
                if (isApplyingAddress) {
                    return;
                }

                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(() => saveAddressFromForm(form), 250);
            };

            form.addEventListener('input', scheduleSave, true);
            form.addEventListener('change', () => {
                if (!isApplyingAddress) {
                    saveAddressFromForm(form);
                }
            }, true);
            form.addEventListener('submit', () => saveAddressFromForm(form), true);
            form.dataset.addressAutofillBound = '1';
        });

        const shippingButton = document.getElementById('calculate-shipping-btn');
        const checkoutForm = document.getElementById('checkout-form');
        if (shippingButton && checkoutForm && shippingButton.dataset.addressAutofillBound !== '1') {
            shippingButton.addEventListener('click', () => saveAddressFromForm(checkoutForm), true);
            shippingButton.dataset.addressAutofillBound = '1';
        }
    }

    function currentAnalyticsIds() {
        const sessionState = readJson(window.localStorage, 'fas_session_state') || {};
        const visitorState = readJson(window.localStorage, 'fas_visitor_state') || {};
        const params = new URLSearchParams();

        if (sessionState.session_id) {
            params.set('session_id', String(sessionState.session_id));
        }

        if (visitorState.visitor_id) {
            params.set('visitor_id', String(visitorState.visitor_id));
        }

        return params;
    }

    async function fetchAddressDefaults() {
        const params = currentAnalyticsIds();
        const url = params.toString() ? `${endpoint}?${params.toString()}` : endpoint;

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            if (data && data.success && data.has_address && data.address) {
                applyAddress(data.address);
            }
        } catch (error) {
            // Autofill is a convenience only; failures should not interrupt checkout.
        }
    }

    function initAddressAutofill() {
        bindAddressSaving();
        applyAddress(readSavedAddress());
        fetchAddressDefaults();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAddressAutofill);
    } else {
        initAddressAutofill();
    }
})();
