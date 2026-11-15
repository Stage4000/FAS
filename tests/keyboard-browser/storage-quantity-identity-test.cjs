/* Pure exact-byte adapter controls; no browser, network or application execution. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const q=require('./quantity.cjs'),digest=b=>crypto.createHash('sha256').update(b).digest('hex');
const source=fs.readFileSync(path.join(__dirname,'../../public/js/main.js'));
const before=q.storageMain(source);assert.equal(digest(before),q.CANDIDATE_SHA);
const quantityBaseline=q.baselineSource(before);assert.equal(digest(quantityBaseline),q.BASELINE_SHA);
let checks=2;
for(const mutation of [before,Buffer.concat([source,Buffer.from('changed')]),source.subarray(0,source.length-1),Buffer.from(source.toString().replace('Number.isFinite(newQuantity)','Number.isNaN(newQuantity)'))]){
 assert.throws(()=>q.storageMain(mutation));checks++;
}
for(const mutation of [source,quantityBaseline,Buffer.concat([before,Buffer.from('changed')])]){assert.throws(()=>q.baselineSource(mutation));checks++;}
console.log(JSON.stringify({passed:true,checks,scope:'pure quantity source adapter identities; no browser/network/application execution'}));
