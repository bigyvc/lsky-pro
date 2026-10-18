<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#171b1e">
    <meta name="description" content="dayv {{ $site['title'] }}，以套图为单位，收藏日常里的片刻芳华。">
    <title>{{ $initial ? $initial['title'].' · ' : '' }}{{ $site['title'] }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/dayv-gallery/gallery.css?v={{ filemtime(public_path('dayv-gallery/gallery.css')) }}">
    <script id="gallery-bootstrap" type="application/json">{!! json_encode(['title'=>$site['title'],'initial'=>$initial],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
    <script src="/dayv-gallery/gallery.js?v={{ filemtime(public_path('dayv-gallery/gallery.js')) }}" defer></script>
</head>
<body>
    <a class="skip" href="#collection">跳到图集</a>
    <header class="site-header">
        <a class="brand" href="/" aria-label="dayv 首页"><span class="brand-lockup"><span class="brand-name">dayv</span><span class="brand-sub" aria-label="{{ $site['title'] }}">@foreach(preg_split('//u', $site['title'], -1, PREG_SPLIT_NO_EMPTY) as $character)<span aria-hidden="true">{{ $character }}</span>@endforeach</span></span></a>
        <nav aria-label="主导航"><a class="nav-active" href="#collection">图集 <span aria-hidden="true">↗</span></a><button id="about-open" type="button">关于</button></nav>
    </header>
    <main>
        <section class="intro" aria-labelledby="intro-title">
            <div class="intro-copy"><p class="eyebrow"><span></span> A COLLECTION OF MOMENTS</p><h1 id="intro-title">把片刻，<br>留在<span class="serif">这里。</span><sup>↗</sup></h1><p class="intro-note">芳华有时，慢慢看。<br>一些值得停留的画面，一组一组收藏。</p></div>
            <div class="intro-aside"><span class="aside-mark" aria-hidden="true">✳</span><p>THE PHOTO<br>JOURNAL</p><div class="collection-stats" aria-live="polite"><span><strong id="set-count">—</strong> 组图像</span><span><strong id="photo-count">—</strong> 张照片</span></div></div>
        </section>
        <section class="collection" id="collection" aria-labelledby="collection-title">
            <div class="collection-toolbar"><h2 id="collection-title">所有图集<span class="small-label">COLLECTION</span></h2><div class="sort-controls" role="group" aria-label="图集排序"><button type="button" data-order="latest" aria-pressed="true">最新</button><button type="button" data-order="earliest" aria-pressed="false">最早</button><button type="button" data-order="random" aria-pressed="false">随机 <span aria-hidden="true">↝</span></button></div></div>
            <div id="gallery-grid" class="gallery-grid" aria-busy="true"><div class="skeleton" aria-hidden="true"></div><div class="skeleton" aria-hidden="true"></div><div class="skeleton" aria-hidden="true"></div><div class="skeleton" aria-hidden="true"></div></div>
            <div class="collection-bottom"><p id="gallery-status" role="status">正在打开图集…</p><button id="load-more" class="outline-button" type="button" hidden>再看一些 <span aria-hidden="true">↓</span></button></div>
        </section>
    </main>
    <footer class="site-footer"><a class="footer-brand" href="/">dayv <span>{{ $site['title'] }}</span></a><p>留不住韶华，留得住倩影。</p><small>Design adapted from <a href="https://html5up.net/multiverse" target="_blank" rel="noopener noreferrer">Multiverse / HTML5 UP</a></small></footer>
    <dialog id="set-dialog" class="set-dialog" aria-labelledby="set-title">
        <header class="dialog-header"><div><span class="eyebrow">PHOTO COLLECTION</span><h2 id="set-title">图集</h2></div><button id="set-close" class="round-button" type="button" aria-label="关闭图集">×</button></header>
        <p id="set-meta" class="set-meta"></p><div id="set-images" class="set-images"></div><p class="set-hint">点开照片，慢慢看。</p>
    </dialog>
    <dialog id="lightbox" class="lightbox" aria-label="照片大图浏览">
        <div class="lightbox-top"><span id="lightbox-count" aria-live="polite"></span><div><button id="zoom-toggle" class="round-button" type="button" aria-label="放大照片" aria-pressed="false">＋</button><button id="lightbox-close" class="round-button" type="button" aria-label="关闭大图">×</button></div></div>
        <div id="lightbox-stage" class="lightbox-stage"><img id="lightbox-image" alt=""></div>
        <button id="previous-photo" class="photo-arrow previous" type="button" aria-label="上一张">←</button><button id="next-photo" class="photo-arrow next" type="button" aria-label="下一张">→</button>
        <p id="lightbox-caption" class="lightbox-caption"></p>
    </dialog>
    <dialog id="about-dialog" class="about-dialog" aria-labelledby="about-title"><button id="about-close" class="round-button" type="button" aria-label="关闭关于">×</button><p class="eyebrow">ABOUT THIS COLLECTION</p><h2 id="about-title">{{ $site['title'] }}</h2><p>把零散的片刻，整理成一组组可以慢慢翻看的图集。这里按原帖组合收录照片，让每一组画面保留自己的节奏。</p><p>你可以从最新开始，也可以回到最早，或让随机带来一次偶遇。</p><small>界面设计改编自 <a href="https://html5up.net/multiverse" target="_blank" rel="noopener noreferrer">Multiverse by HTML5 UP</a>，保留原设计署名。</small></dialog>
    <noscript><p class="noscript">请启用 JavaScript 浏览分页图集。图片原链接仍可独立打开。</p></noscript>
</body>
</html>
