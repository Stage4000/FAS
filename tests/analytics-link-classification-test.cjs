'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const cases=[['/product/6377','product_click'],['//flipandstrip.com/product/6377?x=1#pic','product_click'],['https://FLIPANDSTRIP.COM/product/6377','product_click'],['/products','none'],['https://other.example/product/6377?x=1#pic','external_link_click'],['//other.example/product/6377','external_link_click'],['https://flipandstrip.com.other.example/product/6377','external_link_click'],['https://flipandstrip.com@other.example/product/6377','external_link_click'],['https://www.ebay.com/product/6377','ebay_link_click,external_link_click'],['https://other.example/about','external_link_click'],['mailto:test@example.com','none'],['#section','none']];
async function exercise(source,href,expected,beaconWorks){
 const listeners={},payloads=[],attempts=[]; const storage=()=>({getItem(){return null},setItem(){},removeItem(){}});
 class Element {closest(selector){return selector==='a[href]'?this:null}getAttribute(name){return name==='href'?href:null}get textContent(){return 'test link'} }
 const document={title:'Synthetic',referrer:'',visibilityState:'visible',documentElement:{clientWidth:1200,clientHeight:800,scrollHeight:800,offsetHeight:800,scrollTop:0},body:{scrollHeight:800,offsetHeight:800,scrollTop:0},querySelectorAll(){return []},addEventListener(name,fn){listeners[name]=fn}};
 const location=new URL('https://flipandstrip.com/products');
 class FakeBlob{constructor(parts){this.body=parts.join('')}}
 const navigator={userAgent:'Synthetic',language:'en',cookieEnabled:true,sendBeacon(url,b){assert.equal(url,'/api/track-event.php');attempts.push('beacon');if(beaconWorks)payloads.push(JSON.parse(b.body));return beaconWorks}};
 const window={localStorage:storage(),sessionStorage:storage(),innerWidth:1200,innerHeight:800,screen:{width:1200,height:800},addEventListener(){},setTimeout(){return 1},setInterval(){return 1},requestAnimationFrame(fn){fn()},fetch(url,opts){assert.equal(url,'/api/track-event.php');attempts.push('fetch');payloads.push(JSON.parse(opts.body));return Promise.resolve({ok:true,status:200,json:()=>Promise.resolve({})})}};
 vm.runInNewContext(source,{window,document,navigator,location,URL,URLSearchParams,Element,HTMLFormElement:class{},HTMLInputElement:class{},Blob:FakeBlob,Intl,console});
 listeners.DOMContentLoaded(); await window.fasAnalytics.flush(false);await Promise.resolve();payloads.length=0;attempts.length=0;
 listeners.click({target:new Element}); await window.fasAnalytics.flush(false);await Promise.resolve();await window.fasAnalytics.flush(false);
 const events=payloads.flatMap(p=>p.events).filter(e=>['product_click','external_link_click','ebay_link_click'].includes(e.event_type));
 assert.equal(events.map(e=>e.event_type).join(',')||'none',expected,href);
 for(const e of events){const u=new URL(href,location);assert.equal(e.target_url,e.event_type==='product_click'?u.pathname:u.href);if(e.event_type!=='product_click')assert.equal(e.target_host,u.hostname)}
 if(expected.includes('external_link_click')){assert.ok(attempts.includes('beacon'));if(!beaconWorks)assert.ok(attempts.includes('fetch'))}
 return {href,beaconWorks,types:events.map(e=>e.event_type),attempts};
}
(async()=>{const fixed=fs.readFileSync(__dirname+'/../public/js/analytics.js','utf8');const clause='if (productMatch && url.hostname === location.hostname) {';assert.equal(fixed.split(clause).length,2);const sourceByName={baseline:fixed.replace(clause,'if (productMatch) {'),candidate:fixed};const paths=['baseline','candidate'];const result={};for(const path of paths){let passes=0,failures=[];for(const [href,expected]of cases)for(const beacon of [true,false]){try{await exercise(sourceByName[path],href,expected,beacon);passes++}catch(e){failures.push({href,beacon,message:e.message})}}result[path]={passes,failures};}assert.equal(result[paths[0]].passes,14);assert.equal(result[paths[0]].failures.length,10);assert.equal(result[paths[1]].passes,24);assert.equal(result[paths[1]].failures.length,0);console.log(JSON.stringify(result,null,2))})().catch(e=>{console.error(e);process.exitCode=1});
