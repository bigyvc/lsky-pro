<?php
namespace App\Services;

use App\Models\Image;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** TG originals are streamed as bytes, without decoding or copying the entire image into memory. */
class ArchivedImageOutput
{
    public function response(Request $request, Image $image): StreamedResponse
    {
        $size = (int)round($image->size*1024); $start = 0; $end = $size-1; $status = 200;
        $etag = '"'.$image->sha1.'"';
        $headers = ['Content-Type'=>$image->mimetype, 'ETag'=>$etag,
            'Accept-Ranges'=>'bytes', 'Cache-Control'=>'public, max-age=3600', 'X-Content-Type-Options'=>'nosniff'];
        if (in_array($image->extension, ['hevc','h265'], true)) $headers['Content-Disposition'] = 'attachment';
        if ($request->header('If-None-Match') === $etag) {
            return response()->stream(fn()=>null, 304, $headers);
        }
        $range = $request->isMethod('GET') ? $request->header('Range') : null;
        if ($range && (!$request->header('If-Range') || $request->header('If-Range') === $etag)) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) || ($match[1] === '' && $match[2] === '')) {
                return response()->stream(fn()=>null, 416, ['Content-Range'=>'bytes */'.$size]);
            }
            if ($match[1] === '') { $start=max(0, $size-(int)$match[2]); }
            else { $start=(int)$match[1]; if ($match[2] !== '') $end=min($end, (int)$match[2]); }
            if ($start > $end || $start >= $size) {
                return response()->stream(fn()=>null, 416, ['Content-Range'=>'bytes */'.$size]);
            }
            $status=206; $headers['Content-Range']="bytes $start-$end/$size";
        }
        $headers['Content-Length']=(string)($end-$start+1);
        // HEAD, cache validation and rejected ranges must not download TG media.
        if ($request->isMethod('HEAD')) return response()->stream(fn()=>null, $status, $headers);
        try {
            $stream = $image->filesystem()->readStream($image->pathname);
            if (!is_resource($stream)) throw new \RuntimeException('Invalid media stream');
        } catch (\Throwable) { abort(503, '归档文件暂时无法读取'); }
        return response()->stream(function () use ($stream, $start, $end) {
            try {
                if ($start && @fseek($stream, $start) !== 0) {
                    $skip=$start;
                    while ($skip > 0) {
                        $chunk=fread($stream, min(1048576, $skip));
                        if ($chunk === false || $chunk === '') return;
                        $skip-=strlen($chunk);
                    }
                }
                $remaining=$end-$start+1;
                while ($remaining > 0 && !connection_aborted()) {
                    $chunk=fread($stream, min(1048576, $remaining));
                    if ($chunk === false || $chunk === '') break;
                    echo $chunk; $remaining-=strlen($chunk);
                }
            } finally { fclose($stream); }
        }, $status, $headers);
    }
}
