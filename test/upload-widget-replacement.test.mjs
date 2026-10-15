import assert from 'node:assert/strict';
import fs from 'node:fs';
import {JSDOM} from 'jsdom';
import jquery from 'jquery';
const dom=new JSDOM('<div id="lsky-upload-widget"><input type="file" id="picker" multiple><div id="preview"><span class="upload-info"></span><button data-operate="upload"></button></div></div>',{url:'https://site.test/upload',runScripts:'outside-only'});
const w=dom.window,$=jquery(w);w.jQuery=w.$=$;
for(const name of ['jquery.ui.widget.js','jquery.fileupload.js'])w.eval(fs.readFileSync(new URL('../src/public/js/blueimp-file-upload/'+name,import.meta.url),'utf8'));
w.eval(fs.readFileSync(new URL('../src/public/js/chunk-upload.js',import.meta.url),'utf8'));
// Reproduce the exact production error using the real plugin on a disposable input.
const legacy=$('<input type="file" id="legacy-picker">').appendTo(w.document.body);
legacy.fileupload({autoUpload:false,add(){}});
Object.defineProperty(legacy[0],'files',{value:[new w.File(['x'],'legacy.png')],configurable:true});legacy.trigger('change');
assert.throws(()=>legacy.fileupload('option','done'),/prior to initialization/);
let fail=false, successes=0, failures=0;const queue=[];
w.LskyR2Direct={run:async()=>{if(fail)throw new Error('实际校验失败');return{status:true,data:{links:{url:'https://r2.test/image.png'}}};}};
const host=$('#lsky-upload-widget');
const attach=w.LskyChunkUpload.create(host,{directUrl:'/upload/r2-direct',useDirect:()=>true,strategy:()=>3});
host.fileupload({fileInput:$('#picker'),autoUpload:false,
 add(e,data){data.$preview=$('#preview');attach(data);queue.push(data);},
 done(e,data){assert(data.result.status);successes++;},
 fail(e,data){assert.equal(data.jqXHR.responseJSON.message,'实际校验失败');failures++;}});
async function select(name){
 const old=$('#picker')[0];Object.defineProperty(old,'files',{value:[new w.File(['data'],name,{type:'image/png'})],configurable:true});
 $(old).trigger('change');
 assert.notEqual($('#picker')[0],old,'real blueimp replaces the file input');
 assert.equal($(old).data('blueimp-fileupload'),undefined,'old input has no widget data');
 const data=queue.at(-1);assert(data);await data.submit();
}
await select('small.png');assert.equal(successes,1);
await select('second.png');assert.equal(successes,2);
fail=true;await select('failure.png');assert.equal(failures,1);assert.equal($('#preview .upload-info').text(),'实际校验失败');
assert(host.fileupload('instance'),'stable wrapper retains the real uploader instance');
const blade=fs.readFileSync(new URL('../src/resources/views/components/upload.blade.php',import.meta.url),'utf8');
assert(blade.includes('id="lsky-upload-widget"'));assert(blade.includes("let $picker = $('#lsky-upload-widget');"));assert(blade.includes("fileInput: $('#picker')"));
dom.window.close();
console.log('PASS real blueimp file input replacement: small direct completion, repeat selection and error callbacks retain initialized widget');
