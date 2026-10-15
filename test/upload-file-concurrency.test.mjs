import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../src/public/js/chunk-upload.js',import.meta.url),'utf8');
async function scenario(limit, expected) {
    let active=0, peak=0, started=0, done=0;
    const releases=[];
    const context={FormData:class{},LskyR2Direct:{async run(file, options){
        active++; started++; peak=Math.max(peak,active);
        try { await new Promise(resolve=>releases.push(resolve)); if(file.name==='bad') throw Error('test failure'); return {status:true}; }
        finally { active--; }
    }}};
    vm.createContext(context);vm.runInContext(source,context);
    const picker={0:{},fileupload:(op,key)=>key==='done'?()=>done++:()=>{}};
    const view={find:()=>({hide(){},show(){},text(){},css(){}})};
    const attach=context.LskyChunkUpload.create(picker,{directUrl:'/direct',useDirect:()=>true,fileConcurrency:limit});
    const rows=['a','bad','c','d'].map(name=>({files:[{name,size:100}],$preview:view}));
    rows.forEach(attach);
    const promises=rows.map(r=>r.submit());
    assert.equal(rows[0].submit(),promises[0],'duplicate submit reuses queued/running task');
    await new Promise(setImmediate);
    assert.equal(started,expected,'initial file concurrency respects role setting');
    while(started<4 || active) {
        releases.splice(0).forEach(resolve=>resolve());
        await new Promise(setImmediate);
    }
    await Promise.all(promises);
    assert.equal(peak,expected);assert.equal(done,3,'a failed file releases its slot and does not block later files');
}
await scenario(0,4);
await scenario(2,2);
await scenario(1,1);
await scenario(undefined,3);
const template=fs.readFileSync(new URL('../src/resources/views/components/upload.blade.php',import.meta.url),'utf8');
assert.match(template,/fileConcurrency:.*ConcurrentUploadNum/);
console.log('PASS: role file concurrency 0 unlimited, positive limits, default, duplicate submit and failure release');
