const VERSION='tlpwa-1.2.0';
const STATIC_CACHE=`static-${VERSION}`;
const STATIC_ASSETS=['/','/index.html','/script.js','/dashboard.js','/manifest.json'];

self.addEventListener('install',e=>{
  e.waitUntil((async()=>{ const c=await caches.open(STATIC_CACHE); await c.addAll(STATIC_ASSETS); self.skipWaiting(); })());
});
self.addEventListener('activate',e=>{
  e.waitUntil((async()=>{ const ks=await caches.keys(); await Promise.all(ks.filter(k=>k.startsWith('static-')&&k!==STATIC_CACHE).map(k=>caches.delete(k))); self.clients.claim(); })());
});
self.addEventListener('fetch',e=>{
  const url=new URL(e.request.url);
  if (url.pathname.startsWith('/api')) return; // nooit cachen
  if (e.request.mode==='navigate'){
    e.respondWith((async()=>{
      try{ const fresh=await fetch(e.request); const c=await caches.open(STATIC_CACHE); c.put('/index.html',fresh.clone()); return fresh; }
      catch{ const c=await caches.open(STATIC_CACHE); const cached=await c.match('/index.html'); return cached || new Response('<h1>Offline</h1>',{headers:{'Content-Type':'text/html'}}); }
    })());
    return;
  }
  e.respondWith((async()=>{
    const c=await caches.open(STATIC_CACHE); const cached=await c.match(e.request); if (cached) return cached;
    try{ const fresh=await fetch(e.request); if (fresh.ok && e.request.url.startsWith(self.location.origin)) c.put(e.request,fresh.clone()); return fresh; }
    catch{ return cached || Response.error(); }
  })());
});
