const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../cart.php'),'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const element=()=>({style:{},innerHTML:'',textContent:'',classList:{add(){},remove(){}}});
const elements=Object.fromEntries(['cart-items-container','empty-cart-message','checkout-btn','cart-subtotal','cart-total','cart-shipping-estimate','cart-shipping-estimate-result'].map(k=>[k,element()]));
const cart=[{id:'42',name:'Used <Rotor> & parts',sku:'TEST-42',price:20,quantity:2,stock:3}];
const document={addEventListener(){},getElementById:id=>elements[id],querySelector:()=>element(),querySelectorAll:()=>[],createElement:()=>({set textContent(v){this.innerHTML=String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');}})};
const window={cart:{cart,getTotal:()=>40}};const context={document,window,setTimeout(){}};vm.createContext(context);vm.runInContext(source,context);
let checks=0;function check(value,message){assert.ok(value,message);checks++;}
context.displayCartItems();let markup=elements['cart-items-container'].innerHTML;
check(markup.includes('href="/product/42"'),'Cart title provides native product destination');
check(markup.includes('Used &lt;Rotor&gt; &amp; parts</a>'),'Product name remains escaped inside anchor');
check(JSON.stringify(cart)==='[{"id":"42","name":"Used <Rotor> & parts","sku":"TEST-42","price":20,"quantity":2,"stock":3}]','Rendering leaves cart content and quantity unchanged');
for(const id of [42,'7',1]){cart[0].id=id;context.displayCartItems();check(elements['cart-items-container'].innerHTML.includes('href="/product/'+id+'"'),'Positive numeric IDs have product links');}
for(const id of [null,undefined,'','0',0,-1,'-2','1.5','1e3','javascript:alert(1)','42/../../checkout','42" onclick="alert(1)']){cart[0].id=id;context.displayCartItems();markup=elements['cart-items-container'].innerHTML;check(!markup.includes('class="cart-product-link'),'Invalid IDs remain unlinked');check(markup.includes('Used &lt;Rotor&gt; &amp; parts'),'Invalid IDs retain readable escaped name');}
check(elements['checkout-btn'].style.display==='block','Checkout remains available for a nonempty cart');
console.log('PASS',checks,'cart product-link and unchanged-cart rendering assertions');
