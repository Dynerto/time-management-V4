// service-worker.js
// PWA cache + Offline Background Sync (start/stop timelogs) zonder extra libraries.

const CACHE = 'tm-pwa-v3';
const ASSETS = ['/', '/index.html', '/script.js', '/style.css', '/manifest.json'];

// ==== IndexedDB helpers (queue + map) ====
const DB_NAME = 'tm-bg-sync';
const STORE_QUEUE = 'queue'; // queued actions
const STORE_MAP   = 'map';   // clientLogId -> serverId map

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_QUEUE))
        db.createObjectStore(STORE_QUEUE, { keyPath: 'id', autoIncrement: true });
      if (!db.objectStoreNames.contains(STORE_MAP))
        db.createObjectStore(STORE_MAP, { keyPath: 'clientLogId' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
function idbAdd(store, value) {
  return openDB().then(db => new Promise((res, rej) => {
    const tx = db.transaction(store, 'readwrite');
    tx.oncomplete = () => res();
    tx.onerror = () => rej(tx.error);
    tx.objectStore(store).add(value);
  }));
}
function idbPut(store, value) {
  return openDB().then(db => new Promise((res, rej) => {
    const tx = db.transaction(store, 'readwrite');
    tx.oncomplete = () => res();
    tx.onerror = () => rej(tx.error);
    tx.objectStore(store).put(value);
  }));
}
function idbDel(store, key) {
  return openDB().then(db => new Promise((res, rej) => {
    const tx = db.transaction(store, 'readwrite');
    tx.oncomplete = () => res();
    tx.onerror = () => rej(tx.error);
    tx.objectStore(store).delete(key);
  }));
}
function idbGetAll(store) {
  return openDB().then(db => new Promise((res, rej) => {
    const tx = db.transaction(store, 'readonly');
    const req = tx.objectStore(store).getAll();
    req.onsuccess = () => res(req.result || []);
    req.onerror = () => rej(req.error);
  }));
}
function mapGet(clientLogId) {
  return openDB().then(db => new Promise((res, rej) => {
    const tx = db.transaction(STORE_MAP, 'readonly');
    const req = tx.objectStore(STORE_MAP).get(clientLogId);
    req.onsuccess = () => res(req.result?.serverId || null);
    req.onerror = () => rej(req.error);
  }));
}
function mapPut(clientLogId, serverId) {
  return idbPut(STORE_MAP, { clientLogId, serverId });
}

// ====== Install/Activate (cache statics) ======
self.addEventListener('install', (e) => {
  e.waitUntil((async () => {
    const c = await caches.open(CACHE);
    await c.addAll(ASSETS);
    self.skipWaiting();
  })());
});

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)));
    self.clients.claim();
  })());
});

// ====== Simple cache fallback for navigation & statics ======
self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  // Alleen cache voor GET requests naar eigen origin en statische assets
  const isHTML = req.headers.get('accept')?.includes('text/html');
  if (req.method === 'GET' && (isHTML || url.origin === location.origin)) {
    e.respondWith((async () => {
      const cached = await caches.match(req);
      if (cached) return cached;
      try {
        const net = await fetch(req);
        return net;
      } catch {
        if (isHTML) return caches.match('/index.html');
        throw new Error('Offline');
      }
    })());
  }
});

// ====== Background Sync Queue ======
let csrfToken = null; // laatste bekende CSRF; wordt vanuit de client gezet

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SET_CSRF') {
    csrfToken = data.csrf || csrfToken;
    return;
  }
  if (data.type === 'QUEUE_TIMELOG') {
    const item = data.item || {};
    // Zorg dat we een CSRF header kunnen zetten bij flush:
    if (!item.csrf && csrfToken) item.csrf = csrfToken;
    item.createdAt = item.createdAt || Date.now();
    // item: { type:'start'|'stop', clientLogId, apiBase, csrf, payload:{} }
    event.waitUntil((async () => {
      await idbAdd(STORE_QUEUE, item);
      await registerSync();
    })());
    return;
  }
  if (data.type === 'FLUSH_QUEUE') {
    event.waitUntil(flushQueue());
  }
});

