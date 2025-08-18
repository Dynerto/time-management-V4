/* Tijdregistratie PWA – premium auth + volledige functionaliteit
 * - API base automatisch via GET /api/public-config
 * - Auth-gate: zonder login eerst op Auth-scherm
 * - Polished auth UI (tabs, toggles, spinners, foutmeldingen)
 * - Multitask timers, DnD (hoofd + beheer), aandachtverdeling, export
 * - Onboarding, sessie-persistent actieve taken, beforeunload guard
 */

function qs(s, r=document){ return r.querySelector(s); }
function qsa(s, r=document){ return Array.from(r.querySelectorAll(s)); }
function fmt2(n){ return n<10?'0'+n:''+n; }
function hms(sec){ sec=Math.max(0,Math.floor(sec)); const h=Math.floor(sec/3600),m=Math.floor((sec%3600)/60),s=sec%60; return `${fmt2(h)}:${fmt2(m)}:${fmt2(s)}`; }
function nowISO(){ return new Date().toISOString(); }
function getCookie(name){ const m=document.cookie.match(new RegExp('(^|; )'+name.replace(/[$()*+./?[\\\]^{|}-]/g,'\\$&')+'=([^;]*)')); return m?decodeURIComponent(m[2]):''; }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;' }[m])); }
function setSession(k,v){ try{ sessionStorage.setItem(k,JSON.stringify(v)); }catch{} }
function getSession(k,d=null){ try{ const v=sessionStorage.getItem(k); return v!=null?JSON.parse(v):d; }catch{ return d; } }

const state = {
  apiBase: null,
  csrfCookie: 'tm_csrf',
  user: null,
  categories: [],
  active: {},
  timers: {},
  reorderLock: false,
  settings: {
    warn3: localStorage.getItem('warn3') || '#f59e0b',
    warn5: localStorage.getItem('warn5') || '#ef4444',
    warn7: localStorage.getItem('warn7') || '#7c3aed'
  }
};

