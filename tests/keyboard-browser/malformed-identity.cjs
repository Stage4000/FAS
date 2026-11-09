/* Pure exact malformed overlay identity; no browser/server/network. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),zlib=require('node:zlib'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const MANIFEST_SHA='f2d85b16feadfbb91c6fb8382e567c9355314a524cb5bedf0fd33962137bb299';
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
function manifest(){const raw=fs.readFileSync(path.join(__dirname,'malformed-sources.json'));assert.equal(hash(raw),MANIFEST_SHA,'exact malformed source manifest');return JSON.parse(raw);}
function restoreMain(data){const entry=manifest().files['public/js/main.js'];assert.equal(hash(data),entry.after_sha256,'exact malformed runtime candidate');const before=zlib.gunzipSync(Buffer.from(entry.baseline_gzip_b64,'base64'));assert.equal(hash(before),entry.before_sha256,'exact malformed baseline');return before;}
module.exports={manifest,restoreMain,MANIFEST_SHA};
