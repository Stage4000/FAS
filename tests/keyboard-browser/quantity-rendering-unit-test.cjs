/* Actual ShoppingCart + complete cart.php inline script; synthetic DOM/storage only. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const rootFlag=process.argv.indexOf('--source-root');
const root=path.resolve(rootFlag<0?path.join(__dirname,'../..'):process.argv[rootFlag+1]);
const source=fs.readFileSync(path.join(root,'public/js/main.js'),'utf8').split('// Initialize cart')[0];
const page=fs.readFileSync(path.join(root,'cart.php'),'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const seed=[{id:'1',name:'Synthetic rotor',sku:'SYN-1',price:10,quantity:2,stock:3,image:'',image_alt:'Rotor alt',free_shipping:true,weight:7,length:4,width:5,height:6,custom:{keep:['a',1]}},{id:'2',name:'Untouched synthetic part',price:7,quantity:1,stock:8,unrelated:{keep:true}}];
let stored=JSON.stringify(seed),saves=0,events=0,checks=0;
const el=()=>({style:{},innerHTML:'',textContent:'',classList:{add(){},remove(){}}});
const elements=Object.fromEntries(['cart-items-container','empty-cart-message','checkout-btn','cart-subtotal','cart-total','cart-shipping-estimate','cart-shipping-estimate-result'].map(k=>[k,el()]));
const document={addEventListener(){},dispatchEvent(){events++;},getElementById:id=>elements[id]||null,querySelector:()=>el(),querySelectorAll:()=>[],createElement:()=>({set textContent(v){this.innerHTML=String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');}})};
const context={window:{},document,localStorage:{getItem:()=>stored,setItem:(k,v)=>{stored=v;saves++;}},CustomEvent:class{},setTimeout(){}};
vm.createContext(context);vm.runInContext(source+'\nwindow.cart=new ShoppingCart()',context);vm.runInContext(page,context);
function verify(quantity){
 const expected=structuredClone(seed);expected[0].quantity=quantity;
 assert.equal(JSON.stringify(context.window.cart.cart),JSON.stringify(expected),'all unrelated fields and second item preserved');
 assert.equal(stored,JSON.stringify(expected),'storage equals expected complete cart');
 assert.equal(elements['cart-items-container'].innerHTML.match(/<input type="number"[^>]*value="([^"]*)"/)[1],String(quantity),'wrapper redraw restores first item displayed quantity');
 assert.equal(elements['cart-total'].textContent,'$'+(10*quantity+7).toFixed(2),'wrapper redraw restores total');
 checks+=4;
}
context.displayCartItems();verify(2);
for(const value of ['', ' ', 'abc', '9'.repeat(400)]){
 const beforeSaves=saves,beforeEvents=events;
 context.updateItemQuantity('1',value);verify(2);
 assert.equal(saves,beforeSaves,'rejected input does not save');assert.equal(events,beforeEvents,'rejected input does not emit mutation');checks+=2;
}
for(const [value,expected] of [['3',3],['4',3],['2',2],['1',1],['2',2],['2.9',2],['1e2',1],['2',2]]){
 context.updateItemQuantity('1',value);verify(expected);
}
console.log('PASS '+checks+' cart wrapper rendering, recovery, stock bounds, storage, totals and unrelated-field assertions');
