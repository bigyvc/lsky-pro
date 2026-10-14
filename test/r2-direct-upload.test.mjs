import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
const MiB = 1024 * 1024;
const size = 354 * MiB + 634 * 1024;
let maxRead = 0, transfers = 0, maxTransfers = 0, puts = [], failOnce = true, expireOnce = true, refreshes = 0, signatures = {};
const tasks = new Map(), saved = new Map();
class Form { constructor(){this.data=new Map();} append(k,v){this.data.set(k,v);} get(k){return this.data.get(k);} }
class XHR {
 constructor(){this.upload={};this.headers={};}
 open(method,url){this.method=method;this.url=url;}
 setRequestHeader(k,v){this.headers[k.toLowerCase()]=v;}
 abort(){this.aborted=true;this.onabort?.();}
 send(body){
  const finish=data=>{if(this.aborted)return;this.status=200;this.responseText=JSON.stringify(data);this.onload();};
  if(this.method==='PUT'){
   assert(this.headers['content-md5']);assert(!this.headers['x-csrf-token']);assert(!this.withCredentials);
   const index=Number(new URL(this.url).searchParams.get('part'));
   assert.equal(body.size,Math.min(50*MiB,size-index*50*MiB));
   transfers++;maxTransfers=Math.max(maxTransfers,transfers);puts.push(index);
   return setTimeout(()=>{
    transfers--;if(this.aborted)return;
    if(index===1&&expireOnce){expireOnce=false;this.status=403;return this.onload();}
    if(index===2&&failOnce){failOnce=false;return this.onerror();}
    this.upload.onprogress?.({lengthComputable:true,loaded:body.size,total:body.size});
    this.status=200;this.onload();
   },10);
  }
  assert.equal(this.headers['x-csrf-token'],'CSRF');
  if(this.url.endsWith('/sign')){refreshes++;return setTimeout(()=>finish({status:true,data:signatures[Number(body.get('index'))]}),1);}
  if(this.url.endsWith('/complete'))return setTimeout(()=>finish({status:true,data:{id:1,links:{url:'r2'}}}),1);
  assert.equal(this.url,'https://site/upload/r2-direct');
  assert.equal(Number(body.get('size')),size);
  const parts=JSON.parse(body.get('parts_json'));
  assert.equal(parts.length,8);assert.equal(parts[7].size,size-350*MiB);
  const signed={};parts.forEach((p,index)=>{if(index!==0)signed[index]={url:`https://r2.test/?part=${index}`,md5:Buffer.from(p.md5,'hex').toString('base64'),size:p.size};});
  signatures=signed;
  return setTimeout(()=>finish({status:true,data:{id:'a'.repeat(32),received:[0],parts:signed,uploaded:false,result:null}}),1);
 }
}
const context={console,crypto:webcrypto,WebAssembly,Uint8Array,ArrayBuffer,TextEncoder,TextDecoder,Buffer,FormData:Form,XMLHttpRequest:XHR,
 setTimeout:(fn,ms)=>setTimeout(fn,Math.min(ms,15)),localStorage:{getItem:k=>saved.get(k)||null,setItem:(k,v)=>saved.set(k,v),removeItem:k=>saved.delete(k)}};
vm.createContext(context);
for(const path of ['hash-wasm/md5.umd.min.js','hash-wasm/sha1.umd.min.js','chunk-upload.js','r2-direct-upload.js'])vm.runInContext(fs.readFileSync(new URL('../src/public/js/'+path,import.meta.url),'utf8'),context);
const file={name:'large.png',size,lastModified:1,slice(start,end){return{size:end-start,arrayBuffer:async()=>{maxRead=Math.max(maxRead,end-start);assert(end-start<=MiB);return new Uint8Array(end-start).fill(120).buffer;}};}};
const md5=createHash('md5'),sha1=createHash('sha1');
for(let offset=0;offset<size;offset+=MiB){const buffer=Buffer.alloc(Math.min(MiB,size-offset),120);md5.update(buffer);sha1.update(buffer);}
const actual=await context.LskyR2Direct.hashes(file,()=>false,()=>{});
assert.equal(actual.md5,md5.digest('hex'));assert.equal(actual.sha1,sha1.digest('hex'));assert.equal(maxRead,MiB);
assert.equal(context.LskyR2Direct.CONCURRENCY,6);assert.equal(context.LskyR2Direct.CHUNK_SIZE,50*MiB);
let successes=0,failures=0;
const picker={0:{},fileupload:(_,name)=>name==='done'?()=>successes++:()=>failures++};
const preview={find:()=>({hide(){return this;},text(){return this;},css(){return this;}})};
let direct=true,native=0;
const attach=context.LskyChunkUpload.create(picker,{url:'https://site/upload/chunks',directUrl:'https://site/upload/r2-direct',csrf:'CSRF',strategy:()=>1,useDirect:()=>direct});
const data={files:[file],$preview:preview,submit:()=>native++,abort(){}};attach(data);await data.submit();
assert.equal(successes,1);assert.equal(failures,0);assert.equal(maxTransfers,6);assert(!puts.includes(0));assert.equal(puts.filter(p=>p===2).length,2);assert.equal(saved.size,0);assert.equal(refreshes,1);
direct=false;const small={files:[{size:100,name:'small.png'}],submit:()=>native++,abort(){}};attach(small);small.submit();assert.equal(native,1);
await assert.rejects(context.LskyR2Direct.hashes(file,()=>true,()=>{}),e=>e.cancelled);
console.log('PASS full-file streaming hashes, 1MiB read budget, 50MiB R2 parts, six concurrent PUTs, resume, retry, no CSRF/cookies to R2, relay selectable');
