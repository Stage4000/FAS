// First-party analytics for Flip and Strip.
(function () {
    'use strict';

    const endpoint = '/api/track-event.php';
    const queue = [];
    const sentScrollDepths = {};
    const impressionKeys = {};
    const bannerViewKeys = {};
    const dedupeKeys = {};
    const pageStartedAt = Date.now();
    const batchSize = 20;
    const maxQueueSize = 120;
    const sessionTtlMs = 30 * 24 * 60 * 60 * 1000;
    const sessionTtlDays = 30;
    const sessionStateKey = 'fas_session_state';
    const visitorStateKey = 'fas_visitor_state';
    const adminHintKey = 'fas_admin_analytics_hint';
    const adminMarkerEndpoint = '/admin/mark-analytics-session.php';
    const errorEndpoint = '/api/log-client-error.php';
    const currentPagePath = location.pathname + location.search;

    let maxScrollDepth = 0;
    let flushTimer = null;
    let flushInFlight = null;
    let exitSent = false;

    function reportClientError(area, message, context, error) {
        try {
            const payload = {
                area: area || 'analytics',
                severity: context && context.severity ? context.severity : 'error',
                source: 'public/js/analytics.js',
                message: message || (error && error.message) || 'Client error',
                url: location.href,
                page: currentPagePath,
                session_id: sessionId,
                context: context || {},
                stack: error && error.stack ? error.stack : ''
            };

            fetch(errorEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
                keepalive: true
            }).catch(() => {});
        } catch (ignored) {}
    }

    const eventAliases = {
        cart_item_added: 'add_to_cart',
        cart_added: 'add_to_cart',
        cart_add: 'add_to_cart',
        cart_item_removed: 'remove_from_cart',
        checkout_started: 'checkout_start',
        shipping_calculation_started: 'shipping_rate_requested',
        shipping_rates_requested: 'shipping_rate_requested',
        shipping_method_selected: 'shipping_rate_selected',
        coupon_apply_attempted: 'coupon_attempted',
        coupon_apply_invalid: 'coupon_rejected',
        order_completed: 'purchase_completed',
        order_completion_failed: 'purchase_failed',
        ebay_exit_click: 'ebay_link_click',
        ebay_store_click: 'ebay_link_click'
    };

    const dedupedEvents = {
        checkout_start: 2500,
        cart_view: 2500,
        product_view: 2500,
        shipping_rate_selected: 1500,
        coupon_attempted: 1500,
        purchase_completed: 5000
    };

    function createId(prefix) {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            return prefix + '_' + Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        }

        return prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    function storageGet(storage, key) {
        try {
            return storage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function storageSet(storage, key, value) {
        try {
            storage.setItem(key, value);
        } catch (error) {
        }
    }

    function storageGetJson(storage, key) {
        const value = storageGet(storage, key);
        if (!value) {
            return null;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    function storageSetJson(storage, key, value) {
        storageSet(storage, key, JSON.stringify(value));
    }

    function isoNow(timestamp) {
        return new Date(timestamp || Date.now()).toISOString();
    }

    function getVisitorState() {
        const now = Date.now();
        const existing = storageGetJson(window.localStorage, visitorStateKey) || {};
        let visitorId = existing.visitor_id || storageGet(window.localStorage, 'fas_visitor_id');
        const isNewVisitor = !visitorId;

        if (!visitorId) {
            visitorId = createId('vis');
        }

        const state = {
            visitor_id: visitorId,
            first_seen_at: existing.first_seen_at || isoNow(now),
            last_seen_at: isoNow(now),
            pageviews: toInteger(existing.pageviews, 0) + 1,
            is_returning_visitor: !isNewVisitor && !!existing.last_seen_at
        };

        storageSet(window.localStorage, 'fas_visitor_id', visitorId);
        storageSetJson(window.localStorage, visitorStateKey, state);

        return state;
    }

    function getSessionState() {
        const now = Date.now();
        const existing = storageGetJson(window.localStorage, sessionStateKey) || {};
        const lastActivityAt = toInteger(existing.last_activity_at, 0);
        const isExpired = !existing.session_id || !lastActivityAt || (now - lastActivityAt) > sessionTtlMs;
        const state = isExpired ? {
            session_id: createId('ses'),
            landing_page: currentPagePath,
            started_at: isoNow(now),
            page_sequence: 0,
            previous_page_path: ''
        } : Object.assign({}, existing);

        state.previous_page_path = state.last_page || '';
        state.page_sequence = toInteger(state.page_sequence, 0) + 1;
        state.last_page = currentPagePath;
        state.last_activity_at = now;
        state.last_seen_at = isoNow(now);
        state.session_expires_at = isoNow(now + sessionTtlMs);

        storageSetJson(window.localStorage, sessionStateKey, state);
        storageSet(window.sessionStorage, 'fas_session_id', state.session_id);
        storageSet(window.sessionStorage, 'fas_landing_page', state.landing_page);

        return state;
    }

    let activeSessionState = getSessionState();

    function touchSessionState() {
        activeSessionState.last_activity_at = Date.now();
        activeSessionState.last_seen_at = isoNow(activeSessionState.last_activity_at);
        activeSessionState.session_expires_at = isoNow(activeSessionState.last_activity_at + sessionTtlMs);
        storageSetJson(window.localStorage, sessionStateKey, activeSessionState);
    }

    function getSessionAgeSeconds() {
        const started = Date.parse(activeSessionState.started_at || '');
        if (!Number.isFinite(started)) {
            return getDurationSeconds();
        }

        return Math.max(0, Math.round((Date.now() - started) / 1000));
    }

    const visitorState = getVisitorState();
    const visitorId = visitorState.visitor_id;
    const sessionId = activeSessionState.session_id;
    const pageSequence = activeSessionState.page_sequence || 1;
    const previousPagePath = activeSessionState.previous_page_path || '';

    function normalizeEventType(eventType) {
        const normalized = String(eventType || 'custom_event')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');

        return eventAliases[normalized] || normalized || 'custom_event';
    }

    function toNumber(value, fallback) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : (fallback || 0);
    }

    function toInteger(value, fallback) {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : (fallback || 0);
    }

    function getDeviceType() {
        const width = window.innerWidth || document.documentElement.clientWidth || 0;
        if (width < 768) {
            return 'mobile';
        }
        if (width < 1024) {
            return 'tablet';
        }
        return 'desktop';
    }

    function getBrowser() {
        const ua = navigator.userAgent || '';
        if (ua.includes('Edg/')) return 'Edge';
        if (ua.includes('Chrome/')) return 'Chrome';
        if (ua.includes('Firefox/')) return 'Firefox';
        if (ua.includes('Safari/')) return 'Safari';
        return 'Other';
    }

    function getOS() {
        const ua = navigator.userAgent || '';
        if (ua.includes('Windows')) return 'Windows';
        if (ua.includes('Mac OS')) return 'macOS';
        if (ua.includes('Android')) return 'Android';
        if (/iPhone|iPad|iPod/.test(ua)) return 'iOS';
        if (ua.includes('Linux')) return 'Linux';
        return 'Other';
    }

    function getReferrerHost(referrer) {
        if (!referrer) {
            return '';
        }

        try {
            return new URL(referrer).hostname;
        } catch (error) {
            return '';
        }
    }

    function getConnectionType() {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        return connection && connection.effectiveType ? connection.effectiveType : '';
    }

    function getSaveDataPreference() {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        return connection && connection.saveData ? 1 : 0;
    }

    function getColorScheme() {
        if (!window.matchMedia) {
            return '';
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function getViewportOrientation() {
        const width = window.innerWidth || document.documentElement.clientWidth || 0;
        const height = window.innerHeight || document.documentElement.clientHeight || 0;
        return width >= height ? 'landscape' : 'portrait';
    }

    function getUtmContext() {
        const params = new URLSearchParams(location.search);
        return {
            utm_source: params.get('utm_source') || '',
            utm_medium: params.get('utm_medium') || '',
            utm_campaign: params.get('utm_campaign') || '',
            utm_term: params.get('utm_term') || '',
            utm_content: params.get('utm_content') || ''
        };
    }

    function getContext() {
        return Object.assign({
            session_id: sessionId,
            visitor_id: visitorId,
            page_url: location.href,
            page_path: currentPagePath,
            page_title: document.title,
            landing_page: activeSessionState.landing_page || currentPagePath,
            session_started_at: activeSessionState.started_at || '',
            session_expires_at: activeSessionState.session_expires_at || '',
            session_ttl_days: sessionTtlDays,
            session_age_seconds: getSessionAgeSeconds(),
            page_sequence: pageSequence,
            previous_page_path: previousPagePath,
            referrer: document.referrer || '',
            referrer_host: getReferrerHost(document.referrer || ''),
            visitor_first_seen_at: visitorState.first_seen_at || '',
            visitor_pageviews: visitorState.pageviews || 1,
            is_returning_visitor: visitorState.is_returning_visitor ? 1 : 0,
            device_type: getDeviceType(),
            browser: getBrowser(),
            os: getOS(),
            language: navigator.language || '',
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
            screen_width: window.screen ? window.screen.width : 0,
            screen_height: window.screen ? window.screen.height : 0,
            viewport_width: window.innerWidth || document.documentElement.clientWidth || 0,
            viewport_height: window.innerHeight || document.documentElement.clientHeight || 0,
            viewport_orientation: getViewportOrientation(),
            connection_type: getConnectionType(),
            save_data: getSaveDataPreference(),
            color_scheme: getColorScheme(),
            cookies_enabled: navigator.cookieEnabled ? 1 : 0
        }, getUtmContext());
    }

    function getCartItems() {
        if (window.cart && Array.isArray(window.cart.cart)) {
            return window.cart.cart;
        }

        try {
            const stored = JSON.parse(window.localStorage.getItem('flipandstrip_cart') || '[]');
            return Array.isArray(stored) ? stored : [];
        } catch (error) {
            return [];
        }
    }

    function getCartSummary(items) {
        const cartItems = Array.isArray(items) ? items : getCartItems();
        return cartItems.reduce((summary, item) => {
            const quantity = toInteger(item.quantity, 0);
            const price = toNumber(item.price || item.unit_price, 0);
            summary.cart_unique_items += 1;
            summary.cart_items_count += quantity;
            summary.cart_value += price * quantity;
            return summary;
        }, {
            cart_unique_items: 0,
            cart_items_count: 0,
            cart_value: 0
        });
    }

    function getDurationSeconds() {
        return Math.max(0, Math.round((Date.now() - pageStartedAt) / 1000));
    }

    function pickTopLevelFields(data) {
        const fields = {};
        [
            'event_name',
            'page_url',
            'page_path',
            'page_title',
            'referrer',
            'referrer_host',
            'previous_page_path',
            'page_sequence',
            'session_age_seconds',
            'session_expires_at',
            'session_ttl_days',
            'visitor_first_seen_at',
            'visitor_pageviews',
            'is_returning_visitor',
            'viewport_orientation',
            'connection_type',
            'save_data',
            'color_scheme',
            'cookies_enabled',
            'product_id',
            'product_name',
            'product_sku',
            'category',
            'manufacturer',
            'product_source',
            'product_price',
            'condition_name',
            'stock_quantity',
            'list_name',
            'list_position',
            'quantity',
            'cart_items_count',
            'cart_unique_items',
            'cart_value',
            'coupon_code',
            'coupon_status',
            'discount_amount',
            'shipping_service',
            'shipping_cost',
            'destination_state',
            'checkout_step',
            'payment_provider',
            'order_id',
            'order_number',
            'revenue',
            'currency',
            'search_term',
            'link_text',
            'link_source',
            'target_url',
            'target_host',
            'banner_id',
            'campaign_name',
            'event_value',
            'scroll_depth',
            'duration_seconds'
        ].forEach(key => {
            if (data[key] !== undefined && data[key] !== null && data[key] !== '') {
                fields[key] = data[key];
            }
        });
        return fields;
    }

    function dedupeKey(eventType, data) {
        return [
            eventType,
            data.product_id || data.id || '',
            data.order_id || data.order_number || '',
            data.coupon_code || data.code || '',
            data.shipping_index || data.shipping_service || '',
            location.pathname
        ].join('|');
    }

    function shouldDedupe(eventType, data) {
        const windowMs = dedupedEvents[eventType];
        if (!windowMs) {
            return false;
        }

        const key = dedupeKey(eventType, data);
        const now = Date.now();
        if (dedupeKeys[key] && now - dedupeKeys[key] < windowMs) {
            return true;
        }

        dedupeKeys[key] = now;
        return false;
    }

    function track(eventType, data, options) {
        const normalizedEventType = normalizeEventType(eventType);
        const eventData = data || {};
        const flushOptions = options || {};

        if (shouldDedupe(normalizedEventType, eventData)) {
            return;
        }

        touchSessionState();

        const cart = eventData.cart_summary || eventData.cart || getCartSummary();
        const event = Object.assign({
            event_type: normalizedEventType,
            event_name: eventData.event_name || normalizedEventType,
            page_url: location.href,
            page_path: currentPagePath,
            page_title: document.title,
            referrer: document.referrer || '',
            referrer_host: getReferrerHost(document.referrer || ''),
            previous_page_path: previousPagePath,
            page_sequence: eventData.page_sequence !== undefined ? eventData.page_sequence : pageSequence,
            session_age_seconds: eventData.session_age_seconds !== undefined ? eventData.session_age_seconds : getSessionAgeSeconds(),
            session_expires_at: activeSessionState.session_expires_at || '',
            session_ttl_days: sessionTtlDays,
            visitor_first_seen_at: visitorState.first_seen_at || '',
            visitor_pageviews: visitorState.pageviews || 1,
            is_returning_visitor: visitorState.is_returning_visitor ? 1 : 0,
            viewport_orientation: getViewportOrientation(),
            connection_type: getConnectionType(),
            save_data: getSaveDataPreference(),
            color_scheme: getColorScheme(),
            cookies_enabled: navigator.cookieEnabled ? 1 : 0,
            currency: eventData.currency || 'USD',
            duration_seconds: eventData.duration_seconds !== undefined ? eventData.duration_seconds : getDurationSeconds(),
            scroll_depth: eventData.scroll_depth !== undefined ? eventData.scroll_depth : maxScrollDepth,
            cart_items_count: eventData.cart_items_count !== undefined ? eventData.cart_items_count : cart.cart_items_count,
            cart_unique_items: eventData.cart_unique_items !== undefined ? eventData.cart_unique_items : cart.cart_unique_items,
            cart_value: eventData.cart_value !== undefined ? eventData.cart_value : cart.cart_value,
            metadata: Object.assign({}, eventData.metadata || {}, eventData, { cart })
        }, pickTopLevelFields(eventData));

        queue.push(event);
        while (queue.length > maxQueueSize) {
            queue.shift();
        }

        if (flushOptions.immediate) {
            flush(!!flushOptions.beacon);
            return;
        }

        scheduleFlush();
    }

    function scheduleFlush() {
        if (flushTimer) {
            return;
        }

        flushTimer = window.setTimeout(() => {
            flushTimer = null;
            flush(false);
        }, 1800);
    }

    function buildPayload(events) {
        return JSON.stringify({
            session_id: sessionId,
            visitor_id: visitorId,
            context: getContext(),
            events
        });
    }

    function markAdminSessionIfNeeded() {
        const hint = storageGetJson(window.localStorage, adminHintKey);
        const now = Date.now();
        if (!hint || toInteger(hint.expires_at, 0) < now) {
            try {
                window.localStorage.removeItem(adminHintKey);
            } catch (error) {}
            return;
        }

        const markedKey = 'fas_admin_marked_' + sessionId;
        if (storageGet(window.localStorage, markedKey) === '1') {
            return;
        }

        const checkedKey = 'fas_admin_marker_checked_' + sessionId;
        const lastCheckedAt = toInteger(storageGet(window.localStorage, checkedKey), 0);
        if (lastCheckedAt && now - lastCheckedAt < 60000) {
            return;
        }

        storageSet(window.localStorage, checkedKey, String(now));

        fetch(adminMarkerEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                session_id: sessionId,
                visitor_id: visitorId,
                context: getContext()
            })
        })
            .then(response => response.ok ? response.json().catch(() => ({})) : {})
            .then(data => {
                if (data && data.admin === true && data.marked === true) {
                    storageSet(window.localStorage, markedKey, '1');
                } else if (data && data.admin === false) {
                    window.localStorage.removeItem(adminHintKey);
                }
            })
            .catch(() => {});
    }

    function flush(useBeacon) {
        if (flushInFlight || queue.length === 0) {
            return flushInFlight || Promise.resolve(false);
        }

        const events = queue.splice(0, batchSize);
        const body = buildPayload(events);

        if (useBeacon && navigator.sendBeacon) {
            const blob = new Blob([body], { type: 'application/json' });
            if (navigator.sendBeacon(endpoint, blob)) {
                return Promise.resolve(true);
            }
        }

        flushInFlight = fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body,
            credentials: 'same-origin',
            keepalive: !!useBeacon
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Analytics request failed');
                }
                return response.json().catch(() => ({}));
            })
 .catch(error => {
 reportClientError('analytics', 'Analytics event upload failed.', {
 severity: 'warning',
 queued_events: events.length,
 endpoint
 }, error);
 events.reverse().forEach(event => queue.unshift(event));
 return false;
 })
            .finally(() => {
                flushInFlight = null;
                if (queue.length > 0 && !useBeacon) {
                    scheduleFlush();
                }
            });

        return flushInFlight;
    }

    function dataFromProductElement(element) {
        if (!element) {
            return {};
        }

        const button = element.matches && element.matches('.add-to-cart[data-id]')
            ? element
            : element.querySelector('.add-to-cart[data-id]');

        if (!button) {
            return {};
        }

        const productPrice = toNumber(button.dataset.price, 0);
        return {
            product_id: button.dataset.id || '',
            product_name: button.dataset.name || '',
            product_sku: button.dataset.sku || '',
            category: button.dataset.category || '',
            manufacturer: button.dataset.manufacturer || '',
            product_source: button.dataset.source || '',
            product_price: productPrice,
            condition_name: button.dataset.condition || '',
            stock_quantity: toInteger(button.dataset.stock, 0),
            event_value: productPrice
        };
    }

    function dataFromCartItem(item) {
        const price = toNumber(item.price || item.unit_price, 0);
        const quantity = toInteger(item.quantity, 1);
        return {
            product_id: item.product_id || item.id || '',
            product_name: item.product_name || item.name || '',
            product_sku: item.product_sku || item.sku || '',
            category: item.category || '',
            manufacturer: item.manufacturer || '',
            product_source: item.product_source || item.source || '',
            product_price: price,
            condition_name: item.condition_name || item.condition || '',
            stock_quantity: toInteger(item.stock || item.stock_quantity, 0),
            quantity,
            event_value: price * Math.max(1, quantity)
        };
    }

    function getCurrentProductContext() {
        if (!window.FAS_PRODUCT_DATA) {
            return {};
        }

        const productData = Object.assign({}, window.FAS_PRODUCT_DATA);
        if (productData.event_value !== undefined && productData.product_price === undefined) {
            productData.product_price = productData.event_value;
        }

        return productData;
    }

    function dataFromBannerElement(element) {
        if (!element) {
            return {};
        }

        const banner = element.closest('[data-analytics-banner]');
        if (!banner) {
            return {};
        }

        const link = element.matches && element.matches('a[href]')
            ? element
            : banner.querySelector('[data-analytics-banner-link], a[href]');
        const linkText = link
            ? (link.textContent || link.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 120)
            : '';
        const targetUrl = link
            ? (link.getAttribute('data-analytics-target-url') || link.getAttribute('href') || '')
            : '';

        return {
            banner_id: banner.getAttribute('data-analytics-banner') || '',
            campaign_name: banner.getAttribute('data-analytics-campaign') || '',
            banner_text: (banner.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 160),
            link_text: linkText,
            target_url: targetUrl
        };
    }

    function isEbayHost(hostname) {
        const host = String(hostname || '').toLowerCase();
        return host === 'ebay.com'
            || host.endsWith('.ebay.com')
            || host.startsWith('ebay.')
            || host.includes('.ebay.');
    }

    function trackCartEvent(eventType, item, extra) {
        const extraData = extra || {};
        const summary = extraData.cart_summary || extraData.cart || getCartSummary();
        const productData = dataFromCartItem(item || {});
        const payload = Object.assign({}, productData, extraData, {
            cart_items_count: summary.cart_items_count,
            cart_unique_items: summary.cart_unique_items,
            cart_value: summary.cart_value,
            cart_summary: summary
        });

        track(eventType, payload, { immediate: true });
    }

    function updateScrollDepth() {
        const doc = document.documentElement;
        const body = document.body || doc;
        const scrollTop = window.scrollY || doc.scrollTop || body.scrollTop || 0;
        const scrollHeight = Math.max(body.scrollHeight, doc.scrollHeight, body.offsetHeight, doc.offsetHeight);
        const viewport = window.innerHeight || doc.clientHeight || 1;
        const trackable = Math.max(1, scrollHeight - viewport);
        const depth = Math.min(100, Math.round((scrollTop / trackable) * 100));
        maxScrollDepth = Math.max(maxScrollDepth, depth);

        [25, 50, 75, 90, 100].forEach(mark => {
            if (maxScrollDepth >= mark && !sentScrollDepths[mark]) {
                sentScrollDepths[mark] = true;
                track('scroll_depth', { scroll_depth: mark });
            }
        });
    }

    function setupScrollTracking() {
        let pending = false;
        window.addEventListener('scroll', () => {
            if (pending) {
                return;
            }
            pending = true;
            window.requestAnimationFrame(() => {
                updateScrollDepth();
                pending = false;
            });
        }, { passive: true });
        updateScrollDepth();
    }

    function setupClickTracking() {
        document.addEventListener('click', event => {
            const clicked = event.target;
            if (!(clicked instanceof Element)) {
                return;
            }

            const themeToggle = clicked.closest('#themeToggle, #navbarThemeToggle');
            if (themeToggle) {
                track('site_setting_changed', {
                    setting: 'theme',
                    control_id: themeToggle.id || ''
                }, { immediate: true });
                return;
            }

            if (clicked.closest('.add-to-cart')) {
                return;
            }

            const bannerLink = clicked.closest('[data-analytics-banner-link], [data-analytics-banner] a[href]');
            if (bannerLink) {
                const bannerData = dataFromBannerElement(bannerLink);
                if (bannerData.banner_id) {
                    track('banner_click', bannerData, { immediate: true, beacon: true });
                }
            }

            const link = clicked.closest('a[href]');
            if (!link) {
                return;
            }

            const href = link.getAttribute('href') || '';
            if (!href || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                return;
            }

            let url;
            try {
                url = new URL(href, location.href);
            } catch (error) {
                return;
            }

            const text = (link.textContent || link.getAttribute('aria-label') || '').trim().slice(0, 120);
            const productMatch = url.pathname.match(/^\/product\/([^/]+)/);
            if (productMatch) {
                const cardData = dataFromProductElement(link.closest('.product-card'));
                track('product_click', Object.assign({
                    product_id: productMatch[1],
                    link_text: text,
                    target_url: url.pathname
                }, cardData), { immediate: true });
                return;
            }

            if (url.hostname !== location.hostname) {
                const outboundData = Object.assign({
                    link_text: text,
                    link_source: link.getAttribute('data-analytics-source') || '',
                    target_url: url.href,
                    target_host: url.hostname
                }, getCurrentProductContext());

                if (isEbayHost(url.hostname)) {
                    track('ebay_link_click', Object.assign({
                        event_name: 'eBay outbound click'
                    }, outboundData), { immediate: true, beacon: true });
                }

                track('external_link_click', outboundData, { immediate: true, beacon: true });
            }
        });
    }

    function setupSearchTracking() {
        document.addEventListener('submit', event => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const searchInput = form.querySelector('input[name="search"], input[type="search"]');
            if (!searchInput) {
                return;
            }

            const searchTerm = searchInput.value.trim();
            if (searchTerm) {
                track('search_submitted', {
                    search_term: searchTerm,
                    form_action: form.action || location.href
                }, { immediate: true });
            }
        });
    }

    function setupProductViewTracking() {
        if (!window.FAS_PRODUCT_DATA) {
            return;
        }

        track('product_view', getCurrentProductContext(), { immediate: true });
    }

    function setupProductImpressions() {
        const cards = Array.from(document.querySelectorAll('.product-card, [data-analytics-product-card]'));
        if (cards.length === 0) {
            return;
        }

        function trackCard(card, position) {
            const data = dataFromProductElement(card);
            if (!data.product_id || impressionKeys[data.product_id]) {
                return;
            }

            impressionKeys[data.product_id] = true;
            track('product_impression', Object.assign({
                list_position: position + 1,
                list_name: document.title || 'Product list'
            }, data));
        }

        if (!('IntersectionObserver' in window)) {
            cards.slice(0, 24).forEach(trackCard);
            return;
        }

        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const card = entry.target;
                    observer.unobserve(card);
                    trackCard(card, cards.indexOf(card));
                }
            });
        }, { threshold: 0.5 });

        cards.forEach(card => observer.observe(card));
    }

    function setupBannerViews() {
        const banners = Array.from(document.querySelectorAll('.alert-banner[data-analytics-banner]'));
        if (banners.length === 0) {
            return;
        }

        function trackBannerView(banner) {
            const bannerData = dataFromBannerElement(banner);
            if (!bannerData.banner_id) {
                return;
            }

            const key = location.pathname + ':' + bannerData.banner_id;
            if (bannerViewKeys[key]) {
                return;
            }

            bannerViewKeys[key] = true;
            track('banner_view', bannerData);
        }

        if (!('IntersectionObserver' in window)) {
            banners.forEach(trackBannerView);
            return;
        }

        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    trackBannerView(entry.target);
                }
            });
        }, { threshold: 0.25 });

        banners.forEach(banner => observer.observe(banner));
    }

    function setupShippingSelectionTracking() {
        document.addEventListener('change', event => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || input.name !== 'shipping_method') {
                return;
            }

            track('shipping_rate_selected', {
                shipping_index: input.value,
                shipping_cost: toNumber(input.dataset.cost, 0),
                shipping_service: input.dataset.courier || '',
                event_value: toNumber(input.dataset.cost, 0)
            }, { immediate: true });
        });
    }

    function trackRouteMilestones() {
        const path = location.pathname.replace(/\/+$/, '') || '/';
        if (path === '/cart' || path === '/cart.php') {
            track('cart_view', getCartSummary(), { immediate: true });
        }

        if (path === '/checkout' || path === '/checkout.php') {
            track('checkout_start', getCartSummary(), { immediate: true });
        }
    }

    function sendLifecycleEvent(eventType) {
        updateScrollDepth();
        track(eventType, {
            duration_seconds: getDurationSeconds(),
            scroll_depth: maxScrollDepth
        }, { immediate: true, beacon: true });
    }

    function sendExit() {
        if (exitSent) {
            return;
        }
        exitSent = true;
        const cart = getCartSummary();
        if (cart.cart_items_count > 0 || cart.cart_value > 0) {
            track('cart_abandonment_signal', Object.assign({
                event_name: 'Cart still active on site exit',
                duration_seconds: getDurationSeconds(),
                scroll_depth: maxScrollDepth
            }, cart), { immediate: true, beacon: true });
        }
        sendLifecycleEvent('page_exit');
    }

    window.fasAnalytics = {
        track,
        flush,
        trackCartEvent,
        cartSummary: getCartSummary,
        context: getContext,
        refreshProductImpressions: setupProductImpressions,
        refreshBannerViews: setupBannerViews
    };

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            sendLifecycleEvent('page_hidden');
        } else if (document.visibilityState === 'visible') {
            track('page_resumed', {
                duration_seconds: getDurationSeconds(),
                scroll_depth: maxScrollDepth
            }, { immediate: true });
        }
    });

    window.addEventListener('pagehide', sendExit);
    window.addEventListener('online', () => flush(false));
    window.addEventListener('error', event => {
        reportClientError('analytics', event.message || 'Unhandled browser error', {
            severity: 'error',
            filename: event.filename || '',
            line: event.lineno || 0,
            column: event.colno || 0
        }, event.error || null);
    });
    window.addEventListener('unhandledrejection', event => {
        const reason = event.reason;
        reportClientError('analytics', reason && reason.message ? reason.message : 'Unhandled browser promise rejection', {
            severity: 'error',
            reason: String(reason || '')
        }, reason instanceof Error ? reason : null);
    });

    window.setInterval(() => {
        track('session_heartbeat', {
            event_name: 'Session active duration update',
            duration_seconds: getDurationSeconds(),
            active_page_seconds: getDurationSeconds(),
            scroll_depth: maxScrollDepth
        }, { immediate: true });
    }, 120000);

    document.addEventListener('DOMContentLoaded', () => {
        markAdminSessionIfNeeded();

        const sessionStartedKey = 'fas_analytics_started_' + sessionId;
        if (!storageGet(window.localStorage, sessionStartedKey)) {
            storageSet(window.localStorage, sessionStartedKey, '1');
            track('session_start', {
                event_name: 'Session started',
                session_ttl_days: sessionTtlDays
            }, { immediate: true });
        }

        track('page_view', {
            event_name: 'Page viewed'
        });

        setupScrollTracking();
            setupClickTracking();
            setupSearchTracking();
            setupShippingSelectionTracking();
            setupBannerViews();
            setupProductViewTracking();
            setupProductImpressions();
            trackRouteMilestones();
    });
})();
