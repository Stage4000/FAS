'use strict';
// Exact CSS-only control. Never accepts a near match or rewrites another asset.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const BEFORE = 'a93225a27b120a97b80fcedb5537d1c033e1214024388721096b3ac6105682b5';
const AFTER = 'e95cade954370b5b003f4d7d863135c4920eb81ef8b582fb76c8c22d955c6ca3';
const OLD = '#main-content { scroll-margin-top: 6rem; }';
const NEW = '#main-content, #productsContent { scroll-margin-top: 6rem; }';
const digest = data => crypto.createHash('sha256').update(data).digest('hex');
function baselineCss(data) {
    assert.equal(digest(data), AFTER, 'exact reviewed catalog CSS required');
    const text = data.toString('utf8');
    assert.equal(text.split(NEW).length, 2, 'one exact shared offset rule');
    const restored = Buffer.from(text.replace(NEW, OLD));
    assert.equal(digest(restored), BEFORE, 'catalog CSS restoration equals exact pinned preimage');
    return restored;
}
module.exports = {BEFORE, AFTER, OLD, NEW, digest, baselineCss};
