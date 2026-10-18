/* DAYV gallery. No tokens, external scripts, or bulk original-image requests. */
(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const boot = JSON.parse($('gallery-bootstrap').textContent);
    const grid = $('gallery-grid'), more = $('load-more'), status = $('gallery-status');
    const setDialog = $('set-dialog'), lightbox = $('lightbox'), about = $('about-dialog');
    let order = 'latest', page = 1, snapshot = null, seed = null, busy = false, loaded = 0;
    let listRequest = null, setRequest = null, currentSet = null, photoIndex = 0, listEpoch = 0;

    function lockBody() { document.body.classList.toggle('has-dialog', [setDialog, lightbox, about].some(d => d.open)); }
    function show(dialog) { if (!dialog.open) dialog.showModal(); lockBody(); }
    function close(dialog) { if (dialog.open) dialog.close(); lockBody(); }
    async function json(url, signal) {
        const controller = new AbortController();
        const cancel = () => controller.abort();
        signal?.addEventListener('abort', cancel, {once: true});
        if (signal?.aborted) controller.abort();
        const timer = setTimeout(cancel, 20000);
        try {
            const r = await fetch(url, {signal: controller.signal, headers: {'Accept': 'application/json'}, credentials: 'omit'});
            if (!r.ok) throw new Error(r.status === 404 ? '这组影像暂时不可用。' : '暂时未能加载，请稍后重试。');
            return await r.json();
        } catch (e) {
            if (controller.signal.aborted && !signal?.aborted) throw new Error('加载超时，请点击重新加载。');
            throw e;
        } finally {clearTimeout(timer); signal?.removeEventListener('abort', cancel);}
    }
    function thumb(data, lazy = true) {
        const image = document.createElement('img');
        image.alt = data.name || '影集照片'; image.loading = lazy ? 'lazy' : 'eager'; image.decoding = 'async';
        if (data.width > 0 && data.height > 0) {image.width = data.width; image.height = data.height;}
        image.addEventListener('load', () => image.classList.add('loaded'));
        image.addEventListener('error', () => {
            if (!image.dataset.fallback) {image.dataset.fallback = '1'; image.src = '/dayv-gallery/placeholder.svg';}
            image.classList.add('loaded');
        });
        image.src = data.thumbnail;
        return image;
    }
    function card(set) {
        const a = document.createElement('a'); a.className = 'set-card'; a.href = set.href;
        a.setAttribute('aria-label', `${set.title}，${set.count} 张照片`);
        const picture = document.createElement('div'); picture.className = 'card-image';
        picture.append(thumb(set.cover, loaded >= 4));
        const index = document.createElement('span'); index.className = 'card-index'; index.textContent = 'NO. ' + String(set.number).padStart(5, '0');
        const count = document.createElement('span'); count.className = 'card-count'; count.textContent = `${set.count} 张照片`;
        picture.append(index, count);
        const info = document.createElement('div'); info.className = 'card-info';
        const title = document.createElement('h3'); title.textContent = set.title;
        const arrow = document.createElement('span'); arrow.textContent = '↗'; arrow.setAttribute('aria-hidden', 'true');
        info.append(title, arrow); a.append(picture, info);
        a.addEventListener('click', e => {if (e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {e.preventDefault(); openSet(set.key, true);}});
        return a;
    }
    async function load(reset = false) {
        if (busy && !reset) return;
        const epoch = reset ? ++listEpoch : listEpoch;
        if (reset) {
            listRequest?.abort(); page = 1; snapshot = null; seed = null; loaded = 0;
            grid.replaceChildren(...Array.from({length: 4}, () => {const skeleton = document.createElement('div'); skeleton.className = 'skeleton'; skeleton.setAttribute('aria-hidden', 'true'); return skeleton;})); more.hidden = true;
        }
        busy = true; grid.setAttribute('aria-busy', 'true'); more.disabled = true;
        status.textContent = '正在打开影集…';
        listRequest = new AbortController();
        const params = new URLSearchParams({order, page: String(page)});
        if (snapshot !== null) params.set('snapshot', String(snapshot));
        if (seed) params.set('seed', seed);
        try {
            const data = await json('/gallery-api/sets?' + params, listRequest.signal);
            if (epoch !== listEpoch) return;
            if (!Array.isArray(data.sets)) throw new Error('影集数据暂时不可用。');
            if (page === 1) grid.replaceChildren();
            data.sets.forEach(set => {grid.append(card(set)); loaded++;});
            snapshot = data.snapshot; seed = data.seed; page = data.next_page;
            $('set-count').textContent = Number(data.total).toLocaleString('zh-CN');
            $('photo-count').textContent = Number(data.photos).toLocaleString('zh-CN');
            more.hidden = page === null;
            more.textContent = '再看一些 ↓';
            status.textContent = !loaded ? '影集还在整理中，稍后再来看看。' : page === null ? `已看完 ${loaded} 组影像。换个顺序，也许会有新的发现。` : `已展开 ${loaded} 组影像`;
        } catch (e) {
            if (e.name === 'AbortError' || epoch !== listEpoch) return;
            if (!loaded) grid.replaceChildren();
            status.textContent = e.message; more.hidden = false; more.textContent = '重新加载 ↻';
        } finally {
            if (epoch === listEpoch) {busy = false; grid.setAttribute('aria-busy', 'false'); more.disabled = false;}
        }
    }
    function renderSet(set) {
        currentSet = set;
        $('set-title').textContent = set.title; $('set-meta').textContent = `${set.count} 张照片 · 按原帖顺序`;
        $('set-images').replaceChildren();
        set.images.forEach((image, index) => {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'photo-tile';
            button.setAttribute('aria-label', `打开第 ${index + 1} 张：${image.name}`); button.append(thumb(image, index > 3));
            const number = document.createElement('span'); number.textContent = String(index + 1).padStart(2, '0'); button.append(number);
            button.addEventListener('click', () => openPhoto(index)); $('set-images').append(button);
        });
        show(setDialog); setDialog.scrollTop = 0;
        document.title = set.title + ' · ' + boot.title;
    }
    async function openSet(key, push) {
        setRequest?.abort(); setRequest = new AbortController(); close(lightbox); currentSet = null;
        $('set-title').textContent = '正在打开…'; $('set-meta').textContent = ''; $('set-images').replaceChildren(); show(setDialog);
        if (push) history.pushState({set: key}, '', '/sets/' + key);
        try {renderSet(await json('/gallery-api/sets/' + key, setRequest.signal));}
        catch (e) {if (e.name !== 'AbortError') {$('set-title').textContent = '暂时无法打开'; $('set-meta').textContent = e.message;}}
    }
    function leaveSet() {
        setRequest?.abort(); close(lightbox); close(setDialog); currentSet = null; document.title = boot.title;
        if (location.pathname.startsWith('/sets/')) history.replaceState(null, '', '/');
    }
    function openPhoto(index) {
        if (!currentSet || index < 0 || index >= currentSet.images.length) return;
        photoIndex = index; const data = currentSet.images[index];
        const image = $('lightbox-image'); image.alt = data.name; image.src = data.url;
        $('lightbox-count').textContent = `${index + 1} / ${currentSet.images.length}`;
        $('lightbox-caption').textContent = data.name;
        $('previous-photo').disabled = index === 0; $('next-photo').disabled = index === currentSet.images.length - 1;
        $('lightbox-stage').classList.remove('is-zoomed'); $('zoom-toggle').setAttribute('aria-pressed', 'false');
        $('zoom-toggle').setAttribute('aria-label', '放大照片'); show(lightbox);
    }
    more.addEventListener('click', () => load());
    document.querySelectorAll('[data-order]').forEach(button => button.addEventListener('click', () => {
        order = button.dataset.order;
        document.querySelectorAll('[data-order]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
        load(true);
    }));
    $('set-close').addEventListener('click', leaveSet);
    setDialog.addEventListener('cancel', e => {e.preventDefault(); leaveSet();});
    $('lightbox-close').addEventListener('click', () => close(lightbox));
    $('previous-photo').addEventListener('click', () => openPhoto(photoIndex - 1));
    $('next-photo').addEventListener('click', () => openPhoto(photoIndex + 1));
    $('zoom-toggle').addEventListener('click', () => {
        const zoom = $('lightbox-stage').classList.toggle('is-zoomed');
        $('zoom-toggle').setAttribute('aria-pressed', String(zoom)); $('zoom-toggle').setAttribute('aria-label', zoom ? '适应屏幕' : '放大照片');
    });
    $('lightbox-image').addEventListener('error', () => {$('lightbox-caption').textContent = '这张照片暂时未能加载，请切换后重试。';});
    $('about-open').addEventListener('click', () => show(about)); $('about-close').addEventListener('click', () => close(about));
    [setDialog, lightbox, about].forEach(dialog => dialog.addEventListener('close', lockBody));
    document.addEventListener('keydown', e => {
        if (lightbox.open && ['ArrowLeft','ArrowRight'].includes(e.key)) {e.preventDefault(); openPhoto(photoIndex + (e.key === 'ArrowLeft' ? -1 : 1));}
    });
    let touchStart = null;
    $('lightbox-stage').addEventListener('touchstart', e => {touchStart = e.touches.length === 1 ? [e.touches[0].clientX, e.touches[0].clientY] : null;}, {passive: true});
    $('lightbox-stage').addEventListener('touchend', e => {
        if (!touchStart || $('lightbox-stage').classList.contains('is-zoomed') || e.changedTouches.length !== 1) return;
        const dx = e.changedTouches[0].clientX - touchStart[0], dy = e.changedTouches[0].clientY - touchStart[1];
        if (Math.abs(dx) > 65 && Math.abs(dx) > Math.abs(dy) * 1.5) openPhoto(photoIndex + (dx < 0 ? 1 : -1));
        touchStart = null;
    }, {passive: true});
    window.addEventListener('popstate', () => {
        const match = location.pathname.match(/^\/sets\/([a-f0-9]{32})$/);
        if (match) openSet(match[1], false); else leaveSet();
    });
    if (boot.initial) renderSet(boot.initial);
    load();
})();
