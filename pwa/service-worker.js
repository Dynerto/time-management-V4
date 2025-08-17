// service-worker.js
// PWA cache + Offline Background Sync (start/stop timelogs)

const CACHE = 'tm-pwa-v3';
const ASSETS = ['/', '/index.html', '/script.js', '/style.css', '/manifest.json'];

const DB_NAME = 'tm-bg-sync';
const STORE_QUEUE = 'queue';
const STORE_MAP   = 'map';

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

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  const isHTML = req.headers.get('accept')?.includes('text/html');
  if (req.method === 'GET' && (isHTML || url.origin === location.origin)) {
    e.respondWith((async () => {
      const cached = await caches.match(req);
      if (cached) return cached;
      try { return await fetch(req); }
      catch {
        if (isHTML) return caches.match('/index.html');
        throw new Error('Offline');
      }
    })());
  }
});

let csrfToken = null;

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SET_CSRF') {
    csrfToken = data.csrf || csrfToken;
    return;
  }
  if (data.type === 'QUEUE_TIMELOG') {
    const item = data.item || {};
    if (!item.csrf && csrfToken) item.csrf = csrfToken;
    item.createdAt = item.createdAt || Date.now();
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

async function notifyClients(payload) {
  try {
    const clis = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
    clis.forEach(c => c.postMessage(payload));
  } catch {}
}

async function flushQueue() {
  const items = await idbGetAll(STORE_QUEUE);
  if (!items.length) return;

  const groups = {};
  for (const it of items) {
    groups[it.clientLogId] = groups[it.clientLogId] || [];
    groups[it.clientLogId].push(it);
  }
  for (const id in groups) groups[id].sort((a,b)=>a.createdAt - b.createdAt);

  for (const clientLogId of Object.keys(groups)) {
    const actions = groups[clientLogId];
    const startItem = actions.find(a => a.type === 'start');
    const stopItem  = actions.find(a => a.type === 'stop');
    let serverId = await mapGet(clientLogId);

    try {
      if (!serverId && startItem && stopItem) {
        const body = Object.assign({}, startItem.payload, {
          end_time: stopItem.payload.end_time,
          duration: stopItem.payload.duration
        });
        await doFetchJSON(startItem.apiBase + '/timelogs', 'POST', body, startItem.csrf);
        await removeFromQueue([startItem.id, stopItem.id]);
        await notifyClients({ type:'SYNC_RESULT', clientLogId, closed:true });
        continue;
      }
      if (!serverId && startItem) {
        const data = await doFetchJSON(startItem.apiBase + '/timelogs', 'POST', startItem.payload, startItem.csrf);
        serverId = data?.id || null;
        if (serverId) {
          await mapPut(clientLogId, serverId);
          await idbDel(STORE_QUEUE, startItem.id);
          await notifyClients({ type:'SYNC_RESULT', clientLogId, serverId });
        } else continue;
      }
      if (stopItem) {
        if (!serverId) continue;
        await doFetchJSON(stopItem.apiBase + '/timelogs/' + serverId, 'PUT', stopItem.payload, stopItem.csrf);
        await idbDel(STORE_QUEUE, stopItem.id);
        await notifyClients({ type:'SYNC_RESULT', clientLogId, serverId, closed:true });
      }
    } catch {
      continue; // probeer later opnieuw
    }
  }
}
async function removeFromQueue(ids) { for (const id of ids) await idbDel(STORE_QUEUE, id); }
async function doFetchJSON(url, method, body, csrf) {
  const headers = { 'Content-Type': 'application/json' };
  if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;
  const resp = await fetch(url, { method, headers, body: method==='GET'?undefined:JSON.stringify(body||{}), credentials:'include' });
  const ct = resp.headers.get('Content-Type') || '';
  if (!resp.ok) {
    const msg = ct.includes('application/json') ? (await resp.json())?.error : await resp.text();
    throw new Error(msg || ('HTTP ' + resp.status));
  }
  return ct.includes('application/json') ? resp.json() : resp.text();
}
