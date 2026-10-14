// Attach to r2.dayv.cc/* as a Worker route, keeping the existing R2 custom domain.
// R2 binding: BUCKET. Variables: APP_ORIGIN=https://dayv.cc, SOURCE_STRATEGY_ID=<actual ID>.
export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (!['GET', 'HEAD'].includes(request.method)) return new Response('Method not allowed', {status:405});
    const origin = new URL(env.APP_ORIGIN);
    if (origin.protocol !== 'https:' || origin.hostname === url.hostname) return new Response('Invalid origin', {status:503});
    const source = String(env.SOURCE_STRATEGY_ID);
    if (!/^\d+$/.test(source)) return new Response('Invalid source strategy', {status:503});
    if (!env.BUCKET || typeof env.BUCKET.get !== 'function') return new Response('Missing R2 binding', {status:503});
    if (url.pathname === '/.__lsky_archive_probe') {
      try {
        const response=await fetch(new Request(`${origin.origin}/archive/r2/${source}/health`, {method:'GET'}));
        if (!response.ok) return new Response('Archive origin unavailable', {status:503});
        const info=await response.json();
        const key=url.searchParams.get('path');
        if (key) {
          const object=await env.BUCKET.head(key);
          if (!object) return new Response('R2 binding does not contain the probe object', {status:503});
          info.bucket_etag=object.httpEtag; info.bucket_size=object.size;
        }
        return Response.json(info, {headers:{'Cache-Control':'no-store'}});
      } catch { return new Response('Archive probe failed', {status:503}); }
    }
    let path;
    try { path=decodeURIComponent(url.pathname.slice(1)); }
    catch { return new Response('Invalid path', {status:400}); }
    // Prefer R2 while migration is in progress. PHP is involved only after an object leaves R2.
    const object=await env.BUCKET.get(path, {onlyIf:request.headers, range:request.headers});
    if (object) {
      const headers=new Headers(); object.writeHttpMetadata(headers);
      headers.set('ETag',object.httpEtag); headers.set('Accept-Ranges','bytes'); headers.set('X-Content-Type-Options','nosniff');
      if (!('body' in object)) return new Response(null, {status:request.headers.has('If-None-Match')?304:412,headers});
      let status=200;
      if (object.range) {
        const {offset,length}=object.range;
        headers.set('Content-Range',`bytes ${offset}-${offset+length-1}/${object.size}`);
        headers.set('Content-Length',String(length)); status=206;
      } else headers.set('Content-Length',String(object.size));
      return new Response(request.method==='HEAD'?null:object.body, {status,headers});
    }
    const destination=new URL(`/archive/r2/${source}`, origin.origin); destination.searchParams.set('path',path);
    const headers=new Headers();
    for (const name of ['Range','If-Range','If-None-Match']) if(request.headers.has(name)) headers.set(name,request.headers.get(name));
    try { return await fetch(destination, {method:request.method, headers, redirect:'manual'}); }
    catch { return new Response('Archive origin unavailable', {status:503}); }
  }
};
