/* Pure source identity adapters; no browser/server/network/application execution. */
'use strict';const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const m=require('./malformed-identity.cjs'),q=require('./quantity.cjs'),s=require('./storage-unavailable.cjs');
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
const raw=fs.readFileSync(path.join(__dirname,'../../public/js/main.js')),before=m.restoreMain(raw);let checks=0;
assert.equal(hash(before),'3a38e022e228f573c06f5272fe80f56754a438fb40e2ed699f59990d2817abf2');checks++;
assert.equal(s.FIX,hash(before));checks++;
const quantityCandidate=q.storageMain(raw);assert.equal(hash(quantityCandidate),q.CANDIDATE_SHA);checks++;
assert.equal(hash(q.baselineSource(quantityCandidate)),q.BASELINE_SHA);checks++;
for(const changed of [before,raw.subarray(0,raw.length-1),Buffer.concat([raw,Buffer.from('changed')]),Buffer.from(raw.toString().replace('Number.isFinite','Number.isNaN'))]){assert.throws(()=>m.restoreMain(changed));checks++;}
for(const changed of [before,quantityCandidate]){assert.throws(()=>q.storageMain(changed));checks++;}
console.log(JSON.stringify({passed:true,checks,scope:'pure malformed→storage→quantity exact historical identities; no browser/server/network/application execution'}));
