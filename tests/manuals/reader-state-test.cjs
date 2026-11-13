const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../public/js/manual-reader.mjs','utf8');
const helpers=source.slice(source.indexOf('export function spreadStart'),source.indexOf('const root = document.querySelector')).replaceAll('export ','');
const context={URLSearchParams};vm.createContext(context);vm.runInContext(helpers,context);
let count=0;const test=(label,fn)=>{fn();count++;console.log('PASS',label);};
for(const pages of [1,2,3,7,100])for(let page=1;page<=pages;page++)for(const spread of [false,true]){
 test(`page bounds ${page}/${pages} spread=${spread}`,()=>{const values=context.pagesFor(page,pages,spread);assert(values.length>=1&&values.length<=2);assert(values.includes(page));assert(values.every(n=>n>=1&&n<=pages));assert(context.nextPage(page,pages,spread,1)<=pages);assert(context.nextPage(page,pages,spread,-1)>=1);});
}
test('cover turns to first spread',()=>assert.equal(context.nextPage(1,7,true,1),2));
test('first spread returns to cover',()=>assert.equal(context.nextPage(2,7,true,-1),1));
test('odd page advances from its current spread',()=>assert.equal(context.nextPage(3,7,true,1),4));
for(const [hash,expected]of[['',1],['#page=4',4],['#page=-3',1],['#page=oops',1],['#page=999',7],['#page=0',1]])test('fragment '+hash,()=>assert.equal(context.queryPage(hash,7),expected));
test('bundled renderer configured without annotations/XFA',()=>{assert(source.includes('annotationMode: pdfjs.AnnotationMode.DISABLE'));assert(source.includes('enableXfa:false'));assert(source.includes('isEvalSupported:false'));});
test('PDF cleanup uses supported loading-task API',()=>{assert(!source.includes('pdf.destroy'));assert(source.includes('loadingTask?.destroy()'));});
console.log(count+' checks passed; helper and source-contract tests, not browser acceptance.');
