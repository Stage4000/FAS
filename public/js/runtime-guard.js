(function () {
    'use strict';

    const ignoredMessages = [
        'Error invoking postMessage: Java object is gone'
    ];

    function messageFrom(value) {
        if (!value) {
            return '';
        }

        if (typeof value === 'string') {
            return value;
        }

        if (value.message) {
            return String(value.message);
        }

        try {
            return String(value);
        } catch (error) {
            return '';
        }
    }

    function shouldIgnore(value) {
        const message = messageFrom(value);

        return ignoredMessages.some(ignoredMessage => message.includes(ignoredMessage));
    }

    window.FASShouldIgnoreRuntimeError = shouldIgnore;

    window.addEventListener('error', event => {
        if (shouldIgnore(event.message) || shouldIgnore(event.error)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    window.addEventListener('unhandledrejection', event => {
        if (shouldIgnore(event.reason)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
})();
