(() => {
  // =========================================================
  // CONFIG & BOOTSTRAP
  // =========================================================
  let API_BASE = localStorage.getItem('tm_api_url') || '/api';
  let FE_ADMIN_UNLOCKED = localStorage.getItem('tm_fe_admin_unlocked') === '1';

  function setApiBase(v){ API_BASE=v; localStorage.setItem('tm_api_url', v); }
  function setAdminUnlocked(v){ FE_ADMIN_UNLOCKED=!!v; localStorage.setItem('tm_fe_admin_unlocked', v?'1':'0'); }

  // PWA install prompt
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; });

  // =========================================================
  // API WRAPPER (cookie-sessies + CSRF)
  // =========================================================
  function getCookie(name){
    const m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
    return m ? decodeURIComponent(m[2]) : null;
  }

  async function apiRequest(path, method='GET', data=null) {
    const headers = { 'Content-Type': 'application/json' };
    if (method !== 'GET' && method !== 'HEAD') {
      const csrf = getCookie('tm_csrf');
      if (csrf) headers['X-CSRF-Token'] = csrf;
    }
    let url = API_BASE + path;
    let body;
    if (method === 'GET' && data) {
      const qs = new URLSearchParams(data).toString();
      if (qs) url += '?' + qs;
    } else if (data !== null) {
      body = JSON.stringify(data);
    }
    const res = await fetch(url, { method, headers, body, credentials: 'include' });
    if (!res.ok) {
      let msg = 'API error';
      try { const e = await res.json(); msg = e.error || msg; } catch {}
      throw new Error(msg);
    }
    const ct = res.headers.get('Content-Type')||'';
    return ct.includes('application/json') ? res.json() : res.text();
  }

  const api = {
    // Auth
    register: (email, pw) => apiRequest('/auth/register', 'POST', { email, password: pw }),
    login:    (email, pw) => apiRequest('/auth/login', 'POST', { email, password: pw }),
    logout:   () => apiRequest('/auth/logout', 'POST'),
    me:       () => apiRequest('/auth/me', 'GET'),
    resendVerify: () => apiRequest('/auth/resend-verify', 'POST'),
    requestReset: (email) => apiRequest('/auth/request-reset', 'POST', { email }),
    resetPass: (token,new_password) => apiRequest('/auth/reset','POST',{ token, new_password }),
    // Data
    listCategories: () => apiRequest('/categories', 'GET'),
    createCategory: (p) => apiRequest('/categories', 'POST', p),
    updateCategory: (id, p) => apiRequest('/categories/' + id, 'PUT', p),
    deleteCategory: (id) => apiRequest('/categories/' + id, 'DELETE'),
    reorderCategories: (order) => apiRequest('/categories/reorder', 'PUT', { order }),
    listTimelogs: (params) => apiRequest('/timelogs', 'GET', params),
    createTimelog: (p) => apiRequest('/timelogs', 'POST', p),
    updateTimelog: (id, p) => apiRequest('/timelogs/' + id, 'PUT', p),
    deleteTimelog: (id) => apiRequest('/timelogs/' + id, 'DELETE'),
    exportCsv: (params) => apiRequest('/export/csv', 'GET', params),
    // Front-end admin verify
    verifyFrontendAdmin: (password) => apiRequest('/admin/fe/verify','POST',{ password }),
  };

  // =========================================================
  // UTILITIES
  // =========================================================
  function hslToHex(h,s,l){ s/=100; l/=100; const c=(1-Math.abs(2*l-1))*s; const x=c*(1-Math.abs(((h/60)%2)-1)); const m=l-c/2;
    let r=0,g=0,b=0; if(h<60){r=c;g=x;} else if(h<120){r=x;g=c;} else if(h<180){g=c;b=x;} else if(h<240){g=x;b=c;}
    else if(h<300){r=x;b=c;} else {r=c;b=x;}
    const toHex=v=>Math.round((v+m)*255).toString(16).padStart(2,'0'); return '#'+toHex(r)+toHex(g)+toHex(b);
  }
  function generateSoftColor(){ const h=Math.floor(Math.random()*360); const s=60+Math.random()*20; const l=75+Math.random()*15; return hslToHex(h,s,l); }

  function distributeAttention(tasks){
    const res={}; if(!tasks || tasks.length===0) return res;
    let totalMin=0; tasks.forEach(t=>{ totalMin+=t.min_attention; res[t.id]=t.min_attention; });
    if (totalMin>=100){ tasks.forEach(t=>{ res[t.id]=(t.min_attention/totalMin)*100 }); return res; }
    let avail=100-totalMin; let rem=tasks.map(t=>t.id);
    while(avail>0 && rem.length>0){
      const share=avail/rem.length; const next=[];
      rem.forEach(id=>{ const t=tasks.find(x=>x.id===id); const room=t.max_attention-res[id];
        if(room>=share){ res[id]+=share; avail-=share; next.push(id); } else { res[id]+=room; avail-=room; }
      });
      rem=next;
    }
    const sum=Object.values(res).reduce((a,b)=>a+b,0); const diff=100-sum; const ids=Object.keys(res); if(ids[0]) res[ids[0]]+=diff;
    return res;
  }
  function fmtDuration(seconds){
    const s = Math.max(0, Math.round(seconds||0));
    const hh=String(Math.floor(s/3600)).padStart(2,'0');
    const mm=String(Math.floor((s%3600)/60)).padStart(2,'0');
    const ss=String(s%60).padStart(2,'0');
    return `${hh}:${mm}:${ss}`;
  }
  function uuid(){ return 'cl-' + Math.random().toString(16).slice(2) + Date.now().toString(16); }

  // =========================================================
  // STATE
  // =========================================================
  const state = {
    user: null,
    categories: [],
    activeLogs: {}, // {catId:{logId,startTime,withTasks,intervalId}}
    view: null,
    error: null,
    settings: { warn3:'#ffcc66', warn5:'#ff9966', warn7:'#ff6666' },
    tourSeen: localStorage.getItem('tm_tour_seen') === '1',
    _resetToken: null,
  };
  function loadSettings(){ try{ const s=localStorage.getItem('tm_settings'); if(s) Object.assign(state.settings, JSON.parse(s)); }catch{} }
  function saveSettings(){ localStorage.setItem('tm_settings', JSON.stringify(state.settings)); }

  // =========================================================
  // BACKGROUND SYNC HELPERS (client <-> SW)
  // =========================================================
  function sendCsrfToSW(){
    const csrf = getCookie('tm_csrf');
    if (navigator.serviceWorker?.controller && csrf) {
      navigator.serviceWorker.controller.postMessage({ type:'SET_CSRF', csrf });
    }
  }
  function queueTimelog(type, clientLogId, payload){
    // item: { type:'start'|'stop', clientLogId, apiBase, csrf, payload }
    const csrf = getCookie('tm_csrf');
    if (navigator.serviceWorker?.controller) {
      navigator.serviceWorker.controller.postMessage({
        type:'QUEUE_TIMELOG',
        item: { type, clientLogId, apiBase: API_BASE, csrf, payload, createdAt: Date.now() }
      });
    } else {
      // nood-fallback: bewaar tijdelijk in localStorage (wordt niet automatisch geflusht)
      const QKEY='tm_offline_q';
      const q = JSON.parse(localStorage.getItem(QKEY)||'[]');
      q.push({ type, clientLogId, apiBase: API_BASE, csrf, payload, createdAt: Date.now() });
      localStorage.setItem(QKEY, JSON.stringify(q));
    }
  }
  // Flush vragen als je weer online bent (fallback bij ontbreken SyncManager)
  window.addEventListener('online', () => {
    if (navigator.serviceWorker?.controller)
      navigator.serviceWorker.controller.postMessage({ type:'FLUSH_QUEUE' });
  });
  // Ontvang sync-resultaten (optioneel: mapping pending->serverId)
  navigator.serviceWorker?.addEventListener('message', (e)=>{
    const d = e.data || {};
    if (d.type === 'SYNC_RESULT' && d.clientLogId && d.serverId) {
      // Als de log nog actief is met pending id, vervang door echte serverId
      for (const cid of Object.keys(state.activeLogs)) {
        const entry = state.activeLogs[cid];
        if (entry?.logId === d.clientLogId) {
          entry.logId = d.serverId; // geen render nodig; puur interne mapping
        }
      }
    }
  });
  // Bij SW (her)activatie meteen CSRF delen
  navigator.serviceWorker?.addEventListener('controllerchange', sendCsrfToSW);

  // =========================================================
  // INIT
  // =========================================================
  async function init(){
    loadSettings();

    // Deep links vanuit e-mail
    const url = new URL(location.href);
    const verifyTok = url.searchParams.get('verify');
    const resetTok  = url.searchParams.get('reset');
    if(verifyTok){ await handleVerifyDeepLink(verifyTok); url.searchParams.delete('verify'); history.replaceState(null,'',url.toString()); }
    if(resetTok){ state.view='reset'; state._resetToken=resetTok; render(); return; }

    try {
      const me=await api.me();
      state.user=me.user;
      await fetchCategories();
      await resyncActiveFromServer();
      state.view='home';
    } catch {
      state.view='login';
    }
    render();
    maybeStartFirstRunTour();

    // Deel CSRF met de service worker voor background sync
    sendCsrfToSW();
  }

  async function handleVerifyDeepLink(token){
    try { await fetch(API_BASE+'/auth/verify?token='+encodeURIComponent(token), {credentials:'include'}); } catch {}
  }

  async function fetchCategories(){
    try{ state.categories=await api.listCategories(); }
    catch(e){ state.error=e.message; }
  }

  async function resyncActiveFromServer(){
    try{
      const to = new Date();
      const from = new Date(Date.now() - 48*3600*1000);
      const logs = await api.listTimelogs({ from: from.toISOString().slice(0,10), to: to.toISOString().slice(0,10) });
      (logs||[]).filter(l => !l.end_time).forEach(l => {
        if (!state.activeLogs[l.category_id]) {
          const entry = { logId: l.id, startTime: new Date(l.start_time), withTasks: JSON.parse(l.with_tasks||'[]'), intervalId: null };
          state.activeLogs[l.category_id] = entry;
          entry.intervalId = setInterval(updateActiveDurations, 1000);
        }
      });
    } catch(e){}
  }

  // =========================================================
  // TIMERS (met offline fallback + queue)
  // =========================================================
  async function startTask(id){
    if(state.activeLogs[id]) return;
    const withTasks=Object.keys(state.activeLogs);
    const payload={ category_id:id, start_time:new Date().toISOString(), with_tasks: withTasks.length? JSON.stringify(withTasks): null };

    try{
      // Probeer online create
      const resp=await api.createTimelog(payload);
      const entry={ logId:resp.id, startTime:new Date(), withTasks, intervalId:null };
      state.activeLogs[id]=entry; entry.intervalId=setInterval(updateActiveDurations,1000);
      render();
    }catch(e){
      // Offline fallback: queue + tijdelijke clientLogId
      if (navigator.onLine === false) {
        const clientLogId = uuid();
        const entry={ logId:clientLogId, startTime:new Date(), withTasks, intervalId:null };
        state.activeLogs[id]=entry; entry.intervalId=setInterval(updateActiveDurations,1000);
        queueTimelog('start', clientLogId, payload);
        render();
      } else {
        state.error=e.message; render();
      }
    }
  }

  async function stopTask(id){
    const entry=state.activeLogs[id]; if(!entry) return;
    const now=new Date(); const dur=Math.round((now-entry.startTime)/1000);
    const payload={ category_id:id, start_time: entry.startTime.toISOString(), end_time: now.toISOString(), duration: dur, with_tasks: entry.withTasks.length? JSON.stringify(entry.withTasks): null };

    // UI direct updaten; sync mag later
    clearInterval(entry.intervalId); delete state.activeLogs[id]; render();

    try{
      if (String(entry.logId).startsWith('cl-')) {
        // pending start -> queue stop; SW combineert of doet PUT na POST
        queueTimelog('stop', entry.logId, payload);
      } else {
        // bekende serverId
        await api.updateTimelog(entry.logId, payload);
      }
    }catch(e){
      if (navigator.onLine === false) {
        const clientLogId = String(entry.logId).startsWith('cl-') ? entry.logId : uuid();
        queueTimelog('stop', clientLogId, payload);
      } else {
        state.error=e.message; render();
      }
    }
  }

  function updateActiveDurations(){
    const tasks=Object.keys(state.activeLogs).map(id=>{ const c=state.categories.find(x=>x.id===id)||{min_attention:0,max_attention:100}; return {id, min_attention:c.min_attention, max_attention:c.max_attention}; });
    const attention=distributeAttention(tasks);
    Object.keys(state.activeLogs).forEach(id=>{
      const entry=state.activeLogs[id]; const elapsed=Math.round((Date.now()-entry.startTime)/1000);
      const el=document.querySelector(`[data-cat-id="${id}"]`); if(!el) return;
      const t=el.querySelector('.js-timer'); if(t) t.textContent=fmtDuration(elapsed);
      const a=el.querySelector('.js-attn'); if(a && attention[id]!=null) a.textContent=`${Math.round(attention[id])}%`;
    });
  }

  // =========================================================
  // RENDER ROOT
  // =========================================================
  function render(){
    const app=document.getElementById('app'); if(!app) return;
    app.innerHTML='';
    const err=state.error; state.error=null;

    switch(state.view){
      case 'login':     renderLogin(app); break;
      case 'reset':     renderReset(app); break;
      case 'home':      renderHome(app); break;
      case 'dashboard': renderDashboard(app); break;
      case 'tasks':     renderTaskManagement(app); break;
      case 'settings':  renderSettings(app); break;
      case 'export':    renderExport(app); break;
      case 'admin':     renderAdmin(app); break;
      case 'help':      renderHelp(app); break;
      default:          renderLogin(app);
    }
    if(err){ const d=document.createElement('div'); d.className='error'; d.textContent=err; app.prepend(d); }
    toggleFab();
  }

  // =========================================================
  // NAVIGATION (menu met 3 stippen)
  // =========================================================
  function renderNav(container,title){
    const nav=document.createElement('div'); nav.className='flex items-center mb-4';
    nav.innerHTML=`
      <h1 class="text-xl font-semibold">${title}</h1>
      <div class="ml-auto relative">
        <button id="menuBtn" class="text-2xl leading-none px-2 py-1 rounded hover:bg-slate-100" aria-label="Menu">⋮</button>
        <div id="menu" class="menu hidden">
          <a href="#" data-action="home">Registreren</a>
          <a href="#" data-action="dashboard">Dashboard</a>
          <a href="#" data-action="tasks">Taakbeheer</a>
          <a href="#" data-action="settings">Instellingen</a>
          <a href="#" data-action="export">Export</a>
          <a href="#" data-action="help">Help</a>
          ${deferredPrompt ? '<a href="#" data-action="install">Installeer app</a>' : ''}
          <a href="#" data-action="admin">Admin</a>
          <a href="#" data-action="logout" style="color:#b91c1c">Uitloggen</a>
        </div>
      </div>`;
    container.appendChild(nav);

    const menu=nav.querySelector('#menu');
    nav.querySelector('#menuBtn').addEventListener('click',e=>{ e.stopPropagation(); menu.classList.toggle('hidden'); });
    document.addEventListener('click',e=>{ if(!nav.contains(e.target)) menu.classList.add('hidden'); });

    menu.addEventListener('click', async (e)=>{
      const a=e.target.closest('a'); if(!a) return; e.preventDefault(); menu.classList.add('hidden');
      const act=a.getAttribute('data-action');
      if(act==='logout'){ try{ await api.logout(); }catch{} ; state.user=null;
        Object.values(state.activeLogs).forEach(x=>clearInterval(x.intervalId)); state.activeLogs={};
        state.view='login'; render(); return;
      }
      if(act==='install' && deferredPrompt){ deferredPrompt.prompt(); deferredPrompt=null; return; }
      state.view=(act==='home'?'home':act); render();
    });

    const nActive=Object.keys(state.activeLogs).length;
    if(nActive>=3){
      const pill=document.createElement('span');
      const color = nActive>=7 ? state.settings.warn7 : nActive>=5 ? state.settings.warn5 : state.settings.warn3;
      pill.className='ml-3 text-xs px-2 py-1 rounded-full';
      pill.style.background=color; pill.style.color='#111827';
      pill.textContent = `${nActive} actief`;
      nav.insertBefore(pill, nav.children[1]);
    }
  }

  // =========================================================
  // LOGIN / RESET
  // =========================================================
  function renderLogin(container){
    const card=document.createElement('div'); card.className='card';
    card.innerHTML=`
      <h1 class="text-2xl font-semibold mb-2">Welkom</h1>
      <div class="grid md:grid-cols-2 gap-6">
        <form id="loginForm" class="card">
          <h2 class="font-semibold mb-2">Inloggen</h2>
          <p><input type="email" id="loginEmail" class="w-full" placeholder="E‑mail" required></p>
          <p class="mt-2"><input type="password" id="loginPass" class="w-full" placeholder="Wachtwoord" required></p>
          <p class="mt-3 flex gap-3 items-center">
            <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Inloggen</button>
            <a href="#" id="forgot" class="text-sm">Wachtwoord vergeten?</a>
          </p>
        </form>
        <form id="registerForm" class="card">
          <h2 class="font-semibold mb-2">Registreren</h2>
          <p><input type="email" id="regEmail" class="w-full" placeholder="E‑mail" required></p>
          <p class="mt-2"><input type="password" id="regPass" class="w-full" placeholder="Wachtwoord (min 8)" required></p>
          <p class="mt-3"><button class="bg-slate-800 hover:bg-slate-900 text-white rounded px-4 py-2">Registreren</button></p>
        </form>
      </div>`;
    container.appendChild(card);

    card.querySelector('#loginForm').addEventListener('submit', async e=>{
      e.preventDefault();
      try {
        const r=await api.login(card.querySelector('#loginEmail').value.trim(), card.querySelector('#loginPass').value);
        state.user=r.user; await fetchCategories(); await resyncActiveFromServer(); state.view='home'; render(); maybeStartFirstRunTour();
        sendCsrfToSW(); // nieuwe sessie → token delen met SW
      } catch(err){ state.error=err.message; render(); }
    });

    card.querySelector('#registerForm').addEventListener('submit', async e=>{
      e.preventDefault();
      try {
        const r=await api.register(card.querySelector('#regEmail').value.trim(), card.querySelector('#regPass').value);
        state.user=r.user; await fetchCategories(); await resyncActiveFromServer(); state.view='home'; render(); maybeStartFirstRunTour();
        sendCsrfToSW();
      } catch(err){ state.error=err.message; render(); }
    });

    card.querySelector('#forgot').addEventListener('click', async e=>{
      e.preventDefault();
      const email=prompt('Voer je e‑mail in voor een reset‑link:'); if(!email) return;
      try{ await api.requestReset(email); alert('Als het e‑mailadres bestaat, is er een reset‑link verstuurd.'); }catch(ex){ alert(ex.message||'Fout'); }
    });
  }

  function renderReset(container){
    const card=document.createElement('div'); card.className='card';
    card.innerHTML=`
      <h2 class="font-semibold mb-2">Nieuw wachtwoord instellen</h2>
      <form id="resetForm">
        <p><input type="password" id="newPass" class="w-full" placeholder="Nieuw wachtwoord (min 8)" required></p>
        <p class="mt-2"><button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Opslaan</button></p>
      </form>`;
    container.appendChild(card);
    card.querySelector('#resetForm').addEventListener('submit', async e=>{
      e.preventDefault();
      try{ await api.resetPass(state._resetToken, card.querySelector('#newPass').value); alert('Wachtwoord gewijzigd. Log nu in.'); state.view='login'; render(); }
      catch(ex){ alert(ex.message||'Fout'); }
    });
  }

  // =========================================================
  // HOME
  // =========================================================
  function renderHome(container){
    renderNav(container,'Tijdregistratie');

    if(state.user && !state.user.verified){
      const banner=document.createElement('div'); banner.className='card';
      banner.innerHTML=`<strong>E‑mail niet geverifieerd.</strong> 
        <button class="ml-2 bg-slate-800 hover:bg-slate-900 text-white rounded px-3 py-1" id="resend">Stuur bevestiging opnieuw</button>`;
      banner.querySelector('#resend').addEventListener('click', async ()=>{
        try{ await api.resendVerify(); alert('E‑mail verzonden.'); }catch(ex){ alert(ex.message); }
      });
      container.appendChild(banner);
    }

    const card=document.createElement('div'); card.className='card';
    card.innerHTML='<h2 class="font-semibold mb-2">Taken</h2>';
    const list=document.createElement('div'); list.id='taskList';

    state.categories.forEach(cat=>{
      const row=document.createElement('div'); row.className='category-item'; row.setAttribute('data-cat-id',cat.id);
      const left=document.createElement('div'); left.className='flex items-center';
      const dot=document.createElement('span'); dot.className='category-color'; dot.style.background=cat.color||'#e5e7eb';
      const nm=document.createElement('span'); nm.textContent=cat.name; nm.className='ml-2';
      left.appendChild(dot); left.appendChild(nm); row.appendChild(left);

      const right=document.createElement('div'); right.className='flex items-center gap-2';
      const timer=document.createElement('span'); timer.className='js-timer text-sm text-slate-600';
      const att=document.createElement('span'); att.className='js-attn text-sm text-slate-600';
      if(state.activeLogs[cat.id]){ timer.textContent='00:00:00'; att.textContent='0%'; }
      const btn=document.createElement('button');
      btn.textContent=state.activeLogs[cat.id]?'Stop':'Start';
      btn.className = state.activeLogs[cat.id] ? 'bg-red-600 hover:bg-red-700 text-white rounded px-3 py-1'
                                              : 'bg-green-600 hover:bg-green-700 text-white rounded px-3 py-1';
      btn.addEventListener('click',()=>{ state.activeLogs[cat.id]?stopTask(cat.id):startTask(cat.id); });
      right.appendChild(timer); right.appendChild(att); right.appendChild(btn); row.appendChild(right);
      list.appendChild(row);
    });

    card.appendChild(list); container.appendChild(card);

    if(state.categories.length===0){
      const empty=document.createElement('div'); empty.className='card';
      empty.innerHTML = `<p class="text-slate-600">Nog geen taken. Klik op de <strong>+</strong> knop rechtsonder om je eerste taak toe te voegen.</p>`;
      container.appendChild(empty);
    }
  }

  // =========================================================
  // DASHBOARD (donut + tabellen)
  // =========================================================
  async function renderDashboard(container){
    renderNav(container,'Dashboard');

    const card=document.createElement('div'); card.className='card relative';
    card.innerHTML = `<span class="panel-close" role="button" title="Sluit paneel">×</span>
      <h2 class="font-semibold mb-2">Overzicht</h2>`;
    card.querySelector('.panel-close').addEventListener('click',()=>{ state.view='home'; render(); });

    const form=document.createElement('form'); form.className='flex flex-wrap gap-2 items-end mb-4';
    form.innerHTML = `
      <label class="mr-2">Van<br><input type="date" id="fromDate" class="border rounded px-2 py-1"></label>
      <label class="mr-2">Tot<br><input type="date" id="toDate" class="border rounded px-2 py-1"></label>
      <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Toon</button>`;
    card.appendChild(form);

    const wrap = document.createElement('div'); wrap.className='grid md:grid-cols-2 gap-4';
    const donutCard = document.createElement('div'); donutCard.className='card';
    donutCard.innerHTML = `<h3 class="font-semibold mb-2">Verdeling</h3><div class="donut-wrap"><canvas id="donut" width="320" height="320"></canvas></div>`;
    const listCard = document.createElement('div'); listCard.className='card';
    listCard.innerHTML = `<h3 class="font-semibold mb-2">Totalen</h3><div id="totals"></div><h3 class="font-semibold mt-4 mb-2">Sessies</h3><div id="logs"></div>`;
    wrap.appendChild(donutCard); wrap.appendChild(listCard); card.appendChild(wrap);

    container.appendChild(card);

    const today=new Date(); const weekAgo=new Date(today.getTime()-6*24*60*60*1000);
    form.querySelector('#fromDate').value=weekAgo.toISOString().slice(0,10);
    form.querySelector('#toDate').value=today.toISOString().slice(0,10);

    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      await loadDashboard(form.querySelector('#fromDate').value, form.querySelector('#toDate').value, donutCard.querySelector('#donut'), listCard);
    });

    await loadDashboard(form.querySelector('#fromDate').value, form.querySelector('#toDate').value, donutCard.querySelector('#donut'), listCard);
  }

  async function loadDashboard(from,to,canvas,listCard){
    const params={}; if(from) params.from=from; if(to) params.to=to;
    try{
      const logs=await api.listTimelogs(params);
      const totals={};
      logs.forEach(l=>{
        const id=l.category_id; const dur=+l.duration||0; totals[id]=(totals[id]||0)+dur;
      });
      const tWrap = listCard.querySelector('#totals'); tWrap.innerHTML='';
      const table=document.createElement('table'); table.className='w-full text-sm';
      table.innerHTML='<thead><tr><th class="text-left py-1">Taak</th><th class="text-right py-1">Duur</th></tr></thead>';
      const tb=document.createElement('tbody');
      Object.keys(totals).sort((a,b)=>totals[b]-totals[a]).forEach(id=>{
        const c=state.categories.find(x=>x.id===id); const name=c?c.name:'Onbekend';
        const s=totals[id];
        const tr=document.createElement('tr'); tr.innerHTML=`<td class="border-t py-1">${name}</td><td class="border-t py-1 text-right">${fmtDuration(s)}</td>`;
        tb.appendChild(tr);
      });
      table.appendChild(tb); tWrap.appendChild(table);

      const logsWrap = listCard.querySelector('#logs'); logsWrap.innerHTML='';
      (logs||[]).slice(0,200).forEach(l=>{
        const name = state.categories.find(c=>c.id===l.category_id)?.name || 'Onbekend';
        const line=document.createElement('div'); line.className='flex justify-between text-sm border-b py-1';
        const s=(l.start_time||'').replace('T',' ').slice(0,16);
        const e=(l.end_time||'').replace('T',' ').slice(0,16);
        line.innerHTML = `<span>${name}</span><span>${s} → ${e}</span>`;
        logsWrap.appendChild(line);
      });

      drawDonut(canvas, totals);
    }catch(e){ listCard.querySelector('#totals').textContent='Fout: '+e.message; }
  }

  function drawDonut(canvas, totals){
    const ctx = canvas.getContext('2d'); const w=canvas.width, h=canvas.height; ctx.clearRect(0,0,w,h);
    const cats = Object.keys(totals).map(id=>({ id, sec: totals[id], color: (state.categories.find(c=>c.id===id)?.color)||'#93c5fd', name: state.categories.find(c=>c.id===id)?.name||'Taak' }))
                                   .sort((a,b)=>b.sec-a.sec).slice(0,8);
    const sum = cats.reduce((a,b)=>a+b.sec,0)||1;
    let radius = Math.min(w,h)/2 - 10; const ring = 16; const gap=6; const cx=w/2, cy=h/2;
    cats.forEach((c,idx)=>{
      const frac = c.sec/sum; const end = -Math.PI/2 + frac*2*Math.PI;
      ctx.beginPath(); ctx.arc(cx,cy,radius, -Math.PI/2, end, false); ctx.lineWidth = ring; ctx.strokeStyle = c.color; ctx.lineCap='round'; ctx.stroke();
      ctx.fillStyle='#0f172a'; ctx.font='12px system-ui,Arial';
      const label = c.name.length>16? c.name.slice(0,16)+'…' : c.name;
      ctx.fillText(label, 10, 18 + idx*16);
      radius -= (ring + gap);
    });
  }

  // =========================================================
  // TAAKBEHEER (drag & drop)
  // =========================================================
  function renderTaskManagement(container){
    renderNav(container,'Taakbeheer');

    const card=document.createElement('div'); card.className='card relative';
    card.innerHTML = `<span class="panel-close" role="button" title="Sluit paneel">×</span><h2 class="font-semibold mb-2">Taken beheren</h2>`;
    card.querySelector('.panel-close').addEventListener('click',()=>{ state.view='home'; render(); });

    const list=document.createElement('div'); list.id='manageList'; list.className='border rounded p-2';
    state.categories.forEach((cat,index)=>{
      const row=document.createElement('div'); row.className='category-item'; row.draggable=true; row.setAttribute('data-index',index); row.style.cursor='grab';
      row.addEventListener('dragstart',e=>{ e.dataTransfer.setData('text/plain', index); row.style.opacity='0.5'; });
      row.addEventListener('dragend',()=>{ row.style.opacity='1'; });
      row.addEventListener('dragover',e=>e.preventDefault());
      row.addEventListener('drop',async e=>{
        e.preventDefault(); const from=parseInt(e.dataTransfer.getData('text/plain'),10); const to=parseInt(row.getAttribute('data-index'),10);
        if(isNaN(from)||isNaN(to)||from===to) return;
        const moved=state.categories.splice(from,1)[0]; state.categories.splice(to,0,moved);
        Array.from(list.children).forEach((c,idx)=>c.setAttribute('data-index',idx));
        const order=state.categories.map((c,idx)=>({id:c.id,sort_index:idx}));
        try{ await api.reorderCategories(order); }catch(e){ state.error=e.message; } render();
      });

      const left=document.createElement('div'); left.className='flex items-center';
      const dot=document.createElement('span'); dot.className='category-color'; dot.style.background=cat.color||'#e5e7eb';
      const name=document.createElement('span'); name.textContent=cat.name; name.className='ml-2';
      left.appendChild(dot); left.appendChild(name);

      const right=document.createElement('div'); right.className='flex items-center gap-2';
      const edit=document.createElement('button'); edit.textContent='Bewerk'; edit.className='bg-slate-800 hover:bg-slate-900 text-white rounded px-3 py-1';
      edit.addEventListener('click',()=>showTaskModal(cat));
      const del=document.createElement('button'); del.textContent='Verwijder'; del.className='bg-red-600 hover:bg-red-700 text-white rounded px-3 py-1';
      del.addEventListener('click',async()=>{
        if(!confirm('Taak verwijderen?')) return;
        try{ await api.deleteCategory(cat.id); state.categories=state.categories.filter(c=>c.id!==cat.id); render(); }
        catch(e){ state.error=e.message; render(); }
      });

      row.appendChild(left); row.appendChild(right); right.appendChild(edit); right.appendChild(del); list.appendChild(row);
    });

    const addBtn=document.createElement('button');
    addBtn.textContent='Nieuwe taak'; addBtn.className='bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 my-2';
    addBtn.addEventListener('click',()=>showTaskModal(null));

    card.appendChild(addBtn); card.appendChild(list); container.appendChild(card);
  }

  function showTaskModal(task){
    const overlay=document.createElement('div');
    Object.assign(overlay.style,{position:'fixed',inset:'0',background:'rgba(2,6,23,.55)',display:'flex',alignItems:'center',justifyContent:'center',zIndex:1000});
    const modal=document.createElement('div'); modal.className='card'; modal.style.maxWidth='440px'; modal.style.width='100%';
    modal.innerHTML=`<h2 class="font-semibold mb-2">${task?'Taak bewerken':'Nieuwe taak'}</h2>
    <form id="taskForm">
      <p><label>Naam<br><input id="taskName" class="w-full" required></label></p>
      <p class="mt-2 flex items-center gap-2"><label class="flex-1">Kleur<br><input id="taskColor" class="w-full" placeholder="#aabbcc"></label> 
         <button type="button" id="genColor" class="bg-slate-600 hover:bg-slate-700 text-white rounded px-3 py-2">Zachte kleur</button></p>
      <div class="grid grid-cols-2 gap-2 mt-2">
        <p><label>Min aandacht (%)<br><input id="taskMin" type="number" min="0" max="100" class="w-full" value="${task?task.min_attention:0}"></label></p>
        <p><label>Max aandacht (%)<br><input id="taskMax" type="number" min="0" max="100" class="w-full" value="${task?task.max_attention:100}"></label></p>
      </div>
      <p class="mt-3"><button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Opslaan</button>
         <button type="button" id="cancel" class="bg-slate-200 hover:bg-slate-300 text-slate-900 rounded px-4 py-2 ml-2">Annuleer</button></p>
    </form>`;
    overlay.appendChild(modal); document.body.appendChild(overlay);

    const f=modal.querySelector('#taskForm'); const name=f.querySelector('#taskName'); const color=f.querySelector('#taskColor');
    const min=f.querySelector('#taskMin'); const max=f.querySelector('#taskMax');
    if(task){ name.value=task.name; color.value=task.color||''; }
    modal.querySelector('#genColor').addEventListener('click',()=>{ color.value=generateSoftColor(); });
    modal.querySelector('#cancel').addEventListener('click',()=>document.body.removeChild(overlay));

    f.addEventListener('submit',async e=>{
      e.preventDefault();
      const p={ name:name.value.trim(), color:color.value.trim(), min_attention:parseInt(min.value||'0',10), max_attention:parseInt(max.value||'100',10) };
      if(!p.name){ alert('Naam is vereist.'); return; }
      if(p.min_attention<0||p.max_attention>100||p.min_attention>p.max_attention){ alert('Ongeldige aandachtwaarden'); return; }
      try{
        if(task) await api.updateCategory(task.id,p);
        else     await api.createCategory(p);
        await fetchCategories(); state.view='tasks'; render();
      } catch(ex){ state.error=ex.message; render(); }
      document.body.removeChild(overlay);
    });
  }

  // =========================================================
  // INSTELLINGEN
  // =========================================================
  function renderSettings(container){
    renderNav(container,'Instellingen');
    const card=document.createElement('div'); card.className='card relative';
    card.innerHTML = `<span class="panel-close" role="button" title="Sluit paneel">×</span><h2 class="font-semibold mb-2">Kleurinstellingen</h2>`;
    card.querySelector('.panel-close').addEventListener('click',()=>{ state.view='home'; render(); });

    const form=document.createElement('form'); form.className='grid md:grid-cols-3 gap-3';
    form.innerHTML = `
      <label>Waarschuwing ≥3<br><input type="color" id="warn3" class="w-full" value="${state.settings.warn3}"></label>
      <label>Waarschuwing ≥5<br><input type="color" id="warn5" class="w-full" value="${state.settings.warn5}"></label>
      <label>Waarschuwing ≥7<br><input type="color" id="warn7" class="w-full" value="${state.settings.warn7}"></label>
      <div class="md:col-span-3"><button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Opslaan</button></div>`;
    form.addEventListener('submit',e=>{
      e.preventDefault();
      state.settings.warn3=form.querySelector('#warn3').value;
      state.settings.warn5=form.querySelector('#warn5').value;
      state.settings.warn7=form.querySelector('#warn7').value;
      saveSettings(); state.view='home'; render();
    });
    card.appendChild(form); container.appendChild(card);
  }

  // =========================================================
  // EXPORT
  // =========================================================
  function renderExport(container){
    renderNav(container,'Export');
    const card=document.createElement('div'); card.className='card relative';
    card.innerHTML = `<span class="panel-close" role="button" title="Sluit paneel">×</span><h2 class="font-semibold mb-2">Exporteren</h2>`;
    card.querySelector('.panel-close').addEventListener('click',()=>{ state.view='home'; render(); });

    const form=document.createElement('form'); form.className='flex flex-wrap gap-2 items-end';
    form.innerHTML=`
      <label>Van<br><input type="date" id="expFrom" class="border rounded px-2 py-1"></label>
      <label>Tot<br><input type="date" id="expTo" class="border rounded px-2 py-1"></label>
      <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Download CSV</button>`;
    const msg=document.createElement('div'); msg.className='mt-2 text-sm text-slate-600';
    form.addEventListener('submit', async e=>{
      e.preventDefault();
      const from=form.querySelector('#expFrom').value; const to=form.querySelector('#expTo').value;
      try{
        const csv=await api.exportCsv({from,to});
        const blob=new Blob([csv],{type:'text/csv'}); const url=URL.createObjectURL(blob);
        const a=document.createElement('a'); a.href=url; a.download='export.csv'; a.click(); URL.revokeObjectURL(url);
        msg.textContent='Export voltooid.';
      }catch(ex){ msg.textContent='Fout: '+ex.message; }
    });
    card.appendChild(form); card.appendChild(msg); container.appendChild(card);
  }

  // =========================================================
  // FRONT-END ADMIN (vergrendeld)
  // =========================================================
  function renderAdmin(container){
    renderNav(container,'Front‑end admin');
    const card=document.createElement('div'); card.className='card';

    if(!FE_ADMIN_UNLOCKED){
      card.innerHTML=`<h2 class="font-semibold mb-2">Ontgrendel beheer</h2>
        <p class="text-slate-600">Voer het front‑end admin wachtwoord in (server‑side beheerd).</p>
        <div class="mt-2 flex gap-2">
          <input type="password" id="fePwd" class="border rounded px-2 py-1 flex-1" placeholder="Wachtwoord">
          <button id="unlock" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1">Ontgrendel</button>
        </div>
        <p class="text-xs text-slate-500 mt-2">Tip: valideer in <code>/admin/fe/verify</code> van de Public API.</p>`;
      card.querySelector('#unlock').addEventListener('click', async ()=>{
        const pwd=card.querySelector('#fePwd').value;
        try { await api.verifyFrontendAdmin(pwd); setAdminUnlocked(true); state.view='admin'; render(); }
        catch(e){ alert('Ongeldig wachtwoord of endpoint ontbreekt.'); }
      });
      container.appendChild(card); return;
    }

    card.innerHTML=`<h2 class="font-semibold mb-2">Instellingen</h2>
      <p class="text-slate-600 mb-2">Stel de Public API‑URL in. (Cookies & CSRF regelen beveiliging automatisch.)</p>
      <div class="grid gap-3">
        <label>Public API URL<br><input id="apiUrl" class="border rounded px-2 py-1 w-full" value="${API_BASE}"></label>
        <div class="flex gap-2">
          <button id="save" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Opslaan</button>
          <button id="lock" class="bg-slate-200 hover:bg-slate-300 text-slate-900 rounded px-4 py-2">Vergrendel</button>
          <button id="ping" class="bg-slate-800 hover:bg-slate-900 text-white rounded px-4 py-2">Test verbinding</button>
        </div>
      </div>`;
    card.querySelector('#save').addEventListener('click',()=>{
      const url = card.querySelector('#apiUrl').value.trim();
      if(!url){ alert('Vul de URL in.'); return; }
      setApiBase(url); alert('Instellingen opgeslagen'); 
    });
    card.querySelector('#lock').addEventListener('click',()=>{ setAdminUnlocked(false); state.view='home'; render(); });
    card.querySelector('#ping').addEventListener('click', async ()=>{
      try{ await api.me(); alert('Verbinding OK.'); } catch(e){ alert('Mislukt: '+(e.message||'onbekend')); }
    });
    container.appendChild(card);
  }

  // =========================================================
  // HELP & DEMO-TOUR
  // =========================================================
  function renderHelp(container){
    renderNav(container,'Help');
    const card=document.createElement('div'); card.className='card';
    card.innerHTML = `
      <h2 class="font-semibold mb-2">Snel aan de slag</h2>
      <ol class="list-decimal ml-6 space-y-1 text-slate-700">
        <li><strong>Admin koppeling</strong>: menu ▸ Admin (stel Public API‑URL in).</li>
        <li><strong>Account</strong>: registreer of log in.</li>
        <li><strong>Taken</strong>: maak taken aan (menu ▸ Taakbeheer of + knop).</li>
        <li><strong>Timer</strong>: start/stop via het hoofdscherm; meerdere tegelijk mag.</li>
        <li><strong>Dashboard</strong>: bekijk totalen + donutgrafiek per periode.</li>
        <li><strong>Export</strong>: download CSV (incl. kolom “Met” voor simultane taken).</li>
      </ol>

      <h3 class="font-semibold mt-4">Tips</h3>
      <ul class="list-disc ml-6 text-slate-700 space-y-1">
        <li>Waarschuwingen bij ≥3/5/7 gelijktijdige timers (kleuren in Instellingen).</li>
        <li>Drag‑&‑drop voor de volgorde (Taakbeheer).</li>
        <li>Installeren als app via menu (indien mogelijk).</li>
      </ul>

      <div class="mt-4 flex gap-2">
        <button id="startTour" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Start demo‑tour</button>
        <button id="goAdmin" class="bg-slate-800 hover:bg-slate-900 text-white rounded px-4 py-2">Naar Admin</button>
      </div>
    `;
    card.querySelector('#startTour').addEventListener('click', ()=> startTour(true));
    card.querySelector('#goAdmin').addEventListener('click', ()=>{ state.view='admin'; render(); });
    container.appendChild(card);
  }

  const tour = {
    active:false, i:0, steps: [
      { q:'#menuBtn', title:'Menu', text:'Open het menu met de drie stippen. Hier vind je Dashboard, Taakbeheer, Instellingen, Export, Help en Admin.' },
      { q:'#fab', title:'Snel taak toevoegen', text:'De + knop verschijnt op het hoofdscherm en bij Taakbeheer om snel taken aan te maken.' , ensure:'home' },
      { q:'#taskList .category-item:nth-child(1) button', title:'Start/stop', text:'Start of stop een timer per taak. Meerdere timers kunnen tegelijk lopen.' , ensure:'home' },
      { q:'form #fromDate', title:'Dashboard filter', text:'Filter je overzicht per datum. De donutgrafiek en totalen passen zich aan.' , ensure:'dashboard' },
      { q:'form #expFrom', title:'Export', text:'Exporteer timelogs als CSV (incl. kolom “Met” voor simultane taken).' , ensure:'export' },
      { q:null, title:'Klaar!', text:'Je bent er klaar voor. Veel succes! Je kunt de tour later opnieuw starten via Help.' }
    ]
  };

  function maybeStartFirstRunTour(){
    if (!state.tourSeen && state.user) {
      if ((state.categories||[]).length===0) startTour(false);
    }
  }

  function startTour(force){
    tour.active=true; tour.i=0;
    document.body.appendChild(createTourOverlay());
    goStep(force);
  }

  function goStep(force){
    const st = tour.steps[tour.i];
    if (st.ensure && state.view !== st.ensure) { state.view = st.ensure; render(); }
    positionTour(st, force);
  }

  function createTourOverlay(){
    let el = document.querySelector('.tour-overlay');
    if (el) return el;
    el = document.createElement('div'); el.className='tour-overlay'; el.style.display='block';
    const spot = document.createElement('div'); spot.className='tour-spotlight';
    const box = document.createElement('div'); box.className='tour-box';
    box.innerHTML = `
      <h3 class="font-semibold mb-1"></h3>
      <div class="text-sm text-slate-700"></div>
      <div class="tour-actions">
        <button class="bg-slate-200 px-3 py-1 rounded" data-act="prev">Vorige</button>
        <button class="bg-slate-800 text-white px-3 py-1 rounded" data-act="next">Volgende</button>
        <button class="bg-red-600 text-white px-3 py-1 rounded" data-act="end">Einde</button>
      </div>`;
    el.appendChild(spot); el.appendChild(box);
    el.addEventListener('click', (e)=>{ if(e.target===el) endTour(); });
    box.querySelector('[data-act="prev"]').addEventListener('click', (e)=>{ e.preventDefault(); tour.i=Math.max(0,tour.i-1); goStep(); });
    box.querySelector('[data-act="next"]').addEventListener('click', (e)=>{ e.preventDefault(); tour.i=Math.min(tour.steps.length-1,tour.i+1); goStep(); });
    box.querySelector('[data-act="end"]').addEventListener('click', (e)=>{ e.preventDefault(); endTour(); });
    return el;
  }

  function positionTour(step, force){
    const overlay=document.querySelector('.tour-overlay');
    const spot=overlay.querySelector('.tour-spotlight');
    const box=overlay.querySelector('.tour-box');
    overlay.style.display='block';

    box.querySelector('h3').textContent = step.title;
    box.querySelector('div').textContent = step.text;

    const target = step.q ? document.querySelector(step.q) : null;
    if (!target) {
      spot.style.display='none';
      box.style.left = '50%'; box.style.top='50%'; box.style.transform='translate(-50%,-50%)';
      return;
    }
    const rect = target.getBoundingClientRect();
    const pad=8;
    spot.style.display='block';
    spot.style.left = (rect.left - pad) + 'px';
    spot.style.top = (rect.top - pad + window.scrollY) + 'px';
    spot.style.width = (rect.width + pad*2) + 'px';
    spot.style.height = (rect.height + pad*2) + 'px';

    const topSpace = rect.top + window.scrollY - 140;
    const bottomSpace = (window.scrollY + window.innerHeight) - (rect.bottom + window.scrollY) - 140;
    if (bottomSpace > topSpace) {
      box.style.left = (rect.left) + 'px';
      box.style.top = (rect.bottom + window.scrollY + 12) + 'px';
      box.style.transform = 'none';
    } else {
      box.style.left = (rect.left) + 'px';
      box.style.top = (rect.top + window.scrollY - 180) + 'px';
      box.style.transform = 'none';
    }
    if (!force) target.scrollIntoView({ behavior:'smooth', block:'center' });
  }

  function endTour(){
    const ov=document.querySelector('.tour-overlay'); if(ov) ov.remove();
    tour.active=false; state.tourSeen=true; localStorage.setItem('tm_tour_seen','1');
  }

  function toggleFab(){
    const fab = document.getElementById('fab');
    if (!fab) return;
    if (state.view==='home' || state.view==='tasks') {
      fab.classList.remove('hidden'); fab.onclick = ()=> showTaskModal(null);
    } else fab.classList.add('hidden');
  }

  // =========================================================
  // START
  // =========================================================
  init();
})();
