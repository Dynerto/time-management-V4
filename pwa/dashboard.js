/* Dashboard rendering – totalen + laatste sessies
 * Export: renderDashboard(containerSelector, timelogs, categories)
 */
function renderDashboard(sel, rows, cats){
  const root = document.querySelector(sel);
  if (!root) return;
  const byCat = new Map();
  const catName = id => (cats.find(c=>c.id===id)?.name || 'Onbekend');

  let totalSec = 0;
  for (const r of rows){
    const s = (r.duration != null) ? r.duration :
              (r.end_time ? Math.max(0,(new Date(r.end_time)-new Date(r.start_time))/1000) : 0);
    totalSec += s;
    byCat.set(r.category_id, (byCat.get(r.category_id)||0) + s);
  }
  const top = [...byCat.entries()].sort((a,b)=>b[1]-a[1]).slice(0,6);

  const fmt2=n=>n<10?'0'+n:''+n;
  const hms=sec=>{ sec=Math.floor(sec); const h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60), s=sec%60; return `${fmt2(h)}:${fmt2(m)}:${fmt2(s)}`; };

  const last = rows.slice(0,20);

  root.innerHTML = `
    <div class="grid md:grid-cols-3 gap-4">
      <div class="p-4 bg-white border border-slate-200 rounded">
        <div class="text-sm text-slate-500">Totaal (periode)</div>
        <div class="text-2xl font-semibold">${hms(totalSec)}</div>
      </div>
      <div class="p-4 bg-white border border-slate-200 rounded">
        <div class="text-sm text-slate-500">Aantal sessies</div>
        <div class="text-2xl font-semibold">${rows.length}</div>
      </div>
      <div class="p-4 bg-white border border-slate-200 rounded">
        <div class="text-sm text-slate-500">Top taak</div>
        <div class="text-lg font-medium">${top[0] ? `${escapeHtml(catName(top[0][0]))} – ${hms(top[0][1])}` : '—'}</div>
      </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4 mt-4">
      <div class="p-4 bg-white border border-slate-200 rounded">
        <div class="mb-2 font-semibold">Verdeling per taak</div>
        <ul class="space-y-1">
          ${top.map(([id,sec])=>`
            <li class="flex items-center justify-between">
              <span>${escapeHtml(catName(id))}</span>
              <span class="font-mono">${hms(sec)}</span>
            </li>
          `).join('')}
        </ul>
      </div>

      <div class="p-4 bg-white border border-slate-200 rounded">
        <div class="mb-2 font-semibold">Laatste sessies</div>
        <div class="overflow-auto max-h-80">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500">
              <th class="py-1 pr-2">Taak</th>
              <th class="py-1 pr-2">Start</th>
              <th class="py-1 pr-2">Einde</th>
              <th class="py-1 pr-2 text-right">Duur</th>
            </tr></thead>
            <tbody>
              ${last.map(r=>{
                const s = r.start_time ? new Date(r.start_time) : null;
                const e = r.end_time   ? new Date(r.end_time)   : null;
                const dur = (r.duration != null) ? r.duration : (s && e ? Math.max(0,(e-s)/1000) : 0);
                return `
                <tr class="border-t border-slate-200">
                  <td class="py-1 pr-2">${escapeHtml(catName(r.category_id))}</td>
                  <td class="py-1 pr-2">${s ? s.toLocaleString() : '—'}</td>
                  <td class="py-1 pr-2">${e ? e.toLocaleString() : '—'}</td>
                  <td class="py-1 pl-2 text-right font-mono">${hms(dur)}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `;
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
