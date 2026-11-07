'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '../public/js/theme-toggle.js'), 'utf8');
let passed = 0;

function fixture(options = {}) {
    const state = {
        saved: options.saved ?? null,
        propertyFails: !!options.propertyFails,
        getFails: !!options.getFails,
        setFails: !!options.setFails,
        dark: !!options.dark,
        theme: options.initialTheme || 'light',
        writes: 0
    };
    const buttons = {};
    for (const id of options.buttons || ['themeToggle', 'navbarThemeToggle']) {
        const classes = new Set(['fas', 'fa-moon']);
        const icon = options.noIcons ? null : {
            classList: {add: c => classes.add(c), remove: c => classes.delete(c)}
        };
        const handlers = {};
        buttons[id] = {classes, icon, handlers,
            querySelector: () => icon,
            addEventListener: (type, callback) => { handlers[type] = callback; }
        };
    }
    function visual(expected) {
        assert.equal(state.theme, expected);
        for (const button of Object.values(buttons)) {
            if (!button.icon) continue;
            assert.equal(button.classes.has('fa-sun'), expected === 'dark');
            assert.equal(button.classes.has('fa-moon'), expected === 'light');
            assert.ok(button.classes.has('fas'));
        }
    }
    const storage = {
        getItem(key) {
            assert.equal(key, 'theme');
            if (state.getFails) throw new Error('getItem unavailable');
            return state.saved;
        },
        setItem(key, value) {
            assert.equal(key, 'theme');
            // A storage exception must never leave either icon behind the UI.
            visual(value);
            state.writes++;
            if (state.setFails) throw new Error('setItem unavailable');
            state.saved = value;
        }
    };
    const mediaHandlers = [];
    const media = {get matches() { return state.dark; },
        addEventListener(type, callback) {
            assert.equal(type, 'change');
            mediaHandlers.push(callback);
        }
    };
    const window = {
        matchMedia(query) {
            assert.equal(query, '(prefers-color-scheme: dark)');
            return media;
        }
    };
    const getStorage = () => {
        if (state.propertyFails) throw new Error('localStorage SecurityError');
        return storage;
    };
    Object.defineProperty(window, 'localStorage', {get: getStorage});
    const context = {window, document: {
        getElementById: id => buttons[id] || null,
        documentElement: {
            setAttribute(name, value) { assert.equal(name, 'data-theme'); state.theme = value; },
            getAttribute(name) { assert.equal(name, 'data-theme'); return state.theme; }
        }
    }};
    Object.defineProperty(context, 'localStorage', {get: getStorage});
    vm.runInNewContext(source, context, {filename: 'theme-toggle.js'});
    assert.equal(mediaHandlers.length, 1);
    for (const button of Object.values(buttons)) assert.equal(typeof button.handlers.click, 'function');
    return {state, visual,
        click(id = 'themeToggle') { buttons[id].handlers.click(); },
        system(dark) { state.dark = dark; mediaHandlers.forEach(callback => callback({matches: dark})); }
    };
}

function test(name, run) {
    run();
    passed++;
    console.log('PASS ' + name);
}

for (const dark of [false, true]) {
    test('system initialization ' + (dark ? 'dark' : 'light'), () => fixture({dark}).visual(dark ? 'dark' : 'light'));
    for (const failure of ['propertyFails', 'getFails']) {
        test(failure + ' initialization and manual choice, system=' + dark, () => {
            const f = fixture({dark, [failure]: true, setFails: true});
            f.visual(dark ? 'dark' : 'light');
            f.click();
            f.visual(dark ? 'light' : 'dark');
            f.system(!dark);
            f.system(dark);
            f.visual(dark ? 'light' : 'dark');
            f.click('navbarThemeToggle');
            f.visual(dark ? 'dark' : 'light');
        });
    }
    for (const saved of ['light', 'dark']) {
        test('saved ' + saved + ' overrides system=' + dark, () => {
            const f = fixture({saved, dark});
            f.visual(saved);
            f.system(!dark);
            f.visual(saved);
        });
    }
}
test('both controls keep icons and storage synchronized', () => {
    const f = fixture(); f.click(); f.visual('dark'); assert.equal(f.state.saved, 'dark');
    f.click('navbarThemeToggle'); f.visual('light'); assert.equal(f.state.saved, 'light');
});
test('system changes apply without saved preference', () => {
    const f = fixture(); f.system(true); f.visual('dark'); f.system(false); f.visual('light');
    assert.equal(f.state.writes, 0);
});
test('system changes still work with inaccessible storage and no manual choice', () => {
    const f = fixture({propertyFails: true}); f.system(true); f.visual('dark'); f.system(false); f.visual('light');
});
for (const saved of [null, 'light', 'dark']) {
    test('failed write wins over stale stored value ' + saved, () => {
        const f = fixture({saved, setFails: true}); f.click();
        const manual = f.state.theme;
        f.system(true); f.system(false); f.visual(manual);
        assert.equal(f.state.saved, saved);
    });
}
test('clearing a successful manual save resumes system changes', () => {
    const f = fixture(); f.click(); f.state.saved = null; f.system(false); f.visual('light');
    f.system(true); f.visual('dark');
});
test('clearing a pre-existing saved theme resumes system changes', () => {
    const f = fixture({saved: 'light'}); f.state.saved = null; f.system(true); f.visual('dark');
});
test('external preference changes are reread when storage works', () => {
    const f = fixture(); f.state.saved = 'light'; f.system(true); f.visual('light');
    f.state.saved = null; f.system(false); f.system(true); f.visual('dark');
});
test('failed read retains the last known saved preference', () => {
    const f = fixture({saved: 'light'}); f.state.getFails = true; f.system(true); f.visual('light');
    f.state.getFails = false; f.state.saved = null; f.system(false); f.system(true); f.visual('dark');
});
test('property becomes unavailable after a successful manual save', () => {
    const f = fixture(); f.click(); f.state.propertyFails = true; f.system(false); f.visual('dark');
    f.click(); f.visual('light'); f.system(true); f.visual('light');
});
test('successful retry drops transient preference so clearing works again', () => {
    const f = fixture({setFails: true}); f.click(); f.visual('dark');
    f.state.setFails = false; f.click(); f.visual('light'); assert.equal(f.state.saved, 'light');
    f.state.saved = null; f.system(true); f.visual('dark');
});
test('read fails but write succeeds then recovered clear is honored', () => {
    const f = fixture({getFails: true}); f.click(); f.system(false); f.visual('dark');
    f.state.getFails = false; f.state.saved = null; f.system(true); f.system(false); f.visual('light');
});
for (const buttons of [[], ['themeToggle'], ['navbarThemeToggle']]) {
    test('optional controls: ' + buttons.join(','), () => {
        const f = fixture({buttons}); f.system(true); f.visual('dark');
        if (buttons.length) { f.click(buttons[0]); f.visual('light'); }
    });
}
test('missing icons do not break controls', () => {
    const f = fixture({noIcons: true}); f.click(); f.visual('dark'); f.click('navbarThemeToggle'); f.visual('light');
});
test('initial saved light synchronizes a pre-existing dark attribute', () => {
    fixture({initialTheme: 'dark', saved: 'light', dark: true}).visual('light');
});
console.log(`${passed} theme behavior cases passed.`);
