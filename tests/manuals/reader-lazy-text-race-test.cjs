/* Focused state regression only: DOM/PDF stubs, never browser acceptance. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;
const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
async function fixture(){
  const nodes=new Map(),listeners=new Map(),plans=new Map(),calls=new Map();
  const renderPlans=new Map(),animations=[];
  const tick=()=>new Promise(r=>setTimeout(r,0));
  function hold(n){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});plans.set(n,promise);return{resolve,reject};}
  function element(tag='DIV'){
    return {tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',
      addEventListener(t,fn){this.listeners[t]=fn;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},
      append(...items){this.children.push(...items);},replaceChildren(...items){this.children=items;},replaceWith(){},focus(){},animate(){let resolve,reject;const finished=new Promise((a,b)=>{resolve=a;reject=b});const animation={finished,resolve,cancel(){reject(new Error('canceled'))}};animations.push(animation);return animation;},getContext(){return{};},
      querySelector(selector){this.nodes??=new Map();if(!this.nodes.has(selector))this.nodes.set(selector,element());return this.nodes.get(selector);}};
  }
  const panel=element('DETAILS');panel.open=false;
  const root=element();root.dataset.pdfUrl='/manuals/file.php?id=1';
  root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,element());if(s==='[data-page-text]')nodes.get(s).closest=()=>panel;return nodes.get(s);};
  const papers=[element(),element()];root.querySelectorAll=()=>papers;
  const pdf={numPages:3,async getPage(n){return{getViewport({scale}){return{width:300*scale,height:400*scale};},render(){return{promise:renderPlans.get(n)||Promise.resolve(),cancel(){}};},async getTextContent(){calls.set(n,(calls.get(n)||0)+1);if(plans.has(n))return await plans.get(n);return{items:[{str:'synthetic page '+n}]};}};}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(){}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},
    getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),ResizeObserver:class{observe(){}disconnect(){}},
    document:{querySelector:()=>root,createElement:tag=>element(tag.toUpperCase()),createDocumentFragment:()=>element(),addEventListener(){}},
    window:{matchMedia:()=>({matches:false,addEventListener(){}}),addEventListener(t,f){listeners.set(t,f)},devicePixelRatio:1},
    pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){}})}};
  vm.createContext(context);
  let source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
  source=source.replace('  const setControls =', '  globalThis.displayText=displayText; globalThis.owner=()=>renderEpoch; globalThis.displayEpoch=()=>textEpoch; globalThis.getText=textFor; globalThis.cache=textCache; globalThis.inFlight=typeof pendingText!=="undefined"?pendingText:null;\n  const setControls =');
  vm.runInContext(source,context);await context.initialized;
  return {nodes,root,papers,panel,plans,calls,context,hold,tick,renderPlans,animations};
}

(async()=>{
 for(const fail of [false,true]){
  const f=await fixture();const oldText=f.hold(1);let releaseRender;
  f.renderPlans.set(2,new Promise(r=>releaseRender=r));
  f.nodes.get('[data-page]').value='2';f.nodes.get('[data-page-form]').listeners.submit({preventDefault(){}});await f.tick();
  f.panel.open=true;f.panel.listeners.toggle();await f.tick();
  check(f.calls.get(1)===1,'opening while render pending requests currently visible page');
  releaseRender();await f.tick();check(f.animations.length===1,'outgoing animation reached');
  f.animations[0].resolve();await f.tick();check(f.animations.length===2,'incoming animation is pending after commit');
  check(f.papers[0].querySelector('[data-page-label]').textContent===2,'new page really committed');
  const current=f.nodes.get('[data-page-text]').children[0];
  if(fail)oldText.reject(new Error('old page failed'));else oldText.resolve({items:[{str:'Old page one text'}]});await f.tick();
  check(f.nodes.get('[data-page-text]').children[0]===current,'old '+(fail?'error':'text')+' cannot overwrite new page during animation');
  f.animations[1].resolve();await f.tick();
  const content=f.nodes.get('[data-page-text]').children[0];
  check(content.children?.some(x=>x.textContent==='synthetic page 2'),'current complete text appears after animation');
  const epoch=f.context.displayEpoch(),calls=[...f.calls.values()].reduce((a,b)=>a+b,0);
  await f.context.displayText([1],f.context.owner());
  check(f.context.displayEpoch()===epoch&&f.nodes.get('[data-page-text]').children[0]===content,'stale selected pages cannot mutate display epoch or placeholder');
  await f.context.displayText([2],f.context.owner()-1);
  check(f.context.displayEpoch()===epoch&&f.nodes.get('[data-page-text]').children[0]===content,'stale render owner cannot mutate display epoch or placeholder');
  check([...f.calls.values()].reduce((a,b)=>a+b,0)===calls,'stale display entry starts no extraction');
 }
 console.log(checks+' lazy-text navigation/animation race checks passed; controlled promises, not browser acceptance.');
})().catch(e=>{console.error(e);process.exitCode=1;});
