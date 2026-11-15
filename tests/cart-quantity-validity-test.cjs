const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const repo=process.argv[2]||path.join(__dirname,'..');
const src=fs.readFileSync(path.join(repo,'public/js/main.js'),'utf8').split('// Initialize cart')[0];
let checks=0;
function fixture(){
 const data=new Map(),events=[];
 const ctx={window:{},document:{getElementById:()=>null,dispatchEvent:e=>events.push(e.type)},CustomEvent:class{constructor(type){this.type=type;}},localStorage:{getItem:k=>data.get(k)||null,setItem:(k,v)=>data.set(k,v)}};
 vm.runInNewContext(src+'\nthis.Cart=ShoppingCart',ctx);const cart=new ctx.Cart();
 cart.addItem({id:'test',price:10,stock:3});return {cart,ctx,data,events};
}
for(const input of ['', ' ', 'abc','NaN','Infinity','-Infinity','9'.repeat(400)]){
 const f=fixture(),before=f.data.get('flipandstrip_cart'),eventCount=f.events.length;
 f.cart.updateQuantity('test',input);
 assert.equal(f.cart.cart[0].quantity,1,'invalid quantity must retain previous finite quantity: '+JSON.stringify(input));
 assert.equal(f.data.get('flipandstrip_cart'),before,'invalid update must not write storage');
 assert.equal(f.events.length,eventCount,'invalid update must not emit cart mutation');
 assert.equal(new f.ctx.Cart().getTotal(),10,'reload preserves total');checks+=4;
}
for(const [input,expected] of [['2',2],['3',3],['4',3],['2.9',2],['1e2',1],['9007199254740993',3]]){
 const f=fixture();f.cart.updateQuantity('test',input);
 assert.equal(f.cart.cart[0].quantity,expected,'retain existing finite parseInt and stock semantics');
 assert.equal(new f.ctx.Cart().cart[0].quantity,expected);checks+=2;
}
for(const input of ['0','-1']){
 const f=fixture();f.cart.updateQuantity('test',input);assert.equal(f.cart.cart.length,0);assert.equal(new f.ctx.Cart().cart.length,0);checks+=2;
}
console.log('PASS '+checks+' quantity validity, no-op side effects, reload, finite parsing, stock and removal assertions');
