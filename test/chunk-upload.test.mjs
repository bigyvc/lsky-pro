import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import { webcrypto } from 'node:crypto';
const source = fs.readFileSync(new URL('../src/public/js/chunk-upload.js', import.meta.url), 'utf8');
const MiB = 1024 * 1024;
const saved = new Map();
const tasks = new Map();
let simultaneous = 0, peak = 0, commitCount = 0, partRequests = [], idNumber = 0, failPartOnce = true;
class Form { constructor() { this.entries = new Map(); } append(k,v) { this.entries.set(k,v); } get(k) { return this.entries.get(k); } }
class FakeXHR {
    constructor() { this.upload = {}; }
    open(method,url) { this.url=url; }
    setRequestHeader() {}
    abort() { this.aborted = true; this.onabort?.(); }
    send(body) {
        const match = this.url.match(/\/([a-f0-9]{32})\/(part|complete)$/);
        const finish = data => { if(this.aborted)return; this.status=200;this.responseText=JSON.stringify(data);this.onload(); };
        if (!match) {
            const old=body.get('id');
            const id=tasks.has(old)?old:(++idNumber).toString(16).padStart(32,'0');
            if(!tasks.has(id))tasks.set(id,{received:new Set([0]),result:null});
            return setTimeout(()=>finish({status:true,data:{id,received:[...tasks.get(id).received],result:tasks.get(id).result}}),1);
        }
        const task=tasks.get(match[1]);
        if(match[2]==='complete') {
            if(!task.result) { commitCount++; task.result={status:true,data:{id:123,links:{url:'test'}}}; }
            return setTimeout(()=>finish(task.result),1);
        }
        const index=Number(body.get('index'));
        simultaneous++;peak=Math.max(peak,simultaneous);partRequests.push(index);
        setTimeout(()=>{
            simultaneous--;
            if(this.aborted)return;
            if(index===2&&failPartOnce) {failPartOnce=false;return this.onerror();}
            this.upload.onprogress?.({lengthComputable:true,loaded:body.get('file').size,total:body.get('file').size});
            task.received.add(index);
            finish({status:true,data:{}});
        },8);
    }
}
const context={console,crypto:webcrypto,Uint8Array,FormData:Form,XMLHttpRequest:FakeXHR,
    setTimeout:(fn,ms)=>setTimeout(fn,Math.min(ms,10)),
    localStorage:{getItem:k=>saved.get(k)||null,setItem:(k,v)=>saved.set(k,v),removeItem:k=>saved.delete(k)}};
vm.createContext(context);vm.runInContext(source,context);
const {CHUNK_SIZE,CONCURRENCY,retry,workers,create}=context.LskyChunkUpload;
assert.equal(CHUNK_SIZE,50*MiB);assert.equal(CONCURRENCY,6);
let active=0,maxActive=0,seen=[];
await workers([0,1,2,3,4,5,6,7],async i=>{active++;maxActive=Math.max(active,maxActive);await new Promise(r=>setTimeout(r,3));seen.push(i);active--;},()=>false);
assert.equal(maxActive,6);assert.equal(seen.length,8);
let attempts=0;await retry(async()=>{if(++attempts<4)throw {retryable:true};},()=>false,async()=>{});assert.equal(attempts,4);
attempts=0;await assert.rejects(retry(async()=>{attempts++;throw Object.assign(new Error('413'),{retryable:false});},()=>false,async()=>{}));assert.equal(attempts,1);
let done=0,failed=0;
const picker={0:{},fileupload:(option,name)=>name==='done'?()=>{done++;}:()=>{failed++;}};
const preview={find:()=>({hide(){return this;},text(){return this;},css(){return this;}})};
function file(name) {return {name,size:354*MiB+634*1024,lastModified:1,slice(start,end){return{size:end-start,arrayBuffer:async()=>new Uint8Array(Math.min(end-start,MiB)).buffer};}};}
const attach=create(picker,{url:'https://example/upload/chunks',csrf:'token',strategy:()=>1,fileConcurrency:1});
const data={files:[file('large.png')],$preview:preview};attach(data);await data.submit();
assert.equal(done,1);assert.equal(failed,0);assert.equal(peak,6);assert.equal(commitCount,1);
assert(!partRequests.includes(0),'resume skips previously acknowledged first part');
assert.equal(partRequests.filter(i=>i===2).length,2,'failed part retries');
assert.equal(saved.size,0,'successful completion clears resume identity');
// An explicit file limit of one preserves serial relay uploads.
peak=0;const a={files:[file('a.png')],$preview:preview},b={files:[file('b.png')],$preview:preview};attach(a);attach(b);await Promise.all([a.submit(),b.submit()]);assert.equal(peak,6);assert.equal(done,3);
let nativeCalls=0;const small={files:[file('small.png')],$preview:preview,submit:()=>nativeCalls++};small.files[0].size=100;attach(small);await small.submit();assert.equal(nativeCalls,0);assert.equal(done,4,'small relay files use the chunk completion path');
console.log('PASS 50 MiB / 6 workers; retry; resume skips accepted parts; whole-page concurrency; completion; small-file compatibility');
