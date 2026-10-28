const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const sourcePath = process.argv[2] || path.join(__dirname, '../public/js/address-autofill.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const marker = "    if (document.readyState === 'loading') {";
assert.equal(source.split(marker).length, 2, 'unique initialization boundary');
const context = vm.createContext({});
new vm.Script(source.slice(0, source.indexOf(marker)) +
    'globalThis.helpers={normalizeState,normalizeAddress,isCompleteAddress,setFieldValue};\n})();', {filename:sourcePath})
    .runInContext(context, {timeout:1000});
const {normalizeState,normalizeAddress,isCompleteAddress,setFieldValue} = context.helpers;
const cases = [
    ['Nevada',''],['New York',''],['North Carolina',''],['California',''],['Washington',''],
    ['', ''],[null,''],[undefined,''],['N',''],['123',''],['12',''],['N1',''],
    ['N.V.',''],['N-V',''],['N V',''],['NV!',''],['NVX',''],['éa',''],['ＮＶ',''],
    ['\t nv \r\n','NV'],['\u00a0NV\u00a0','NV']
];
// All ASCII pairs are grammatically accepted; no state allowlist or new policy.
for (let first=65; first<=90; first++) {
    for (let second=65; second<=90; second++) {
        const code=String.fromCharCode(first,second);
        cases.push([code,code],[code.toLowerCase(),code],[' \t'+code.toLowerCase()+'\n',code]);
        for (const invalid of [code+'x','0'+code,code[0]+'-'+code[1],code[0]+' '+code[1]]) cases.push([invalid,'']);
    }
}
const codes='AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY AS GU MP PR VI AA AE AP'.split(' ');
for (const code of codes) cases.push([code,code],[code.toLowerCase(),code]);
let failures=0, assertions=0, oracleFailures=0, propertyFailures=0;
function property(actual,expected,label,input) {
    assertions++;
    if (actual!==expected) {
        if (failures<8) console.error(JSON.stringify({input,label,expected,actual}));
        failures++; propertyFailures++;
    }
}
for (const [input,expected] of cases) {
    const actual=normalizeState(input);
    assertions++;
    if (actual!==expected) {
        if (failures<8) console.error(JSON.stringify({input,expected,actual}));
        failures++; oracleFailures++;
    }
    property(normalizeState(actual),actual,'idempotence',input);
    const address=normalizeAddress({city:'Testville',state:input,zip:'02108'});
    property(address.state,actual,'address delegation',input);
    property(isCompleteAddress(address),actual.length===2,'existing completeness follows normalized value',input);
}
for (const value of ['Nevada','N.V.','NV','nv','123456']) {
    const field={value,dispatchEvent(){throw Error('occupied field changed');}};
    assert.equal(setFieldValue(field,'NV'),false); assertions++;
    assert.equal(field.value,value); assertions++;
}
console.log(JSON.stringify({cases:cases.length,assertions,failures,oracleFailures,propertyFailures,occupiedFieldsPreserved:5,sourcePath}));
process.exitCode=failures?1:0;
