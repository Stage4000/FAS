// Execute the actual inline product mutation handlers with synthetic DOM/fetch.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '../admin/products.php'), 'utf8');
const visibility = source.slice(source.indexOf("document.querySelectorAll('.visibility-toggle')"), source.indexOf('// Product-level free shipping toggle'));
const shippingStart = source.indexOf("document.querySelectorAll('.free-shipping-toggle')");
const shipping = source.slice(shippingStart, source.indexOf('// Delete confirmation', shippingStart));
const removalStart = source.indexOf("document.getElementById('confirmImgRemove').addEventListener");
const removal = source.slice(removalStart, source.indexOf('imgRemovalDialog.hide();', removalStart) + 'imgRemovalDialog.hide();'.length) + '\n});';
assert.ok(visibility && shipping && removalStart >= 0, 'Product mutation handlers available');
let token = 'synthetic-token-first';
const requests = [];
function element() {
    return {dataset:{productId:'9001',enabled:'0'}, checked:true, disabled:false, callbacks:{},
        classList:{toggle(){}}, setAttribute(){}, closest(){return null;},
        addEventListener(event,callback){this.callbacks[event]=callback;},
        querySelector(){return {dataset:{imageUrl:'/gallery/uploads/synthetic.png'}};},
        remove(){this.removed=true;}};
}
const toggle=element(), button=element(), confirm=element(), image=element();
const context = {FormData,URLSearchParams,console,
    alert(message){throw new Error(message);},
    document:{
        querySelector(selector){return {value:selector.includes('product_id')?'9001':token};},
        querySelectorAll(selector){return selector==='.visibility-toggle'?[toggle]:[button];},
        getElementById(){return confirm;}
    },
    targetImgElement:image, imgRemovalDialog:{hide(){}},
    async fetch(url,options){requests.push(options.body);return {async json(){return {success:true,free_shipping:true,qualifies_for_free_shipping:true};}};}
};
vm.createContext(context);
vm.runInContext(visibility,context);
vm.runInContext(shipping,context);
vm.runInContext(removal,context);
(async()=>{
    await toggle.callbacks.change.call(toggle);
    assert.equal(requests.at(-1).get('csrf_token'),token,'Visibility handler transmits current CSRF');
    token='synthetic-token-refreshed';
    await button.callbacks.click.call(button);
    assert.equal(requests.at(-1).get('csrf_token'),token,'Free shipping handler transmits refreshed CSRF');
    await confirm.callbacks.click();
    assert.equal(requests.at(-1).get('csrf_token'),token,'Image removal handler transmits current CSRF');
    assert.equal(requests.at(-1).get('product_id'),'9001');
    assert.equal(requests.at(-1).get('image_path'),'/gallery/uploads/synthetic.png');
    assert.equal(image.removed,true,'Successful immediate removal updates DOM');
    console.log('PASS 6 product AJAX client assertions; synthetic DOM and requests only.');
})().catch(error=>{console.error(error);process.exitCode=1;});
