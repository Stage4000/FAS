'use strict';
const assert = require('node:assert/strict');
const zlib = require('node:zlib');
const {contrast, decodeScreenshot, parseOutlineColor, adjacentExteriorPixel, assertOutline, assertCatalogContent} = require('./navbar.cjs');
let checks = 0;
function crc32(bytes) {
    let crc = 0xffffffff;
    for (const byte of bytes) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
    }
    return (crc ^ 0xffffffff) >>> 0;
}
function png(width, height, channels, pixels, filter = 0) {
    const stride = width * channels, raw = Buffer.alloc((stride + 1) * height);
    for (let y = 0; y < height; y++) {
        raw[y * (stride + 1)] = filter;
        for (let x = 0; x < stride; x++) {
            const i = y * stride + x;
            const a = x >= channels ? pixels[i - channels] : 0;
            const b = y ? pixels[i - stride] : 0;
            const c = y && x >= channels ? pixels[i - stride - channels] : 0;
            let predictor = 0;
            if (filter === 1) predictor = a;
            if (filter === 2) predictor = b;
            if (filter === 3) predictor = Math.floor((a + b) / 2);
            if (filter === 4) {
                const distances = [Math.abs(b - c), Math.abs(a - c), Math.abs(a + b - 2 * c)];
                predictor = [a, b, c][distances.indexOf(Math.min(...distances))];
            }
            raw[y * (stride + 1) + x + 1] = (pixels[i] - predictor + 256) % 256;
        }
    }
    function chunk(type, data) {
        const result = Buffer.alloc(data.length + 12);
        result.writeUInt32BE(data.length); result.write(type, 4); data.copy(result, 8);
        result.writeUInt32BE(crc32(result.subarray(4, data.length + 8)), data.length + 8);
        return result;
    }
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width); header.writeUInt32BE(height, 4); header[8] = 8; header[9] = channels === 3 ? 2 : 6;
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
for (const channels of [3, 4]) for (let filter = 0; filter <= 4; filter++) {
    const pixels = Buffer.from(Array.from({length: 7 * 8 * channels}, (_, i) => channels === 4 && i % 4 === 3 ? 255 : (i * 101 + 73) % 256));
    const result = decodeScreenshot(png(7, 8, channels, pixels, filter));
    for (let y = 0; y < 8; y++) for (let x = 0; x < 7; x++) {
        const offset = (y * 7 + x) * channels;
        assert.deepEqual(result.pixel(x + 0.9, y + 0.1), [...pixels.subarray(offset, offset + 3)]); checks++;
    }
}
assert.equal(contrast([0, 0, 0], [255, 255, 255]), 21); checks++;
assert.equal(contrast([255, 255, 255], [255, 255, 255]), 1); checks++;
assert(contrast([219, 3, 53], [33, 37, 41]) < 3); checks++;
const geometry = {
    rect: {left: 20, top: 20, right: 80, bottom: 60, width: 60, height: 40},
    offset: 3, outlineWidth: 3, focusVisible: true, visible: true, unobscured: true,
    outline: 'solid', outlineColor: 'rgb(255, 255, 255)', shadow: 'none', transition: 'none',
    viewport: {width: 100, height: 80}, documentWidth: 100, clipping: [],
};
function ringScreenshot(ring, background, painted = true, overrides = []) {
    const pixels = Buffer.alloc(100 * 80 * 3);
    for (let y = 0; y < 80; y++) for (let x = 0; x < 100; x++) {
        const onRing = painted && x >= 14 && x < 86 && y >= 14 && y < 66 &&
            !(x >= 17 && x < 83 && y >= 17 && y < 63);
        pixels.set(onRing ? ring : background, (y * 100 + x) * 3);
    }
    for (const [x, y, rgb] of overrides) pixels.set(rgb, (y * 100 + x) * 3);
    return png(100, 80, 3, pixels, 4);
}
const good = ringScreenshot([255, 255, 255], [33, 37, 41]);
assert.equal(assertOutline(geometry, good).length, 12); checks++;
assert.equal(assertOutline({...geometry, outlineColor: 'rgb(0, 0, 0)'}, ringScreenshot([0, 0, 0], [255, 255, 255])).length, 12); checks++;
for (const changes of [
    {focusVisible: false}, {unobscured: false}, {outlineWidth: 2}, {shadow: '0 0 0 3px blue'},
    {transition: 'all'}, {documentWidth: 110}, {viewport: {width: 82, height: 80}},
    {clipping: [{x: 'hidden', y: 'visible', left: 18, right: 100}]},
    {clipping: [{x: 'visible', y: 'hidden', top: 18, bottom: 80}]},
]) { assert.throws(() => assertOutline({...geometry, ...changes}, good)); checks++; }
assert.throws(() => assertOutline(geometry, ringScreenshot([255, 255, 255], [33, 37, 41], false)), /painted/); checks++;
assert.throws(() => assertOutline({...geometry, outlineColor: 'rgb(70, 70, 70)'}, ringScreenshot([70, 70, 70], [33, 37, 41])), /contrast/); checks++;
assert.throws(() => decodeScreenshot(Buffer.from('not a PNG'))); checks++;

