/* Actual header in run.py's disposable PHP fixture, in a loopback-only namespace. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

function contrast(a, b) {
    const luminance = rgb => rgb.reduce((sum, value, i) => {
        const c = value / 255;
        return sum + [0.2126, 0.7152, 0.0722][i] * (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    }, 0);
    const [lo, hi] = [luminance(a), luminance(b)].sort((x, y) => x - y);
    return (hi + 0.05) / (lo + 0.05);
}

// Chromium's own opaque, non-interlaced 8-bit RGB/RGBA PNGs only. No new dependency.
function decodeScreenshot(png) {
    assert(png.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])));
    let width, height, channels;
    const chunks = [];
    for (let offset = 8; offset < png.length;) {
        const length = png.readUInt32BE(offset), type = png.toString('ascii', offset + 4, offset + 8);
        assert(offset + 12 + length <= png.length, 'complete PNG chunk');
        const data = png.subarray(offset + 8, offset + 8 + length);
        if (type === 'IHDR') {
            width = data.readUInt32BE(0); height = data.readUInt32BE(4);
            assert(width > 0 && height > 0 && width * height <= 10000000);
            assert.equal(data[8], 8); assert([2, 6].includes(data[9]));
            assert.deepEqual([...data.subarray(10)], [0, 0, 0]);
            channels = data[9] === 2 ? 3 : 4;
        }
        if (type === 'IDAT') chunks.push(data);
        offset += length + 12;
        if (type === 'IEND') break;
    }
    assert(channels && chunks.length, 'PNG header and pixels');
    const stride = width * channels, raw = zlib.inflateSync(Buffer.concat(chunks));
    assert.equal(raw.length, height * (stride + 1));
    const pixels = Buffer.alloc(height * stride);
    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)];
        assert(filter <= 4, 'known PNG filter');
        for (let x = 0; x < stride; x++) {
            const i = y * stride + x, left = x >= channels ? pixels[i - channels] : 0;
            const above = y ? pixels[i - stride] : 0;
            const corner = y && x >= channels ? pixels[i - stride - channels] : 0;
            const p = left + above - corner;
            const paeth = Math.abs(p - left) <= Math.abs(p - above) && Math.abs(p - left) <= Math.abs(p - corner)
                ? left : Math.abs(p - above) <= Math.abs(p - corner) ? above : corner;
            const predictor = [0, left, above, Math.floor((left + above) / 2), paeth][filter];
            pixels[i] = (raw[y * (stride + 1) + x + 1] + predictor) & 255;
        }
    }
    return {width, height, pixel(x, y) {
        x = Math.floor(x); y = Math.floor(y);
        assert(x >= 0 && y >= 0 && x < width && y < height, 'sample inside screenshot');
        const i = (y * width + x) * channels;
        if (channels === 4) assert.equal(pixels[i + 3], 255, 'opaque screenshot pixel');
        return [...pixels.subarray(i, i + 3)];
    }};
}

// Runs inside the browser. No DOM, style, tabindex or focus mutations.
function focusedGeometry() {
    const el = document.activeElement, r = el.getBoundingClientRect(), s = getComputedStyle(el);
    const rect = b => ({left: b.left, top: b.top, right: b.right, bottom: b.bottom, width: b.width, height: b.height});
    const clipping = [];
    for (let parent = el.parentElement; parent; parent = parent.parentElement) {
        const ps = getComputedStyle(parent), p = parent.getBoundingClientRect();
        if (/(hidden|clip|auto|scroll)/.test(ps.overflowX + ps.overflowY))
            clipping.push({tag: parent.tagName, id: parent.id, x: ps.overflowX, y: ps.overflowY,
                left: p.left + parent.clientLeft, top: p.top + parent.clientTop,
                right: p.left + parent.clientLeft + parent.clientWidth,
                bottom: p.top + parent.clientTop + parent.clientHeight});
    }
    const center = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return {tag: el.tagName, id: el.id, href: el.getAttribute('href'),
        label: el.getAttribute('aria-label') || el.textContent.trim(), rect: rect(r),
        focusVisible: el.matches(':focus-visible'), inNavbar: !!el.closest('.navbar'),
        unobscured: !!center && (center === el || el.contains(center)),
        visible: s.visibility === 'visible' && s.display !== 'none',
        outline: s.outlineStyle, outlineWidth: parseFloat(s.outlineWidth),
        offset: parseFloat(s.outlineOffset), outlineColor: s.outlineColor,
        shadow: s.boxShadow, transition: s.transitionProperty, clipping,
        scroll: {x: scrollX, y: scrollY, visualX: visualViewport?.offsetLeft ?? 0, visualY: visualViewport?.offsetTop ?? 0},
        viewport: {width: innerWidth, height: innerHeight}, documentWidth: document.documentElement.scrollWidth,
        theme: document.documentElement.getAttribute('data-theme') || 'light',
        forced: matchMedia('(forced-colors: active)').matches,
        reduced: matchMedia('(prefers-reduced-motion: reduce)').matches};
}

function parseOutlineColor(color, forced) {
    const match = color.match(/^(rgb|rgba)\((\d+), (\d+), (\d+)(?:, ((?:\d*\.)?\d+))?\)$/);
    assert(match && (match[1] === 'rgba') === (match[5] !== undefined), 'computed RGB/RGBA outline color');
    const rgb = match.slice(2, 5).map(Number), alpha = match[5] === undefined ? 1 : Number(match[5]);
    assert(rgb.every(value => value >= 0 && value <= 255) && alpha > 0 && alpha <= 1, 'valid visible outline color');
    // Chromium may choose a translucent system focus color in forced-colors.
    // The authored indicator must remain opaque outside that UA palette.
    assert(alpha === 1 || forced === true, 'authored outline is opaque');
    return {rgb, alpha};
}

function assertCatalogContent(catalog) {
    assert.equal(catalog.heading, 'All Products', 'destination is the real catalog');
    assert.equal(catalog.method, 'get', 'native catalog search form');
    assert.equal(catalog.action, '/products');
    assert.equal(catalog.inputName, 'search');
}

function adjacentExteriorPixel(point, side) {
    // Choose the first fully external pixel, not an extra pixel farther away.
    // Right/bottom edges at integer coordinates already border that pixel;
    // left/top edges border the preceding pixel. Fractional edges round outward.
    const axis = side === 'left' || side === 'right' ? 0 : 1;
    const pixel = point.map(Math.floor);
    pixel[axis] = side === 'left' || side === 'top'
        ? Math.floor(point[axis]) - 1 : Math.ceil(point[axis]);
    return pixel;
}

function assertOutline(g, screenshot) {
    const r = g.rect, outer = g.offset + g.outlineWidth;
    assert(g.focusVisible && g.visible && g.unobscured, 'keyboard focus is visible and unobscured');
    assert.equal(g.outline, 'solid'); assert.equal(g.outlineWidth, 3); assert.equal(g.offset, 3);
    assert.equal(g.shadow, 'none'); assert.equal(g.transition, 'none', 'focus is immediate, not animated');
    assert(r.width > 0 && r.height > 0 && r.left - outer >= 0 && r.top - outer >= 0 &&
        r.right + outer <= g.viewport.width && r.bottom + outer <= g.viewport.height, 'entire outline inside viewport');
    assert(g.documentWidth <= g.viewport.width + 1, 'no horizontal overflow');
    for (const clip of g.clipping) {
        if (/(hidden|clip|auto|scroll)/.test(clip.x))
            assert(r.left - outer >= clip.left - 0.5 && r.right + outer <= clip.right + 0.5, 'outline not horizontally clipped');
        if (/(hidden|clip|auto|scroll)/.test(clip.y))
            assert(r.top - outer >= clip.top - 0.5 && r.bottom + outer <= clip.bottom + 0.5, 'outline not vertically clipped');
    }
    const png = decodeScreenshot(screenshot);
    assert.equal(png.width, g.viewport.width); assert.equal(png.height, g.viewport.height);
    const {rgb, alpha} = parseOutlineColor(g.outlineColor, g.forced), samples = [];
    // Sample three straight-edge points on each side, avoiding rounded corners.
    for (const fraction of [1 / 3, 0.5, 2 / 3]) {
        const x = r.left + r.width * fraction, y = r.top + r.height * fraction;
        for (const [side, point] of [
            ['left', distance => [r.left - distance, y]], ['right', distance => [r.right + distance, y]],
            ['top', distance => [x, r.top - distance]], ['bottom', distance => [x, r.bottom + distance]],
        ]) {
            const ring = png.pixel(...point(g.offset + g.outlineWidth / 2));
            const outsideCoordinate = adjacentExteriorPixel(point(outer), side);
            const inner = png.pixel(...point(g.offset / 2)), outside = png.pixel(...outsideCoordinate);
            // Compare the actual paint with the system RGBA color composited
            // over both neighboring samples. Contrast still uses actual pixels.
            const expected = [inner, outside].map(background => rgb.map((value, i) =>
                Math.round(alpha * value + (1 - alpha) * background[i])));
            assert(expected.every(color => ring.every((v, i) => Math.abs(v - color[i]) <= 3)),
                `${side} outline painted, not obscured`);
            const ratios = [contrast(ring, inner), contrast(ring, outside)];
            assert(ratios.every(value => value >= 3), `${side} rendered focus contrast >= 3:1`);
            samples.push({side, fraction, ring, inner, outside, outsideCoordinate, expected, ratios});
        }
    }
    return samples;
}

// Synchronous read only: works when page-authored JavaScript/timers are disabled.
function captureReadiness() {
    const focus = document.activeElement;
    const animations = new Set(focus.getAnimations({subtree: true}));
    for (let parent = focus.parentElement; parent; parent = parent.parentElement)
        for (const animation of parent.getAnimations()) animations.add(animation);
    return {fonts: document.fonts.status,
        animations: [...animations].filter(a => a.playState === 'running' || a.pending)
            .map(a => ({state: a.playState, pending: a.pending}))};
}

// Read-only host-timed sampling, not animation-frame observations. No screenshot retries.
async function captureStableOutline(page, geometryReader, options, record = () => {}) {
    const initial = await page.evaluate(geometryReader);
    const identity = g => ({tag: g.tag, id: g.id, href: g.href, label: g.label});
    const requireIdentity = g => assert.deepEqual(identity(g), identity(initial), 'Capture focused target changed during settlement');
    const started = Date.now(), deadline = started + 2000;
    let geometry, previous, readiness, stable = 0, samples = 0;
    for (let count = 0; count < 125 && stable < 3 && Date.now() < deadline; count++) {
        if (count) await new Promise(resolve => setTimeout(resolve, 16)); // Node timer, never in-page.
        readiness = await page.evaluate(captureReadiness);
        geometry = await page.evaluate(geometryReader);
        requireIdentity(geometry);
        samples++;
        const ready = readiness.fonts === 'loaded' && readiness.animations.length === 0;
        stable = ready ? (previous && JSON.stringify(previous) === JSON.stringify(geometry) ? stable + 1 : 1) : 0;
        previous = ready ? geometry : undefined;
    }
    assert(Date.now() <= deadline, 'Capture settlement exceeded 2000ms');
    assert.equal(stable, 3, 'Capture requires ready fonts/animations and three matching timed geometry samples');
    const bytes = await page.screenshot(options); // Exactly one screenshot; never retry a failed sample.
    const after = await page.evaluate(geometryReader);
    const afterReadiness = await page.evaluate(captureReadiness);
    const settlement = {initial, samples, stableObservations: stable, sampleIntervalMs: 16,
        elapsedMs: Date.now() - started, readiness, afterReadiness, sampling: 'host-timed; not animation frames'};
    record({geometry, after, bytes, settlement}); // Preserve evidence before any postshot rejection.
    assert.deepEqual(after, geometry, 'Capture geometry changed across screenshot');
    assert.equal(afterReadiness.fonts, 'loaded', 'Capture fonts changed across screenshot');
    assert.equal(afterReadiness.animations.length, 0, 'Capture animation started across screenshot');
    return {geometry, after, bytes, settlement};
}

module.exports = {focusedGeometry,captureStableOutline,assertOutline};
