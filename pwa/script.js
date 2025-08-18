/* Tijdregistratie PWA – script.js (feature-parity + extra’s)
 * - API-config automatisch via GET /api/public-config
 * - Multitasking met realtime timers
 * - Drag & Drop reorder op Hoofdscherm én Taakbeheer
 * - Aandachtverdeling (min/max), waarschuwingen >3/>5/>7
 * - Auth: register/login/verify/reset
 * - Export CSV (“Met” = simultane taken; ;‑gescheiden)
 * - Onboarding help overlay
 * - Sessie-persistentie van actieve taken + beforeunload-waarschuwing
 * - Defensieve event-binding (if(btn) btn.onclick = …) en 401/403 handling
 */

/* --------------------------- Utilities --------------------------- */
function qs(sel, el=document){ return el.querySelector(sel); }
function qsa(sel, el=document){ return Array.from(el.querySelectorAll(sel)); }
function fmt2(n){ return n<10 ? '0'+n : ''+n; }
function hms(sec){ sec=Math.max(0,Math.floor(sec)); const h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60), s=sec%60; return `${fmt2(h)}:${fmt2(m)}:${fmt2(s)}`; }
function nowISO(){ return new Date().toISOString(); }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

function getCookie(name){
  const m = document.cookie.match(new RegExp('(^|; )' + name.replace(/[$()*+./?[\\\]^{|}-]/g,'\\$&') + '=([^;]*)'));
  return m ? decodeURIComponent(m[2]) : '';
}
function setLocal(key,val){ try{ localStorage.setItem(key, JSON.stringify(val)); }catch{} }
function getLocal(key, def=null){ try{ const v=localStorage.getItem(key); return v!=null?JSON.parse(v):def; }catch{ return def; } }
function setSession(key,val){ try{ sessionStorage.setItem(key, JSON.stringify(val)); }catch{} }
function getSession(key, def=null){ try{ const v=sessionStorage.getItem(key); return v!=null?JSON.parse(v):def; }catch{ return def; } }

/* --------------------------- App State --------------------------- */
const state = {
  apiBase: null,            // bv. '/api' of 'https://time.domein/api'
  csrfCookie: 'tm_csrf',
  user: null,               // {id,email,verified}
  categories: [],           // [{id,name,color,min_attention,max_attention,sort_index,...}]
  active: {},               // { catId: {startISO, startMs} }
  timers: {},               // { catId: DOMRef }
  reorderLock: false,       // voorkom race-conditions bij reorders
  settings: {
    warn3: localStorage.getItem('warn3') || '#f59e0b',
    warn5: localStorage.getItem('warn5') || '#ef4444',
    warn7: localStorage.getItem('warn7') || '#7c3aed'
  }
};

