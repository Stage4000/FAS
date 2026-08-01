// First-party analytics for Flip and Strip.
(function () {
    'use strict';

    const endpoint = '/api/track-event.php';
    const queue = [];
    const sentScrollDepths = {};
    const impressionKeys = {};
    const pageStartedAt = Date.now();
    let maxScrollDepth = 0;
    let flushTimer = null;
    let exitSent = false;

    function createId(prefix) {
        if (window.crypto && crypto.getRandomValues) {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return prefix + '_' + Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
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

    function getVisitorId() {
        let visitorId = storageGet(localStorage, 'fas_visitor_id');
        if (!visitorId) {
            visitorId = createId('vis');
            storageSet(localStorage, 'fas_visitor_id', visitorId);
        }
        return visitorId;
    }

    function getSessionId() {
        let sessionId = storageGet(sessionStorage, 'fas_session_id');
        if (!sessionId) {
            sessionId = createId('ses');
            storageSet(sessionStorage, 'fas_session_id', sessionId);
            storageSet(sessionStorage, 'fas_landing_page', location.pathname + location.search);
        }
        return sessionId;
    }

    const visitorId = getVisitorId();
    const sessionId = getSessionId();

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
        const ua = navigator.userAgent;
        if (ua.includes('Edg/')) return 'Edge';
        if (ua.includes('Chrome/')) return 'Chrome';
        if (ua.includes('Firefox/')) return 'Firefox';
        if (ua.includes('Safari/') && !ua.includes('Chrome/')) return 'Safari';
        return 'Other';
    }

    function getOS() {
        const ua = navigator.userAgent;
        if (ua.includes('Windows')) return 'Windows';
        if (ua.includes('Mac OS')) return 'macOS';
        if (ua.includes('Android')) return 'Android';
        if (ua.includes('iPhone') || ua.includes('iPad')) return 'iOS';
        if (ua.includes('Linux')) return 'Linux';
        return 'Other';
    }

    function getContext() {
        const params = new URLSearchParams(location.search);
        return {
            page_url: location.href,
            page_path: location.pathname + location.search,
            page_title: document.title,
            landing_page: storageGet(sessionStorage, 'fas_landing_page') || location.pathname + location.search,
            referrer: document.referrer || '',
            utm_source: params.get('utm_source') || '',
            utm_medium: params.get('utm_medium') || '',
            utm_campaign: params.get('utm_campaign') || '',
            utm_term: params.get('utm_term') || '',
            utm_content: params.get('utm_content') || '',
            device_type: getDeviceType(),
            browser: getBrowser(),
            os: getOS(),
            language: navigator.language || '',
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
            screen_width: window.screen ? window.screen.width : 0,
            screen_height: window.screen ? window.screen.height : 0,
            viewport_width: window.innerWidth || 0,
            viewport_height: window.innerHeight || 0
        };
    }

    function getCartItems() {
        if (window.cart && Array.isArray(window.cart.cart)) {
            return window.cart.cart;
        }

        try {
            const stored = JSON.parse(localStorage.getItem('flipandstrip_cart') || '[]');
            return Array.isArray(stored) ? stored : [];
        } catch (error) {
            return [];
        }
    }

    function getCartSummary() {
        const items = getCartItems();
        return items.reduce((summary, item) => {
            const quantity = Number(item.quantity || 0);
            const price = Number(item.price || 0);
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
            'event_name', 'page_url', 'page_path', 'page_title', 'referrer', 'product_id',
            'product_name', 'product_sku', 'category', 'quantity', 'cart_value',
            'event_value', 'scroll_depth', 'duration_seconds'
        ].forEach(key => {
            if (data[key] !== undefined && data[key] !== null && data[key] !== '') {
                fields[key] = data[key];
            }
        });
        return fields;
    }

    function track(eventType, data, options) {
        const eventData = data || {};
        const cartSummary = getCartSummary();
        const event = Object.assign({
            event_type: eventType,
            event_name: eventData.event_name || eventType,
            duration_seconds: eventData.duration_seconds !== undefined ? eventData.duration_seconds : getDurationSeconds(),
            scroll_depth: eventData.scroll_depth !== undefined ? eventData.scroll_depth : maxScrollDepth,
            cart_value: eventData.cart_value !== undefined ? eventData.cart_value : cartSummary.cart_value,
            metadata: Object.assign({}, eventData, { cart: cartSummary })
        }, pickTopLevelFields(eventData));

        queue.push(event);

        if (options && options.immediate) {
            flush(!!options.beacon);
            return;
        }

        scheduleFlush();
    }

    function scheduleFlush() {
        if (queue.length >= 10) {
            flush(false);
            return;
        }

        clearTimeout(flushTimer);
        flushTimer = setTimeout(() => flush(false), 1500);
    }

    function flush(useBeacon) {
        if (queue.length === 0) {
            return Promise.resolve();
        }

        clearTimeout(flushTimer);
        const events = queue.splice(0, queue.length);
        const body = JSON.stringify({
            session_id: sessionId,
            visitor_id: visitorId,
            context: getContext(),
            events
        });

        if (useBeacon && navigator.sendBeacon) {
            const blob = new Blob([body], { type: 'application/json' });
            if (navigator.sendBeacon(endpoint, blob)) {
                return Promise.resolve();
            }
        }

        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body,
            credentials: 'same-origin',
            keepalive: !!useBeacon
        }).catch(() => {
        });
    }

    function extractItem(item) {
        const product = item || {};
        return {
            product_id: product.id || product.product_id || '',
            product_name: product.name || product.product_name || '',
            product_sku: product.sku || product.product_sku || '',
            category: product.category || '',
            quantity: Number(product.quantity || 1),
            event_value: Number(product.price || product.event_value || 0)
        };
    }

    function trackCartEvent(eventType, item, extra) {
        track(eventType, Object.assign(extractItem(item), extra || {}), { immediate: true });
    }

    function updateScrollDepth() {
        const doc = document.documentElement;
        const body = document.body;
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
            requestAnimationFrame(() => {
                updateScrollDepth();
                pending = false;
            });
        }, { passive: true });
        updateScrollDepth();
    }

    function setupClickTracking() {
        document.addEventListener('click', event => {
            const themeToggle = event.target.closest('#themeToggle, #navbarThemeToggle');
            if (themeToggle) {
                track('site_setting_changed', { setting: 'theme', control_id: themeToggle.id }, { immediate: true });
                return;
            }

            if (event.target.closest('.add-to-cart')) {
                return;
            }

            const link = event.target.closest('a[href]');
            if (!link) {
                return;
            }

            const href = link.href;
            if (!href || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                return;
            }

            const url = new URL(href, location.href);
            const text = (link.textContent || link.getAttribute('aria-label') || '').trim().slice(0, 120);
            const isExternal = url.hostname !== location.hostname;
            const productMatch = url.pathname.match(/^\/product\/([^/]+)/);

            if (productMatch) {
                track('product_click', {
                    product_id: productMatch[1],
                    target_url: href,
                    link_text: text
                }, { immediate: true, beacon: true });
            } else {
                track(isExternal ? 'outbound_click' : 'navigation_click', {
                    target_url: href,
                    target_path: url.pathname + url.search,
                    link_text: text
                }, { immediate: true, beacon: isExternal });
            }
        });
    }

    function setupSearchTracking() {
        document.addEventListener('submit', event => {
            const form = event.target;
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
        if (window.FAS_PRODUCT_DATA) {
            track('product_view', window.FAS_PRODUCT_DATA, { immediate: true });
        }
    }

    function setupProductImpressions() {
        const cards = Array.from(document.querySelectorAll('.product-card'));
        if (cards.length === 0) {
            return;
        }

        function trackCard(card, position) {
            const button = card.querySelector('.add-to-cart[data-id]');
            if (!button || impressionKeys[button.dataset.id]) {
                return;
            }

            impressionKeys[button.dataset.id] = true;
            track('product_impression', {
                product_id: button.dataset.id,
                product_name: button.dataset.name || '',
                product_sku: button.dataset.sku || '',
                event_value: Number(button.dataset.price || 0),
                list_position: position + 1
            });
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
        sendLifecycleEvent('page_exit');
    }

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

    setInterval(() => {
        track('session_heartbeat', {
            duration_seconds: getDurationSeconds(),
            scroll_depth: maxScrollDepth
        }, { immediate: true });
    }, 30000);

    window.fasAnalytics = {
        track,
        flush,
        trackCartEvent,
        cartSummary: getCartSummary
    };

    document.addEventListener('DOMContentLoaded', () => {
        const hasStarted = storageGet(sessionStorage, 'fas_analytics_started');
        if (!hasStarted) {
            storageSet(sessionStorage, 'fas_analytics_started', '1');
            track('session_start', { event_name: 'Session started' }, { immediate: true });
        }

        track('page_view', { event_name: 'Page viewed' });
        setupScrollTracking();
        setupClickTracking();
        setupSearchTracking();
        setupProductViewTracking();
        setupProductImpressions();
    });
})();
