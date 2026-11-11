const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const pricing=require('../public/js/cart-pricing.js');
const CART='flipandstrip_cart',ACCEPTANCE='flipandstrip_cart_price_acceptance_v1';
const cartClass=fs.readFileSync(path.join(__dirname,'../public/js/main.js'),'utf8').split('// Initialize cart')[0];
const fixtures=()=>[{id:'1',name:'Synthetic',quantity:2,price:100,sku:'TEST-1',stock:9},{id:'2',name:'Unchanged',quantity:1,price:10}];
function sharedStorage(items=fixtures()) {
 const values=new Map([[CART,JSON.stringify(items)]]);
 return {writes:[],beforeSet:null,getItem:key=>values.has(key)?values.get(key):null,setItem(key,value){if(this.beforeSet)this.beforeSet(key,value);this.writes.push(key);values.set(key,String(value));},removeItem:key=>values.delete(key),clear:()=>values.clear()};
}
function tab(storage) {
 const context={localStorage:storage,document:{getElementById:()=>null,dispatchEvent(){}},CustomEvent:class{},window:{}};
 vm.createContext(context);vm.runInContext(cartClass+'\nglobalThis.TestCart=ShoppingCart;',context);
 return new context.TestCart();
}
function reviewPage(storage,catalog) {
 function element() {
  return {children:[],listeners:{},hidden:false,_text:'',set textContent(value){this._text=String(value);this.children=[];},
   get textContent(){return this._text+this.children.map(child=>child.textContent).join('');},
   replaceChildren(){this.children=[];this._text='';},append(child){this.children.push(child);},addEventListener(name,fn){this.listeners[name]=fn;}};
 }
 const panel=element(),events={},document={getElementById:()=>panel,createElement:element,addEventListener:(name,fn)=>{events[name]=fn;}};
 const page={document,localStorage:storage,cart:tab(storage),addEventListener:(name,fn)=>{events[name]=fn;},location:{reload(){page.reloads++;}},reloads:0};
 const context={window:page,fetch:async url=>({ok:true,json:()=>catalog(new URL(url,'http://fixture.invalid').searchParams.get('id'))})};
 vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/js/cart-pricing.js'),'utf8'),context);
 return {page,panel,start:()=>events.DOMContentLoaded(),button:()=>panel.children.find(child=>child.type==='button'),
  storageEvent:key=>events.storage({key,storageArea:storage})};
}
let checks=0;
function check(value,message){assert.ok(value,message);checks++;}
function rejects(action,pattern){assert.throws(action,pattern);checks++;}
(async()=>{
 const items=[{id:1,name:'Synthetic',quantity:2,price:100},{id:2,name:'Unchanged',quantity:1,price:10}];
 const original=JSON.stringify(items);
 const lookup=async id=>({exists:true,active:true,unit_price_cents:id==='1'?6025:1000});
 const changes=await pricing.changes(items,lookup);
 assert.deepEqual(changes,[{id:'1',name:'Synthetic',before:10000,after:6025}]);
 assert.equal(JSON.stringify(items),original);
 const accepted=pricing.apply(items,changes,pricing.snapshot(items));
 assert.equal(accepted[0].price,60.25);assert.equal(accepted[0].quantity,2);assert.equal(items[0].price,100);
 assert.equal(accepted[1].price,10);
 assert.throws(()=>pricing.apply([{...items[0],quantity:3},items[1]],changes,pricing.snapshot(items)),/cart changed/);
 assert.throws(()=>pricing.apply([{...items[0],price:101},items[1]],changes,pricing.snapshot(items)),/cart changed/);
 await assert.rejects(pricing.changes(items,async()=>({exists:false})),/unavailable/);
 await assert.rejects(pricing.changes(items,async()=>({exists:true,active:true,unit_price_cents:1.5})),/unavailable/);
 await assert.rejects(pricing.changes(items,async()=>{throw new Error('offline')}),/offline/);
 assert.equal(JSON.stringify(items),original);
 checks+=12;

 // Two actual ShoppingCart instances use one store, as independent tabs do.
 const storage=sharedStorage(),a=tab(storage),b=tab(storage);
 const initial=storage.getItem(CART),expected=pricing.capture(a,storage);
 const updates=await pricing.changes(a.cart,lookup);
 pricing.accept(a,updates,expected,storage);
 check(storage.getItem(CART)===initial,'Acceptance never writes the base cart');
 check(storage.writes.join(',')===ACCEPTANCE,'Acceptance writes only its separate versioned overlay');
 check(a.cart[0].price===60.25&&a.cart[0].quantity===2&&a.cart[0].sku==='TEST-1','Acceptance keeps quantity and metadata');
 const reloaded=tab(storage);
 check(reloaded.cart[0].price===60.25&&reloaded.cart[1].price===10,'A normal cart/checkout initialization loads accepted item prices');
 check(reloaded.getTotal()===130.5,'Reloaded total uses the deliberately accepted price');
 reloaded.updateQuantity('1',3);
 check(JSON.parse(storage.getItem(CART))[0].price===60.25&&JSON.parse(storage.getItem(CART))[0].quantity===3,'Ordinary edits materialize the displayed accepted price');
 check(tab(storage).cart[0].price===60.25,'A stale overlay does not undo materialized prices');
 rejects(()=>pricing.capture(b,storage),/saved cart changed/);
 {
  const store=sharedStorage(),first=tab(store);
  pricing.accept(first,updates,pricing.capture(first,store),store);
  const again=tab(store),next=await pricing.changes(again.cart,async id=>({exists:true,active:true,unit_price_cents:id==='1'?5000:1000}));
  pricing.accept(again,next,pricing.capture(again,store),store);
  check(tab(store).cart[0].price===50,'A second explicit acceptance replaces the prior accepted price');
  const returning=tab(store),back=await pricing.changes(returning.cart,async id=>({exists:true,active:true,unit_price_cents:id==='1'?10000:1000}));
  pricing.accept(returning,back,pricing.capture(returning,store),store);
  check(tab(store).cart[0].price===100&&store.getItem(CART)===initial,'An explicit return to the original saved amount clears the effective overlay without rewriting the cart');
 }

 for(const [name,edit] of [
  ['quantity',cart=>cart.updateQuantity('1',3)],
  ['add',cart=>cart.addItem({id:'3',name:'Added',price:4,stock:9})],
  ['delete',cart=>cart.removeItem('1')],
  ['metadata',cart=>{cart.cart[0].name='Changed in second tab';cart.saveCart();}],
  ['clear',cart=>cart.clearCart()]
 ]) {
  const store=sharedStorage(),first=tab(store),second=tab(store),token=pricing.capture(first,store);
  const changes=await pricing.changes(first.cart,lookup);edit(second);
  const other=store.getItem(CART),memory=JSON.stringify(first.cart),writes=store.writes.length;
  rejects(()=>pricing.accept(first,changes,token,store),/saved cart changed/);
  check(store.getItem(CART)===other&&store.writes.length===writes,name+' saved by the other tab remains byte-identical');
  check(JSON.stringify(first.cart)===memory,name+' rejection does not silently accept an in-memory price');
 }

 // A change while awaiting either the initial or fresh catalog lookup is caught.
 for(const phase of ['review','acceptance']) {
  const store=sharedStorage(),first=tab(store),second=tab(store),token=pricing.capture(first,store);
  if(phase==='acceptance')await pricing.changes(first.cart,lookup);
  let release;const gate=new Promise(resolve=>{release=resolve;});
  const pending=pricing.changes(first.cart,async id=>{await gate;return lookup(id);});
  second.updateQuantity('1',4);const other=store.getItem(CART);release();const fresh=await pending;
  rejects(()=>pricing.assertCurrent(first,store,token),/saved cart changed/);
  rejects(()=>pricing.accept(first,fresh,token,store),/saved cart changed/);
  check(store.getItem(CART)===other,phase+' async change is preserved');
 }

 // Adversarial scheduling: tab B writes inside tab A's overlay setItem, AFTER
 // A's final read. The old read/check/base-write approach loses this update.
 for(const [name,edit] of [
  ['quantity',cart=>cart.updateQuantity('1',5)],
  ['add',cart=>cart.addItem({id:'3',name:'Added at commit',price:7,stock:9})],
  ['delete',cart=>cart.removeItem('1')]
 ]) {
  const store=sharedStorage(),first=tab(store),second=tab(store),token=pricing.capture(first,store);
  const fresh=await pricing.changes(first.cart,lookup);let other;
  store.beforeSet=key=>{if(key!==ACCEPTANCE)return;store.beforeSet=null;edit(second);other=store.getItem(CART);};
  rejects(()=>pricing.accept(first,fresh,token,store),/saved cart changed/);
  check(store.getItem(CART)===other,name+' interleaved base-cart write survives acceptance');
  check(JSON.stringify(tab(store).cart)===other,name+' stale overlay is ignored after reload');
 }
 // Negative control proves this harness would catch the former implementation.
 {
  const store=sharedStorage(),first=tab(store),second=tab(store);
  const fresh=await pricing.changes(first.cart,lookup);
  store.beforeSet=key=>{if(key!==CART)return;store.beforeSet=null;second.updateQuantity('1',5);};
  first.cart=pricing.apply(first.cart,fresh,pricing.snapshot(first.cart));first.saveCart();
  check(JSON.parse(store.getItem(CART))[0].quantity===2,'Negative control reproduces old lost quantity after check/write interleaving');
 }
 // A stale overlay never supplies prices for a different/malformed cart.
 for(const invalid of ['{','null','{}','[]',JSON.stringify({version:2,currency:'USD',cart:initial,prices:[{id:'1',before:10000,after:6025}]}),
  JSON.stringify({version:1,currency:'EUR',cart:initial,prices:[{id:'1',before:10000,after:6025}]}),
  JSON.stringify({version:1,currency:'USD',cart:initial,prices:[{id:'1',before:9999,after:6025}]}),
  JSON.stringify({version:1,currency:'USD',cart:initial,prices:[{id:'1',before:10000,after:1.5}]}),
  JSON.stringify({version:1,currency:'USD',cart:initial,prices:[{id:'1',before:10000,after:6025},{id:'1',before:10000,after:7000}]})]) {
  const store=sharedStorage();store.setItem(ACCEPTANCE,invalid);
  check(tab(store).cart[0].price===100,'Invalid overlay is ignored without adopting any price');
 }
 for(const malformed of ['{','null','{}','[null]','[{"id":"1","quantity":0,"price":100}]']) {
  const store=sharedStorage(),first=tab(store);store.setItem(CART,malformed);const writes=store.writes.length;
  rejects(()=>pricing.capture(first,store),/saved cart|invalid saved cart/);
  check(store.writes.length===writes,'Malformed saved cart fails closed without writes');
 }
 {
  const store=sharedStorage(),first=tab(store),token=pricing.capture(first,store),memory=JSON.stringify(first.cart);
  store.setItem=()=>{throw new Error('storage unavailable');};
  rejects(()=>pricing.accept(first,updates,token,store),/storage unavailable/);
  check(JSON.stringify(first.cart)===memory&&store.getItem(CART)===initial,'Failed overlay persistence changes neither cart nor displayed price');
 }
 {
  const store=sharedStorage(),first=tab(store),token=pricing.capture(first,store);first.cart[0].quantity=8;
  rejects(()=>pricing.accept(first,updates,token,store),/saved cart changed/);
  check(store.writes.length===0,'Same-tab edits require a new review');
 }
 // Exercise the real DOMContentLoaded/click flow with a minimal offline DOM.
 {
  const store=sharedStorage(),page=reviewPage(store,lookup);await page.start();
  check(page.button()?.textContent==='Accept current prices','The review offers deliberate price acceptance');
  await page.button().listeners.click();
  check(page.page.reloads===1&&tab(store).cart[0].price===60.25,'The real click flow persists accepted prices for reload');
  check(store.getItem(CART)===initial,'The real click flow never rewrites the cart');
 }
 {
  const store=sharedStorage(),page=reviewPage(store,lookup),second=tab(store);await page.start();
  second.updateQuantity('1',3);const other=store.getItem(CART);page.storageEvent(CART);
  check(!page.button()&&/saved cart changed/.test(page.panel.textContent),'Cross-tab storage events remove the stale acceptance button');
  check(store.getItem(CART)===other,'Storage event handling does not persist stale cart data');
 }
 for(const phase of ['review','acceptance']) {
  const store=sharedStorage();let release,hold=phase==='review';const gate=new Promise(resolve=>{release=resolve;});
  const page=reviewPage(store,async id=>{if(hold)await gate;return lookup(id);}),second=tab(store);
  let pending;
  if(phase==='review')pending=page.start();
  else {await page.start();hold=true;pending=page.button().listeners.click();}
  second.updateQuantity('1',6);const other=store.getItem(CART);release();await pending;
  check(/saved cart changed/.test(page.panel.textContent)&&!page.button(),phase+' UI catches changes made during its awaited lookup');
  check(page.page.reloads===0&&store.getItem(CART)===other&&!store.getItem(ACCEPTANCE),phase+' UI preserves other edits and accepts no price');
 }
 {
  const store=sharedStorage(),page=reviewPage(store,async()=>{throw new Error('offline fixture');});await page.start();
  check(/offline fixture/.test(page.panel.textContent)&&!page.button()&&store.writes.length===0,'Offline UI shows failure and accepts no price');
 }
 console.log('PASS',checks,'saved-cart price assertions; real ShoppingCart in independent VM tabs, shared synthetic storage, mocked catalog, no network.');
})().catch(error=>{console.error(error);process.exitCode=1});
