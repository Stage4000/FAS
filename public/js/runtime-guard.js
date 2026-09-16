(function () {
    'use strict';

    const ignoredMessages = [
        'Error invoking postMessage: Java object is gone'
    ];
    const ignoredSourcePatterns = [
        '/dist/inject_content.js',
        'chrome-extension://',
        'moz-extension://',
        'safari-web-extension://'
    ];

    function messageFrom(value) {
        if (!value) {
            return '';
        }

        if (typeof value === 'string') {
            return value;
        }

        if (value.message || value.stack) {
            return [value.message, value.stack].filter(Boolean).join('\n');
        }

        try {
            return String(value);
        } catch (error) {
            return '';
        }
    }

    function shouldIgnore(value, source) {
        const message = messageFrom(value);
        const sourceText = messageFrom(source);
        const haystack = `${message}\n${sourceText}`.toLowerCase();

        if (ignoredMessages.some(ignoredMessage => message.includes(ignoredMessage))) {
            return true;
        }

        const normalizedMessage = message.toLowerCase();
        const isInjectedSource = ignoredSourcePatterns.some(pattern => haystack.includes(pattern));
        return isInjectedSource
            && (normalizedMessage.includes('illegal invocation') || normalizedMessage === 'script error.');
    }

    window.FASShouldIgnoreRuntimeError = shouldIgnore;

    window.addEventListener('error', event => {
        if (shouldIgnore(event.message, event.filename) || shouldIgnore(event.error, event.filename)) {
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
