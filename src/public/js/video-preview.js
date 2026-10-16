/* Experimental video preview. Raw HEVC is downloadable; browser codec support is not assumed. */
(function (root) {
    'use strict';
    const extensions = new Set(['mp4', 'mov', 'webm', 'hevc', 'h265']);
    function extension(url) {
        try {
            const parsed = new URL(url, root.location.href);
            return ['https:', 'http:'].includes(parsed.protocol) ? parsed.pathname.split('.').pop().toLowerCase() : '';
        } catch (_) { return ''; }
    }
    function isVideo(url) { return extensions.has(extension(url)); }
    function open(url, name) {
        if (!isVideo(url)) return;
        const dialog = document.createElement('dialog');
        Object.assign(dialog.style, { width: 'min(94vw, 960px)', maxHeight: '90vh', padding: '20px', border: 'none', borderRadius: '16px', background: '#171b23', color: '#f3f4f6' });
        const title = document.createElement('p'); title.textContent = name || '视频';
        const close = document.createElement('button'); close.type = 'button'; close.textContent = '关闭';
        const download = document.createElement('a'); download.href = url; download.download = name || ''; download.textContent = '下载原文件';
        Object.assign(download.style, { color: '#9dbbff', display: 'inline-block', margin: '12px 20px 0 0' });
        dialog.append(title);
        let video;
        if (!['hevc', 'h265'].includes(extension(url))) {
            video = document.createElement('video'); video.controls = true; video.playsInline = true; video.preload = 'metadata'; video.src = url;
            Object.assign(video.style, { display: 'block', width: '100%', maxHeight: '65vh', background: '#000' });
            const hint = document.createElement('p'); hint.textContent = '无法播放时可下载原文件；播放取决于设备支持的视频编码。';
            dialog.append(video, hint);
        } else {
            const hint = document.createElement('p'); hint.textContent = '这是 HEVC/H.265 裸码流，请下载后使用支持的播放器打开。'; dialog.append(hint);
        }
        dialog.append(download, close); document.body.append(dialog);
        let disposed = false;
        const dispose = () => { if (disposed) return; disposed = true; if (video) { video.removeAttribute('src'); video.load(); } dialog.remove(); };
        close.addEventListener('click', () => { if (typeof dialog.close === 'function') dialog.close(); dispose(); });
        dialog.addEventListener('close', dispose, { once: true });
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else { dialog.setAttribute('open', ''); Object.assign(dialog.style, { position: 'fixed', top: '5vh', zIndex: '10000' }); }
        return dialog;
    }
    document.addEventListener('click', event => {
        const card = event.target.closest && event.target.closest('#images-grid .images-item');
        if (!card || event.target.closest('.image-selector')) return;
        const image = card.querySelector('img[data-original]');
        const url = image && image.getAttribute('data-original');
        if (!isVideo(url)) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); event.stopImmediatePropagation(); open(url, image.alt);
    }, true);
    root.LskyVideoPreview = { isVideo, open };
})(window);
