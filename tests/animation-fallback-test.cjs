'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/js/animations.js'), 'utf8');

function fixture(supported) {
    const node = initial => {
        const classes = new Set(initial || []);
        return {style: {}, dataset: {}, classList: {
            add: value => classes.add(value), remove: value => classes.delete(value),
            contains: value => classes.has(value),
        }};
    };
    const reveal = node(), card = node(), image = node(['skeleton']);
    image.dataset.src = '/synthetic-deferred-image.jpg';
    const observers = [], callbacks = [];
    const document = {
        addEventListener: (event, callback) => {if (event === 'DOMContentLoaded') callbacks.push(callback);},
        querySelectorAll: selector => ({'.scroll-reveal': [reveal], '.product-card': [card], 'img[data-src]': [image]})[selector] || [],
        getElementById: () => null, createElement: () => ({}), head: {appendChild() {}},
    };
    const context = {document, window: {}, setTimeout() {}};
    if (supported) {
        context.IntersectionObserver = class {
            constructor(callback) {this.callback = callback; this.observed = []; this.removed = []; observers.push(this);}
            observe(node) {this.observed.push(node);}
            unobserve(node) {this.removed.push(node);}
        };
        context.window.IntersectionObserver = context.IntersectionObserver;
    }
    vm.createContext(context);
    vm.runInContext(source, context);
    callbacks.forEach(callback => callback());
    return {context, reveal, card, image, observers};
}

const fallback = fixture(false);
assert(fallback.reveal.classList.contains('revealed'), 'content reveals without IntersectionObserver');
assert(!fallback.card.classList.contains('card-entrance'), 'fallback does not hide cards behind entrance animation');
assert.equal(fallback.image.src, fallback.image.dataset.src, 'deferred image loads without observation');
assert(!fallback.image.classList.contains('skeleton'), 'loaded fallback image loses placeholder styling');
assert.equal(typeof fallback.context.window.showToast, 'function', 'remaining DOM initialization completes');

const normal = fixture(true);
assert.equal(normal.observers.length, 2, 'scroll and image observers retained');
assert(normal.card.classList.contains('card-entrance'), 'supported browsers retain entrance effects');
assert(!normal.reveal.classList.contains('revealed'), 'offscreen reveal remains deferred');
assert.equal(normal.image.src, undefined, 'offscreen image remains deferred');
for (const observer of normal.observers) {
    observer.callback(observer.observed.map(target => ({target, isIntersecting: false})), observer);
    assert.equal(observer.removed.length, 0, 'non-intersecting content stays observed');
    observer.callback(observer.observed.map(target => ({target, isIntersecting: true})), observer);
    assert.equal(observer.removed.length, observer.observed.length, 'revealed content stops observing');
}
assert(normal.reveal.classList.contains('revealed'), 'intersecting content reveals');
assert.equal(normal.image.src, normal.image.dataset.src, 'intersecting image loads');
assert(!normal.image.classList.contains('skeleton'), 'intersecting image loses placeholder styling');
console.log('PASS animation fallback and observer-supported initialization scenarios');