/* ---------------------- API Bootstrapping ------------------------ */
async function detectApiBase(){
  // Probeer /api/public-config op eigen origin
  const url = new URL('/api/public-config', location.origin).toString();
  try {
    const r = await fetch(url, { credentials:'include' });
    if (r.ok) {
      const j = await r.json().catch(()=>null);
      if (j) {
        state.apiBase = (j.api_base_url || j.apiBase || j.base || '/api');
        state.csrfCookie = j.csrf_cookie_name || state.csrfCookie;
        return;
      }
    }
  } catch {}
  // Fallback: /api op dezelfde origin
  state.apiBase = '/api';
}
function buildApiUrl(path){
  const base = state.apiBase || '/api';
  if (/^https?:\/\//i.test(base)) return base.replace(/\/+$/,'') + (path.startsWith('/')?path:'/'+path);
  return (base.endsWith('/')?base.slice(0,-1):base) + (path.startsWith('/')?path:'/'+path);
}
async function apiFetch(path, opts={}){
  if (!state.apiBase) await detectApiBase();
  const method = (opts.method||'GET').toUpperCase();
  const headers = Object.assign({'Content-Type':'application/json'}, opts.headers||{});
  if (['POST','PUT','PATCH','DELETE'].includes(method)) headers['X-CSRF-Token'] = getCookie(state.csrfCookie) || '';
  let res, body, ct;
  try {
    res = await fetch(buildApiUrl(path), { ...opts, headers, credentials:'include' });
    ct = res.headers.get('content-type') || '';
    body = ct.includes('application/json') ? await res.json().catch(()=>null) : await res.text().catch(()=> '');
  } catch (e) {
    const err = new Error('Netwerkfout / geblokkeerd');
    err.status = 0; throw err;
  }
  if (!res.ok) {
    if (res.status===401 || res.status===403) showAuthPanel(); // uitklappen login-paneel
    const msg = body && typeof body==='object' && body.error ? body.error
              : (typeof body === 'string' && body.trim() ? body : `${res.status} ${res.statusText}`);
    const err = new Error(msg); err.status = res.status; err.body = body; throw err;
  }
  return body;
}

/* ---------------------------- UI refs ---------------------------- */
let els = {};
function grabEls(){
  els = {
    // Menu & nav
    menuBtn: qs('#menuBtn'), menu: qs('#menu'),
    navRegister: qs('#nav-register'), navDashboard: qs('#nav-dashboard'),
    navManage: qs('#nav-manage'), navSettings: qs('#nav-settings'),
    navExport: qs('#nav-export'), navLogin: qs('#nav-login'), navLogout: qs('#nav-logout'),
    // Schermen
    screenMain: qs('#screen-main'), taskList: qs('#taskList'), emptyState: qs('#emptyState'), fabAdd: qs('#fabAdd'),
    panelDashboard: qs('#panel-dashboard'), dashClose: qs('#dashClose'), dashboardRoot: qs('#dashboardRoot'),
    panelManage: qs('#panel-manage'), manageList: qs('#manageList'), fabAddManage: qs('#fabAddManage'), manageClose: qs('#manageClose'),
    panelSettings: qs('#panel-settings'), settingsClose: qs('#settingsClose'),
    warn3: qs('#warn3'), warn5: qs('#warn5'), warn7: qs('#warn7'), saveSettings: qs('#saveSettings'),
    panelExport: qs('#panel-export'), expFrom: qs('#expFrom'), expTo: qs('#expTo'),
    btnExport: qs('#btnExport'), downloadLink: qs('#downloadLink'), exportClose: qs('#exportClose'),
    panelAuth: qs('#panel-auth'), authClose: qs('#authClose'),
    formLogin: qs('#formLogin'), formRegister: qs('#formRegister'),
    loginEmail: qs('#loginEmail'), loginPass: qs('#loginPass'), btnResetReq: qs('#btnResetReq'),
    regEmail: qs('#regEmail'), regPass: qs('#regPass'),
    resetBox: qs('#resetBox'), resetToken: qs('#resetToken'), resetPass: qs('#resetPass'), btnResetDo: qs('#btnResetDo'),
    // Modal
    modal: qs('#modalTask'), modalTitle: qs('#modalTitle'), modalClose: qs('#modalClose'),
    taskName: qs('#taskName'), taskColor: qs('#taskColor'), taskMin: qs('#taskMin'), taskMax: qs('#taskMax'),
    btnSoftColor: qs('#btnSoftColor'), btnSaveTask: qs('#btnSaveTask'), btnDeleteTask: qs('#btnDeleteTask'),
    // Overige
    userInfo: qs('#userInfo'), activeWarning: qs('#activeWarning'),
    helpOverlay: qs('#helpOverlay'), helpBtn: qs('#helpBtn'), helpClose: qs('#helpClose')
  };
}

/* ---------------------------- Menu/UI ---------------------------- */
function toggleMenu(force){
  if (!els.menu) return;
  if (force === true) els.menu.classList.remove('hidden');
  else if (force === false) els.menu.classList.add('hidden');
  else els.menu.classList.toggle('hidden');
}
document.addEventListener('click', e=>{
  if (!els.menu || !els.menuBtn) return;
  if (!els.menu.contains(e.target) && e.target !== els.menuBtn) toggleMenu(false);
});
function bindMenu(){
  if (els.menuBtn) els.menuBtn.onclick = ()=> toggleMenu();
  if (els.navRegister)  els.navRegister.onclick  = ()=>{ toggleMenu(false); showOnly(els.screenMain); };
  if (els.navDashboard) els.navDashboard.onclick = ()=>{ toggleMenu(false); showOnly(els.panelDashboard); loadDashboard(); };
  if (els.navManage)    els.navManage.onclick    = ()=>{ toggleMenu(false); showOnly(els.panelManage); };
  if (els.navSettings)  els.navSettings.onclick  = ()=>{ toggleMenu(false); showOnly(els.panelSettings); };
  if (els.navExport)    els.navExport.onclick    = ()=>{ toggleMenu(false); showOnly(els.panelExport); };
  if (els.navLogin)     els.navLogin.onclick     = ()=>{ toggleMenu(false); showOnly(els.panelAuth); };
  if (els.navLogout)    els.navLogout.onclick    = async ()=>{ toggleMenu(false); try{ await apiFetch('/auth/logout',{method:'POST'});}catch{} state.user=null; await refreshUser(); toast('Uitgelogd.'); };
  if (els.dashClose)    els.dashClose.onclick    = ()=> showOnly(els.screenMain);
  if (els.manageClose)  els.manageClose.onclick  = ()=> showOnly(els.screenMain);
  if (els.settingsClose)els.settingsClose.onclick= ()=> showOnly(els.screenMain);
  if (els.exportClose)  els.exportClose.onclick  = ()=> showOnly(els.screenMain);
  if (els.authClose)    els.authClose.onclick    = ()=> showOnly(els.screenMain);
}
function showOnly(id){
  const sections = [els.screenMain, els.panelDashboard, els.panelManage, els.panelSettings, els.panelExport, els.panelAuth];
  sections.forEach(x=> x && x.classList.add('hidden'));
  if (id) id.classList.remove('hidden');
  if (els.fabAdd) els.fabAdd.classList.toggle('hidden', id !== els.screenMain && id !== els.panelManage);
  if (els.fabAddManage) els.fabAddManage.classList.toggle('hidden', id !== els.panelManage);
}

/* ------------------------- Soft Color Gen ------------------------ */
function randomSoftColor(){
  const h = Math.floor(Math.random()*360);
  const s = 55 + Math.floor(Math.random()*20); // 55..75
  const l = 70 + Math.floor(Math.random()*10); // 70..80
  return hslToHex(h,s,l);
}
function hslToHex(h,s,l){
  s/=100; l/=100;
  const k=n=>(n + h/30)%12;
  const a=s*Math.min(l,1-l);
  const f=n=>l - a*Math.max(-1,Math.min(k(n)-3, Math.min(9-k(n),1)));
  const rgb=[0,8,4].map(n=>Math.round(255*f(n)));
  return '#' + rgb.map(x=>x.toString(16).padStart(2,'0')).join('');
}

/* ---------------------- Aandachtverdeling ------------------------ */
function distributeAttention(activeItems){
  // activeItems: [{id, min, max}]
  const n = activeItems.length;
  if (!n) return {};
  const p = {}; let remaining = 100;
  for (const it of activeItems){ const mn=Math.max(0,Math.min(100, it.min||0)); p[it.id]=mn; remaining -= mn; }
  if (remaining <= 0) return p;
  let free = activeItems.map(it=>({id:it.id, room: Math.max(0, Math.min(100,it.max||100) - p[it.id])}));
  while (remaining > 0){
    const open = free.filter(x=>x.room>0);
    if (!open.length) break;
    const add = remaining / open.length;
    let used=0;
    for (const slot of open){
      const give = Math.min(slot.room, add);
      p[slot.id] += give; slot.room -= give; used += give;
    }
    if (used < 0.0001) break;
    remaining -= used;
  }
  return p;
}

/* ----------------------- Taken renderen -------------------------- */
function renderTasks(){
  const ul = els.taskList; if (!ul) return;
  ul.innerHTML = '';
  els.emptyState && els.emptyState.classList.toggle('hidden', !!state.categories.length);

  // active → attention
  const activeItems = Object.keys(state.active).map(id=>{
    const c = state.categories.find(x=>x.id===id);
    return c ? {id, min:c.min_attention||0, max:c.max_attention||100} : null;
  }).filter(Boolean);
  const attention = distributeAttention(activeItems);

  for (const c of state.categories){
    const li = document.createElement('li');
    li.draggable = true;
    li.dataset.id = c.id;
    li.className = 'bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3';

    const dot = document.createElement('div');
    dot.className = 'w-4 h-4 rounded-full shrink-0';
    dot.style.background = c.color || '#e2e8f0';

    const name = document.createElement('div');
    name.className = 'flex-1 font-medium';
    name.textContent = c.name;

    const timer = document.createElement('div');
    timer.className = 'font-mono text-sm text-slate-600 w-24 text-right';
    timer.textContent = state.active[c.id] ? hms((Date.now()-state.active[c.id].startMs)/1000) : '00:00:00';
    state.timers[c.id] = timer;

    const att = document.createElement('div');
    att.className = 'w-16 text-right text-sm text-slate-700';
    const attVal = attention[c.id] ?? 0;
    att.textContent = attVal ? `${Math.round(attVal)}%` : '';

    const btn = document.createElement('button');
    const running = !!state.active[c.id];
    btn.className = 'px-3 py-1 rounded text-white ' + (running ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700');
    btn.textContent = running ? 'Stop' : 'Start';
    btn.onclick = ()=> running ? stopTask(c) : startTask(c);

    li.append(dot,name,timer,att,btn);
    ul.appendChild(li);
  }
  bindDnDMain();
  updateActiveWarning();
}

/* ------------------------ Drag & Drop ---------------------------- */
// Hoofdscherm
function bindDnDMain(){
  const list = els.taskList; if (!list) return;
  qsa(':scope > li', list).forEach(li=>{
    li.addEventListener('dragstart', e=>{
      li.classList.add('dragging');
      e.dataTransfer.setData('text/plain', li.dataset.id);
    });
    li.addEventListener('dragend', ()=> li.classList.remove('dragging'));
  });
  list.addEventListener('dragover', e=>{
    e.preventDefault();
    const dragging = qs('.dragging', list); if (!dragging) return;
    const siblings = qsa(':scope > li', list).filter(x=>x!==dragging);
    const y = e.clientY; let after=null;
    for (const s of siblings){
      const r=s.getBoundingClientRect(); const off=y - r.top - r.height/2;
      if (off<0){ after=s; break; }
    }
    if (!after) list.appendChild(dragging); else list.insertBefore(dragging, after);
  });
  list.addEventListener('drop', commitReorderFromList);
}
// Taakbeheer
function bindDnDManage(){
  const list = els.manageList; if (!list) return;
  qsa(':scope > li', list).forEach(li=>{
    li.setAttribute('draggable','true');
    li.addEventListener('dragstart', e=>{
      li.classList.add('dragging');
      e.dataTransfer.setData('text/plain', li.dataset.id);
    });
    li.addEventListener('dragend', ()=> li.classList.remove('dragging'));
  });
  list.addEventListener('dragover', e=>{
    e.preventDefault();
    const dragging = qs('.dragging', list); if (!dragging) return;
    const siblings = qsa(':scope > li', list).filter(x=>x!==dragging);
    const y = e.clientY; let after=null;
    for (const s of siblings){
      const r=s.getBoundingClientRect(); const off=y - r.top - r.height/2;
      if (off<0){ after=s; break; }
    }
    if (!after) list.appendChild(dragging); else list.insertBefore(dragging, after);
  });
  list.addEventListener('drop', commitReorderFromList);
}
async function commitReorderFromList(){
  if (state.reorderLock) return;
  const ids = qsa('#taskList > li').map(li=>li.dataset.id);
  const idsManage = qsa('#manageList > li').map(li=>li.dataset.id);
  const order = ids.length ? ids : idsManage;
  if (!order.length) return;
  state.reorderLock = true;
  try { await apiFetch('/categories/reorder', { method:'PUT', body: JSON.stringify({ order }) }); }
  catch(e){ console.warn('reorder:', e.message); toast('Volgorde opslaan mislukt.'); }
  finally { state.reorderLock = false; }
}

/* ---------------------- Active waarschuwing ---------------------- */
function updateActiveWarning(){
  const n = Object.keys(state.active).length;
  const el = els.activeWarning; if (!el) return;
  if (n <= 3){ el.classList.add('hidden'); el.textContent=''; return; }
  el.classList.remove('hidden');
  el.textContent = `${n} actief`;
  if (n > 7){ el.style.background = state.settings.warn7; el.style.color='#fff'; }
  else if (n > 5){ el.style.background = state.settings.warn5; el.style.color='#fff'; }
  else { el.style.background = state.settings.warn3; el.style.color='#111827'; }
}

/* --------------------------- Timers ------------------------------ */
let tickHandle=null;
function startTick(){
  if (tickHandle) return;
  tickHandle = setInterval(()=>{
    for (const [id, t] of Object.entries(state.timers)){
      if (state.active[id]){
        const secs = (Date.now()-state.active[id].startMs)/1000;
        t.textContent = hms(secs);
      }
    }
  }, 1000);
}

/* --------------------------- API: Cats --------------------------- */
async function loadCategories(){
  try {
    const list = await apiFetch('/categories');
    state.categories = Array.isArray(list) ? list : [];
    // Ruim active entries op waarvan categorie is verdwenen
    for (const id of Object.keys(state.active)){
      if (!state.categories.find(c=>c.id===id)) delete state.active[id];
    }
    renderTasks();
    renderManage();
  } catch (e){
    // Niet ingelogd of netwerkfout
    console.warn('loadCategories:', e.message);
  }
}
async function createCategory(payload){
  const res = await apiFetch('/categories', { method:'POST', body: JSON.stringify(payload) });
  await loadCategories();
  return res && res.id;
}
async function updateCategory(id, payload){
  await apiFetch(`/categories/${id}`, { method:'PUT', body: JSON.stringify(payload) });
  await loadCategories();
}
async function deleteCategory(id){
  await apiFetch(`/categories/${id}`, { method:'DELETE' });
  if (state.active[id]) delete state.active[id];
  await loadCategories();
}

/* -------------------------- API: Timelogs ------------------------ */
async function createTimelog(entry){
  // entry: {category_id, start_time, end_time, duration?, with_tasks?}
  return apiFetch('/timelogs', { method:'POST', body: JSON.stringify(entry) });
}

/* ----------------------- Start / Stop taak ----------------------- */
function startTask(cat){
  if (!state.user){ toast('Log eerst in om te registreren.'); showAuthPanel(); return; }
  if (state.active[cat.id]) return;
  state.active[cat.id] = { startISO: nowISO(), startMs: Date.now() };
  persistActive();
  renderTasks();
}
async function stopTask(cat){
  const act = state.active[cat.id]; if (!act) return;
  const endISO = nowISO();
  const withTasks = Object.keys(state.active).filter(id=>id!==cat.id).map(id=>{
    const c = state.categories.find(x=>x.id===id);
    return c ? c.name : 'Onbekend';
  });
  try {
    await createTimelog({ category_id: cat.id, start_time: act.startISO, end_time: endISO, with_tasks: withTasks });
  } catch(e){
    console.error(e); toast('Opslaan mislukt (timelog).');
  } finally {
    delete state.active[cat.id];
    persistActive();
    renderTasks();
  }
}

/* --------- Active sessies persistentie + unload waarschuwing ----- */
function persistActive(){
  // Bewaar alleen startISO per catId; startMs reconstrueren bij load
  const store = {};
  for (const [id, v] of Object.entries(state.active)) store[id] = { startISO: v.startISO };
  setSession('activeTasks', store);
  updateUnloadGuard();
}
function restoreActive(){
  const stored = getSession('activeTasks', {});
  const map = typeof stored === 'object' && stored ? stored : {};
  for (const [id, v] of Object.entries(map)){
    const startISO = v.startISO || nowISO();
    const t = new Date(startISO).getTime();
    state.active[id] = { startISO, startMs: isFinite(t) ? t : Date.now() };
  }
}
function updateUnloadGuard(){
  const hasActive = Object.keys(state.active).length > 0;
  window.onbeforeunload = hasActive ? (e)=>{ e.preventDefault(); e.returnValue=''; return ''; } : null;
}

/* --------------------------- Manage UI --------------------------- */
let editingId = null;
function renderManage(){
  const ul = els.manageList; if (!ul) return;
  ul.innerHTML = '';
  for (const c of state.categories){
    const li = document.createElement('li');
    li.dataset.id = c.id;
    li.className = 'bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3';
    const dot = document.createElement('div'); dot.className='w-4 h-4 rounded-full'; dot.style.background = c.color || '#e2e8f0';
    const name = document.createElement('div'); name.className='flex-1'; name.textContent=c.name;
    const edit = document.createElement('button'); edit.className='px-3 py-1 border rounded'; edit.textContent='Bewerken';
    const del  = document.createElement('button'); del.className='px-3 py-1 border rounded';  del.textContent='Verwijderen';
    edit.onclick = ()=> openModal(c);
    del.onclick  = async ()=>{ if (confirm(`Taak “${c.name}” verwijderen?`)) await deleteCategory(c.id); };
    li.append(dot,name,edit,del);
    ul.appendChild(li);
  }
  bindDnDManage();
}
function openModal(c=null){
  editingId = c ? c.id : null;
  if (els.modalTitle) els.modalTitle.textContent = c ? 'Taak bewerken' : 'Nieuwe taak';
  if (els.taskName) els.taskName.value = c ? c.name : '';
  if (els.taskColor) els.taskColor.value = c ? (c.color || randomSoftColor()) : randomSoftColor();
  if (els.taskMin) els.taskMin.value = c ? (c.min_attention ?? 0) : 0;
  if (els.taskMax) els.taskMax.value = c ? (c.max_attention ?? 100) : 100;
  if (els.btnDeleteTask) els.btnDeleteTask.classList.toggle('hidden', !c);
  if (els.modal) els.modal.classList.remove('hidden');
}
function closeModal(){ if (els.modal) els.modal.classList.add('hidden'); }
async function saveModal(){
  const name = (els.taskName && els.taskName.value.trim()) || '';
  const color= (els.taskColor && els.taskColor.value) || randomSoftColor();
  const minA = Math.max(0, Math.min(100, parseInt((els.taskMin && els.taskMin.value)||'0',10)));
  const maxA = Math.max(1, Math.min(100, parseInt((els.taskMax && els.taskMax.value)||'100',10)));
  if (!name){ toast('Naam is verplicht.'); return; }
  if (minA > maxA){ toast('Min mag niet groter zijn dan max.'); return; }
  try {
    if (editingId) await updateCategory(editingId, { name, color, min_attention:minA, max_attention:maxA });
    else await createCategory({ name, color, min_attention:minA, max_attention:maxA });
    closeModal();
  } catch(e){ toast('Opslaan mislukt.'); }
}
async function deleteFromModal(){
  if (!editingId) return;
  if (confirm('Verwijderen?')){ await deleteCategory(editingId); closeModal(); }
}

/* -------------------------- Settings UI -------------------------- */
function loadSettingsUI(){
  if (els.warn3) els.warn3.value = state.settings.warn3;
  if (els.warn5) els.warn5.value = state.settings.warn5;
  if (els.warn7) els.warn7.value = state.settings.warn7;
}
function saveSettingsUI(){
  state.settings.warn3 = (els.warn3 && els.warn3.value) || state.settings.warn3;
  state.settings.warn5 = (els.warn5 && els.warn5.value) || state.settings.warn5;
  state.settings.warn7 = (els.warn7 && els.warn7.value) || state.settings.warn7;
  try {
    localStorage.setItem('warn3', state.settings.warn3);
    localStorage.setItem('warn5', state.settings.warn5);
    localStorage.setItem('warn7', state.settings.warn7);
  } catch {}
  updateActiveWarning();
  toast('Instellingen opgeslagen.');
}

/* ---------------------------- Export ----------------------------- */
async function doExport(){
  try {
    const p = new URLSearchParams();
    if (els.expFrom && els.expFrom.value) p.set('from', new Date(els.expFrom.value).toISOString());
    if (els.expTo && els.expTo.value)   p.set('to',   new Date(els.expTo.value).toISOString());
    const rows = await apiFetch('/timelogs' + (p.toString()?('?'+p.toString()):''));
    const header = ['ID','CategorieID','Start','Einde','Duur(seconden)','Met'];
    const lines = [header.join(',')];
    for (const r of rows){
      const withTasks = r.with_tasks ? (Array.isArray(r.with_tasks) ? r.with_tasks : (safeJson(r.with_tasks) || [])) : [];
      const line = [
        r.id, r.category_id, r.start_time, r.end_time || '', (r.duration || ''),
        withTasks.join(';')
      ].map(csvCell).join(',');
      lines.push(line);
    }
    const csv = lines.join('\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
    const url = URL.createObjectURL(blob);
    if (els.downloadLink){
      els.downloadLink.href = url;
      els.downloadLink.download = 'timelogs.csv';
      els.downloadLink.classList.remove('hidden');
    }
    toast('Export klaar. Klik op “Download CSV”.');
  } catch(e){ console.error(e); toast('Export mislukt.'); }
}
function safeJson(s){ try { return JSON.parse(s); } catch { return null; } }
function csvCell(v){ const s=(v==null)?'':String(v); return /[",\n]/.test(s)?`"${s.replace(/"/g,'""')}"`:s; }

/* ------------------------------ Auth ----------------------------- */
async function refreshUser(){
  try {
    const res = await apiFetch('/auth/me');
    state.user = res.user || res || null;
    if (els.userInfo) els.userInfo.textContent = state.user ? `${state.user.email}${state.user.verified?'':' (niet geverifieerd)'}` : 'Niet ingelogd';
    if (els.navLogout) els.navLogout.classList.toggle('hidden', !state.user);
    if (els.navLogin)  els.navLogin .classList.toggle('hidden',  !!state.user);
  } catch {
    state.user = null;
    if (els.userInfo) els.userInfo.textContent = 'Niet ingelogd';
    if (els.navLogout) els.navLogout.classList.add('hidden');
    if (els.navLogin)  els.navLogin.classList.remove('hidden');
  }
}
function showAuthPanel(){ if (els.panelAuth) showOnly(els.panelAuth); }
async function doLogin(e){
  e.preventDefault();
  const email = (els.loginEmail && els.loginEmail.value.trim()) || '';
  const password = (els.loginPass && els.loginPass.value) || '';
  if (!email || !password) return;
  await apiFetch('/auth/login', { method:'POST', body: JSON.stringify({ email, password }) });
  await refreshUser(); await loadCategories(); showOnly(els.screenMain); toast('Ingelogd.');
}
async function doRegister(e){
  e.preventDefault();
  const email = (els.regEmail && els.regEmail.value.trim()) || '';
  const password = (els.regPass && els.regPass.value) || '';
  if (!email || password.length < 10){ toast('Gebruik min. 10 tekens.'); return; }
  await apiFetch('/auth/register', { method:'POST', body: JSON.stringify({ email, password }) });
  await refreshUser(); toast('Account gemaakt. Check je e‑mail om te verifiëren.'); showOnly(els.screenMain);
}
async function requestReset(){
  const email = prompt('E‑mail voor reset?'); if (!email) return;
  await apiFetch('/auth/request-reset', { method:'POST', body: JSON.stringify({ email }) });
  if (els.resetBox) els.resetBox.classList.remove('hidden');
  toast('Resetmail verstuurd (indien bekende e‑mail).');
}
async function doReset(){
  const token = (els.resetToken && els.resetToken.value.trim()) || (new URL(location.href).searchParams.get('reset') || '');
  const new_password = (els.resetPass && els.resetPass.value) || '';
  if (!token || !new_password){ toast('Token en nieuw wachtwoord zijn nodig.'); return; }
  await apiFetch('/auth/reset', { method:'POST', body: JSON.stringify({ token, new_password }) });
  if (els.resetBox) els.resetBox.classList.add('hidden');
  toast('Wachtwoord gewijzigd. Log opnieuw in.');
}

/* ---------------------------- Dashboard -------------------------- */
async function loadDashboard(){
  if (!els.dashboardRoot) return;
  try {
    const since = new Date(); since.setDate(since.getDate()-7);
    const rows = await apiFetch('/timelogs?from='+encodeURIComponent(since.toISOString()));
    // renderDashboard komt uit dashboard.js
    renderDashboard('#dashboardRoot', rows, state.categories);
  } catch(e){
    console.warn('Dashboard:', e.message);
    els.dashboardRoot.innerHTML = '<div class="text-slate-500">Geen data of niet ingelogd.</div>';
  }
}

/* ----------------------------- Toasts ---------------------------- */
let toastTimeout=null;
function toast(msg){
  let bar = qs('#toastBar');
  if (!bar){
    bar = document.createElement('div');
    bar.id='toastBar';
    bar.className='fixed left-1/2 -translate-x-1/2 bottom-6 bg-slate-900 text-white px-4 py-2 rounded shadow z-50';
    document.body.appendChild(bar);
  }
  bar.textContent = msg;
  bar.style.opacity = '1';
  clearTimeout(toastTimeout);
  toastTimeout = setTimeout(()=> bar.style.opacity='0', 3500);
}

/* ------------------------- Bindings/Init ------------------------- */
function bindMain(){
  if (els.fabAdd) els.fabAdd.onclick = ()=> openModal(null);
  if (els.fabAddManage) els.fabAddManage.onclick = ()=> openModal(null);
  if (els.modalClose) els.modalClose.onclick = closeModal;
  if (els.btnSoftColor) els.btnSoftColor.onclick = (e)=>{ e.preventDefault(); if (els.taskColor) els.taskColor.value = randomSoftColor(); };
  if (els.btnSaveTask) els.btnSaveTask.onclick = saveModal;
  if (els.btnDeleteTask) els.btnDeleteTask.onclick = deleteFromModal;

  if (els.saveSettings) els.saveSettings.onclick = saveSettingsUI;
  if (els.btnExport) els.btnExport.onclick = doExport;

  if (els.formLogin) els.formLogin.onsubmit = doLogin;
  if (els.formRegister) els.formRegister.onsubmit = doRegister;
  if (els.btnResetReq) els.btnResetReq.onclick = requestReset;
  if (els.btnResetDo) els.btnResetDo.onclick = doReset;

  if (els.helpBtn) els.helpBtn.onclick = ()=> els.helpOverlay && els.helpOverlay.classList.remove('hidden');
  if (els.helpClose) els.helpClose.onclick = ()=> els.helpOverlay && els.helpOverlay.classList.add('hidden');
}
function firstRunHelp(){
  const seen = localStorage.getItem('helpSeen');
  if (!seen && els.helpOverlay){
    els.helpOverlay.classList.remove('hidden');
    if (els.helpClose) els.helpClose.addEventListener('click', ()=> localStorage.setItem('helpSeen','1'), { once:true });
  }
}
async function handleUrlParams(){
  const sp = new URLSearchParams(location.search);
  const verify = sp.get('verify');
  const reset  = sp.get('reset');
  if (verify){
    try { await apiFetch('/auth/verify?token='+encodeURIComponent(verify)); toast('E‑mail geverifieerd.'); sp.delete('verify'); history.replaceState({},'',location.pathname); await refreshUser(); }
    catch { toast('Verifiëren mislukt.'); }
  }
  if (reset){
    showOnly(els.panelAuth);
    if (els.resetBox) els.resetBox.classList.remove('hidden');
    if (els.resetToken) els.resetToken.value = reset;
  }
}

let elsCached=false;
async function init(){
  if (!elsCached){ grabEls(); elsCached=true; }
  bindMenu(); bindMain();
  loadSettingsUI();
  restoreActive(); updateUnloadGuard(); startTick();

  await detectApiBase();
  await refreshUser();
  await loadCategories();
  firstRunHelp();
  await handleUrlParams();
}

// DOMContentLoaded binding
document.addEventListener('DOMContentLoaded', init);
