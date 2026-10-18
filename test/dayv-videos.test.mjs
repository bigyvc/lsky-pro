import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';

const template=readFileSync(new URL('../src/resources/views/dayv-gallery/videos.blade.php',import.meta.url),'utf8');
const script=readFileSync(new URL('../src/public/dayv-gallery/videos.js',import.meta.url),'utf8');
const pause=()=>new Promise(r=>setTimeout(r,10));
const video={key:'AbC123',title:'<img src=x onerror=alert(1)>.mp4',href:'/videos/AbC123',poster:'/gallery-video-media/AbC123/poster',url:'/gallery-video-media/AbC123/original',duration:125,mime:'video/mp4'};
const ok=data=>({ok:true,json:async()=>data});
function mount(fetcher,initial=null){
 let html=template.replace(/<script id="video-bootstrap"[\s\S]*?<\/script>/,'<script id="video-bootstrap" type="application/json">'+JSON.stringify({title:'芳华录',initial}).replaceAll('<','\\u003c')+'</script>').replace(/<script src=[\s\S]*?<\/script>/g,'');
 const dom=new JSDOM(html,{url:'https://dayv.org/videos',runScripts:'outside-only'}),w=dom.window;
 w.AbortController=AbortController;w.fetch=fetcher;
 w.HTMLMediaElement.prototype.play=async function(){this.dispatchEvent(new w.Event('playing'));};
 w.HTMLMediaElement.prototype.pause=function(){};w.HTMLMediaElement.prototype.load=function(){};
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 w.eval(script);return dom;
}
{
 const calls=[];const dom=mount(async(url,options)=>{calls.push({url,options});return ok({videos:[video],total:1,next_page:null,snapshot:1});});
 await pause();const d=dom.window.document;
 assert.equal(d.querySelectorAll('.video-card').length,1);assert.equal(d.querySelector('.card-count').textContent,'2:05');
 assert.equal(d.querySelectorAll('[onerror]').length,0);assert.equal(d.querySelector('.card-info h3').textContent,video.title);
 assert.equal(d.querySelector('video').getAttribute('src'),null);assert.equal(d.querySelector('.card-image img').getAttribute('src'),video.poster);
 assert.equal(d.querySelector('.video-card').getAttribute('href'),video.href);
 assert.ok(calls.every(x=>!x.url.includes('/original')&&x.options.credentials==='omit'));
 d.querySelector('[data-order=earliest]').click();await pause();assert.match(calls.at(-1).url,/order=earliest/);
 dom.window.close();console.log('PASS video list loads posters only, uses safe text and supports ordering');
}
{
 const dom=mount(async()=>ok({videos:[],total:0,next_page:null,snapshot:0}),video);await pause();const d=dom.window.document;
 assert.equal(d.getElementById('video-player-section').hidden,false);
 assert.equal(d.getElementById('video-player').getAttribute('src'),null,'opening playback page does not fetch original');
 assert.equal(d.getElementById('video-player').getAttribute('preload'),'none');
 d.getElementById('video-start').click();await pause();assert.equal(d.getElementById('video-player').getAttribute('src'),video.url);
 assert.equal(d.getElementById('video-player-status').textContent,'播放中');
 d.getElementById('video-player').dispatchEvent(new dom.window.Event('error'));assert.equal(d.getElementById('video-start').hidden,false);
 dom.window.dispatchEvent(new dom.window.Event('pagehide'));assert.equal(d.getElementById('video-player').getAttribute('src'),null);
 dom.window.close();console.log('PASS playback requests bytes only after play, reports media errors and releases media on exit');
}
{
 const calls=[];const dom=mount(async url=>{calls.push(url);if(calls.length===1)throw new Error('network failed');return ok({videos:[video],total:2,next_page:calls.length===2?2:null,snapshot:42});});
 await pause();const d=dom.window.document;assert.equal(d.getElementById('video-more').hidden,false);
 d.getElementById('video-more').click();await pause();assert.match(calls[1],/page=1/);
 d.getElementById('video-more').click();await pause();assert.match(calls[2],/page=2/);assert.match(calls[2],/snapshot=42/);
 dom.window.close();console.log('PASS video retry and snapshot pagination');
}
