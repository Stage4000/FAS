(() => {
    'use strict';
    const recover=document.currentScript.dataset.mode==='recover';
    const button=document.getElementById('email-action-button');
    const feedback=document.getElementById('email-action-status');
    let action,token,linkHash;
    let linkGeneration=0;
    const savedTargets=new Set();
    // Keep the current loader predicate exact; the local parity test guards drift.
    function isUsableCartSnapshot(cart) {
        if (!Array.isArray(cart)) return false;
        let total = 0;
        let count = 0;
        return cart.every(item => {
            if (!item || typeof item !== 'object' || Array.isArray(item) ||
                !Number.isInteger(item.quantity) || item.quantity < 1 ||
                !Number.isFinite(item.price) || item.price < 0) return false;
            // Match existing consumer coercions without changing any saved fields.
            try {
                for (const field of ['id', 'name', 'image', 'image_alt', 'sku']) {
                    String(item[field] || '');
                }
                String(item.stock || 999);
                for (const field of ['stock', 'weight', 'length', 'width', 'height']) {
                    Number(item[field] || 1);
                }
            } catch (error) {
                return false;
            }
            total += item.price * item.quantity;
            count += item.quantity;
            return Number.isFinite(total) && Number.isFinite(count);
        });
    }

    function hasDistinctMergeKeys(items) {
        const keys=new Set();
        return items.every(item=>{
            if(!item.id)return false;
            const key=String(item.id);
            if(!key || keys.has(key))return false;
            keys.add(key);
            return true;
        });
    }
    function deferRestore(message) {
        feedback.textContent='Restoration paused. '+message+' This page has not changed your saved cart.';
        button.disabled=false;
    }
    function presentSavedCart(message) {
        button.disabled=true;
        feedback.textContent=message+' ';
        const cartLink=document.createElement('a');
        cartLink.href='/cart';
        cartLink.textContent='Open your cart';
        feedback.appendChild(cartLink);
    }
    function readLink() {
        const nextHash=location.hash;
        if(nextHash===linkHash)return;
        linkHash=nextHash;
        linkGeneration++;
        const params=new URLSearchParams(linkHash.slice(1));
        action=recover?'restore':params.has('unsubscribe')?'unsubscribe':'confirm';
        token=recover?linkHash.slice(1):params.get(action);
        if(!recover)button.textContent=action==='unsubscribe'?'Unsubscribe':'Confirm email signup';
        button.disabled=!/^[a-f0-9]{64}$/.test(token||'');
        feedback.textContent=button.disabled?'This link is incomplete. Open the full link from your email.':'';
        if(recover && savedTargets.has(linkHash)) {
            presentSavedCart('These items have already been saved locally by this page.');
        }
    }
    readLink();
    window.addEventListener('hashchange',readLink);
    button.addEventListener('click',async()=>{
        // A fragment can change before its queued hashchange event arrives.
        if(location.hash!==linkHash)readLink();
        if(button.disabled)return;
        const generation=linkGeneration;
        const requestHash=location.hash;
        const isCurrent=()=>generation===linkGeneration && location.hash===requestHash;
        button.disabled=true;
        let didSave=false;
        try {
            const data=await window.FASGrowth.api(action,{token});
            if(!isCurrent())return;
            if(recover) {
                if(!data || !Array.isArray(data.items)) {
                    deferRestore('The saved-link items could not be safely used.');
                    return;
                }
                if(data.items.length===0) {
                    feedback.textContent='These saved items are no longer available. This page has not changed your existing cart.';
                    return;
                }
                let originalRaw,current;
                try {
                    originalRaw=localStorage.getItem('flipandstrip_cart');
                } catch(error) {
                    deferRestore('Your existing cart could not be read.');
                    return;
                }
                try {
                    current=originalRaw===null?[]:JSON.parse(originalRaw);
                } catch(error) {
                    deferRestore('Your existing saved cart could not be safely used; all original data, including any usable items, is left untouched by this page.');
                    return;
                }
                if(!isUsableCartSnapshot(current)) {
                    deferRestore('Your existing saved cart could not be safely used; all original data, including any usable items, is left untouched by this page.');
                    return;
                }
                if(!hasDistinctMergeKeys(current)) {
                    deferRestore('Your existing cart contains items that cannot be merged without losing data.');
                    return;
                }
                if(!isUsableCartSnapshot(data.items) || !hasDistinctMergeKeys(data.items)) {
                    deferRestore('The saved-link items cannot be merged safely.');
                    return;
                }
                const merged=new Map(current.map(x=>[String(x.id),x]));
                for(const item of data.items) {
                    const old=merged.get(String(item.id));
                    merged.set(String(item.id),{...item,quantity:Math.min(item.stock,Math.max(item.quantity,Number(old?.quantity)||0))});
                }
                const proposed=[...merged.values()];
                if(!isUsableCartSnapshot(proposed)) {
                    deferRestore('The complete restored cart would not be usable.');
                    return;
                }
                if(!isCurrent())return;
                localStorage.setItem('flipandstrip_cart',JSON.stringify(proposed));
                didSave=true;
                savedTargets.add(requestHash);
                feedback.textContent='Available items restored. Opening your cart…';
                location.assign('/cart');
            } else feedback.textContent=data.message;
        } catch(error) {
            if(!isCurrent())return;
            if(didSave) {
                presentSavedCart('Your cart was saved locally, but this page could not open it.');
                return;
            }
            feedback.textContent=error.message;button.disabled=false;
        }
    });
})();
