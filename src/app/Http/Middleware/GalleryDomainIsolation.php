<?php
namespace App\Http\Middleware;

use App\Services\GallerySettings;
use Closure;
use Illuminate\Http\Request;

/** API routes are registered before web routes; block their use on the gallery host. */
class GalleryDomainIsolation
{
    public function handle(Request $request, Closure $next)
    {
        $s=app(GallerySettings::class)->read();
        if (strtolower($request->getHost())===$s['host']) {
            abort_unless($s['enabled']===true && preg_match('~^/(?:$|sets/[a-f0-9]{32}$|gallery-api/|gallery-media/)~D',$request->getPathInfo()),404);
        }
        return $next($request);
    }
}
