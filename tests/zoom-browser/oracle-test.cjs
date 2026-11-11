'use strict';
// Offline controls for physical-pixel sampling; no browser, server or production data.
const assert = require('node:assert/strict'), fs = require('node:fs');
const path = require('node:path'), vm = require('node:vm'), zlib = require('node:zlib');
const source = fs.readFileSync(path.join(__dirname, 'outline.cjs'), 'utf8');
const sandbox = {require, Buffer, module: {exports: {}}};
vm.runInNewContext(source + '\nmodule.exports = {assertOutline, adjacentExteriorPixel, capturePhysicalViewport};', sandbox);
const {assertOutline, adjacentExteriorPixel, capturePhysicalViewport} = sandbox.module.exports;
function crc32(bytes) {
    let crc = 0xffffffff;
    for (const byte of bytes) {
        crc ^= byte;
        for (let i = 0; i < 8; i++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
    }
    return (crc ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
    const name = Buffer.from(type), length = Buffer.alloc(4), crc = Buffer.alloc(4);
    length.writeUInt32BE(data.length); crc.writeUInt32BE(crc32(Buffer.concat([name, data])));
    return Buffer.concat([length, name, data, crc]);
}
function png(width, height, pixel) {
    const header = Buffer.alloc(13); header.writeUInt32BE(width); header.writeUInt32BE(height, 4);
    header[8] = 8; header[9] = 2;
    const rows = Buffer.alloc(height * (width * 3 + 1));
    for (let y = 0; y < height; y++) for (let x = 0; x < width; x++)
        Buffer.from(pixel(x, y)).copy(rows, y * (width * 3 + 1) + 1 + x * 3);
    return Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),
        chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(rows)), chunk('IEND', Buffer.alloc(0))]);
}
function geometry(dpr) {
    return {rect: {left: 20.125, top: 20.125, right: 60.125, bottom: 50.125, width: 40, height: 30},
        viewport: {width: 80, height: 70}, dpr, documentWidth: 80,
        focusVisible: true, visible: true, unobscured: true, outline: 'solid',
        outlineWidth: 3, offset: 3, shadow: 'none', transition: 'none', clipping: [],
        outlineColor: 'rgb(255, 255, 255)', forced: false};
}
function painted(g, change = () => null) {
    const {rect: r, dpr} = g, inside = (x, y, margin) =>
        x >= (r.left - margin) * dpr && x < (r.right + margin) * dpr &&
        y >= (r.top - margin) * dpr && y < (r.bottom + margin) * dpr;
    return png(g.viewport.width * dpr, g.viewport.height * dpr, (x, y) =>
        change(x, y) || (inside(x + .5, y + .5, 6) && !inside(x + .5, y + .5, 3)
            ? [255,255,255] : [32,32,32]));
}
let cases = 0;
function pass(label, callback) {callback(); cases++; console.log('PASS ' + label);}
function reject(label, callback) {pass('reject ' + label, () => assert.throws(callback));}
for (const dpr of [1, 4]) {
    const g = geometry(dpr), good = painted(g);
    pass(dpr + 'x physical outline', () => assert.equal(assertOutline(g, good).length, 12));
    reject(dpr + 'x wrong physical dimensions', () => assertOutline(g, png(81 * dpr, 70 * dpr, () => [32,32,32])));
    reject(dpr + 'x blank screenshot', () => assertOutline(g, png(80 * dpr, 70 * dpr, () => [255,255,255])));
    reject(dpr + 'x missing painted ring', () => assertOutline(g, png(80 * dpr, 70 * dpr, () => [32,32,32])));
    reject(dpr + 'x obscured focus', () => assertOutline({...g, unobscured: false}, good));
    reject(dpr + 'x clipped outline', () => assertOutline({...g, clipping: [{x:'auto', y:'auto',left:18,top:0,right:80,bottom:70}]}, good));
    reject(dpr + 'x inadequate contrast', () => assertOutline(g, painted(g, (x,y) =>
        (x < (g.rect.left - 3) * dpr && x >= (g.rect.left - 6) * dpr) ? null : [220,220,220])));
    const middleY = (g.rect.top + g.rect.height / 2) * dpr;
    const exterior = Array.from(adjacentExteriorPixel([(g.rect.left - 6) * dpr, middleY], 'left'));
    pass(dpr + 'x fractional first exterior coordinate', () =>
        assert.deepEqual(exterior, [Math.floor((g.rect.left - 6) * dpr) - 1, Math.floor(middleY)]));
    // Only the first exterior physical pixel has poor contrast. A CSS-pixel jump would miss it at 4x.
    reject(dpr + 'x first exterior low contrast', () => assertOutline(g, painted(g,
        (x,y) => x === exterior[0] && y === exterior[1] ? [250,250,250] : null)));
}
reject('unreviewed DPR', () => assertOutline({...geometry(1), dpr:2}, painted(geometry(1))));
reject('4x geometry paired with 1x pixels', () => assertOutline(geometry(4), painted(geometry(1))));
async function captureControls() {
    const expected = painted(geometry(4));
    for (const fail of [false, true]) {
        const temp = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'zoom-oracle-'));
        const output = path.join(temp, 'capture.png');
        const calls = [], failure = Error('synthetic capture failure');
        let detached = 0;
        const page = {context: () => ({newCDPSession: async () => ({
            send: async (command, args) => {
                calls.push({command, args});
                if (fail) throw failure;
                return {data: expected.toString('base64')};
            },
            detach: async () => {detached++;}
        })})};
        try {
            if (fail) {
                await assert.rejects(capturePhysicalViewport(page, {path:output, scale:'device'}),
                    error => error === failure);
                assert(!fs.existsSync(output), 'failure must not reuse a prior capture');
            } else {
                const actual = await capturePhysicalViewport(page, {path:output, scale:'device'});
                assert(actual.equals(expected)); assert(fs.readFileSync(output).equals(expected));
            }
            assert.deepEqual(JSON.parse(JSON.stringify(calls)), [{
                command:'Page.captureScreenshot', args:{format:'png',captureBeyondViewport:false}
            }], 'one capture only; no clip or mutating protocol command');
            assert.equal(detached, 1);
            pass(fail ? 'capture failure has no retry/fallback' : 'capture preserves physical PNG bytes', () => {});
        } finally {
            if (fs.existsSync(output)) fs.unlinkSync(output);
            fs.rmdirSync(temp);
        }
    }
}
captureControls().then(() => console.log('PASS ' + cases + ' physical outline/capture controls'))
    .catch(error => {console.error(error); process.exitCode = 1;});
