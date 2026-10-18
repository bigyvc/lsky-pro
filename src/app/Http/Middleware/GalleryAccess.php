<?php
namespace App\Http\Middleware;

use App\Services\GallerySettings;
use Closure;
use Illuminate\Http\Request;

class GalleryAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(GallerySettings::class)->allows($request),404);
        $response=$next($request);
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','same-origin');
        $response->headers->set('Content-Security-Policy',"default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        return $response;
    }
}
