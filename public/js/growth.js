(() => {
    'use strict';
    let csrfPromise;
    let retryAt = 0;
    const activeKey = 'fas_cart_reminder_active';
    function cooldown(response) {
        if(response.status===429 || response.status===503)retryAt=Date.now()+Math.max(1,Math.min(86400,Number(response.headers.get('Retry-After'))||30))*1000;
    }
    function active(value) {
        try {
            if (value === true) sessionStorage.setItem(activeKey,'1');
            else if (value === false) sessionStorage.removeItem(activeKey);
            return sessionStorage.getItem(activeKey)==='1';
        } catch (_) { return false; }
    }
    async function api(action, fields={}) {
        if (Date.now()<retryAt) throw new Error('Please wait '+Math.ceil((retryAt-Date.now())/1000)+' seconds before trying again.');
        if (!csrfPromise) {
            csrfPromise=fetch('/api/growth.php',{credentials:'same-origin',cache:'no-store'})
                .then(async response=>{cooldown(response);const data=await response.json();if(!response.ok || !data.csrf)throw new Error(data.error||'Please try again shortly.');return data.csrf;})
                .catch(error=>{csrfPromise=null;throw error;});
        }
        const response=await fetch('/api/growth.php',{method:'POST',credentials:'same-origin',cache:'no-store',
            headers:{'Content-Type':'application/json'},body:JSON.stringify({...fields,action,csrf:await csrfPromise})});
        cooldown(response);
        const data=await response.json();
        if(response.status===403)csrfPromise=null;
        if(!response.ok || !data.success)throw new Error(data.error||'Please try again shortly.');
        return data;
    }
    function items() {
        const cart=typeof window.getCheckoutItems==='function'?window.getCheckoutItems():(window.cart?.cart||[]);
        return cart.map(item=>({id:item.id,quantity:item.quantity}));
    }
    function status(node,message,error=false) {
        node.textContent=message;
        node.className='small mt-2 '+(error?'text-danger':'text-success');
    }
    function track(name) { window.fasAnalytics?.track(name,{}); } // Never send email addresses to analytics.
    document.addEventListener('DOMContentLoaded',()=>{
        document.querySelectorAll('[data-newsletter-signup],[data-email-cart]').forEach(form=>{
            form.addEventListener('submit',async event=>{
                event.preventDefault();
                if(!form.reportValidity())return;
                const button=form.querySelector('[type=submit]');
                const feedback=form.querySelector('[data-growth-status]');
                const cart=form.hasAttribute('data-email-cart');
                button.disabled=true;status(feedback,'Saving…');
                try {
                    const data=await api(cart?'save_cart':'signup',{email:form.elements.email.value.trim(),consent:form.elements.consent.checked,
                        ...(cart?{items:items(),send_now:true}:{})});
                    if(cart)active(true);
                    track(cart?'cart_saved':'newsletter_requested');
                    status(feedback,data.message);form.reset();
                } catch(error) {status(feedback,error.message,true);}
                finally {button.disabled=false;}
            });
        });
        const opt=document.getElementById('cart-reminder-optin');
        const checkout=document.getElementById('checkout-form');
        const feedback=document.getElementById('cart-reminder-status');
        if(opt && checkout && feedback) {
            let last='';let sequence=Promise.resolve();
            const save=()=>{
                // Serialize toggles: an older opt-in must never race after a later opt-out.
                const selected=opt.checked;
                const email=checkout.elements.email.value.trim();
                const snapshot=items();
                sequence=sequence.catch(()=>{}).then(async()=>{
                    if(!selected) {await api('stop_cart');active(false);last='';status(feedback,'Cart reminders are off.');return;}
                    if(!checkout.elements.email.checkValidity() || !email)return;
                    const signature=JSON.stringify([email,snapshot]);
                    if(signature===last)return;
                    const data=await api('save_cart',{email,items:snapshot,consent:true,send_now:false});
                    active(true);last=signature;status(feedback,data.message);
                }).catch(error=>status(feedback,error.message,true));
            };
            opt.addEventListener('change',save);
            checkout.elements.email.addEventListener('blur',()=>{if(opt.checked)save();});
        }
    });
    let updateTimer;
    document.addEventListener('fas:cart-changed',()=>{
        if(!active())return;
        clearTimeout(updateTimer);
        updateTimer=setTimeout(()=>api('update_cart',{items:items()}).catch(()=>{}),1500);
    });
    window.FASGrowth={api};
})();
