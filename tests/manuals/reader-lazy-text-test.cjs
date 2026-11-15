/* Focused state regression only: DOM/PDF stubs, never browser acceptance. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;
const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
async function scenario(){
  const nodes=new Map(),listeners=new Map(),plans=new Map(),calls=new Map();
  const tick=()=>new Promise(r=>setTimeout(r,0));
  function hold(n){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});plans.set(n,promise);return{resolve,reject};}
  function element(tag='DIV'){
    return {tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',
      addEventListener(t,fn){this.listeners[t]=fn;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},
      append(...items){this.children.push(...items);},replaceChildren(...items){this.children=items;},replaceWith(){},focus(){},getContext(){return{};},
      querySelector(selector){this.nodes??=new Map();if(!this.nodes.has(selector))this.nodes.set(selector,element());return this.nodes.get(selector);}};
  }
  const panel=element('DETAILS');panel.open=false;
  const root=element();root.dataset.pdfUrl='/manuals/file.php?id=1';
  root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,element());if(s==='[data-page-text]')nodes.get(s).closest=()=>panel;return nodes.get(s);};
  const papers=[element(),element()];root.querySelectorAll=()=>papers;
  const pdf={numPages:3,async getPage(n){return{getViewport({scale}){return{width:300*scale,height:400*scale};},render(){return{promise:Promise.resolve(),cancel(){}};},async getTextContent(){calls.set(n,(calls.get(n)||0)+1);if(plans.has(n))return await plans.get(n);return{items:[{str:'synthetic page '+n}]};}};}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(){}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},
    getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),ResizeObserver:class{observe(){}disconnect(){}},
    document:{querySelector:()=>root,createElement:tag=>element(tag.toUpperCase()),createDocumentFragment:()=>element(),addEventListener(){}},
    window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener(t,f){listeners.set(t,f)},devicePixelRatio:1},
    pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){}})}};
  vm.createContext(context);
  let source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
  source=source.replace('  const setControls =', '  globalThis.getText=textFor; globalThis.cache=textCache; globalThis.inFlight=typeof pendingText!=="undefined"?pendingText:null;\n  const setControls =');
  vm.runInContext(source,context);await context.initialized;
  const text=()=>nodes.get('[data-page-text]').children[0];
  const body=()=>text()?.children.map(x=>x.textContent).join(' / ');
  const toggle=async open=>{panel.open=open;panel.listeners.toggle();await tick();};
  const go=async n=>{nodes.get('[data-page]').value=String(n);nodes.get('[data-page-form]').listeners.submit({preventDefault(){}});await tick();};
  check(calls.size===0,'closed disclosure performs no initial text extraction');check(text().textContent.includes('Open Read text'),'closed disclosure explains deferred extraction');
  await go(2);check(calls.size===0,'closed disclosure does not extract on page turns');check(papers[0].querySelector('[data-page-label]').textContent===2,'page rendering/navigation remains ready');
  await toggle(true);check(calls.get(2)===1,'opening extracts current page only');check(body().includes('synthetic page 2'),'opening shows complete current text');
  await toggle(false);check(text().textContent.includes('Open Read text'),'closing releases displayed text DOM');await toggle(true);check(calls.get(2)===1,'reopening uses existing bounded text cache');
  await go(3);check(calls.get(3)===1&&body().includes('synthetic page 3'),'open disclosure follows page turns');
  context.cache.clear();const late=hold(2);await go(2);check(text().textContent.includes('Loading text'),'open disclosure announces pending extraction');await toggle(false);late.resolve({items:[{str:'complete late text'}]});await tick();check(text().textContent.includes('Open Read text'),'late completion does not repopulate closed disclosure');await toggle(true);check(body().includes('complete late text'),'reopen receives complete cached result');plans.delete(2);
  await toggle(false);context.cache.clear();const input=root.querySelector('[data-search-input]'),submit=nodes.get('[data-search-form]').listeners.submit,results=root.querySelector('[data-search-results]');
  input.value='synthetic';await submit({preventDefault(){}});check(results.children.length===3,'document search remains complete with disclosure closed');check(text().textContent.includes('Open Read text'),'search does not expand or populate closed display');const count=calls.get(2);await toggle(true);check(calls.get(2)===count&&body().includes('synthetic page 2'),'opening uses complete text already extracted by search');
  await toggle(false);context.cache.clear();const shared=hold(2);input.value='synthetic';const search=submit({preventDefault(){}});await tick();const sharedCount=calls.get(2);await toggle(true);check(calls.get(2)===sharedCount,'opening shares in-flight extraction with search');shared.resolve({items:[{str:'synthetic shared page 2'}]});await search;await tick();check(results.children.length===3&&body().includes('shared page 2'),'shared completion serves search and disclosure');plans.delete(2);
  context.cache.clear();await toggle(false);const old=hold(2);await toggle(true);await go(3);old.resolve({items:[{str:'stale page 2'}]});await tick();check(body().includes('synthetic page 3')&&!body().includes('stale'),'late old-page extraction cannot replace newer text');plans.delete(2);
  await toggle(false);context.cache.clear();const failure=hold(3);await toggle(true);failure.reject(new Error('synthetic text failure'));await tick();check(text().textContent.includes('could not be extracted'),'active extraction error remains visible');plans.delete(3);await toggle(false);await toggle(true);check(body().includes('synthetic page 3'),'reopening after extraction failure retries');
  await toggle(false);context.cache.clear();const departed=hold(3);await toggle(true);listeners.get('pagehide')({persisted:false});const before=text();departed.resolve({items:[{str:'departed'}]});await tick();check(text()===before,'departed extraction cannot replace UI');const total=[...calls.values()].reduce((a,b)=>a+b,0);await toggle(false);await toggle(true);check([...calls.values()].reduce((a,b)=>a+b,0)===total,'departed disclosure starts no new text work');
}
(async()=>{await scenario();console.log(checks+' lazy page-text checks passed; actual worker/network acceptance remains separate.');})().catch(e=>{console.error(e);process.exitCode=1;});
