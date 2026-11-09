'use strict';const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
/* Pure exact-source VM/cart-wrapper lifecycle checks; no browser/server/network. */
const path=require('node:path'),zlib=require('node:zlib'),crypto=require('node:crypto');
const digest=b=>crypto.createHash('sha256').update(b).digest('hex');
const flag=process.argv.indexOf('--source-root'),root=path.resolve(flag<0?path.join(__dirname,'..'):process.argv[flag+1]);
const source=fs.readFileSync(path.join(root,'public/js/main.js'),'utf8');assert.equal(digest(source),'d4ed1e5bc6e6eef56b50bac94065609602773788e04868bdbf18173fbfb2046e');
const manifest=JSON.parse(fs.readFileSync(path.join(root,'tests/keyboard-browser/malformed-sources.json')));
const before=zlib.gunzipSync(Buffer.from(manifest.files['public/js/main.js'].baseline_gzip_b64,'base64')).toString('utf8');assert.equal(digest(before),'3a38e022e228f573c06f5272fe80f56754a438fb40e2ed699f59990d2817abf2');
const capBlock="                const cappedQuantity = Number(stockLimit);\n                if (!Number.isInteger(cappedQuantity) || cappedQuantity < 1) {\n                    return;\n                }\n                item.quantity = cappedQuantity;";assert.equal(source.split(capBlock).length,2);const guardOnly=source.replace(capBlock,'                item.quantity = stockLimit;');assert.equal(digest(guardOnly),'f2a8bb3dc24e98bedf2eeb1cbadcf27f58ff5ab946e80afdc4b974317f969458');
const sources={baseline:before,candidate:source,'hydration-only':guardOnly};
const cartSource=fs.readFileSync(path.join(root,'cart.php'),'utf8');assert.equal(digest(cartSource),'e3c6f6ee0b91ed5c849ef38bb314abbcdb69cbfef5bcb70bdb58c124495f3d60');
const checkoutSource=fs.readFileSync(path.join(root,'checkout.php'),'utf8');assert.equal(digest(checkoutSource),'3bca0a62c24cd35249d37dc5a9e54cf7bb15b54258da1f8496bf95539501c888');
const shippingSource=fs.readFileSync(path.join(root,'public/js/shipping-estimator.js'),'utf8');assert.equal(digest(shippingSource),'246cf147b1e467abc143ce00c963b44396428684bb20b67f688aa59628093156');
let malformedReport,stockReport;
const seed=[{id:'1',name:'Synthetic <part>',sku:'SYN',price:10,quantity:2,stock:5,custom:{retain:true}},{id:'2',name:'Other',price:7,quantity:1,stock:8}];
function fixture(variant,mode,ready='loading',raw=JSON.stringify(seed)){
 const elements={},listeners={},events=[],timers=[],warnings=[],toasts=[];let saved=raw,reads=0,writes=0,getters=0,fail=mode;
 function element(){let text='',html='';return {style:{},attrs:{},classList:{add(){},remove(){}},addEventListener(){},setAttribute(k,v){this.attrs[k]=v},get textContent(){return text},set textContent(v){text=String(v);html=text.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;')},get innerHTML(){return html},set innerHTML(v){html=v}}}
 for(const id of ['cart-count','cart-items-container','empty-cart-message','empty-cart-title','empty-cart-description','checkout-btn','cart-subtotal','cart-total','cart-shipping-estimate','cart-shipping-estimate-result'])elements[id]=element();
 elements['empty-cart-title'].textContent='Your cart is empty';
 const doc={readyState:ready,body:null,getElementById:id=>elements[id]||null,querySelector:()=>null,querySelectorAll:()=>[],createElement:()=>element(),addEventListener(type,fn){(listeners[type]??=[]).push(fn)},dispatchEvent(e){events.push(e.type)}};
 const mount=()=>doc.body={prepend(el){assert(!elements[el.id]);elements[el.id]=el;warnings.push(el)}};
 if(ready!=='loading')mount();
 const storage={getItem(){reads++;if(fail==='get')throw Error('read denied');return saved},setItem(k,v){writes++;assert.equal(k,'flipandstrip_cart','no backup keys');if(fail==='set')throw Error('write denied');saved=v}};
 const ctx={document:doc,window:{showToast:(message,type)=>toasts.push({message,type})},CustomEvent:class{constructor(type){this.type=type}},setTimeout(fn){timers.push(fn)},clearTimeout(){},alert(){throw Error('unexpected alert')},confirm:()=>true};
 Object.defineProperty(ctx,'localStorage',{get(){getters++;if(fail==='getter')throw Error('getter denied');return storage}});
 vm.createContext(ctx);let error;try{vm.runInContext(sources[variant],ctx);const page=cartSource.match(/<script>([\s\S]*?)<\/script>/)[1];vm.runInContext(page,ctx)}catch(e){error=e.message}
 return {ctx,doc,elements,events,warnings,toasts,error,ready(){if(!doc.body)mount();doc.readyState='interactive';for(const fn of listeners.DOMContentLoaded||[])fn()},flush(){while(timers.length)timers.shift()()},recover(){fail='normal'},denyGetter(){fail='getter'},replaceSaved(value){saved=value},state:()=>({saved,reads,writes,getters})};
}
const shippingConsumer=shippingSource.match(/    function getCartEstimateItems\(\) \{[\s\S]*?\n    \}/)[0];
const checkoutExpressions=[checkoutSource.match(/value:\s*(item\.price\.toFixed\(2\))/)[1],checkoutSource.match(/quantity:\s*(item\.quantity\.toString\(\))/)[1]];
let checks=0;const rows=[];
function start(variant,raw,mode='normal',ready='loading'){
 const f=fixture(variant,mode,ready,raw);try{if(f.error)throw Error(f.error);f.ready();f.flush()}catch(e){f.failure=e.message}return f;
}
const good={id:'1',price:10,quantity:2,name:'Usable neighbor',custom:{preserve:['every',1]}},other={id:2,price:7,quantity:1};
const wrapped=items=>' \n'+JSON.stringify(items)+'\t ';
const invalid=[
 ['malformed-json','[{'],['empty-string',''],['whitespace',' \n\t'],['null-shape','null'],['object-shape','{}'],['number-shape','42'],['string-shape','"cart"'],['bool-shape','false'],
 ['null-item',wrapped([null,good])],['array-item',wrapped([[],good])],['primitive-item',wrapped([1,good])],
 ...['quantity','price'].flatMap(field=>[['null',null],['missing',undefined],['numeric-string','2'],['boolean',true],['object',{}]].map(([tag,value])=>{const bad={...good,[field]:value};return [field+'-'+tag,wrapped([bad,other])]})),
 ['quantity-zero',wrapped([{...good,quantity:0},other])],['quantity-negative',wrapped([{...good,quantity:-2},other])],['quantity-fraction',wrapped([{...good,quantity:1.5},other])],['price-negative',wrapped([{...good,price:-1},other])],
 ['quantity-nonfinite','[{"id":"bad","price":10,"quantity":1e400},'+JSON.stringify(good)+']'],['price-nonfinite','[{"id":"bad","price":1e400,"quantity":1},'+JSON.stringify(good)+']'],
 ['line-overflow',wrapped([{...good,price:1e308,quantity:2},other])],['sum-overflow',wrapped([{...good,price:1e308,quantity:1},{...other,price:1e308,quantity:1}])],['count-overflow',wrapped([{...good,price:0,quantity:1e308},{...other,price:0,quantity:1e308}])],
 ['legacy-merge-missing-price',wrapped([{id:99,quantity:1},good])],
 ...['id','name','image','image_alt','sku','stock','weight','length','width','height'].map(field=>['unsafe-'+field,wrapped([{...good,[field]:{toString:null}},other])])
];
for(const [name,raw]of invalid){
 const f=start('candidate',raw);assert(!f.failure,name+': '+f.failure);const c=f.ctx.window.cart;assert.equal(c.cart.length,0);assert(c.storageUnavailable&&c.savedCartUnreadable&&c.savedCartMalformed);assert.equal(f.elements['empty-cart-title'].textContent,'No items added in this page session');assert.equal(f.elements['checkout-btn'].style.display,'none');assert.equal(f.elements['cart-total'].textContent,'$0.00');assert.equal(f.state().saved,raw);assert.equal(f.state().writes,0);assert.equal(f.events.length,0);assert.equal(f.warnings.length,1);const w=f.warnings[0];assert.match(w.textContent,/This page has kept all original saved data unchanged, including any usable items/);assert.match(w.textContent,/None of those saved items were loaded/);assert.match(w.textContent,/page session only/);assert.match(w.textContent,/not being saved/);assert.equal(w.attrs.role,'status');assert.equal(w.attrs['aria-live'],'polite');assert.equal(w.attrs['aria-atomic'],'true');assert.doesNotMatch(w.textContent,/repaired|reset|cart is empty/);
 c.addItem({id:'session',price:3,stock:5});f.ctx.displayCartItems();f.ctx.updateItemQuantity('session','2');f.ctx.updateItemQuantity('session','');assert.equal(c.cart[0].quantity,2);assert.equal(f.elements['cart-total'].textContent,'$6.00');assert.equal(f.elements['cart-count'].textContent,'2');assert.equal(f.events.length,2);assert.equal(f.toasts.length,0);f.recover();f.ctx.updateItemQuantity('session','3');c.removeItem('session');c.clearCart();c.addItem({id:'later',price:4});f.flush();assert.equal(f.state().saved,raw,'every original byte retained after all edits/recovery');assert.equal(f.state().writes,0);assert.equal(f.warnings.length,1);assert(c.savedCartMalformed);assert(f.events.every(e=>e==='fas:cart-changed'));
 const baseline=start('baseline',raw);let detected=!!baseline.failure;const baselineEvidence=baseline.failure?['initializer/renderer threw']:[];const b=baseline.ctx.window.cart;
 if(b){try{const bad=b.cart.find(i=>!i||typeof i!=='object'||Array.isArray(i)||!Number.isInteger(i.quantity)||i.quantity<1||!Number.isFinite(i.price)||i.price<0);detected ||= !!bad||!Number.isFinite(b.getTotal())||!Number.isFinite(b.getItemCount())}catch{detected=true}}
 if(b){try{vm.runInContext('window.cart.cart.map(item=>['+checkoutExpressions.join(',')+'])',baseline.ctx)}catch{detected=true;baselineEvidence.push('exact checkout item formatting threw')}}
 if(b){try{vm.runInContext(shippingConsumer+';getCartEstimateItems()',baseline.ctx)}catch{detected=true;baselineEvidence.push('exact shipping consumer coercion threw')}}
 // Empty strings were historically treated as absent; new refusal preserves their bytes.
 if(name==='empty-string'){assert(!baseline.failure);assert.equal(b.cart.length,0);b.addItem({id:'old',price:1});assert.notEqual(baseline.state().saved,raw);detected=true}
 assert(detected,'required baseline defect control '+name);assert.equal(baseline.warnings.length,0,'baseline did not diagnose malformed saved data');rows.push({name,baselineFailure:baseline.failure||null,baselineDetected:detected,baselineEvidence:baselineEvidence.length?baselineEvidence:['unsafe stored numeric/writer contract; not necessarily an initialization exception'],candidate:'session-only; whole original string retained',raw_sha256:require('node:crypto').createHash('sha256').update(raw).digest('hex')});checks++;
}
const valid=[['absent',null],['empty-array','[]'],['formatted-empty',' \n[]\t'],['minimal-old',wrapped([{id:42,price:5,quantity:2}])],['missing-id',wrapped([{price:5,quantity:2}])],['invalid-id-supported',wrapped([{id:'not/a/product',price:5,quantity:2}])],['zero-price',wrapped([{id:1,price:0,quantity:1}])],['decimal-price',wrapped([{id:'1',price:1.23,quantity:2}])],['metadata',wrapped([{...good,custom:{toString:null,nested:[1,null,{keep:true}]},product_id:'old',product_price:'metadata-only',unknown:['preserve']},other])],['optional-stock-string',wrapped([{...good,stock:'3',weight:'2',length:'3',width:'4',height:'5'}])],['optional-missing',wrapped([good,other])],['optional-coercible',wrapped([{...good,name:{legacy:'data'},image:[],image_alt:[],sku:0,weight:null,stock:3}])]];
for(const [name,raw]of valid){
 const a=start('baseline',raw),b=start('candidate',raw);assert(!a.failure&&!b.failure,name);assert.equal(b.ctx.window.cart.storageUnavailable,undefined);assert.equal(b.warnings.length,0);assert.equal(b.state().saved,raw);assert.equal(b.state().writes,0);assert.equal(JSON.stringify(b.ctx.window.cart.cart),JSON.stringify(a.ctx.window.cart.cart));assert.equal(b.elements['cart-items-container'].innerHTML,a.elements['cart-items-container'].innerHTML);assert.equal(b.elements['cart-total'].textContent,a.elements['cart-total'].textContent);assert.equal(b.elements['cart-count'].textContent,a.elements['cart-count'].textContent);
 for(const f of [a,b]){f.ctx.window.cart.addItem({id:'new',price:3,stock:5});f.ctx.updateItemQuantity('new','2')}
 assert.equal(b.state().saved,a.state().saved);assert.deepEqual(b.events,a.events);assert.deepEqual(b.toasts,a.toasts);rows.push({name,candidate:'exact baseline hydration/render/edit behavior retained'});checks++;
}
// Existing unavailable-storage distinctions and lock remain intact.
for(const mode of ['get','getter','set']){const raw=wrapped([good,other]),f=start('candidate',raw,mode);assert(!f.failure);f.ctx.window.cart.addItem({id:'x',price:3});f.flush();assert.equal(f.ctx.window.cart.savedCartMalformed,undefined);assert.doesNotMatch(f.warnings[0].textContent,/some saved data was not usable/);assert.equal(f.state().saved,raw);f.recover();f.ctx.window.cart.updateQuantity('x','2');assert.equal(f.state().saved,raw);assert.equal(f.state().writes,mode==='set'?1:0);checks++}
for(const ready of ['loading','interactive']){const f=start('candidate',wrapped([good,{id:'old',quantity:1}]),'normal',ready);assert(!f.failure);assert(f.warnings[0].textContent);assert.equal(f.ctx.window.cart.cart.length,0);assert.equal(f.state().writes,0);checks++}
// An independent correction cannot unlock this instance or be overwritten; a new instance may load it.
{const raw=wrapped([good,{id:'old',quantity:1}]),f=start('candidate',raw);f.ctx.window.cart.addItem({id:'new',price:3});assert.equal(f.state().saved,raw);const corrected=wrapped([good,other]);f.replaceSaved(corrected);f.ctx.updateItemQuantity('new','2');assert.equal(f.state().saved,corrected);assert.equal(f.state().writes,0);assert.equal(f.ctx.window.cart.cart.length,1);const reloaded=start('candidate',corrected);assert.equal(reloaded.ctx.window.cart.cart.length,2);assert.equal(reloaded.warnings.length,0);checks++}
{const raw='[{',f=start('candidate',raw);f.ctx.window.cart.addItem({id:'new',price:3});const reloaded=start('candidate',f.state().saved);assert.equal(reloaded.ctx.window.cart.cart.length,0);assert(reloaded.ctx.window.cart.savedCartMalformed);assert.equal(reloaded.state().saved,raw);checks++}
// Numeric cap correction preserves stock metadata and prevents string quantity persistence.
{const raw=wrapped([{id:'1',price:10,quantity:2,stock:'3'}]);for(const variant of ['baseline','candidate']){const f=start(variant,raw);f.ctx.updateItemQuantity('1','4');assert.equal(f.ctx.window.cart.cart[0].quantity,variant==='candidate'?3:'3');assert.equal(JSON.parse(f.state().saved)[0].quantity,variant==='candidate'?3:'3')}checks++}
malformedReport={passed:true,checks,invalidCases:invalid.length,validCases:valid.length,method:'complete exact main.js + actual cart.php inline wrapper, isolated synthetic DOM/storage; no PHP/browser/network',rows};

{
const neighbor={id:2,name:'Unchanged numeric-ID neighbor',price:7,quantity:1,extra:{keep:true}};
const seed=stock=>[{id:'1',name:'Legacy',price:10,quantity:1,stock,custom:{keep:['all']}},neighbor];
function start(variant,raw){const f=fixture(variant,'normal','loading',raw);assert(!f.error);f.ready();f.flush();return f}
function run(variant,stock,input){const raw=' \n'+JSON.stringify(seed(stock))+'\t',f=start(variant,raw);f.ctx.updateItemQuantity('1',input);const saved=f.state().saved,reload=start(variant,saved);return {f,reload,raw,saved}}
let checks=0;const rows=[];
for(const variant of ['baseline','hydration-only','candidate']){
 const {f,reload,saved}=run(variant,'3','4');const q=f.ctx.window.cart.cart[0].quantity;
 if(variant==='candidate'){assert.equal(q,3);assert.equal(reload.ctx.window.cart.cart.length,2);assert.equal(reload.ctx.window.cart.cart[0].quantity,3);assert.equal(reload.warnings.length,0)}
 else{assert.equal(q,'3');assert.equal(JSON.parse(saved)[0].quantity,'3');if(variant==='hydration-only'){assert.equal(reload.ctx.window.cart.cart.length,0);assert(reload.ctx.window.cart.savedCartMalformed);assert.equal(reload.state().saved,saved)}else{assert.equal(reload.ctx.window.cart.cart.length,2);assert.equal(reload.ctx.window.cart.getItemCount(),'031')}}
 rows.push({variant,stock:'3',input:'4',savedQuantity:q,savedType:typeof q,reloadedItems:reload.ctx.window.cart.cart.length,reloadedCount:reload.ctx.window.cart.getItemCount(),malformedWarning:!!reload.ctx.window.cart.savedCartMalformed});checks++;
}
for(const stock of [3,'3',3.0,'3.0','3e0',true,[3]]){
 const {f,reload}=run('candidate',stock,'4');const numeric=Number(stock);assert.equal(f.ctx.window.cart.cart[0].quantity,numeric);assert.equal(typeof f.ctx.window.cart.cart[0].quantity,'number');assert.equal(reload.ctx.window.cart.cart.length,2);assert.equal(reload.warnings.length,0);assert.equal(f.state().writes,1);assert.equal(f.events.length,1);const expected=seed(stock);expected[0].quantity=numeric;assert.equal(JSON.stringify(f.ctx.window.cart.cart),JSON.stringify(expected),'stock/custom/all other fields unchanged');assert.equal(JSON.stringify(reload.ctx.window.cart.cart),JSON.stringify(expected));checks++;
}
// Refuse unusable numeric caps; do not floor, default, remove or replace previous whole quantity.
for(const stock of [2.5,'2.5',-1,'-1','0',' ',[],[2.5]]){
 const {f,reload,raw}=run('candidate',stock,'4');assert.equal(f.ctx.window.cart.cart[0].quantity,1);assert.equal(f.state().saved,raw);assert.equal(f.state().writes,0);assert.equal(f.events.length,0);assert.equal(f.toasts.length,0);assert.equal(reload.ctx.window.cart.cart.length,2);assert.equal(reload.warnings.length,0);checks++;
}
// Preserve the existing stock fallback/comparison; invalid metadata is not assigned a new stock policy.
for(const stock of [undefined,null,0,'',false,'abc',{},[1,2],'Infinity']){
 const a=run('baseline',stock,'4'),b=run('candidate',stock,'4');assert.equal(a.f.ctx.window.cart.cart[0].quantity,4);assert.equal(b.f.ctx.window.cart.cart[0].quantity,4);assert.equal(a.saved,b.saved);assert.equal(b.reload.ctx.window.cart.cart.length,2);checks++;
}
// Fractional user input continues parseInt; it is distinct from a fractional stock cap.
for(const stock of [3,'3',2.5,'2.5']){const {f,reload}=run('candidate',stock,'2.9');assert.equal(f.ctx.window.cart.cart[0].quantity,2);assert.equal(reload.ctx.window.cart.cart[0].quantity,2);checks++}
// Removal runs before stock-cap validation and keeps original <=0 semantics, including invalid caps.
for(const stock of [3,'3',2.5,'2.5',-1,'0','abc',{}])for(const input of ['0','-1']){
 const a=run('baseline',stock,input),b=run('candidate',stock,input);assert.equal(a.f.ctx.window.cart.cart.length,1);assert.equal(b.f.ctx.window.cart.cart.length,1);assert.equal(a.saved,b.saved);assert.equal(b.reload.ctx.window.cart.cart.length,1);assert.equal(JSON.stringify(b.reload.ctx.window.cart.cart[0]),JSON.stringify(neighbor));checks++;
}
// Negative control: accepting fractional capped quantities would make the new loader refuse its own saved output.
for(const stock of [2.5,'2.5',-1,'0']){const a=run('hydration-only',stock,'4');assert(a.reload.ctx.window.cart.savedCartMalformed);assert.equal(a.reload.ctx.window.cart.cart.length,0);checks++}
stockReport={passed:true,checks,method:'actual complete source + cart wrapper load/edit/save/reload, synthetic storage only',rows,scope:'numeric cap conversion; unusable cap no-op; no stock fallback/rounding policy'};

}
console.log(JSON.stringify({passed:true,checks:malformedReport.checks+stockReport.checks,malformed:malformedReport,stock:stockReport},null,2));