/* ---------- API bootstrap ---------- */
async function detectApiBase(){
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
  } catch{}
  state.apiBase = '/api';
}
function apiUrl(p){ const b=state.apiBase||'/api'; return (/^https?:\/\//i.test(b)?b.replace(/\/+$/,''):(b.endsWith('/')?b.slice(0,-1):b)) + (p.startsWith('/')?p:'/'+p); }
async function apiFetch(path, opts={}){
  if (!state.apiBase) await detectApiBase();
  const method=(opts.method||'GET').toUpperCase();
  const headers=Object.assign({'Content-Type':'application/json'}, opts.headers||{});
  if (['POST','PUT','PATCH','DELETE'].includes(method)) headers['X-CSRF-Token']=getCookie(state.csrfCookie)||'';
  let res,body,ct;
  try {
    res=await fetch(apiUrl(path),{...opts,headers,credentials:'include'});
    ct=res.headers.get('content-type')||'';
    body=ct.includes('application/json')?await res.json().catch(()=>null):await res.text().catch(()=> '');
  } catch {
    throw new Error('Netwerkfout: API onbereikbaar');
  }
  if (!res.ok) {
    if (res.status===401||res.status===403) showAuthPanel();
    const msg = (body && typeof body==='object' && body.error) ? body.error :
                (typeof body==='string' && body.trim()?body:`${res.status} ${res.statusText}`);
    const err=new Error(msg); err.status=res.status; err.body=body; throw err;
  }
  return body;
}

/* ---------- Elements ---------- */
let els={};
function grabEls(){
  els={
    menuBtn:qs('#menuBtn'), menu:qs('#menu'),
    navRegister:qs('#nav-register'), navDashboard:qs('#nav-dashboard'),
    navManage:qs('#nav-manage'), navSettings:qs('#nav-settings'),
    navExport:qs('#nav-export'), navLogin:qs('#nav-login'), navLogout:qs('#nav-logout'),
    screenMain:qs('#screen-main'), taskList:qs('#taskList'), emptyState:qs('#emptyState'), fabAdd:qs('#fabAdd'),
    panelDashboard:qs('#panel-dashboard'), dashClose:qs('#dashClose'), dashboardRoot:qs('#dashboardRoot'),
    panelManage:qs('#panel-manage'), manageList:qs('#manageList'), fabAddManage:qs('#fabAddManage'), manageClose:qs('#manageClose'),
    panelSettings:qs('#panel-settings'), settingsClose:qs('#settingsClose'),
    warn3:qs('#warn3'), warn5:qs('#warn5'), warn7:qs('#warn7'), saveSettings:qs('#saveSettings'),
    panelExport:qs('#panel-export'), expFrom:qs('#expFrom'), expTo:qs('#expTo'), btnExport:qs('#btnExport'), downloadLink:qs('#downloadLink'), exportClose:qs('#exportClose'),
    userInfo:qs('#userInfo'), activeWarning:qs('#activeWarning'),
    // Auth premium
    panelAuth:qs('#panel-auth'),
    tabLogin:qs('#tabLogin'), tabRegister:qs('#tabRegister'),
    formLogin:qs('#formLogin'), loginEmail:qs('#loginEmail'), loginPass:qs('#loginPass'),
    loginToggle:qs('#loginToggle'), btnLogin:qs('#btnLogin'), loginSpinner:qs('#loginSpinner'), loginLabel:qs('#loginLabel'),
    rememberMe:qs('#rememberMe'), btnResetReq:qs('#btnResetReq'),
    formRegister:qs('#formRegister'), regEmail:qs('#regEmail'), regPass:qs('#regPass'),
    regToggle:qs('#regToggle'), btnRegister:qs('#btnRegister'), regSpinner:qs('#regSpinner'), regLabel:qs('#regLabel'),
    authError:qs('#authError'), regError:qs('#regError'),
    gotoRegister:qs('#gotoRegister'), gotoLogin:qs('#gotoLogin'),
    resetBox:qs('#resetBox'), resetToken:qs('#resetToken'), resetPass:qs('#resetPass'), btnResetDo:qs('#btnResetDo'), resetMsg:qs('#resetMsg'),
    // Modal
    modal:qs('#modalTask'), modalTitle:qs('#modalTitle'), modalClose:qs('#modalClose'),
    taskName:qs('#taskName'), taskColor:qs('#taskColor'), taskMin:qs('#taskMin'), taskMax:qs('#taskMax'),
    btnSoftColor:qs('#btnSoftColor'), btnSaveTask:qs('#btnSaveTask'), btnDeleteTask:qs('#btnDeleteTask'),
    // Help
    helpOverlay:qs('#helpOverlay'), helpBtn:qs('#helpBtn'), helpClose:qs('#helpClose')
  };
}

/* ---------- UI helpers ---------- */
function toggleMenu(force){
  if (!els.menu) return;
  if (force===true) els.menu.classList.remove('hidden');
  else if (force===false) els.menu.classList.add('hidden');
  else els.menu.classList.toggle('hidden');
}
document.addEventListener('click', e=>{
  if (!els.menu || !els.menuBtn) return;
  if (!els.menu.contains(e.target) && e.target!==els.menuBtn) toggleMenu(false);
});
function showOnly(id){
  const sections=[els.screenMain,els.panelDashboard,els.panelManage,els.panelSettings,els.panelExport,els.panelAuth];
  sections.forEach(x=>x&&x.classList.add('hidden'));
  id && id.classList.remove('hidden');
  if (els.fabAdd) els.fabAdd.classList.toggle('hidden', id!==els.screenMain && id!==els.panelManage);
  if (els.fabAddManage) els.fabAddManage.classList.toggle('hidden', id!==els.panelManage);
}
let toastTimeout=null;
function toast(msg){
  let bar=qs('#toastBar');
  if (!bar){ bar=document.createElement('div'); bar.id='toastBar'; bar.className='fixed left-1/2 -translate-x-1/2 bottom-6 bg-slate-900 text-white px-4 py-2 rounded shadow z-50'; document.body.appendChild(bar); }
  bar.textContent=msg; bar.style.opacity='1';
  clearTimeout(toastTimeout); toastTimeout=setTimeout(()=> bar.style.opacity='0', 3500);
}

/* ---------- Auth gating ---------- */
async function refreshUser(){
  try{
    const res=await apiFetch('/auth/me');
    state.user = res.user || res || null;
  }catch{
    state.user = null;
  }
  if (state.user){
    els.userInfo && (els.userInfo.textContent = `${state.user.email}${state.user.verified?'':' (niet geverifieerd)'}`);
    els.navLogout && els.navLogout.classList.remove('hidden');
    els.navLogin && els.navLogin.classList.add('hidden');
    showOnly(els.screenMain);
  }else{
    els.userInfo && (els.userInfo.textContent = 'Niet ingelogd');
    els.navLogout && els.navLogout.classList.add('hidden');
    els.navLogin && els.navLogin.classList.remove('hidden');
    showAuthPanel();
  }
}
function showAuthPanel(){
  // zet tabs op Login
  setAuthTab('login');
  showOnly(els.panelAuth);
}

/* ---------- Premium auth UI ---------- */
function setAuthTab(which){
  if (!els.tabLogin || !els.tabRegister) return;
  const isLogin = which==='login';
  els.tabLogin.classList.toggle('bg-white', isLogin);
  els.tabLogin.classList.toggle('shadow', isLogin);
  els.tabLogin.classList.toggle('text-slate-900', isLogin);
  els.tabLogin.classList.toggle('text-slate-600', !isLogin);

  els.tabRegister.classList.toggle('bg-white', !isLogin);
  els.tabRegister.classList.toggle('shadow', !isLogin);
  els.tabRegister.classList.toggle('text-slate-900', !isLogin);
  els.tabRegister.classList.toggle('text-slate-600', isLogin);

  els.formLogin && els.formLogin.classList.toggle('hidden', !isLogin);
  els.formRegister && els.formRegister.classList.toggle('hidden', isLogin);
  els.resetBox && els.resetBox.classList.add('hidden');
}
function togglePassword(inputEl){
  if (!inputEl) return;
  inputEl.type = inputEl.type==='password' ? 'text' : 'password';
}
function pwStrength(pw){
  let s=0;
  if (pw.length>=10) s+=1;
  if (/[A-Z]/.test(pw)) s+=1;
  if (/[0-9]/.test(pw)) s+=1;
  if (/[^A-Za-z0-9]/.test(pw)) s+=1;
  return s; // 0..4
}

/* ---------- Menu bindings ---------- */
function bindMenu(){
  els.menuBtn && (els.menuBtn.onclick=()=>toggleMenu());
  els.navRegister && (els.navRegister.onclick=()=>{ toggleMenu(false); showAuthPanel(); setAuthTab('register'); });
  els.navDashboard && (els.navDashboard.onclick=()=>{ toggleMenu(false); showOnly(els.panelDashboard); loadDashboard(); });
  els.navManage && (els.navManage.onclick=()=>{ toggleMenu(false); showOnly(els.panelManage); });
  els.navSettings && (els.navSettings.onclick=()=>{ toggleMenu(false); showOnly(els.panelSettings); });
  els.navExport && (els.navExport.onclick=()=>{ toggleMenu(false); showOnly(els.panelExport); });
  els.navLogin && (els.navLogin.onclick=()=>{ toggleMenu(false); showAuthPanel(); });
  els.navLogout && (els.navLogout.onclick=async ()=>{ toggleMenu(false); try{ await apiFetch('/auth/logout',{method:'POST'});}catch{} state.user=null; await refreshUser(); toast('Uitgelogd.'); });
  els.dashClose && (els.dashClose.onclick=()=>showOnly(els.screenMain));
  els.manageClose && (els.manageClose.onclick=()=>showOnly(els.screenMain));
  els.settingsClose && (els.settingsClose.onclick=()=>showOnly(els.screenMain));
  els.exportClose && (els.exportClose.onclick=()=>showOnly(els.screenMain));
}

/* ---------- Main bindings ---------- */
function bindMain(){
  // Auth tabs & toggles
  els.tabLogin && (els.tabLogin.onclick=()=>setAuthTab('login'));
  els.tabRegister && (els.tabRegister.onclick=()=>setAuthTab('register'));
  els.gotoRegister && (els.gotoRegister.onclick=(e)=>{ e.preventDefault(); setAuthTab('register'); });
  els.gotoLogin && (els.gotoLogin.onclick=(e)=>{ e.preventDefault(); setAuthTab('login'); });
  els.loginToggle && (els.loginToggle.onclick=()=>togglePassword(els.loginPass));
  els.regToggle && (els.regToggle.onclick=()=>togglePassword(els.regPass));

  // PW meter
  const pwBar=qs('#pwBar');
  els.regPass && (els.regPass.oninput=()=>{
    const s=pwStrength(els.regPass.value);
    const w=[0,25,50,75,100][s];
    if (pwBar){ pwBar.style.width=w+'%'; pwBar.className='h-1'; pwBar.classList.add(w<50?'bg-rose-500': (w<75?'bg-amber-500':'bg-emerald-500')); }
  });

  // Login submit
  els.formLogin && (els.formLogin.onsubmit=async (e)=>{
    e.preventDefault();
    if (!els.loginEmail.value || !els.loginPass.value) return;
    setBusy(els.btnLogin, els.loginSpinner, els.loginLabel, true, 'Bezig…');
    try {
      await apiFetch('/auth/login',{method:'POST', body: JSON.stringify({ email:els.loginEmail.value.trim(), password:els.loginPass.value, remember: !!(els.rememberMe && els.rememberMe.checked) })});
      clearAuthErrors();
      await refreshUser(); await loadCategories(); showOnly(els.screenMain); toast('Ingelogd.');
    } catch(e){
      showAuthError(String(e.message||'Inloggen mislukt'));
    } finally {
      setBusy(els.btnLogin, els.loginSpinner, els.loginLabel, false, 'Inloggen');
    }
  });

  // Register submit
  els.formRegister && (els.formRegister.onsubmit=async (e)=>{
    e.preventDefault();
    if (!els.regEmail.value || !els.regPass.value){ showRegError('Vul e‑mail en wachtwoord in.'); return; }
    if (els.regPass.value.length<10){ showRegError('Gebruik minimaal 10 tekens.'); return; }
    setBusy(els.btnRegister, els.regSpinner, els.regLabel, true, 'Bezig…');
    try{
      await apiFetch('/auth/register',{method:'POST', body: JSON.stringify({ email:els.regEmail.value.trim(), password:els.regPass.value })});
      clearRegErrors();
      toast('Account gemaakt. Check je e‑mail om te verifiëren.');
      setAuthTab('login');
    }catch(e){ showRegError(String(e.message||'Registratie mislukt')); }
    finally{ setBusy(els.btnRegister, els.regSpinner, els.regLabel, false, 'Account maken'); }
  });

  // Reset flow
  els.btnResetReq && (els.btnResetReq.onclick=async ()=>{
    const email=prompt('E‑mail voor reset?'); if (!email) return;
    try{ await apiFetch('/auth/request-reset',{method:'POST', body: JSON.stringify({ email })}); if(els.resetBox) els.resetBox.classList.remove('hidden'); toast('Resetmail verstuurd (indien bekend).'); }
    catch(e){ showAuthError(String(e.message||'Kon reset niet starten')); }
  });
  els.btnResetDo && (els.btnResetDo.onclick=async ()=>{
    const token=(els.resetToken && els.resetToken.value.trim()) || new URL(location.href).searchParams.get('reset') || '';
    const new_password=els.resetPass && els.resetPass.value;
    if (!token || !new_password){ toast('Token en nieuw wachtwoord nodig.'); return; }
    try{ await apiFetch('/auth/reset',{method:'POST', body: JSON.stringify({ token, new_password })}); els.resetMsg && (els.resetMsg.textContent='Wachtwoord gewijzigd. Log opnieuw in.'); els.resetMsg && els.resetMsg.classList.remove('hidden'); }
    catch(e){ showAuthError(String(e.message||'Reset mislukt')); }
  });

  // Overige UI
  els.fabAdd && (els.fabAdd.onclick=()=> openModal(null));
  els.fabAddManage && (els.fabAddManage.onclick=()=> openModal(null));
  els.modalClose && (els.modalClose.onclick=closeModal);
  els.btnSoftColor && (els.btnSoftColor.onclick=(e)=>{ e.preventDefault(); els.taskColor.value=randomSoftColor(); });
  els.btnSaveTask && (els.btnSaveTask.onclick=saveModal);
  els.btnDeleteTask && (els.btnDeleteTask.onclick=deleteFromModal);
  els.saveSettings && (els.saveSettings.onclick=saveSettingsUI);
  els.btnExport && (els.btnExport.onclick=doExport);
  els.helpBtn && (els.helpBtn.onclick=()=> els.helpOverlay.classList.remove('hidden'));
  els.helpClose && (els.helpClose.onclick=()=> els.helpOverlay.classList.add('hidden'));
}
function setBusy(btn, spinner, labelEl, busy, labelWhenIdle){
  if (!btn) return;
  btn.disabled = !!busy;
  spinner && spinner.classList.toggle('hidden', !busy);
  labelEl && (labelEl.textContent = busy ? 'Bezig…' : labelWhenIdle);
}
function clearAuthErrors(){ els.authError && els.authError.classList.add('hidden'); }
function showAuthError(msg){ if (!els.authError) return; els.authError.textContent=msg; els.authError.classList.remove('hidden'); }
function clearRegErrors(){ els.regError && els.regError.classList.add('hidden'); }
function showRegError(msg){ if (!els.regError) return; els.regError.textContent=msg; els.regError.classList.remove('hidden'); }

/* ---------- Soft colors ---------- */
function randomSoftColor(){
  const h=Math.floor(Math.random()*360), s=55+Math.floor(Math.random()*20), l=70+Math.floor(Math.random()*10);
  return hsl2hex(h,s,l);
}
function hsl2hex(h,s,l){
  s/=100; l/=100;
  const k=n=>(n+h/30)%12, a=s*Math.min(l,1-l), f=n=>l-a*Math.max(-1,Math.min(k(n)-3,Math.min(9-k(n),1)));
  const r=Math.round(255*f(0)), g=Math.round(255*f(8)), b=Math.round(255*f(4));
  return '#'+[r,g,b].map(x=>x.toString(16).padStart(2,'0')).join('');
}

/* ---------- Attention ---------- */
function distributeAttention(activeItems){
  const n=activeItems.length; if (!n) return {};
  const p={}; let remain=100;
  for (const it of activeItems){ const mn=Math.max(0,Math.min(100,it.min||0)); p[it.id]=mn; remain-=mn; }
  if (remain<=0) return p;
  let free=activeItems.map(it=>({id:it.id,room:Math.max(0,Math.min(100,it.max||100)-p[it.id])}));
  while (remain>0){
    const open=free.filter(x=>x.room>0);
    if (!open.length) break;
    const add=remain/open.length; let used=0;
    for (const s of open){ const g=Math.min(s.room,add); p[s.id]+=g; s.room-=g; used+=g; }
    if (used < 0.0001) break;
    remain-=used;
  }
  return p;
}

/* ---------- Render tasks ---------- */
function renderTasks(){
  const ul=els.taskList; if (!ul) return;
  ul.innerHTML=''; els.emptyState && els.emptyState.classList.toggle('hidden', !!state.categories.length);

  const activeItems=Object.keys(state.active).map(id=>{
    const c=state.categories.find(x=>x.id===id);
    return c?{id, min:c.min_attention||0, max:c.max_attention||100}:null;
  }).filter(Boolean);
  const attention=distributeAttention(activeItems);

  for (const c of state.categories){
    const li=document.createElement('li');
    li.draggable=true; li.dataset.id=c.id;
    li.className='bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3';

    const dot=document.createElement('div'); dot.className='w-4 h-4 rounded-full shrink-0'; dot.style.background=c.color||'#e2e8f0';
    const name=document.createElement('div'); name.className='flex-1 font-medium'; name.textContent=c.name;

    const timer=document.createElement('div'); timer.className='font-mono text-sm text-slate-600 w-24 text-right';
    timer.textContent=state.active[c.id]?hms((Date.now()-state.active[c.id].startMs)/1000):'00:00:00';
    state.timers[c.id]=timer;

    const att=document.createElement('div'); att.className='w-16 text-right text-sm text-slate-700';
    const a=attention[c.id]??0; att.textContent=a?`${Math.round(a)}%`:''; 

    const btn=document.createElement('button');
    const running=!!state.active[c.id];
    btn.className='px-3 py-1 rounded text-white '+(running?'bg-rose-600 hover:bg-rose-700':'bg-emerald-600 hover:bg-emerald-700');
    btn.textContent=running?'Stop':'Start';
    btn.onclick=()=> running?stopTask(c):startTask(c);

    li.append(dot,name,timer,att,btn);
    ul.appendChild(li);
  }
  bindDnDMain(); updateActiveWarning();
}

/* ---------- Drag & Drop ---------- */
function bindDnDMain(){
  const list=els.taskList; if (!list) return;
  qsa(':scope > li', list).forEach(li=>{
    li.addEventListener('dragstart',e=>{ li.classList.add('dragging'); e.dataTransfer.setData('text/plain', li.dataset.id); });
    li.addEventListener('dragend',()=> li.classList.remove('dragging'));
  });
  list.addEventListener('dragover', e=>{
    e.preventDefault();
    const dragging=qs('.dragging',list); if (!dragging) return;
    const siblings=qsa(':scope > li',list).filter(x=>x!==dragging);
    const y=e.clientY; let after=null;
    for (const s of siblings){ const r=s.getBoundingClientRect(); if (y - r.top - r.height/2 < 0){ after=s; break; } }
    if (!after) list.appendChild(dragging); else list.insertBefore(dragging, after);
  });
  list.addEventListener('drop', commitReorderFromUI);
}
function bindDnDManage(){
  const list=els.manageList; if (!list) return;
  qsa(':scope > li', list).forEach(li=>{
    li.setAttribute('draggable','true');
    li.addEventListener('dragstart',e=>{ li.classList.add('dragging'); e.dataTransfer.setData('text/plain', li.dataset.id); });
    li.addEventListener('dragend',()=> li.classList.remove('dragging'));
  });
  list.addEventListener('dragover', e=>{
    e.preventDefault();
    const dragging=qs('.dragging',list); if (!dragging) return;
    const siblings=qsa(':scope > li',list).filter(x=>x!==dragging);
    const y=e.clientY; let after=null;
    for (const s of siblings){ const r=s.getBoundingClientRect(); if (y - r.top - r.height/2 < 0){ after=s; break; } }
    if (!after) list.appendChild(dragging); else list.insertBefore(dragging, after);
  });
  list.addEventListener('drop', commitReorderFromUI);
}
async function commitReorderFromUI(){
  if (state.reorderLock) return;
  const order = qsa('#taskList > li').map(li=>li.dataset.id);
  const order2= qsa('#manageList > li').map(li=>li.dataset.id);
  const payload = order.length?order:order2;
  if (!payload.length) return;
  state.reorderLock=true;
  try { await apiFetch('/categories/reorder',{method:'PUT', body: JSON.stringify({ order: payload })}); }
  catch(e){ toast('Volgorde opslaan mislukt.'); }
  finally { state.reorderLock=false; }
}

/* ---------- Active warning ---------- */
function updateActiveWarning(){
  const n=Object.keys(state.active).length, el=els.activeWarning;
  if (!el) return;
  if (n<=3){ el.classList.add('hidden'); el.textContent=''; return; }
  el.classList.remove('hidden'); el.textContent=`${n} actief`;
  if (n>7){ el.style.background=state.settings.warn7; el.style.color='#fff'; }
  else if (n>5){ el.style.background=state.settings.warn5; el.style.color='#fff'; }
  else { el.style.background=state.settings.warn3; el.style.color='#111827'; }
}

/* ---------- Timers ---------- */
let tickHandle=null;
function startTick(){
  if (tickHandle) return;
  tickHandle=setInterval(()=>{
    for (const [id,t] of Object.entries(state.timers)){
      if (state.active[id]) t.textContent=hms((Date.now()-state.active[id].startMs)/1000);
    }
  },1000);
}

/* ---------- Categories ---------- */
async function loadCategories(){
  try{
    const list=await apiFetch('/categories');
    state.categories=Array.isArray(list)?list:[];
    // purge active voor verwijderde categorieën
    for (const id of Object.keys(state.active)){ if (!state.categories.find(c=>c.id===id)) delete state.active[id]; }
    renderTasks(); renderManage();
  }catch(e){ /* niet ingelogd → auth panel */ }
}
async function createCategory(p){ const r=await apiFetch('/categories',{method:'POST', body: JSON.stringify(p)}); await loadCategories(); return r && r.id; }
async function updateCategory(id,p){ await apiFetch(`/categories/${id}`,{method:'PUT', body: JSON.stringify(p)}); await loadCategories(); }
async function deleteCategory(id){ await apiFetch(`/categories/${id}`,{method:'DELETE'}); if (state.active[id]) delete state.active[id]; await loadCategories(); }

/* ---------- Timelogs ---------- */
async function createTimelog(entry){ return apiFetch('/timelogs',{method:'POST', body: JSON.stringify(entry)}); }

/* ---------- Start/Stop ---------- */
function startTask(cat){
  if (!state.user){ toast('Log eerst in om te registreren.'); showAuthPanel(); return; }
  if (state.active[cat.id]) return;
  state.active[cat.id]={ startISO: nowISO(), startMs: Date.now() };
  persistActive(); renderTasks();
}
async function stopTask(cat){
  const act=state.active[cat.id]; if (!act) return;
  const withTasks=Object.keys(state.active).filter(id=>id!==cat.id).map(id=> (state.categories.find(x=>x.id===id)?.name || 'Onbekend'));
  try{
    await createTimelog({ category_id: cat.id, start_time: act.startISO, end_time: nowISO(), with_tasks: withTasks });
  }catch{ toast('Opslaan mislukt (timelog).'); }
  delete state.active[cat.id]; persistActive(); renderTasks();
}

/* ---------- Persist active + unload guard ---------- */
function persistActive(){
  const store={}; for (const [id,v] of Object.entries(state.active)) store[id]={ startISO:v.startISO };
  setSession('activeTasks', store); updateUnloadGuard();
}
function restoreActive(){
  const m=getSession('activeTasks',{})||{};
  for (const [id,v] of Object.entries(m)){ const t=new Date(v.startISO).getTime(); state.active[id]={ startISO:v.startISO, startMs: isFinite(t)?t:Date.now() }; }
}
function updateUnloadGuard(){
  window.onbeforeunload = Object.keys(state.active).length>0 ? (e)=>{ e.preventDefault(); e.returnValue=''; return ''; } : null;
}

/* ---------- Manage ---------- */
let editingId=null;
function renderManage(){
  const ul=els.manageList; if (!ul) return; ul.innerHTML='';
  for (const c of state.categories){
    const li=document.createElement('li'); li.dataset.id=c.id;
    li.className='bg-white border border-slate-200 rounded-lg p-3 flex items-center gap-3';
    const dot=document.createElement('div'); dot.className='w-4 h-4 rounded-full'; dot.style.background=c.color||'#e2e8f0';
    const name=document.createElement('div'); name.className='flex-1'; name.textContent=c.name;
    const edit=document.createElement('button'); edit.className='btn btn-ghost'; edit.textContent='Bewerken';
    const del=document.createElement('button'); del.className='btn btn-ghost'; del.textContent='Verwijderen';
    edit.onclick=()=> openModal(c);
    del.onclick=async ()=>{ if (confirm(`Taak “${c.name}” verwijderen?`)) await deleteCategory(c.id); };
    li.append(dot,name,edit,del); ul.appendChild(li);
  }
  bindDnDManage();
}
function openModal(c=null){
  editingId=c?c.id:null;
  els.modalTitle && (els.modalTitle.textContent = c?'Taak bewerken':'Nieuwe taak');
  els.taskName && (els.taskName.value = c?c.name:'');
  els.taskColor && (els.taskColor.value = c?(c.color||randomSoftColor()):randomSoftColor());
  els.taskMin && (els.taskMin.value = c?(c.min_attention??0):0);
  els.taskMax && (els.taskMax.value = c?(c.max_attention??100):100);
  els.btnDeleteTask && els.btnDeleteTask.classList.toggle('hidden', !c);
  els.modal && els.modal.classList.remove('hidden');
}
function closeModal(){ els.modal && els.modal.classList.add('hidden'); }
async function saveModal(){
  const name=els.taskName?.value.trim(); if (!name){ toast('Naam is verplicht.'); return; }
  const color=els.taskColor?.value||randomSoftColor();
  const minA=Math.max(0,Math.min(100,parseInt(els.taskMin?.value||'0',10)));
  const maxA=Math.max(1,Math.min(100,parseInt(els.taskMax?.value||'100',10)));
  if (minA>maxA){ toast('Min mag niet groter zijn dan max.'); return; }
  try{
    if (editingId) await updateCategory(editingId,{ name,color,min_attention:minA,max_attention:maxA });
    else await createCategory({ name,color,min_attention:minA,max_attention:maxA });
    closeModal();
  }catch{ toast('Opslaan mislukt.'); }
}
async function deleteFromModal(){
  if (!editingId) return;
  if (confirm('Verwijderen?')){ await deleteCategory(editingId); closeModal(); }
}

/* ---------- Settings ---------- */
function loadSettingsUI(){ els.warn3&&(els.warn3.value=state.settings.warn3); els.warn5&&(els.warn5.value=state.settings.warn5); els.warn7&&(els.warn7.value=state.settings.warn7); }
function saveSettingsUI(){
  state.settings.warn3=els.warn3?.value||state.settings.warn3;
  state.settings.warn5=els.warn5?.value||state.settings.warn5;
  state.settings.warn7=els.warn7?.value||state.settings.warn7;
  try{ localStorage.setItem('warn3',state.settings.warn3); localStorage.setItem('warn5',state.settings.warn5); localStorage.setItem('warn7',state.settings.warn7); }catch{}
  updateActiveWarning(); toast('Instellingen opgeslagen.');
}

/* ---------- Export ---------- */
function csvCell(v){ const s=(v==null)?'':String(v); return /[",\n]/.test(s)?`"${s.replace(/"/g,'""')}"`:s; }
function safeJson(s){ try{ return JSON.parse(s); }catch{ return null; } }
async function doExport(){
  try{
    const p=new URLSearchParams();
    els.expFrom?.value && p.set('from', new Date(els.expFrom.value).toISOString());
    els.expTo?.value && p.set('to', new Date(els.expTo.value).toISOString());
    const rows=await apiFetch('/timelogs'+(p.toString()?('?'+p.toString()):''));
    const header=['ID','CategorieID','Start','Einde','Duur(seconden)','Met'];
    const lines=[header.join(',')];
    for (const r of rows){
      const withTasks = r.with_tasks ? (Array.isArray(r.with_tasks)?r.with_tasks:(safeJson(r.with_tasks)||[])) : [];
      lines.push([r.id, r.category_id, r.start_time, r.end_time||'', (r.duration||''), withTasks.join(';')].map(csvCell).join(','));
    }
    const csv=lines.join('\n'), blob=new Blob([csv],{type:'text/csv;charset=utf-8'}), url=URL.createObjectURL(blob);
    if (els.downloadLink){ els.downloadLink.href=url; els.downloadLink.download='timelogs.csv'; els.downloadLink.classList.remove('hidden'); }
    toast('Export klaar. Klik op “Download CSV”.');
  }catch{ toast('Export mislukt.'); }
}

/* ---------- Dashboard ---------- */
async function loadDashboard(){
  if (!els.dashboardRoot) return;
  try{
    const since=new Date(); since.setDate(since.getDate()-7);
    const rows=await apiFetch('/timelogs?from='+encodeURIComponent(since.toISOString()));
    renderDashboard('#dashboardRoot', rows, state.categories);
  }catch{ els.dashboardRoot.innerHTML='<div class="text-slate-500">Geen data of niet ingelogd.</div>'; }
}

/* ---------- Onboarding & URL flows ---------- */
function firstRunHelp(){
  const seen=localStorage.getItem('helpSeen');
  if (!seen && els.helpOverlay){
    els.helpOverlay.classList.remove('hidden');
    els.helpClose && els.helpClose.addEventListener('click',()=>localStorage.setItem('helpSeen','1'),{once:true});
  }
}
async function handleUrlParams(){
  const sp=new URLSearchParams(location.search); const verify=sp.get('verify'); const reset=sp.get('reset');
  if (verify){
    try{ await apiFetch('/auth/verify?token='+encodeURIComponent(verify)); toast('E‑mail geverifieerd.'); sp.delete('verify'); history.replaceState({},'',location.pathname); await refreshUser(); }
    catch{ toast('Verifiëren mislukt.'); }
  }
  if (reset){
    showAuthPanel(); els.resetBox && els.resetBox.classList.remove('hidden'); els.resetToken && (els.resetToken.value=reset);
  }
}

/* ---------- Init ---------- */
let elsBound=false;
async function init(){
  if (!elsBound){ grabEls(); bindMenu(); bindMain(); elsBound=true; }
  loadSettingsUI(); restoreActive(); updateUnloadGuard(); startTick();
  await detectApiBase();
  await refreshUser(); // gate
  await loadCategories();
  firstRunHelp();
  await handleUrlParams();
}
document.addEventListener('DOMContentLoaded', init);
