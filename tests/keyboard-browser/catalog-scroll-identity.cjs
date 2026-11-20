'use strict';
// Exact CSS-only control. Never accepts a near match or rewrites another asset.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const BEFORE = 'a93225a27b120a97b80fcedb5537d1c033e1214024388721096b3ac6105682b5';
const AFTER = 'e95cade954370b5b003f4d7d863135c4920eb81ef8b582fb76c8c22d955c6ca3';
const BEFORE_WITH_NAVBAR_PALETTE = '7265e9beb0f1eff75e157d1ed1efb1022bf5af2d0a29a4be9a7cb13537ed2081';
const AFTER_WITH_NAVBAR_PALETTE = '43a2d0ccd6d7a0e2da3fce7bdafb6631237f5b8e76a9be1c0fb5362fd6a918da';
const preimages = new Map([[AFTER, BEFORE], [AFTER_WITH_NAVBAR_PALETTE, BEFORE_WITH_NAVBAR_PALETTE]]);
const OLD = '#main-content { scroll-margin-top: 6rem; }';
const NEW = '#main-content, #productsContent { scroll-margin-top: 6rem; }';
const digest = data => crypto.createHash('sha256').update(data).digest('hex');
function baselineCss(data) {
    const before = preimages.get(digest(data));
    assert.ok(before, 'exact reviewed catalog CSS required');
    const text = data.toString('utf8');
    assert.equal(text.split(NEW).length, 2, 'one exact shared offset rule');
    const restored = Buffer.from(text.replace(NEW, OLD));
    assert.equal(digest(restored), before, 'catalog CSS restoration equals exact pinned preimage');
    return restored;
}
module.exports = {BEFORE, AFTER, BEFORE_WITH_NAVBAR_PALETTE, AFTER_WITH_NAVBAR_PALETTE, OLD, NEW, digest, baselineCss};
