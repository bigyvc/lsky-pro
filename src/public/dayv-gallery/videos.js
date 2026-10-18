/* Video listing loads posters only; original bytes are requested after an explicit play action. */
(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const boot = JSON.parse($('video-bootstrap').textContent);
    const grid = $('video-grid'), more = $('video-more'), status = $('video-status');
    const player = $('video-player'), start = $('video-start');
    let order = 'latest', page = 1, snapshot = null, loaded = 0, busy = false, epoch = 0, request = null;
    function duration(value) {
        if (!Number.isFinite(value) || value <= 0) return '视频';
        const seconds = Math.floor(value), h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = String(seconds % 60).padStart(2, '0');
        return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
    }
    function poster(video) {
        const image = document.createElement('img'); image.src = video.poster; image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
        image.addEventListener('load', () => image.classList.add('loaded'));
        image.addEventListener('error', () => {if (!image.dataset.fallback) {image.dataset.fallback = '1'; image.src = '/static/video.svg';} image.classList.add('loaded');});
        return image;
    }
    function card(video) {
        const link = document.createElement('a'); link.className = 'set-card video-card'; link.href = video.href;
        const picture = document.createElement('div'); picture.className = 'card-image'; picture.append(poster(video));
        const play = document.createElement('span'); play.className = 'video-play-icon'; play.textContent = '▷'; play.setAttribute('aria-hidden', 'true');
        const time = document.createElement('span'); time.className = 'card-count'; time.textContent = duration(video.duration); picture.append(play, time);
        const info = document.createElement('div'); info.className = 'card-info';
        const title = document.createElement('h3'); title.textContent = video.title; info.append(title); link.append(picture, info);
        return link;
    }
    async function load(reset = false) {
        if (busy && !reset) return;
        if (reset) {epoch++; request?.abort(); page = 1; snapshot = null; loaded = 0; grid.replaceChildren();}
        const current = epoch; request = new AbortController(); const controller = request;
        busy = true; more.disabled = true; grid.setAttribute('aria-busy', 'true'); status.textContent = '正在打开视频…';
        const params = new URLSearchParams({page: String(page), order}); if (snapshot !== null) params.set('snapshot', String(snapshot));
        const timer = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch('/gallery-api/videos?' + params, {signal: controller.signal, credentials: 'omit', headers: {Accept: 'application/json'}});
            if (!response.ok) throw new Error('暂时无法加载视频，请稍后重试。');
            const data = await response.json(); if (current !== epoch) return;
            if (!Array.isArray(data.videos)) throw new Error('视频数据暂时不可用。');
            if (page === 1) grid.replaceChildren();
            data.videos.forEach(video => {grid.append(card(video)); loaded++;});
            page = data.next_page; snapshot = data.snapshot; $('video-count').textContent = Number(data.total).toLocaleString('zh-CN');
            more.hidden = page === null; more.textContent = '再看一些 ↓';
            status.textContent = loaded ? `已展开 ${loaded} 段视频${page === null ? '，已全部展示。' : ''}` : '视频还在整理中，稍后再来看看。';
        } catch (error) {
            if (current !== epoch) return;
            status.textContent = error.name === 'AbortError' ? '加载超时，请重新加载。' : error.message;
            more.hidden = false; more.textContent = '重新加载 ↻';
        } finally {clearTimeout(timer); if (current === epoch) {busy = false; more.disabled = false; grid.setAttribute('aria-busy', 'false');}}
    }
    function present(video) {
        $('video-player-section').hidden = false; $('video-title').textContent = video.title;
        player.poster = video.poster; start.hidden = false;
        document.title = video.title + ' · ' + boot.title;
        start.addEventListener('click', async () => {
            start.hidden = true; $('video-player-status').textContent = '正在加载视频…';
            if (!player.getAttribute('src') || player.error) {player.src = video.url; player.load();}
            try {await player.play();} catch (_) {start.hidden = false; $('video-player-status').textContent = '未能开始播放，请重试或换用支持此编码的浏览器。';}
        });
        player.addEventListener('playing', () => {$('video-player-status').textContent = '播放中';});
        player.addEventListener('waiting', () => {$('video-player-status').textContent = '正在缓冲…';});
        player.addEventListener('error', () => {start.hidden = false; $('video-player-status').textContent = '视频暂时无法播放，可能是格式不兼容或媒体读取失败。';});
        window.addEventListener('pagehide', () => {player.pause(); player.removeAttribute('src'); player.load(); start.hidden = false; $('video-player-status').textContent = '点击播放后才加载视频。';});
    }
    more.addEventListener('click', () => load());
    document.querySelectorAll('[data-order]').forEach(button => button.addEventListener('click', () => {
        order = button.dataset.order; document.querySelectorAll('[data-order]').forEach(b => b.setAttribute('aria-pressed', String(b === button))); load(true);
    }));
    const about = $('about-dialog');
    $('about-open').addEventListener('click', () => {about.showModal(); document.body.classList.add('has-dialog');});
    $('about-close').addEventListener('click', () => about.close());
    about.addEventListener('close', () => document.body.classList.remove('has-dialog'));
    if (boot.initial) present(boot.initial);
    load();
})();
