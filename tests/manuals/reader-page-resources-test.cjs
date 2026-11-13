const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs';
const source=fs.readFileSync(path,'utf8');let checks=0;
const check=(v,label)=>{assert(v,label);checks++;console.log('PASS '+label);};
const start=source.indexOf('export function createPageResourceTracker()');const end=source.indexOf('export function spreadStart',start);assert(start>=0&&end>start,'resource tracker exists');
const context={};vm.createContext(context);vm.runInContext(source.slice(start,end).replace('export ',''),context);
const create=()=>context.createPageResourceTracker();
let calls=[];const page=n=>({cleanup(){calls.push(n);return true;}}),a=page('a'),b=page('b'),c=page('c');
let t=create();t.acquire(a);t.release(a);check(calls.join()==='a','settled nonvisible page cleaned');
calls=[];t=create();t.acquire(a);t.acquire(a);t.release(a);check(!calls.length,'overlapping draw keeps page resources');t.release(a);check(calls.join()==='a','last draw cleans idle page');t.release(a);check(calls.length===1,'extra release does not repeat cleanup');
calls=[];t=create();t.acquire(a);t.setVisible([a]);t.release(a);check(!calls.length,'visible page retained after completed render');t.setVisible([b]);check(calls.join()==='a','replacement cleans formerly visible page');t.setVisible([b]);check(calls.length===1,'unchanged visible page not cleaned');
calls=[];t=create();t.acquire(a);t.setVisible([a]);t.setVisible([b]);check(!calls.length,'replaced page still rendering retained');t.release(a);check(calls.join()==='a','replaced page cleaned when rendering settles');
calls=[];t=create();t.setVisible([a,b]);t.setVisible([b,c]);check(calls.join()==='a','overlapping spread retains shared visible page');t.setVisible([]);check(calls.join()==='a,b,c','clearing visible set cleans old spread');
calls=[];t=create();t.acquire(a);t.setVisible([a]);t.close();t.release(a);t.acquire(b);t.setVisible([b]);t.release(b);t.close();check(!calls.length,'permanent close leaves cleanup to document destruction');
t=create();t.acquire({cleanup(){throw new Error('cleanup failure')}});const bad={cleanup(){throw new Error('cleanup failure')}};t.acquire(bad);t.release(bad);check(true,'cleanup exception does not escape caller');const missing={};t.acquire(missing);t.release(missing);check(true,'compatibility with absent optional cleanup');
calls=[];const deferred={cleanup(){calls.push('deferred');return false;}};t=create();t.acquire(deferred);t.release(deferred);check(calls.length===1,'PDF.js deferred cleanup requested once without polling');
// Complete integration behavior is covered using the actual initialize/draw path below.
(async()=>{await require('./reader-page-resources-integration.cjs')(path,check);console.log(checks+' page-resource checks passed; not browser/native memory measurements.');})().catch(e=>{console.error(e);process.exitCode=1;});
