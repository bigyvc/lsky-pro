<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#171b1e">
    <title>{{ $initial ? $initial['title'].' · ' : '视频 · ' }}{{ $site['title'] }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/dayv-gallery/gallery.css?v={{ filemtime(public_path('dayv-gallery/gallery.css')) }}">
    <script id="video-bootstrap" type="application/json">{!! json_encode(['title'=>$site['title'],'initial'=>$initial],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
    <script src="/dayv-gallery/videos.js?v={{ filemtime(public_path('dayv-gallery/videos.js')) }}" defer></script>
</head>
<body>
    <a class="skip" href="#video-collection">跳到视频</a>
    <header class="site-header">
        <a class="brand" href="/" aria-label="dayv 首页"><span class="brand-lockup"><span class="brand-name">dayv</span><span class="brand-sub" aria-label="{{ $site['title'] }}">@foreach(preg_split('//u', $site['title'], -1, PREG_SPLIT_NO_EMPTY) as $character)<span aria-hidden="true">{{ $character }}</span>@endforeach</span></span></a>
        <nav aria-label="主导航"><a href="/">图集</a><a class="nav-active" href="/videos" aria-current="page">视频 <span aria-hidden="true">↗</span></a><button id="about-open" type="button">关于</button></nav>
    </header>
    <main>
        <section class="intro video-intro" aria-labelledby="intro-title">
            <div class="intro-copy"><p class="eyebrow"><span></span> MOMENTS IN MOTION</p><h1 id="intro-title">让芳华，<br>在此<span class="serif">流动。</span></h1><p class="intro-note">把光影里的故事，慢慢播放。</p></div>
            <div class="intro-aside"><span class="aside-mark" aria-hidden="true">▷</span><p>THE MOTION<br>JOURNAL</p><div class="collection-stats"><span><strong id="video-count">—</strong> 段视频</span></div></div>
        </section>
        <section id="video-player-section" class="video-player-section" aria-labelledby="video-title" hidden>
            <a class="video-back" href="/videos">← 所有视频</a><h2 id="video-title"></h2>
            <div class="video-player-shell"><video id="video-player" controls playsinline preload="none" aria-label="视频播放器"></video><button id="video-start" class="video-start" type="button">▷ 播放视频</button></div>
            <p id="video-player-status" role="status">点击播放后才加载视频。</p>
            <p class="video-compatibility">播放取决于浏览器对视频编码的支持；HEVC、MKV 等格式可能无法在当前设备播放。</p>
        </section>
        <section class="collection" id="video-collection" aria-labelledby="collection-title">
            <div class="collection-toolbar"><h2 id="collection-title">所有视频<span class="small-label">MOTION</span></h2><div class="sort-controls" role="group" aria-label="视频排序"><button type="button" data-order="latest" aria-pressed="true">最新</button><button type="button" data-order="earliest" aria-pressed="false">最早</button></div></div>
            <div id="video-grid" class="gallery-grid video-grid" aria-busy="true"></div>
            <div class="collection-bottom"><p id="video-status" role="status">正在打开视频…</p><button id="video-more" class="outline-button" type="button" hidden>再看一些 ↓</button></div>
        </section>
    </main>
    <footer class="site-footer"><a class="footer-brand" href="/">dayv <span>{{ $site['title'] }}</span></a><p>留不住韶华，留得住倩影。</p><small>Design adapted from <a href="https://html5up.net/multiverse" target="_blank" rel="noopener noreferrer">Multiverse / HTML5 UP</a></small></footer>
    <dialog id="about-dialog" class="about-dialog" aria-labelledby="about-title"><button id="about-close" class="round-button" type="button" aria-label="关闭关于">×</button><p class="eyebrow">ABOUT THIS COLLECTION</p><h2 id="about-title">{{ $site['title'] }}</h2><p>把照片里的芳华与流动的光影，收藏在一起。图集按原帖组合展示，视频点开后慢慢播放。</p><small>界面设计改编自 <a href="https://html5up.net/multiverse" target="_blank" rel="noopener noreferrer">Multiverse by HTML5 UP</a>。</small></dialog>
    <noscript><p class="noscript">请启用 JavaScript 浏览视频及播放。</p></noscript>
</body>
</html>
