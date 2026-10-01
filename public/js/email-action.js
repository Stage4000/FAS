(() => {
    'use strict';
    const recover=document.currentScript.dataset.mode==='recover';
    const button=document.getElementById('email-action-button');
    const feedback=document.getElementById('email-action-status');
    let action,token;
    function readLink() {
        const params=new URLSearchParams(location.hash.slice(1));
        action=recover?'restore':params.has('unsubscribe')?'unsubscribe':'confirm';
        token=recover?location.hash.slice(1):params.get(action);
        if(!recover)button.textContent=action==='unsubscribe'?'Unsubscribe':'Confirm email signup';
        button.disabled=!/^[a-f0-9]{64}$/.test(token||'');
        feedback.textContent=button.disabled?'This link is incomplete. Open the full link from your email.':'';
    }
    readLink();
    window.addEventListener('hashchange',readLink);
    button.addEventListener('click',async()=>{
        button.disabled=true;
        try {
            const data=await window.FASGrowth.api(action,{token});
            if(recover) {
                // Do not discard an existing cart or add the same quantities twice.
                let current=[];
                try {current=JSON.parse(localStorage.getItem('flipandstrip_cart')||'[]');}catch(_){}
                if(!Array.isArray(current))current=[];
                const merged=new Map(current.filter(x=>x && x.id).map(x=>[String(x.id),x]));
                for(const item of data.items) {
                    const old=merged.get(String(item.id));
                    merged.set(String(item.id),{...item,quantity:Math.min(item.stock,Math.max(item.quantity,Number(old?.quantity)||0))});
                }
                localStorage.setItem('flipandstrip_cart',JSON.stringify([...merged.values()]));
                feedback.textContent=data.items.length?'Available items restored. Opening your cart…':'These saved items are no longer available. You can browse current inventory.';
                if(data.items.length)location.assign('/cart');
            } else feedback.textContent=data.message;
        } catch(error) {feedback.textContent=error.message;button.disabled=false;}
    });
})();
