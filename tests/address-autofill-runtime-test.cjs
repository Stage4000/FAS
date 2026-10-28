const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const sourcePath=process.argv[2]||path.join(__dirname,'../public/js/address-autofill.js');
const source=fs.readFileSync(sourcePath,'utf8');
const now=1800000000000,ttl=30*24*60*60*1000;
const address=(changes={})=>({city:'Fixture City',state:'NV',zip:'89001',country:'US',source:'saved',saved_at:now-1000,...changes});
function fixture({saved,forgotten=false,values={},storageMode='normal',readyState='loading',responseMode='success'}={}) {
    const stored=new Map(),requests=[],timers=new Map(),errors=[];
    if(saved!==undefined)stored.set('fas_shipping_address',typeof saved==='string'?saved:JSON.stringify(saved));
    if(forgotten)stored.set('fas_checkout_details_forgotten','1');
    function field(value='') {return {value,events:[],dispatchEvent(event){this.events.push(event.type);}};}
    function form(id) {
        const fields=Object.fromEntries(['city','state','zip'].map(k=>[k,field(values[id]?.[k]||'')]));
        const form={id,fields,dataset:{},listeners:{},querySelector(selector){return fields[selector.includes('"city"')?'city':selector.includes('"state"')?'state':'zip'];},addEventListener(name,fn){this.listeners[name]=fn;}};
        for(const field of Object.values(fields))field.dispatchEvent=function(event){this.events.push(event.type);form.listeners[event.type]?.(event);};
        return form;
    }
    const checkout=form('checkout-form'),estimator=form('estimator'),listeners={};
    const storage={getItem(k){if(storageMode==='methods')throw Error('Synthetic storage access denied');return stored.get(k)??null;},setItem(k,v){if(storageMode==='methods')throw Error('Synthetic storage write denied');stored.set(k,v);},removeItem(k){stored.delete(k);}};
    class Clock extends Date {static now(){return now;}}
    const window={clearTimeout:id=>timers.delete(id),setTimeout:fn=>{const id=timers.size+1;timers.set(id,fn);return id;}};
    Object.defineProperty(window,'localStorage',{get(){if(storageMode==='getter')throw Error('Synthetic localStorage getter denied');return storage;}});
    const document={readyState,querySelectorAll:()=>[checkout,estimator,checkout],getElementById:id=>id==='checkout-form'?checkout:null,addEventListener:(name,fn)=>{listeners[name]=fn;}};
    const context=vm.createContext({window,document,Date:Clock,URLSearchParams,Event:class{constructor(type){this.type=type;}},fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))});
    // Run the entire actual application script; no source extraction or rewritten helpers.
    try{vm.runInContext(source,context,{timeout:1000});if(listeners.DOMContentLoaded)listeners.DOMContentLoaded();}catch(error){errors.push(error.message);}
    async function settle(data=address({source:'defaults'})) {
        if(requests[0]) {
            if(responseMode==='reject')requests[0].reject(Error('Synthetic unavailable'));
            else requests[0].resolve({ok:responseMode!=='http-error',json:async()=>responseMode==='malformed'?null:{success:true,has_address:!!data,address:data}});
        }
        await new Promise(resolve=>setImmediate(resolve));
    }
    function valuesOf(form){return Object.fromEntries(Object.entries(form.fields).map(([k,f])=>[k,f.value]));}
    return {checkout,estimator,stored,requests,timers,errors,settle,valuesOf};
}
let checks=0,scenarios=0;const failures=[];
function check(value,label){checks++;if(!value)failures.push(label);}
function equal(a,b,label){check(JSON.stringify(a)===JSON.stringify(b),label);}
const empty={city:'',state:'',zip:''},filled={city:'Fixture City',state:'NV',zip:'89001'};
async function scenario(name,run){await run();scenarios++;}
(async()=>{
    await scenario('saved address initialization and event deduplication',async()=>{
        const f=fixture({saved:address()});equal(f.valuesOf(f.checkout),filled,'saved checkout filled');equal(f.valuesOf(f.estimator),filled,'saved estimator filled');
        for(const form of [f.checkout,f.estimator])for(const field of Object.values(form.fields))equal(field.events,['input','change'],'one event pair per saved field');
        check(f.requests.length===1,'one synthetic defaults request');await f.settle();
        for(const form of [f.checkout,f.estimator])for(const field of Object.values(form.fields))check(field.events.length===2,'defaults do not overwrite filled fields');
    });
    await scenario('forgotten checkout excluded while estimator remains usable',async()=>{
        const f=fixture({saved:address(),forgotten:true});equal(f.valuesOf(f.checkout),empty,'forgotten checkout empty');equal(f.valuesOf(f.estimator),filled,'estimator uses saved address');
        await f.settle();equal(f.valuesOf(f.checkout),empty,'defaults respect forgotten marker');check(f.stored.get('fas_checkout_details_forgotten')==='1','marker retained');
        check(!f.checkout.listeners.submit,'forgotten checkout has no saving listener');
    });
    await scenario('occupied values preserved before delayed defaults',async()=>{
        const custom={city:'Typed City',state:'OR',zip:'97035'};const f=fixture({values:{'checkout-form':custom}});
        await f.settle();equal(f.valuesOf(f.checkout),custom,'occupied fields retained');for(const field of Object.values(f.checkout.fields))check(field.events.length===0,'occupied field emits no autofill event');
        equal(f.valuesOf(f.estimator),filled,'empty estimator receives defaults');
    });
    await scenario('typing while defaults pending preserved',async()=>{
        const f=fixture();f.checkout.fields.city.value='Typed During Wait';await f.settle();
        check(f.checkout.fields.city.value==='Typed During Wait','late defaults preserve typed field');check(f.checkout.fields.city.events.length===0,'no event for occupied city');check(f.checkout.fields.state.value==='NV','empty state filled');
    });
    for(const saved of ['not-json','null','[]','42',address({country:'CA'}),address({saved_at:now-ttl-1})]) {
        await scenario('invalid or expired saved value',async()=>{const f=fixture({saved});equal(f.valuesOf(f.checkout),empty,'invalid saved value unused');await f.settle();equal(f.valuesOf(f.checkout),filled,'fresh defaults still apply');});
    }
    for(const responseMode of ['reject','http-error','malformed']) {
        await scenario('defaults failure '+responseMode,async()=>{const f=fixture({responseMode});await f.settle();equal(f.valuesOf(f.checkout),empty,'failed defaults leave empty inputs');check(f.errors.length===0,'defaults failure not thrown');});
    }
    await scenario('saving typed address uses real handler',async()=>{
        const f=fixture();await f.settle(null);for(const [k,v] of Object.entries(filled))f.checkout.fields[k].value=v;
        f.checkout.listeners.change();const saved=JSON.parse(f.stored.get('fas_shipping_address'));
        equal({city:saved.city,state:saved.state,zip:saved.zip},filled,'typed address saved');check(saved.saved_at===now&&saved.source==='user','existing metadata saved');
    });
    for(const storageMode of ['methods','getter']) {
        await scenario('storage unavailable '+storageMode,async()=>{
            const f=fixture({storageMode});check(f.errors.length===0,'storage '+storageMode+' initialization does not throw');check(f.requests.length===1,'storage '+storageMode+' defaults still requested');await f.settle();
            equal(f.valuesOf(f.checkout),filled,'storage '+storageMode+' defaults apply');
            for(const [key,value] of Object.entries(filled))f.checkout.fields[key].value=value;
            let error=null;try{f.checkout.listeners.change?.();}catch(e){error=e.message;}
            check(error===null,'storage '+storageMode+' save does not throw');
        });
    }
    console.log(JSON.stringify({scenarios,checks,failures,sourcePath}));process.exitCode=failures.length?1:0;
})().catch(error=>{console.error(error);process.exitCode=2;});
