(function () {
    'use strict';

    const cookieName = 'fas_user_timezone';
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';

    if (timezone) {
        const expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1);
        document.cookie = `${cookieName}=${encodeURIComponent(timezone)}; expires=${expires.toUTCString()}; path=/; SameSite=Lax`;
        document.documentElement.dataset.userTimezone = timezone;
    }

    function readTimestamp(element) {
        return element.getAttribute('datetime')
            || element.dataset.timestamp
            || element.textContent.trim();
    }

    function optionsFor(format) {
        if (format === 'date') {
            return { year: 'numeric', month: 'short', day: 'numeric' };
        }

        if (format === 'time') {
            return { hour: 'numeric', minute: '2-digit' };
        }

        return {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        };
    }

    function normalize(rawValue) {
        if (!rawValue) {
            return null;
        }

        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(rawValue)) {
            return rawValue.replace(' ', 'T') + 'Z';
        }

        return rawValue;
    }

    function convertElement(element) {
        const rawValue = readTimestamp(element);
        const normalized = normalize(rawValue);
        if (!normalized) {
            return;
        }

        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) {
            return;
        }

        element.textContent = date.toLocaleString('en-US', optionsFor(element.dataset.format || 'datetime'));
        element.title = `${date.toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            timeZoneName: 'short'
        })}${timezone ? ` (${timezone})` : ''}`;
    }

    function convertAll() {
        document.querySelectorAll('time.fas-local-time, [data-local-time]').forEach(convertElement);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', convertAll);
    } else {
        convertAll();
    }

    window.FASTimezone = {
        timezone,
        convertAll,
        format(value, format) {
            const date = new Date(normalize(value));
            if (Number.isNaN(date.getTime())) {
                return value || '';
            }

            return date.toLocaleString('en-US', optionsFor(format || 'datetime'));
        }
    };
})();
