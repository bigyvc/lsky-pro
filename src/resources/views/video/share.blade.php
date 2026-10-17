<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $image->filename }} · 视频分享</title>
    @include('layouts.site-icons')
    <style>
        :root{color-scheme:light dark;font-family:system-ui,-apple-system,sans-serif}
        *{box-sizing:border-box}body{margin:0;background:#10151e;color:#edf1f7;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px}
        main{width:100%;max-width:960px}header{display:flex;gap:16px;justify-content:space-between;align-items:center;margin-bottom:18px}
        h1{font-size:clamp(18px,3vw,26px);margin:0;overflow-wrap:anywhere}small,p{color:#aab5c5;line-height:1.7}
        video,.unsupported{display:block;width:100%;max-height:70vh;background:#080b10;border-radius:16px}
        .unsupported{padding:48px 24px;text-align:center}.actions{display:flex;gap:12px;margin:20px 0;flex-wrap:wrap}
        .button{background:#4b7de9;color:white;padding:11px 18px;border:0;border-radius:10px;text-decoration:none;font:inherit;cursor:pointer}
        .secondary{background:#263245}form{background:#1a2331;padding:20px;border-radius:14px;margin-top:24px}label{display:flex;gap:10px;align-items:center}
        input[type=checkbox]{width:18px;height:18px;accent-color:#4b7de9}.notice{color:#95d5ad}.muted{font-size:13px}a{color:#9bbcff}
    </style>
</head>
<body>
<main>
    <header><h1>{{ $image->filename }}</h1><small>{{ strtoupper($image->extension) }}</small></header>
    @if($playable)
        <video controls playsinline preload="metadata" @if(!$image->video_download_enabled) controlslist="nodownload" @endif src="{{ $image->url }}">
            当前浏览器不支持此视频的播放。
        </video>
        @if(strtolower($image->extension) === 'm3u8')
            <p>M3U8需要浏览器原生HLS支持（如Safari）。分片、密钥及子列表由浏览器向源站读取，源站权限、CORS和链接有效期会影响播放；本图床不备份这些外部文件。</p>
        @endif
        <p id="playback-status" role="status"></p>
        <p>能否播放取决于设备支持的编码。本页保存原始视频，不进行转码。</p>
    @else
        <div class="unsupported"><h2>此格式暂不提供网页播放</h2><p>需要使用支持 {{ strtoupper($image->extension) }} 的播放器打开。</p></div>
    @endif
    <div class="actions">
        @if($image->video_download_enabled)
            <a class="button" href="{{ route('video.download', ['key'=>$image->key]) }}">下载原文件</a>
        @endif
        <button type="button" class="button secondary" id="copy-share">复制分享链接</button>
    </div>
    <p id="copy-status" role="status"></p>
    @if($canManage)
        <form method="POST" action="{{ route('video.settings', ['key'=>$image->key]) }}">
            @csrf
            <input type="hidden" name="public_share" value="0">
            <label><input type="checkbox" name="public_share" value="1" @checked($image->permission === \App\Enums\ImagePermission::Public)>公开分享（允许未登录访问此页）</label>
            <p class="muted">这会同步修改此记录的公开/私有状态，不会撤销现有原文件直链。</p>
            <input type="hidden" name="download_enabled" value="0">
            <label><input type="checkbox" name="download_enabled" value="1" @checked($image->video_download_enabled)>显示下载按钮</label>
            <p class="muted">此开关只控制下载入口，不能防止录屏或提取视频。现有公开原文件链接仍可访问。</p>
            @if($image->permission !== \App\Enums\ImagePermission::Public)
                <p>当前记录为私有，仅所有者和管理员能打开此分享页。需要公开分享时，请勾选上方“公开分享”并保存。</p>
            @endif
            <button class="button" type="submit">保存设置</button>
            @if(session('video_saved'))<span class="notice" role="status"> 已保存</span>@endif
            @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        </form>
    @endif
</main>
<script>
const player=document.querySelector('video');
if(player) player.addEventListener('error',function(){document.getElementById('playback-status').textContent='当前浏览器无法播放，或源文件暂时不可访问。';});
document.getElementById('copy-share').addEventListener('click', async function(){
    const status=document.getElementById('copy-status');
    try{await navigator.clipboard.writeText(window.location.origin+window.location.pathname);status.textContent='分享链接已复制';}
    catch(_){status.textContent='请复制浏览器地址栏中的链接';}
});
</script>
</body>
</html>
