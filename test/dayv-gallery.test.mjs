import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';

const template=readFileSync(new URL('../src/resources/views/dayv-gallery/index.blade.php',import.meta.url),'utf8');
const script=readFileSync(new URL('../src/public/dayv-gallery/gallery.js',import.meta.url),'utf8');
const pause=()=>new Promise(r=>setTimeout(r,5));
const key='a'.repeat(32), other='b'.repeat(32);
const photo=n=>({key:'K'+n,name:n===1?'<img src=x onerror=alert(1)>.jpg':'mzt-'+n+'.jpg',width:600,height:800,thumbnail:'/thumb/'+n,url:'/original/'+n});
const set=(id=key)=>({key:id,number:1,title:'片刻 00001',count:2,href:'/sets/'+id,cover:photo(1),images:[photo(1),photo(2)]});
function mount(fetcher,initial=null){
    let html=template.replace(/<script id="gallery-bootstrap"[\s\S]*?<\/script>/,'<script id="gallery-bootstrap" type="application/json">'+JSON.stringify({title:'DAYV',initial}).replaceAll('<','\\u003c')+'</script>');
    html=html.replace(/<script src=[\s\S]*?<\/script>/g,'');
    const dom=new JSDOM(html,{url:'https://dayv.org/',runScripts:'outside-only'}),w=dom.window;
    w.AbortController=AbortController;w.fetch=fetcher;
    w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
    w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
    w.eval(script);return dom;
}
const ok=data=>({ok:true,status:200,json:async()=>data});
{
    const calls=[];
    const dom=mount(async(url,options)=>{calls.push({url,options});return ok(url.includes('/sets/'+key)?set():{sets:[set()],total:1,photos:2,next_page:null,snapshot:1,seed:'0123456789abcdef'});});
    const w=dom.window,d=w.document;await pause();
    assert.equal(d.querySelectorAll('.set-card').length,1);
    assert.equal(d.getElementById('set-count').textContent,'1');
    assert.equal(d.querySelector('.card-image img').getAttribute('src'),'/thumb/1');
    assert.ok(![...d.images].some(i=>i.getAttribute('src')?.startsWith('/original/')),'listing never fetches originals');
    d.querySelector('.set-card').click();await pause();
    assert.equal(d.getElementById('set-dialog').open,true);
    assert.equal(w.location.pathname,'/sets/'+key);
    assert.equal(d.querySelectorAll('.photo-tile').length,2);
    assert.equal(d.querySelectorAll('[onerror]').length,0,'filenames are text, not markup');
    d.querySelector('.photo-tile').click();
    assert.equal(d.getElementById('lightbox').open,true);
    assert.equal(d.getElementById('lightbox-image').getAttribute('src'),'/original/1');
    d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'ArrowRight'}));
    assert.equal(d.getElementById('lightbox-count').textContent,'2 / 2');
    d.getElementById('zoom-toggle').click();assert.equal(d.getElementById('zoom-toggle').getAttribute('aria-pressed'),'true');
    d.getElementById('lightbox-close').click();assert.equal(d.getElementById('set-dialog').open,true);
    d.getElementById('set-close').click();assert.equal(w.location.pathname,'/');assert.equal(d.body.classList.contains('has-dialog'),false);
    d.querySelector('[data-order=earliest]').click();await pause();assert.ok(calls.at(-1).url.includes('order=earliest'));
    d.querySelector('[data-order=random]').click();await pause();assert.ok(calls.at(-1).url.includes('order=random'));
    assert.ok(calls.every(c=>c.options.credentials==='omit' && !c.options.headers.Authorization));
    dom.window.close();console.log('PASS gallery listing, set browsing, text safety, keyboard and original-image demand loading');
}
{
    let attempt=0;const calls=[];
    const dom=mount(async url=>{calls.push(url);if(++attempt===1)throw new Error('network unavailable');return ok({sets:[set(other)],total:1,photos:2,next_page:null,snapshot:3,seed:'0123456789abcdef'});});
    await pause();const d=dom.window.document;
    assert.equal(d.getElementById('load-more').hidden,false);assert.match(d.getElementById('gallery-status').textContent,/network unavailable/);
    d.getElementById('load-more').click();await pause();assert.equal(d.querySelectorAll('.set-card').length,1);
    assert.match(calls[1],/page=1/);dom.window.close();console.log('PASS network errors offer a real retry and do not leave the interface busy');
}
{
    const calls=[];const dom=mount(async url=>{calls.push(url);return ok({sets:[set()],total:2,photos:4,next_page:calls.length===1?2:null,snapshot:20,seed:'0123456789abcdef'});});
    await pause();dom.window.document.getElementById('load-more').click();await pause();
    assert.match(calls[1],/page=2/);assert.match(calls[1],/snapshot=20/);assert.match(calls[1],/seed=0123456789abcdef/);
    dom.window.close();console.log('PASS pagination retains the same snapshot and random seed');
}
{
    const dom=mount(async()=>ok({sets:[],total:0,photos:0,next_page:null,snapshot:0,seed:'0123456789abcdef'}),set());
    await pause();assert.equal(dom.window.document.getElementById('set-dialog').open,true);
    assert.match(dom.window.document.getElementById('gallery-status').textContent,/整理中/);dom.window.close();console.log('PASS direct set entry and empty collection state');
}
