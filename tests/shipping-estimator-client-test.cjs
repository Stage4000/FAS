const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Element {
    constructor() { this.listeners={};this.dataset={};this._text='';this.innerHTML='';this.disabled=false;this.value='1';this.classes=new Set();this.classList={add:(...v)=>v.forEach(x=>this.classes.add(x)),remove:(...v)=>v.forEach(x=>this.classes.delete(x)),toggle:(v,on)=>on?this.classes.add(v):this.classes.delete(v)}; }
    addEventListener(name,fn) { (this.listeners[name]??=[]).push(fn); }
    emit(name) { return Promise.all((this.listeners[name]??[]).map(fn=>fn({currentTarget:this,preventDefault(){}}))); }
    set textContent(v) { this._text=v;this.innerHTML=String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }
    get textContent() { return this._text; }
    closest() { return this.row; }
}
function fixture(mode='cart') {
    const form=new Element(),button=new Element(),result=new Element(),shipping=new Element(),total=new Element(),row=new Element(),qty=new Element(),source=new Element(),doc=new Element();
    button.innerHTML='Estimate Shipping';total.row=row;row.classList.add('d-none');shipping.textContent='Estimate below';
    form.dataset={estimateMode:mode,resultTarget:'#result',shippingTarget:'#shipping',totalTarget:'#total',productSource:'#source',quantitySource:'#qty'};
    form.fields={city:'Portland',state:'OR',zip:'97035'};form.querySelector=()=>button;
    source.dataset={id:'1',name:'Synthetic part',sku:'TEST-1',price:'100',weight:'1',length:'10',width:'10',height:'10',freeShipping:'0'};
    doc.readyState='complete';doc.querySelectorAll=()=>[form];doc.querySelector=s=>({'#result':result,'#shipping':shipping,'#total':total,'#source':source,'#qty':qty}[s]);doc.createElement=()=>new Element();
    const stored=new Map(),requests=[],items=[{id:'1',name:'Synthetic part',sku:'TEST-1',price:100,quantity:1,weight:1,length:10,width:10,height:10}];
    const storage={getItem:k=>stored.get(k)??null,setItem:(k,v)=>stored.set(k,v),removeItem:k=>stored.delete(k)};
    const window={cart:{cart:items,getTotal:()=>items.reduce((n,i)=>n+i.price*i.quantity,0)}};
    const context={window,document:doc,localStorage:storage,FormData:class{constructor(f){this.f=f;}get(k){return this.f.fields[k];}},AbortController,console,Date};
    context.fetch=(url,options)=>new Promise(resolve=>requests.push({url,options,resolve}));
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/js/shipping-estimator.js'),'utf8'),context);
    const reply=(index,amount=10,extra={})=>requests[index].resolve({json:async()=>({success:true,rates:[{total_charge:amount}],lowest_rate:{total_charge:amount,service_name:'Synthetic delivery'},...extra})});
    return {form,button,result,shipping,total,row,qty,doc,stored,requests,items,reply,window,cache:()=>window.fasShippingEstimator.getLatestEstimate()};
}
(async()=>{
    const f=fixture();let p=f.form.emit('submit');f.reply(0);await p;
    assert.equal(f.shipping.textContent,'From $10.00');assert.equal(f.total.textContent,'$110.00');assert.equal(f.cache().items[0].quantity,1);
    f.form.fields.zip='10001';await f.form.emit('input');
    assert.equal(f.shipping.textContent,'Estimate below','destination edits clear the old quote');assert.ok(f.row.classes.has('d-none'));assert.equal(f.cache(),null);
    p=f.form.emit('submit');f.items[0].quantity=2;await f.doc.emit('fas:cart-changed');assert.equal(f.button.disabled,false,'cart changes permit a new request');f.reply(1,10);await p;
    assert.equal(f.shipping.textContent,'Estimate below','late quote for old cart is ignored');assert.equal(f.cache(),null);
    p=f.form.emit('submit');f.reply(2,18);await p;assert.equal(f.total.textContent,'$218.00');assert.equal(f.cache().items[0].quantity,2);
    const old=f.form.emit('submit');f.form.fields.zip='90210';await f.form.emit('input');const latest=f.form.emit('submit');f.reply(4,7);await latest;f.reply(3,99);await old;
    assert.equal(f.shipping.textContent,'From $7.00','older response cannot replace a newer destination quote');assert.equal(f.cache().address.zip,'90210');assert.equal(f.button.innerHTML,'Estimate Shipping');
    p=f.form.emit('submit');f.form.fields.city='Synthetic city';f.reply(5,44);await p;
    assert.equal(f.cache(),null,'programmatic edits without events are caught before publication');assert.equal(f.button.disabled,false);assert.equal(f.shipping.textContent,'Estimate below');
    p=f.form.emit('submit');f.reply(6,9);await p;p=f.form.emit('submit');f.reply(7,0,{success:false,error:'Synthetic unavailable'});await p;
    assert.equal(f.cache(),null,'failed recalculation cannot leave an old quote cached');assert.equal(f.shipping.textContent,'Estimate below');assert.ok(f.result.innerHTML.includes('Synthetic unavailable'));
    const product=fixture('product');p=product.form.emit('submit');product.reply(0);await p;product.qty.value='2';await product.qty.emit('input');assert.equal(product.cache(),null,'product quantity changes clear the previous estimate');assert.ok(product.result.innerHTML.includes('Estimate shipping again'));
    p=product.form.emit('submit');product.qty.value='3';product.reply(1,20);await p;assert.equal(product.cache(),null,'late product-quantity quote is rejected');
    console.log('PASS shipping estimate destination/cart/product invalidation, late/overlapping responses, failed refresh and retry behavior');
})().catch(error=>{console.error(error);process.exit(1);});
