// auth.js - premium auth gate + API setup + UX
(() => {
  const qs  = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => Array.from(r.querySelectorAll(s));

  const els = {
    appContent: qs('#appContent'),
    authOverlay: qs('#authOverlay'),
    authTitle: qs('#authTitle'),
    authAlert: qs('#authAlert'),
    loginForm: qs('#loginForm'),
    registerForm: qs('#registerForm'),
    resetForm: qs('#resetForm'),
    linkForgot: qs('#linkForgot'),
    linkRegister: qs('#linkRegister'),
    linkLogin1: qs('#linkLogin1'),
    linkLogin2: qs('#linkLogin2'),
    logoutBtn: qs('#logoutBtn'),
    helpBtn: qs('#helpBtn'),
    apiSetupBtn: qs('#apiSetupBtn'),
    apiSetupOverlay: qs('#apiSetupOverlay'),
    apiSetupClose: qs('#apiSetupClose'),
    apiSetupAlert: qs('#apiSetupAlert'),
    apiBaseInput: qs('#apiBaseInput'),
    apiBaseSave: qs('#apiBaseSave'),
    apiBaseTest: qs('#apiBaseTest'),
  };

  const store = {
    get apiBase() { return localStorage.getItem('tm_public_api_base') || ''; },
    set apiBase(v) { localStorage.setItem('tm_public_api_base', v || ''); },
  };

  function show(el) { el.classList.remove('hidden'); }
  function hide(el) { el.classList.add('hidden'); }
  function alertBox(el, kind, msg) {
    const styles = {
      info:  'border-sky-300 bg-sky-50 text-sky-900',
      bad:   'border-rose-300 bg-rose-50 text-rose-900',
      good:  'border-emerald-300 bg-emerald-50 text-emerald-900',
      warn:  'border-amber-300 bg-amber-50 text-amber-900',
    };
    el.className = `mb-4 text-sm rounded border p-3 ${styles[kind] || styles.info}`;
    el.textContent = msg;
    show(el);
  }
  function clearAlert(el){ hide(el); el.textContent=''; }

  function needApiBase() {
    return !store.apiBase || !/^https?:\/\/[^ ]+/.test(store.apiBase);
  }

  async function apiFetch(path, opts={}) {
    if (needApiBase()) throw new Error('Public API basis‑URL ontbreekt. Stel deze in via het tandwiel.');
    const url = store.apiBase.replace(/\/+$/,'') + '/' + path.replace(/^\/+/,'');
    const res = await fetch(url, {
      method: opts.method || 'GET',
      headers: Object.assign({'Content-Type':'application/json'}, opts.headers || {}),
      credentials: 'include', // stuur cookies mee!
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let data;
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      data = await res.json();
    } else {
      // Bescherm tegen HTML 404 van hosting -> toon nette fout
      const text = await res.text();
      throw new Error(`Onverwachte respons (${res.status}) van Public API: ${text.slice(0,200)}...`);
    }
    if (!res.ok || data.ok === false) {
      const msg = (data && (data.message || data.error)) ? (data.message || data.error) : `HTTP ${res.status}`;
      const err = new Error(msg);
      err.payload = data;
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function switchPanel(which) {
    clearAlert(els.authAlert);
    hide(els.loginForm); hide(els.registerForm); hide(els.resetForm);
    if (which === 'login') { els.authTitle.textContent='Inloggen'; show(els.loginForm); }
    if (which === 'register') { els.authTitle.textContent='Account aanmaken'; show(els.registerForm); }
    if (which === 'reset') { els.authTitle.textContent='Wachtwoord resetten'; show(els.resetForm); }
  }

  async function checkSession() {
    try {
      const res = await apiFetch('auth/session', { method:'GET' });
      return res && res.user ? res.user : null;
    } catch {
      return null;
    }
  }

  async function showApp() {
    hide(els.authOverlay);
    show(els.appContent);
    els.logoutBtn.classList.remove('hidden');

    // Belangrijk: jouw bestaande UI init kan hier getriggerd worden als die een "app ready" event nodig heeft.
    document.dispatchEvent(new CustomEvent('app:auth-ready', {detail:{loggedIn:true}}));
  }

  async function showLogin() {
    show(els.authOverlay);
    hide(els.appContent);
    els.logoutBtn.classList.add('hidden');
    switchPanel('login');
  }

  async function doLogin(form) {
    const body = {
      email: form.email.value.trim(),
      password: form.password.value,
    };
    try {
      clearAlert(els.authAlert);
      const res = await apiFetch('auth/login', { method:'POST', body });
      // Public API zet HttpOnly cookie. Daarna sessie opnieuw opvragen:
      const user = await checkSession();
      if (user) await showApp();
      else alertBox(els.authAlert, 'bad', 'Inloggen lijkt te zijn gelukt maar sessie is niet bevestigd.');
    } catch (e) {
      alertBox(els.authAlert, 'bad', e.message || 'Inloggen mislukt.');
    }
  }

  async function doRegister(form) {
    const body = {
      name: form.name.value.trim(),
      email: form.email.value.trim(),
      password: form.password.value,
    };
    try {
      clearAlert(els.authAlert);
      await apiFetch('auth/register', { method:'POST', body });
      alertBox(els.authAlert, 'good', 'Account aangemaakt. Controleer je e‑mail om te bevestigen en log daarna in.');
      switchPanel('login');
    } catch (e) {
      alertBox(els.authAlert, 'bad', e.message || 'Registratie mislukt.');
    }
  }

  async function doReset(form) {
    const body = { email: form.email.value.trim() };
    try {
      clearAlert(els.authAlert);
      await apiFetch('auth/request-reset', { method:'POST', body });
      alertBox(els.authAlert, 'good', 'Als dit e‑mailadres bekend is, sturen we een resetlink.');
      switchPanel('login');
    } catch (e) {
      alertBox(els.authAlert, 'bad', e.message || 'Verzenden mislukt.');
    }
  }

  async function doLogout() {
    try { await apiFetch('auth/logout', { method:'POST' }); } catch {}
    await showLogin();
  }

  // API setup modal
  function openApiSetup() {
    clearAlert(els.apiSetupAlert);
    els.apiBaseInput.value = store.apiBase || '';
    els.apiSetupOverlay.classList.remove('hidden');
    els.apiSetupOverlay.classList.add('flex');
  }
  function closeApiSetup() {
    els.apiSetupOverlay.classList.add('hidden');
    els.apiSetupOverlay.classList.remove('flex');
  }

  async function testApiBase() {
    clearAlert(els.apiSetupAlert);
    const base = (els.apiBaseInput.value || '').trim();
    if (!/^https?:\/\/.+/.test(base)) {
      alertBox(els.apiSetupAlert, 'bad', 'Voer een geldige URL in (bijv. https://time.dynerto.com/api).');
      return;
    }
    try {
      const res = await fetch(base.replace(/\/+$/,'') + '/health', { method:'GET' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (data && data.service === 'public-api') {
        alertBox(els.apiSetupAlert, 'good', 'Verbinding OK met Public API.');
      } else {
        alertBox(els.apiSetupAlert, 'warn', 'Public API antwoordt, maar health payload is onverwacht.');
      }
    } catch (e) {
      alertBox(els.apiSetupAlert, 'bad', 'Verbinding mislukt: ' + (e.message || 'onbekende fout'));
    }
  }

  async function saveApiBase() {
    const base = (els.apiBaseInput.value || '').trim().replace(/\/+$/,'');
    if (!/^https?:\/\/.+/.test(base)) {
      alertBox(els.apiSetupAlert, 'bad', 'Voer een geldige URL in (bijv. https://time.dynerto.com/api).');
      return;
    }
    store.apiBase = base;
    alertBox(els.apiSetupAlert, 'good', 'Opgeslagen. Je kunt nu inloggen.');
  }

  // Bindings
  document.addEventListener('DOMContentLoaded', async () => {
    // Links tussen formulieren
    els.linkForgot.onclick  = () => switchPanel('reset');
    els.linkRegister.onclick= () => switchPanel('register');
    els.linkLogin1.onclick  = () => switchPanel('login');
    els.linkLogin2.onclick  = () => switchPanel('login');
    els.logoutBtn.onclick   = () => doLogout();

    // Forms
    els.loginForm?.addEventListener('submit', (e)=>{ e.preventDefault(); doLogin(e.target); });
    els.registerForm?.addEventListener('submit', (e)=>{ e.preventDefault(); doRegister(e.target); });
    els.resetForm?.addEventListener('submit', (e)=>{ e.preventDefault(); doReset(e.target); });

    // API Setup
    els.apiSetupBtn.onclick   = openApiSetup;
    els.apiSetupClose.onclick = closeApiSetup;
    els.apiBaseTest.onclick   = testApiBase;
    els.apiBaseSave.onclick   = saveApiBase;

    // Startstate
    if (needApiBase()) {
      openApiSetup();
      await showLogin();
      return;
    }
    const user = await checkSession();
    if (user) await showApp();
    else await showLogin();
  });
})();
