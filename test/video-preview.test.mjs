import assert from 'node:assert/strict';
import fs from 'node:fs';
import {JSDOM} from 'jsdom';
const dom=new JSDOM('<div id="images-grid"><a class="images-item"><img alt="clip" data-original="https://r2.test/clip.MOV?x=1"></a></div>',{url:'https://site.test',runScripts:'outside-only'});
const w=dom.window;
w.HTMLMediaElement.prototype.load=function(){this.didLoad=true;};
w.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
w.HTMLDialogElement.prototype.close=function(){this.dispatchEvent(new w.Event('close'));};
w.eval(fs.readFileSync(new URL('../src/public/js/video-preview.js',import.meta.url),'utf8'));
const api=w.LskyVideoPreview;
assert(api.isVideo('https://r2.test/clip.MOV?x=1'));assert(api.isVideo('/clip.hevc'));assert(!api.isVideo('javascript:video.mp4'));assert(!api.isVideo('/image.png'));
w.document.querySelector('img').click();
let dialog=w.document.querySelector('dialog');assert(dialog);assert(dialog.querySelector('video').controls);assert(dialog.querySelector('video').playsInline);assert.equal(dialog.querySelector('video').preload,'metadata');
const video=dialog.querySelector('video');dialog.querySelector('button').click();assert(!w.document.querySelector('dialog'));assert(!video.getAttribute('src'));
dialog=api.open('/stream.h265','<img onerror=alert(1)>');assert(dialog);assert(!dialog.querySelector('video'));assert(!dialog.querySelector('img'));assert(dialog.textContent.includes('暂不提供网页播放'));assert(!dialog.querySelector('a'));dialog.querySelector('button').click();
assert.equal(api.open('data:text/html,video.mp4'),undefined);
for(const ext of ['m4v','mkv','avi','wmv','flv','ts','m2ts','mts','3gp','m3u8']) assert(api.isVideo('/clip.'+ext));
dialog=api.open('/clip.avi','clip');assert(!dialog.querySelector('a'));assert(!dialog.querySelector('video'));dialog.querySelector('button').click();
dialog=api.open('/clip.mp4','clip');assert.equal(dialog.querySelector('video').getAttribute('controlslist'),null);assert(!dialog.querySelector('[download]'));dialog.querySelector('button').click();
for(const ext of ['mp3','flac','wav','m4a','aac','ogg','opus']) {
 dialog=api.open('/tone.'+ext,'tone');
 assert(dialog.querySelector('audio'));assert(!dialog.querySelector('video'));assert(!dialog.querySelector('[download]'));
 const media=dialog.querySelector('audio');dialog.querySelector('button').click();assert(!media.getAttribute('src'));
}
for(const ext of ['wma','ape','aif','aiff']) {
 dialog=api.open('/tone.'+ext,'tone');assert(!dialog.querySelector('audio'));assert(!dialog.querySelector('a'));dialog.querySelector('button').click();
}
console.log('PASS media preview, no share/download controls, safe names/URLs and player cleanup');
