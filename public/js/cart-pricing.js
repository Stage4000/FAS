/* Saved carts require deliberate acceptance of current catalog prices. */
(function (root) {
    'use strict';
    const storageKey = 'flipandstrip_cart';
    const acceptanceKey = 'flipandstrip_cart_price_acceptance_v1';
    const changedMessage = 'Your saved cart changed. Reload to check prices again.';
    const snapshot = items => JSON.stringify(items.map(item => [String(item.id), Number(item.quantity), Number(item.price)]));
    function serialize(items) {
        const ids = new Set();
        if (!Array.isArray(items) || items.some(item => {
            if (!item || typeof item !== 'object' || !/^[1-9][0-9]*$/.test(String(item.id)) || ids.has(String(item.id)) ||
                !Number.isSafeInteger(Number(item.quantity)) || Number(item.quantity) < 1 || !Number.isFinite(Number(item.price)) ||
                Number(item.price) < 0 || !Number.isSafeInteger(Math.round(Number(item.price) * 100))) return true;
            ids.add(String(item.id));
            return false;
        })) throw new Error('Review the invalid saved cart before accepting prices.');
        return JSON.stringify(items);
    }
    function requireWritableCart(cart) {
        if (cart.storageUnavailable || cart.savedCartUnreadable || cart.savedCartMalformed) {
            throw new Error('Your saved cart is unavailable. Reload before accepting prices.');
        }
    }
    function capture(cart, storage) {
        requireWritableCart(cart);
        const serialized = serialize(cart.cart);
        const stored = storage.getItem(storageKey);
        const acceptance = storage.getItem(acceptanceKey);
        let saved;
        try { saved = cart.loadCart(); }
        catch (_) { throw new Error('Your saved cart could not be read. Reload before accepting prices.'); }
        requireWritableCart(cart);
        if (serialize(saved) !== serialized || storage.getItem(storageKey) !== stored || storage.getItem(acceptanceKey) !== acceptance) {
            throw new Error(changedMessage);
        }
        return {serialized, stored, acceptance};
    }
    function assertCurrent(cart, storage, expected) {
        requireWritableCart(cart);
        // Include metadata as well as price/quantity: accepting prices must not
        // replace an item added, removed, or edited by another tab.
        if (serialize(cart.cart) !== expected.serialized || storage.getItem(storageKey) !== expected.stored ||
            storage.getItem(acceptanceKey) !== expected.acceptance) throw new Error(changedMessage);
    }
    async function changes(items, lookup) {
        const result = [];
        for (const item of items) {
            const data = await lookup(String(item.id));
            if (!data.exists || !data.active || !Number.isSafeInteger(data.unit_price_cents) || data.unit_price_cents < 1) {
                throw new Error('An item is unavailable. Review your cart before continuing.');
            }
            const previous = Math.round(Number(item.price) * 100);
            if (!Number.isSafeInteger(previous)) throw new Error('Review the invalid saved cart price.');
            if (previous !== data.unit_price_cents) result.push({id:String(item.id),name:String(item.name),before:previous,after:data.unit_price_cents});
        }
        return result;
    }
    function apply(items, updates, expected) {
        if (snapshot(items) !== expected) throw new Error('Your cart changed. Check prices again.');
        return items.map(item => {
            const update = updates.find(change => change.id === String(item.id));
            return update ? {...item, price:update.after / 100} : {...item};
        });
    }
    function accept(cart, updates, expected, storage) {
        assertCurrent(cart, storage, expected);
        const accepted = apply(cart.cart, updates, snapshot(cart.cart));
        serialize(accepted);
        if (typeof cart.isUsableSavedCart === 'function' && !cart.isUsableSavedCart(accepted)) {
            throw new Error('Review the invalid accepted cart before continuing.');
        }
        const base = JSON.parse(expected.stored || '[]');
        serialize(base);
        const prices = accepted.flatMap(item => {
            const original = base.find(candidate => String(candidate.id) === String(item.id));
            const before = Math.round(Number(original.price) * 100);
            const after = Math.round(Number(item.price) * 100);
            return before === after ? [] : [{id:String(item.id),before,after}];
        });
        const serialized = JSON.stringify({version:1,currency:'USD',cart:expected.stored,prices});
        // Write only the acceptance overlay, never the cart. If another tab
        // changes the cart between our read and write, loadCart ignores this
        // overlay because its exact saved-cart value no longer matches.
        storage.setItem(acceptanceKey, serialized);
        if (storage.getItem(storageKey) !== expected.stored || storage.getItem(acceptanceKey) !== serialized) {
            throw new Error(changedMessage);
        }
        cart.cart = accepted;
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {snapshot,changes,apply,capture,assertCurrent,accept};
    if (!root.document) return;
    root.document.addEventListener('DOMContentLoaded', async function () {
        const panel = root.document.getElementById('cart-price-review');
        if (!panel || !root.cart) return;
        let storageRevision = 0;
        root.addEventListener('storage', event => {
            if ((event.storageArea && event.storageArea !== root.localStorage) ||
                (event.key !== null && event.key !== storageKey && event.key !== acceptanceKey)) return;
            storageRevision++;
            panel.hidden = false;
            panel.textContent = changedMessage;
        });
        const lookup = async id => {
            if (!/^[1-9][0-9]*$/.test(id)) throw new Error('Review the invalid saved cart item.');
            const response = await fetch('/api/product-check.php?id='+encodeURIComponent(id), {cache:'no-store'});
            if (!response.ok) throw new Error('Current prices could not be checked. Try again before paying.');
            return response.json();
        };
        async function review() {
            panel.replaceChildren();
            try {
                const expected = capture(root.cart, root.localStorage);
                const revision = storageRevision;
                const checkCurrent = () => {
                    if (revision !== storageRevision) throw new Error(changedMessage);
                    assertCurrent(root.cart, root.localStorage, expected);
                };
                const updates = await changes(root.cart.cart.map(item => ({...item})),lookup);
                checkCurrent();
                if (!updates.length) { panel.hidden=true; return; }
                panel.hidden=false;
                const message=root.document.createElement('p');
                message.textContent='Prices changed since these items were saved. Review and accept the current prices before paying.';panel.append(message);
                const list=root.document.createElement('ul');
                for (const change of updates) {
                    const line=root.document.createElement('li');
                    line.textContent=change.name+': $'+(change.before/100).toFixed(2)+' → $'+(change.after/100).toFixed(2);list.append(line);
                }
                panel.append(list);
                const button=root.document.createElement('button');button.type='button';button.className='btn btn-outline-danger';button.textContent='Accept current prices';panel.append(button);
                button.addEventListener('click',async()=>{
                    button.disabled=true;
                    try {
                        checkCurrent();
                        const fresh=await changes(root.cart.cart.map(item=>({...item})),lookup);
                        checkCurrent();
                        if (JSON.stringify(fresh)!==JSON.stringify(updates)) { await review(); return; }
                        accept(root.cart,fresh,expected,root.localStorage);
                        // A page reload clears shipping estimates and renders the accepted totals.
                        root.location.reload();
                    } catch(error) { panel.textContent=error.message; }
                });
            } catch(error) { panel.hidden=false;panel.textContent=error.message; }
        }
        await review();
    });
})(typeof window !== 'undefined' ? window : globalThis);
