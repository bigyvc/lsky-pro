<?php
namespace App\Services;

use App\Models\Album;
use Illuminate\Http\Request;
use RuntimeException;

/** Explicit album publication in SQLite-backed application data, outside Laravel config cache. */
class GallerySettings
{
    public static function routeHost(): string { return getenv('DAYV_GALLERY_HOST') ?: 'dayv.org'; }

    public function read(): array
    {
        $defaults=['enabled'=>false,'host'=>self::routeHost(),'title'=>'DAYV · 影像之间','user_id'=>0,'album_id'=>0,'video_album_id'=>0];
        try {
            $payload=\Illuminate\Support\Facades\DB::table('gallery_site_settings')->where('id',1)->value('payload');
            $data=$payload ? json_decode($payload,true) : null;
            return is_array($data) ? array_merge($defaults,$data) : $defaults;
        } catch (\Throwable $e) {
            // Routes also load before first installation/migration; never create or expose a site then.
            return $defaults;
        }
    }

    public function allows(Request $request): bool
    {
        $s=$this->read();
        return $s['enabled']===true && strtolower($request->getHost())===$s['host']
            && Album::where('id',$s['album_id'])->where('user_id',$s['user_id'])->exists();
    }

    public function write(array $settings): void
    {
        $host=strtolower($settings['host']);
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D',$host)) {
            throw new RuntimeException('图库域名必须是不含协议/路径的域名');
        }
        if ($host===strtolower(parse_url(config('app.url'),PHP_URL_HOST) ?: '')) {
            throw new RuntimeException('图库需要独立域名，不能覆盖当前图床域名');
        }
        if ($host!==self::routeHost()) throw new RuntimeException('图库域名须与服务器DAYV_GALLERY_HOST一致，当前默认dayv.org');
        $settings['host']=$host;
        \Illuminate\Support\Facades\DB::table('gallery_site_settings')->updateOrInsert(
            ['id'=>1],['payload'=>json_encode($settings,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]
        );
    }
}
