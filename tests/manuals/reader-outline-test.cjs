const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs';
const source=fs.readFileSync(path,'utf8');let checks=0;
const check=(v,m)=>{assert(v,m);checks++;};
const c={};vm.createContext(c);vm.runInContext(source.slice(source.indexOf('export function manualOutline'),source.indexOf('export function createPageResourceTracker')).replaceAll('export ',''),c);
(async()=>{
 let r=c.manualOutline(null);check(r.entries.length===0&&!r.truncated,'missing outline');
 r=c.manualOutline([{title:' <b>章</b> ',dest:[0],items:[{title:'child',dest:[{num:4,gen:0}]}]},{title:'named',dest:'section'}]);
 check(r.entries.length===3,'all entries');check(r.entries[0].title==='<b>章</b>','literal Unicode title');check(r.entries[1].depth===1,'nested depth');check(r.entries[2].dest==='section','named destination retained');
 for(const bad of [{url:'https://example.test',dest:[0]},{unsafeUrl:'javascript:alert(1)',dest:[0]},{action:'Print',dest:[0]},{dest:[-1]},{dest:[1.5]},{dest:'x'.repeat(1025)},{dest:[{num:1,gen:-1}]},{dest:{url:'x'}}]) check(c.manualOutline([bad]).entries[0].dest===null,'unsupported destination not retained');
 r=c.manualOutline(Array.from({length:10000},()=>({title:'x',dest:[0]})));check(r.entries.length===500&&r.truncated,'large list bounded');
 const cycle={title:'loop'};cycle.items=[cycle];r=c.manualOutline([cycle]);check(r.entries.length===12&&r.truncated,'cycle and depth bounded');
 r=c.manualOutline([{title:'x'.repeat(201)}]);check(r.entries[0].title.length===200&&r.truncated,'heading length bounded');
 r=c.manualOutline([{title:'x'.repeat(199)+'😀tail'}]);check(r.entries[0].title==='x'.repeat(199)&&r.truncated,'heading cap preserves surrogate pair');
 r=c.manualOutline([null,{title:'  '}]);check(r.entries.every(e=>e.title==='Untitled section'),'missing titles');
 let names=0,refs=0;const pdf={numPages:5000,getDestination:async n=>{names++;return n==='section'?[4999]:null;},getPageIndex:async()=>{refs++;return 20;}};
 check(await c.outlinePage(pdf,[0])===1,'zero based direct');check(await c.outlinePage(pdf,'section')===5000&&names===1,'named last page');check(await c.outlinePage(pdf,[{num:4,gen:0}])===21&&refs===1,'indirect reference');
 for(const dest of [null,[],[-1],[5000],[Infinity],[0.5],'missing',[{}]]){let failed=false;try{await c.outlinePage(pdf,dest);}catch(_){failed=true;}check(failed,'invalid destination rejected');}
 for(const n of [-1,5000,0.5,NaN]){let failed=false;try{await c.outlinePage({...pdf,getPageIndex:async()=>n},[{num:4,gen:0}]);}catch(_){failed=true;}check(failed,'resolved page boundary');}
 check(source.includes('button.textContent =')&&!source.includes('button.innerHTML'),'literal DOM label');
 console.log(checks+' outline model checks passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