for (const [color, background, rendered] of [
    ['rgba(5, 0, 73, 0.8)', [255, 255, 255], [55, 51, 109]],
    ['rgba(0, 230, 255, 0.8)', [0, 0, 0], [0, 184, 204]],
]) {
    const g = {...geometry, forced: true, outlineColor: color};
    assert.equal(assertOutline(g, ringScreenshot(rendered, background)).length, 12); checks++;
    assert.throws(() => assertOutline({...g, forced: false}, ringScreenshot(rendered, background)), /authored outline is opaque/); checks++;
    assert.throws(() => assertOutline(g, ringScreenshot(rendered, background, false)), /painted/); checks++;
    assert.throws(() => assertOutline(g, ringScreenshot([255, 0, 255], background)), /painted/); checks++;
}
assert.throws(() => assertOutline({...geometry, forced: true, outlineColor: 'rgba(5, 0, 73, 0.1)'},
    ringScreenshot([230, 230, 237], [255, 255, 255])), /contrast/); checks++;
for (const color of ['rgba(0, 0, 0, 0)', 'rgba(0, 0, 0, 1.1)', 'rgb(256, 0, 0)',
    'rgb(0, 0, 0, 0.8)', 'rgba(0, 0, 0)', 'rgba(0, 0, 0, NaN)', 'transparent']) {
    assert.throws(() => parseOutlineColor(color, true)); checks++;
}
const catalog = {heading: 'All Products', method: 'get', action: '/products', inputName: 'search'};
assertCatalogContent(catalog); checks++;
for (const key of Object.keys(catalog)) {
    assert.throws(() => assertCatalogContent({...catalog, [key]: undefined})); checks++;
    assert.throws(() => assertCatalogContent({...catalog, [key]: 'wrong-destination'})); checks++;
}
// Pixel-boundary regression: the first completely exterior pixel is directional.
for (const [side, edge, expected] of [
    ['left', [14, 40], [13, 40]], ['right', [86, 40], [86, 40]],
    ['top', [50, 14], [50, 13]], ['bottom', [50, 66], [50, 66]],
    ['left', [14.25, 40.6], [13, 40]], ['right', [86.25, 40.6], [87, 40]],
    ['top', [50.6, 14.25], [50, 13]], ['bottom', [50.6, 66.25], [50, 67]],
    ['left', [13.75, 40.6], [12, 40]], ['right', [85.75, 40.6], [86, 40]],
    ['top', [50.6, 13.75], [50, 12]], ['bottom', [50.6, 65.75], [50, 66]],
]) { assert.deepEqual(adjacentExteriorPixel(edge, side), expected); checks++; }
// CI's Cart outline is intact. A Facebook glyph fringe one pixel beyond the
// actual adjacent background must not be mistaken for the outline's underlay.
for (const [color, background, rendered, fringe] of [
    ['rgba(5, 0, 73, 0.8)', [255, 255, 255], [55, 51, 109], [255, 231, 225]],
    ['rgba(0, 230, 255, 0.8)', [0, 0, 0], [0, 184, 204], [7, 31, 0]],
]) {
    assert.equal(assertOutline({...geometry, forced: true, outlineColor: color},
        ringScreenshot(rendered, background, true, [[87, 40, fringe]])).length, 12); checks++;
}
// Do not skip actual adjacent low contrast or accept an absent/obscured ring.
assert.throws(() => assertOutline(geometry,
    ringScreenshot([255, 255, 255], [33, 37, 41], true, [[86, 40, [255, 255, 255]]])), /contrast/); checks++;
for (const badRing of [[33, 37, 41], [255, 0, 255]]) {
    assert.throws(() => assertOutline(geometry,
        ringScreenshot([255, 255, 255], [33, 37, 41], true, [[84, 40, badRing]])), /painted/); checks++;
}
console.log(`PASS ${checks} navbar helper/negative checks (not browser acceptance)`);
