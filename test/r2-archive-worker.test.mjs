import assert from 'node:assert/strict';
import worker from '../tools/r2-archive-worker.mjs';
let requested, reads=0;
const env={APP_ORIGIN:'https://dayv.cc',SOURCE_STRATEGY_ID:'2',BUCKET:{get:async(path)=>{reads++;assert.equal(path,'1/old.png');return null;}}};
const originalFetch=globalThis.fetch;
try {
 globalThis.fetch=async(url,init)=>{requested={url:url instanceof Request?url.url:String(url),init};if(requested.url.endsWith('/health')) return Response.json({lsky_r2_archive:1,source:2});return new Response('archived',{headers:{'Content-Type':'image/png'}});};
 const req=new Request('https://r2.dayv.cc/1/old.png',{headers:{Range:'bytes=0-7',Cookie:'session=secret','X-CSRF-TOKEN':'secret'}});
 let response=await worker.fetch(req,env);assert.equal(await response.text(),'archived');
 const dest=new URL(requested.url);assert.equal(dest.origin,'https://dayv.cc');assert.equal(dest.pathname,'/archive/r2/2');assert.equal(dest.searchParams.get('path'),'1/old.png');
 assert.equal(requested.init.headers.get('Range'),'bytes=0-7');assert.equal(requested.init.headers.get('Cookie'),null);assert.equal(requested.init.headers.get('X-CSRF-TOKEN'),null);
 console.log('PASS old R2 URL forwards to exact archived path without credentials');
 env.BUCKET.get=async()=>({body:'r2-original',httpEtag:'"etag"',size:11,writeHttpMetadata:h=>h.set('Content-Type','image/png')});requested=null;
 response=await worker.fetch(new Request('https://r2.dayv.cc/1/old.png'),env);assert.equal(await response.text(),'r2-original');assert.equal(requested,null);
 console.log('PASS unmigrated object stays on R2 without origin lookup');
 env.BUCKET.get=async()=>({body:'abc',httpEtag:'"etag"',size:11,range:{offset:2,length:3},writeHttpMetadata:h=>h.set('Content-Type','image/png')});
 response=await worker.fetch(new Request('https://r2.dayv.cc/1/old.png',{headers:{Range:'bytes=2-4'}}),env);assert.equal(response.status,206);assert.equal(response.headers.get('Content-Range'),'bytes 2-4/11');
 console.log('PASS R2 range headers retained');
 response=await worker.fetch(new Request('https://r2.dayv.cc/.__lsky_archive_probe'),env);assert.equal(requested.url,'https://dayv.cc/archive/r2/2/health');
 console.log('PASS deployment probe uses configured source strategy');
 env.BUCKET.head=async()=>null;response=await worker.fetch(new Request('https://r2.dayv.cc/.__lsky_archive_probe?path=1%2Fold.png'),env);assert.equal(response.status,503);
 env.BUCKET.head=async()=>({httpEtag:'"etag"',size:11});response=await worker.fetch(new Request('https://r2.dayv.cc/.__lsky_archive_probe?path=1%2Fold.png'),env);let info=await response.json();assert.equal(info.bucket_etag,'"etag"');assert.equal(info.bucket_size,11);
 console.log('PASS readiness requires the actual R2 probe object in the bound bucket');
 response=await worker.fetch(new Request('https://r2.dayv.cc/old.png',{method:'POST'}),env);assert.equal(response.status,405);
 response=await worker.fetch(new Request('https://r2.dayv.cc/old.png'),{...env,APP_ORIGIN:'https://r2.dayv.cc'});assert.equal(response.status,503);
 console.log('PASS unsafe method and forwarding loop rejected');
} finally {globalThis.fetch=originalFetch;}
