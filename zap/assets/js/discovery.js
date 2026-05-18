// ── Helpers (self-contained for discovery page) ───────────────────────────────
function escapeHtml(v) {
  if (v == null) return '';
  return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escapeAttr(v) {
  if (v == null) return '';
  return String(v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function formatNum(v) {
  const n = parseInt(v);
  return isNaN(n) ? '–' : n.toLocaleString('de-DE');
}
function truncate(str, max) {
  if (!str) return '';
  return str.length > max ? str.slice(0, max) + '…' : str;
}
function fmtDate(iso) {
  if (!iso || iso === '–') return '–';
  try {
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return iso;
    return d.toLocaleDateString('de-DE', { day:'2-digit', month:'2-digit', year:'2-digit' });
  } catch(e) { return iso; }
}
function fmtDateDE(iso) {
  // "2026-05-18" → "18.05."
  if (!iso) return '?';
  const [y, m, d] = iso.split('-');
  return `${d}.${m}.`;
}

// ── Discovery Dashboard JS ─────────────────────────────────────────────────────
const discApiBase = '/dashboards/zap/api/discovery.php';

let discCurrentView   = 'cumulative';
let discSelectedDate  = '';
let discCurrentOffset = 0;
let discCurrentTotal  = 0;
let discCurrentLimit  = 500;
let discDatesCache    = null;

// ── View Switch ───────────────────────────────────────────────────────────────
function switchView(view) {
  discCurrentView   = view;
  discCurrentOffset = 0;

  document.getElementById('tabCumulative')?.classList.toggle('is-active', view === 'cumulative');
  document.getElementById('tabDaily')?.classList.toggle('is-active', view === 'daily');

  const tlWrap = document.getElementById('timelineWrap');
  if (tlWrap) tlWrap.style.display = view === 'daily' ? '' : 'none';

  const dateCol = document.getElementById('dateColHeader');
  if (dateCol) dateCol.textContent = view === 'cumulative' ? 'Zuletzt geprüft' : 'Snapshot';

  if (view === 'daily') {
    if (!discDatesCache) {
      loadTimeline().then(() => loadDiscovery());
    } else {
      renderTimeline(discDatesCache);
      loadDiscovery();
    }
  } else {
    loadDiscovery();
  }
}

// ── Timeline ──────────────────────────────────────────────────────────────────
async function loadTimeline(forceReload) {
  if (discDatesCache && !forceReload) {
    renderTimeline(discDatesCache);
    return discDatesCache;
  }
  try {
    const res = await fetch(discApiBase + '?mode=dates&_t=' + Date.now(), { cache: 'no-store' });
    const d   = await res.json();
    if (!d.success) return;
    discDatesCache = d;
    renderTimeline(d);
    return d;
  } catch(e) {
    console.error('Timeline load error:', e);
  }
}

function renderTimeline(data) {
  const strip = document.getElementById('timelineStrip');
  if (!strip) return;

  const dates = data.dates || [];
  if (!dates.length) {
    strip.innerHTML = '<div class="step-empty" style="padding:12px 20px;">Keine Snapshots vorhanden.</div>';
    return;
  }

  const maxKw = Math.max(...dates.map(d => parseInt(d.keywords) || 0), 1);

  strip.innerHTML = dates.map(row => {
    const dt    = row.snapshot_date || '';
    const kw    = parseInt(row.keywords) || 0;
    const top10 = parseInt(row.top10)    || 0;
    const pct   = Math.round((kw / maxKw) * 100);
    const sel   = dt === discSelectedDate;

    return `<div class="timeline-pill ${sel ? 'is-selected' : ''}" onclick="selectDate('${dt}')" data-date="${dt}">
      <div class="tl-date">${fmtDateDE(dt)}</div>
      <div class="tl-count">${formatNum(kw)} KW</div>
      <div class="tl-count">${formatNum(top10)} Top10</div>
      <div class="tl-bar"><div class="tl-bar-fill" style="width:${pct}%"></div></div>
    </div>`;
  }).join('');
}

function selectDate(date) {
  discSelectedDate  = date;
  discCurrentOffset = 0;
  document.querySelectorAll('.timeline-pill').forEach(p =>
    p.classList.toggle('is-selected', p.getAttribute('data-date') === date)
  );
  loadDiscovery();
}

// ── Stats Chips ───────────────────────────────────────────────────────────────
function renderStatChips({ total, top3, top10, label, date }) {
  const el = document.getElementById('discStats');
  if (!el) return;
  const lbl = date ? 'Snapshot ' + fmtDate(date) : (label || 'Portfolio');
  el.innerHTML = `
    <div class="disc-chip"><strong>${formatNum(total)}</strong><span>${escapeHtml(lbl)}</span></div>
    <div class="disc-chip"><strong>${formatNum(top3)}</strong><span>Top 3</span></div>
    <div class="disc-chip"><strong>${formatNum(top10)}</strong><span>Top 10</span></div>
  `;
}

// ── Load Data ─────────────────────────────────────────────────────────────────
async function loadDiscovery() {
  const keyword   = (document.getElementById('discKeyword')?.value  || '').trim();
  const urlFilter = (document.getElementById('discUrl')?.value      || '').trim();
  const pos       = document.getElementById('discPos')?.value       || '';
  discCurrentLimit = parseInt(document.getElementById('discLimit')?.value || '500');

  const body = document.getElementById('discBody');
  const info = document.getElementById('discTableInfo');
  if (body) body.innerHTML = '<tr class="loading-row"><td colspan="9"><div class="spinner"></div></td></tr>';
  if (info) info.textContent = 'Laden…';

  try {
    let url = discApiBase + '?mode=' + discCurrentView
            + '&limit=' + discCurrentLimit
            + '&offset=' + discCurrentOffset
            + '&_t=' + Date.now();
    if (keyword)   url += '&keyword='   + encodeURIComponent(keyword);
    if (urlFilter) url += '&url='       + encodeURIComponent(urlFilter);
    if (pos)       url += '&pos='       + encodeURIComponent(pos);
    if (discCurrentView === 'daily' && discSelectedDate)
      url += '&date=' + encodeURIComponent(discSelectedDate);

    const res = await fetch(url, { cache: 'no-store' });
    const d   = await res.json();
    if (!d.success) throw new Error(d.error || 'API-Fehler');

    discCurrentTotal = d.total || 0;
    const rows = d.rows || [];

    // Stats
    if (discCurrentView === 'daily' && d.stats) {
      renderStatChips({ total: d.stats.total, top3: d.stats.top3, top10: d.stats.top10, date: d.date });
    } else if (discCurrentView === 'cumulative') {
      const cum = discDatesCache?.cumulative || {};
      renderStatChips({ total: d.total, top3: cum.top3, top10: cum.top10, label: 'Keywords gesamt' });
    }

    renderTable(rows, discCurrentOffset);
    updatePagination();

    const shown = Math.min(discCurrentOffset + discCurrentLimit, discCurrentTotal);
    if (info) info.textContent = shown.toLocaleString('de-DE') + ' von ' + discCurrentTotal.toLocaleString('de-DE') + ' Keywords';

    const refresh = document.getElementById('discRefreshText');
    if (refresh) refresh.textContent = 'Aktualisiert: ' + new Date().toLocaleTimeString('de-DE');

  } catch(e) {
    if (body) body.innerHTML = '<tr><td colspan="9"><div class="step-empty">Fehler: ' + escapeHtml(e.message) + '</div></td></tr>';
    if (info) info.textContent = 'Fehler beim Laden';
    console.error('Discovery load error:', e);
  }
}

// ── Table Rendering ───────────────────────────────────────────────────────────
function renderTable(rows, offset) {
  const body = document.getElementById('discBody');
  if (!body) return;
  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="9"><div class="step-empty">Keine Daten gefunden.</div></td></tr>';
    return;
  }

  body.innerHTML = rows.map((row, i) => {
    const pos  = row.position          != null ? row.position          : '–';
    const prev = row.previous_position != null ? row.previous_position : '–';
    const vol  = row.search_volume     != null ? formatNum(row.search_volume) : '–';
    const cpc  = row.cpc              != null ? '€' + parseFloat(row.cpc).toFixed(2) : '–';
    const loc  = row.location_code    || '–';
    const dt   = row.last_checked || row.updated_at || row.snapshot_date || '–';

    const url     = row.url || '';
    const urlDisp = url
      ? `<a href="${escapeAttr(url)}" target="_blank" class="step-link" title="${escapeAttr(url)}">${escapeHtml(truncate(url, 52))}</a>`
      : '–';

    // Delta badge
    let badge = '';
    if (row.position != null && row.previous_position != null) {
      const delta = row.previous_position - row.position;
      if (delta > 0) badge = ` <span class="step-badge ok"   style="font-size:.68rem;padding:1px 5px;">+${delta}</span>`;
      if (delta < 0) badge = ` <span class="step-badge error" style="font-size:.68rem;padding:1px 5px;">${delta}</span>`;
    }

    const posStyle = pos === '–'     ? 'color:var(--zap-text-muted)'
                   : pos <= 3       ? 'color:#18e888;font-weight:700'
                   : pos <= 10      ? 'color:#7dd3fc;font-weight:700'
                   : pos <= 20      ? 'color:#fbbf24'
                   :                  'color:var(--zap-text-muted)';

    return `<tr>
      <td style="color:var(--zap-text-muted);font-size:.76rem;">${offset + i + 1}</td>
      <td style="max-width:240px;white-space:normal;word-break:break-word;">${escapeHtml(row.keyword || '')}</td>
      <td style="max-width:280px;">${urlDisp}</td>
      <td style="${posStyle}">${pos}${badge}</td>
      <td style="color:var(--zap-text-muted);">${prev}</td>
      <td>${vol}</td>
      <td>${cpc}</td>
      <td style="color:var(--zap-text-muted);font-size:.76rem;">${loc}</td>
      <td style="color:var(--zap-text-muted);font-size:.76rem;">${fmtDate(dt)}</td>
    </tr>`;
  }).join('');
}

// ── Pagination ────────────────────────────────────────────────────────────────
function updatePagination() {
  const info  = document.getElementById('discPaginationInfo');
  const prev  = document.getElementById('discPrevBtn');
  const next  = document.getElementById('discNextBtn');
  const pages = Math.ceil(discCurrentTotal / discCurrentLimit);
  const page  = Math.floor(discCurrentOffset / discCurrentLimit) + 1;

  if (info) info.textContent = pages > 1 ? `Seite ${page} / ${pages}` : '';
  if (prev) prev.style.display = discCurrentOffset > 0 ? '' : 'none';
  if (next) next.style.display = (discCurrentOffset + discCurrentLimit) < discCurrentTotal ? '' : 'none';
}

function discPage(dir) {
  discCurrentOffset = Math.max(0, discCurrentOffset + dir * discCurrentLimit);
  loadDiscovery();
}

// ── Debounced search ──────────────────────────────────────────────────────────
let discSearchTimer = null;
function discSearchDebounce() {
  clearTimeout(discSearchTimer);
  discSearchTimer = setTimeout(() => { discCurrentOffset = 0; loadDiscovery(); }, 450);
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  ['discKeyword', 'discUrl'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', discSearchDebounce);
  });
  ['discPos', 'discLimit'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { discCurrentOffset = 0; loadDiscovery(); });
  });

  // Load dates first (for cumulative stats), then load the default view
  loadTimeline().then(data => {
    if (data?.cumulative) {
      renderStatChips({
        total: data.cumulative.total,
        top3:  data.cumulative.top3,
        top10: data.cumulative.top10,
        label: 'Keywords gesamt',
      });
    }
    loadDiscovery();
  });
});