self.addEventListener('sync', (e) => {
  if (e.tag === 'timelog-sync') {
    e.waitUntil(flushQueue());
  }
});

async function registerSync() {
  if ('sync' in self.registration) {
    try { await self.registration.sync.register('timelog-sync'); } catch (_) {}
  }
}

// Kleine helper om resultaat naar alle clients te sturen
async function notifyClients(payload) {
  try {
    const clis = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
    clis.forEach(c => c.postMessage(payload));
  } catch {}
}

// Flush de queue in batches, met slimme samenvoeging start+stop
async function flushQueue() {
  const items = await idbGetAll(STORE_QUEUE);
  if (!items.length) return;

  // Groepeer per clientLogId en sorteer op tijd
  const groups = {};
  for (const it of items) {
    groups[it.clientLogId] = groups[it.clientLogId] || [];
    groups[it.clientLogId].push(it);
  }
  for (const id in groups) {
    groups[id].sort((a,b) => a.createdAt - b.createdAt);
  }

  // Verwerk elke groep
  for (const clientLogId of Object.keys(groups)) {
    const actions = groups[clientLogId];
    const startItem = actions.find(a => a.type === 'start');
    const stopItem  = actions.find(a => a.type === 'stop');
    let serverId = await mapGet(clientLogId);

    try {
      // Combineer start+stop tot één create (POST) als er nog geen serverId is.
      if (!serverId && startItem && stopItem) {
        const body = Object.assign({}, startItem.payload, {
          end_time: stopItem.payload.end_time,
          duration: stopItem.payload.duration
        });
        await doFetchJSON(startItem.apiBase + '/timelogs', 'POST', body, startItem.csrf);
        // Server genereert ID; we hoeven het niet terug te geven aan UI omdat log al gesloten is.
        await removeFromQueue([startItem.id, stopItem.id]);
        await notifyClients({ type:'SYNC_RESULT', clientLogId, closed:true });
        continue;
      }

      // Alleen start aanwezig en nog geen serverId => create (POST)
      if (!serverId && startItem) {
        const data = await doFetchJSON(startItem.apiBase + '/timelogs', 'POST', startItem.payload, startItem.csrf);
        serverId = data?.id || null;
        if (serverId) {
          await mapPut(clientLogId, serverId);
          await idbDel(STORE_QUEUE, startItem.id);
          await notifyClients({ type:'SYNC_RESULT', clientLogId, serverId });
        } else {
          // geen ID kunnen extraheren: laat item staan voor volgende flush
          continue;
        }
      }

      // Stop aanwezig -> update (PUT) mits we een serverId kennen
      if (stopItem) {
        if (!serverId) {
          // Wacht tot start flush een serverId oplevert
          continue;
        }
        await doFetchJSON(stopItem.apiBase + '/timelogs/' + serverId, 'PUT', stopItem.payload, stopItem.csrf);
        await idbDel(STORE_QUEUE, stopItem.id);
        await notifyClients({ type:'SYNC_RESULT', clientLogId, serverId, closed:true });
      }
    } catch (err) {
      // Netwerk of 4xx/5xx: laat items staan; volgende sync/online poging probeert opnieuw
      // (optioneel: exponential backoff kun je toevoegen door 'retryAfter' op te slaan)
      // Breek niet de hele flush; ga door met volgende groep
      continue;
    }
  }
}

async function removeFromQueue(ids) {
  for (const id of ids) { await idbDel(STORE_QUEUE, id); }
}

async function doFetchJSON(url, method, body, csrf) {
  const headers = { 'Content-Type': 'application/json' };
  if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;
  const resp = await fetch(url, {
    method,
    headers,
    body: method === 'GET' ? undefined : JSON.stringify(body || {}),
    credentials: 'include'
  });
  const ct = resp.headers.get('Content-Type') || '';
  if (!resp.ok) {
    // gooi een error zodat queue het later opnieuw probeert
    const msg = ct.includes('application/json') ? (await resp.json())?.error : await resp.text();
    throw new Error(msg || ('HTTP ' + resp.status));
  }
  return ct.includes('application/json') ? resp.json() : resp.text();
}
