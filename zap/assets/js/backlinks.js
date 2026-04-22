let allRows = [];
let filteredRows = [];
let projectInsights = [];
let currentPage = 1;
const perPage = 25;
let sortCol = 10;
let sortDir = 'desc';
let deleteId = null;
let pendingToolbarStatusSelection = null;

/** Reihenfolge wie API (Referring-Domain DR) */
const BL_DR_ORDER = ['0–10', '11–20', '21–30', '31–40', '41–50', '51–60', '61–70', '71–80', '81–90', '91–100', 'k.A.'];

function deriveHttpOptionsFromRows(rows) {
    const nums = new Set();
    let hasEmpty = false;
    (rows || []).forEach((r) => {
        const h = r.http_status;
        if (h === null || h === undefined || h === '') hasEmpty = true;
        else nums.add(Number(h));
    });
    const sorted = [...nums].sort((a, b) => a - b);
    const out = [];
    if (hasEmpty) out.push(null);
    sorted.forEach((n) => out.push(n));
    return out;
}

function populateHttpFilter(options) {
    const root = document.getElementById('httpFilterRoot');
    if (!root) return;
    const prevState = getHttpFilterState();
    const normalized = (options || []).map((v) => (v === null || v === undefined ? '_none' : String(Number(v))));
    const selected = prevState?.mode === 'some' ? prevState.values : new Set(normalized);

    root.innerHTML = normalized.map((value) => {
        const label = value === '_none' ? '— / kein Status' : value;
        const checked = selected.has(value) ? ' checked' : '';
        return `<label class="bl-status-pill">
            <input type="checkbox" class="http-filter-cb" value="${esc(value)}"${checked}>
            <span>${esc(label)}</span>
        </label>`;
    }).join('') || '<span style="font-size:0.72rem;color:var(--zap-text-muted);">Keine HTTP-Status</span>';

    root.querySelectorAll('.http-filter-cb').forEach((cb) => cb.addEventListener('change', filterTable));
}

function getHttpFilterState() {
    const cbs = document.querySelectorAll('#httpFilterRoot .http-filter-cb');
    if (!cbs.length) return null;
    const checked = [...cbs].filter((cb) => cb.checked).map((cb) => cb.value);
    if (checked.length === 0) return { mode: 'none' };
    if (checked.length === cbs.length) return { mode: 'all' };
    return { mode: 'some', values: new Set(checked) };
}

function rowLinkStatusKey(row) {
    if (!row.last_checked) return 'unknown';
    return row.is_online == 1 ? 'online' : 'offline';
}

/** Alle in den Daten vorkommenden Status-Werte (online / offline / unknown). */
function deriveLinkStatusOptionsFromRows(rows) {
    const set = new Set();
    (rows || []).forEach((r) => set.add(rowLinkStatusKey(r)));
    const order = { unknown: 0, offline: 1, online: 2 };
    return [...set].sort((a, b) => (order[a] ?? 9) - (order[b] ?? 9));
}

const LINK_STATUS_LABELS = { online: 'Online', offline: 'Offline', unknown: 'Unbekannt' };

let linkStatusFilterDocClickBound = false;

function bindLinkStatusFilterOutsideClose() {
    if (linkStatusFilterDocClickBound) return;
    linkStatusFilterDocClickBound = true;
    document.addEventListener('click', (e) => {
        const root = document.getElementById('linkStatusFilterRoot');
        const panel = document.getElementById('linkStatusFilterPanel');
        const btn = document.getElementById('linkStatusFilterBtn');
        if (!root || !panel || !btn) return;
        if (e.target && root.contains(e.target)) return;
        panel.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    });
}

function updateLinkStatusFilterButtonLabel() {
    const root = document.getElementById('linkStatusFilterRoot');
    const btn = document.getElementById('linkStatusFilterBtn');
    if (!root || !btn) return;
    const cbs = root.querySelectorAll('input.link-status-cb');
    const n = [...cbs].filter((c) => c.checked).length;
    const el = btn.querySelector('.bl-status-codes-count');
    if (el) el.textContent = cbs.length ? ` ${n}/${cbs.length}` : '';
}

/**
 * Spalte „Status“: Dropdown mit Checkboxen für alle vorkommenden Zustände (Online/Offline/Unbekannt).
 */
function renderLinkStatusColumnFilter(statusKeys) {
    const root = document.getElementById('linkStatusFilterRoot');
    if (!root) return;
    bindLinkStatusFilterOutsideClose();

    const opts = (statusKeys && statusKeys.length) ? statusKeys : deriveLinkStatusOptionsFromRows(allRows);
    if (!opts.length) {
        root.innerHTML = '<span style="font-size:0.72rem;color:var(--zap-text-muted);">—</span>';
        return;
    }

    const checks = opts.map((key) =>
        `<label class="bl-checkbox-row"><input type="checkbox" class="link-status-cb" value="${esc(key)}" checked> ${esc(LINK_STATUS_LABELS[key] || key)}</label>`
    ).join('');

    root.innerHTML = `
        <button type="button" class="bl-status-codes-btn" id="linkStatusFilterBtn" aria-expanded="false" aria-haspopup="true">
            <span>Status<span class="bl-status-codes-count"></span></span>
            <i class="fas fa-chevron-down"></i>
        </button>
        <div class="bl-status-codes-panel" id="linkStatusFilterPanel" hidden role="group" onclick="event.stopPropagation()">
            ${checks}
            <div class="bl-status-codes-actions">
                <button type="button" class="btn-text-sm" id="linkStatusFilterAll">Alle</button>
                <button type="button" class="btn-text-sm" id="linkStatusFilterNone">Keine</button>
            </div>
        </div>`;

    const btn = document.getElementById('linkStatusFilterBtn');
    const panel = document.getElementById('linkStatusFilterPanel');
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    root.querySelectorAll('.link-status-cb').forEach((cb) => {
        cb.addEventListener('change', () => {
            updateLinkStatusFilterButtonLabel();
            filterTable();
        });
    });
    document.getElementById('linkStatusFilterAll')?.addEventListener('click', () => {
        root.querySelectorAll('.link-status-cb').forEach((c) => { c.checked = true; });
        updateLinkStatusFilterButtonLabel();
        filterTable();
    });
    document.getElementById('linkStatusFilterNone')?.addEventListener('click', () => {
        root.querySelectorAll('.link-status-cb').forEach((c) => { c.checked = false; });
        updateLinkStatusFilterButtonLabel();
        filterTable();
    });
    updateLinkStatusFilterButtonLabel();
}

/** @returns {{ mode: 'all' }|{ mode: 'none' }|{ mode: 'some', values: Set<string> }|null} */
function getLinkStatusColumnFilterState() {
    const root = document.getElementById('linkStatusFilterRoot');
    if (!root) return null;
    const cbs = root.querySelectorAll('input.link-status-cb');
    if (!cbs.length) return null;
    const checked = [...cbs].filter((c) => c.checked).map((c) => c.value);
    if (checked.length === 0) return { mode: 'none' };
    if (checked.length === cbs.length) return { mode: 'all' };
    return { mode: 'some', values: new Set(checked) };
}

function renderToolbarStatusFilter(statusKeys) {
    const root = document.getElementById('statusFilterRoot');
    if (!root) return;

    const opts = (statusKeys && statusKeys.length) ? statusKeys : deriveLinkStatusOptionsFromRows(allRows);
    if (!opts.length) {
        root.innerHTML = '<span style="font-size:0.72rem;color:var(--zap-text-muted);">Keine Status</span>';
        return;
    }

    const selected = pendingToolbarStatusSelection instanceof Set
        ? pendingToolbarStatusSelection
        : new Set(opts);

    root.innerHTML = opts.map((key) => {
        const checked = selected.has(key) ? ' checked' : '';
        return `<label class="bl-status-pill">
            <input type="checkbox" class="toolbar-status-cb" value="${esc(key)}"${checked}>
            <span>${esc(LINK_STATUS_LABELS[key] || key)}</span>
        </label>`;
    }).join('');

    root.querySelectorAll('.toolbar-status-cb').forEach((cb) => {
        cb.addEventListener('change', filterTable);
    });

    pendingToolbarStatusSelection = null;
}

function getToolbarStatusFilterState() {
    const cbs = document.querySelectorAll('#statusFilterRoot .toolbar-status-cb');
    if (!cbs.length) return null;
    const checked = [...cbs].filter((cb) => cb.checked).map((cb) => cb.value);
    if (checked.length === 0) return { mode: 'none' };
    if (checked.length === cbs.length) return { mode: 'all' };
    return { mode: 'some', values: new Set(checked) };
}

function setToolbarStatusFilter(statusKeys) {
    pendingToolbarStatusSelection = new Set(statusKeys || []);
    renderToolbarStatusFilter(deriveLinkStatusOptionsFromRows(allRows));
    filterTable();
}

function applyOfflineQuickFilter() {
    const url = new URL(window.location.href);
    setToolbarStatusFilter(['offline']);
    url.searchParams.delete('offline');
    window.history.replaceState({}, '', url.toString());
    const tw = document.querySelector('#backlinksTable')?.closest('.card');
    tw?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Load ─────────────────────────────────────────────────────
async function loadBacklinks() {
    try {
        const res = await fetch('api/backlinks.php');
        const data = await res.json();
        allRows = data.data || [];
        projectInsights = data.projects || [];
        renderStats();
        renderProjectInsights();
        const httpOpts = data.http_status_options && data.http_status_options.length
            ? data.http_status_options
            : deriveHttpOptionsFromRows(allRows);
        populateHttpFilter(httpOpts);
        renderToolbarStatusFilter(deriveLinkStatusOptionsFromRows(allRows));
        renderLinkStatusColumnFilter(deriveLinkStatusOptionsFromRows(allRows));
        filteredRows = [...allRows];
        filterTable();
    } catch (e) {
        document.getElementById('tableBody').innerHTML =
            '<tr><td colspan="13" style="text-align:center;padding:40px;color:#ff5050;"><i class="fas fa-exclamation-circle"></i> Fehler beim Laden</td></tr>';
    }
}

function renderProjectInsights() {
    const wrap = document.getElementById('blProjectsWrap');
    if (!wrap) return;
    if (!projectInsights.length) {
        wrap.innerHTML = '<p class="bl-section-hint" style="margin:0">Keine Projektübersicht verfügbar.</p>';
        return;
    }
    wrap.innerHTML = projectInsights.map(renderProjectCard).join('');
}

function renderProjectCard(p) {
    const t = p.total || 0;
    const df = p.dofollow || 0;
    const nf = p.nofollow || 0;
    const ot = p.other_type || 0;
    const wDf = t ? (df / t) * 100 : 0;
    const wNf = t ? (nf / t) * 100 : 0;
    const wOt = t ? (ot / t) * 100 : 0;

    const brandCls = (p.brand || '').toLowerCase();
    const buckets = p.dr_buckets || {};
    const drKeys = Object.keys(buckets)
        .filter((k) => (buckets[k] || 0) > 0)
        .sort((a, b) => {
            const ia = BL_DR_ORDER.indexOf(a);
            const ib = BL_DR_ORDER.indexOf(b);
            return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
        });

    const drRows = drKeys.map((k) => {
        const c = buckets[k] || 0;
        const pct = t ? (c / t) * 100 : 0;
        return `<div class="bl-dr-row">
                <span class="bl-dr-label">DR ${esc(k)}</span>
                <div class="bl-dr-bar"><div class="bl-dr-fill" style="width:${pct.toFixed(1)}%"></div></div>
                <span class="bl-dr-count">${fmtNum(c)}</span>
            </div>`;
    }).join('');

    const anchors = (p.top_anchors || []).length
        ? `<ul class="bl-anchors">${p.top_anchors.map((a, i) =>
            `<li><span class="bl-anchor-text" title="${esc(a.anchor)}">${i + 1}. ${esc(a.anchor)}</span><span class="bl-anchor-n">${fmtNum(a.count)}</span></li>`
        ).join('')}</ul>`
        : '<p style="font-size:0.78rem;color:var(--zap-text-muted);margin:0;">Noch keine Ankertexte (oder keine Zuordnung).</p>';

    return `<div class="bl-project-card">
        <div class="bl-project-head">
            <div>
                <div class="bl-project-name">${esc(p.name)}</div>
                <div class="bl-project-domain">${esc(p.domain)}</div>
            </div>
            <span class="brand-badge ${brandCls}">${esc(p.brand)}</span>
        </div>
        <div class="bl-stat-line">Backlinks gesamt: <strong>${fmtNum(t)}</strong></div>
        <div>
            <div class="bl-subh">Dofollow / Nofollow (Anteil aller Links)</div>
            <div class="bl-ratio-bar">
                <div class="bl-ratio-seg df" style="width:${wDf.toFixed(2)}%" title="Dofollow"></div>
                <div class="bl-ratio-seg nf" style="width:${wNf.toFixed(2)}%" title="Nofollow"></div>
                <div class="bl-ratio-seg ot" style="width:${wOt.toFixed(2)}%" title="Sponsored / UGC"></div>
            </div>
            <div class="bl-ratio-legend">
                <span>Dofollow: <strong>${fmtNum(df)}</strong>${t ? ` (${((df / t) * 100).toFixed(1)}%)` : ''}</span>
                <span>Nofollow: <strong>${fmtNum(nf)}</strong>${t ? ` (${((nf / t) * 100).toFixed(1)}%)` : ''}</span>
                ${ot ? `<span>Sonstige: <strong>${fmtNum(ot)}</strong> (${((ot / t) * 100).toFixed(1)}%)</span>` : ''}
            </div>
        </div>
        <div>
            <div class="bl-subh">Verteilung Referring-DR</div>
            <div class="bl-dr-list">${drRows || '<span style="font-size:0.75rem;color:var(--zap-text-muted)">Keine Einträge mit DR.</span>'}</div>
        </div>
        <div>
            <div class="bl-subh">Top 10 Ankertexte</div>
            ${anchors}
        </div>
    </div>`;
}

let blSyncTimer = null;

function clearBlSyncUi() {
    if (blSyncTimer) {
        clearInterval(blSyncTimer);
        blSyncTimer = null;
    }
}

async function syncBacklinks() {
    const btn = document.getElementById('syncBLBtn');
    const status = document.getElementById('syncStatus');
    const panel = document.getElementById('blSyncProgress');
    const fill = document.getElementById('blProgressFill');
    const meta = document.getElementById('blProgressMeta');
    if (!btn) return;

    clearBlSyncUi();
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Synchronisiere...';
    if (status) status.textContent = 'Backlinks werden von DataForSEO geladen...';
    if (panel) panel.style.display = 'block';

    const avgMs = typeof getAverageSyncMs === 'function' ? getAverageSyncMs('backlinks') : null;
    const t0 = performance.now();

    const tick = () => {
        const elapsed = performance.now() - t0;
        const avgLine = typeof formatAvgLine === 'function' ? formatAvgLine('backlinks', 'de') : '';
        const elapsedStr = typeof formatDurationMs === 'function' ? formatDurationMs(elapsed) : Math.round(elapsed / 1000) + 's';
        if (fill) {
            if (avgMs == null) {
                fill.classList.add('indeterminate');
                fill.style.removeProperty('width');
            } else {
                fill.classList.remove('indeterminate');
                const pct = Math.min(98, Math.round((elapsed / avgMs) * 100));
                fill.style.width = `${pct}%`;
            }
        }
        if (meta) {
            const pctLabel = avgMs == null ? '…' : `${Math.min(98, Math.round((elapsed / avgMs) * 100))}%`;
            meta.innerHTML = `<strong>${pctLabel}</strong> — läuft seit ${elapsedStr}<br><span style="opacity:0.85">${esc(avgLine)}</span>`;
        }
    };
    blSyncTimer = setInterval(tick, 400);
    tick();

    try {
        const res = await fetch('api/sync_dataforseo.php?action=backlinks');
        const data = await res.json();
        clearBlSyncUi();

        if (fill) {
            fill.classList.remove('indeterminate');
            fill.style.width = '100%';
        }

        const elapsed = performance.now() - t0;
        const lastAvg = typeof formatAvgLine === 'function' ? formatAvgLine('backlinks', 'de') : '';

        if (data.success) {
            if (typeof recordSyncDuration === 'function') recordSyncDuration('backlinks', elapsed);
            const ins = data.inserted ?? 0;
            const upd = data.updated ?? 0;
            showToast(`Synchronisation fertig: ${ins} neu, ${upd} aktualisiert.`);
            if (status) status.textContent = `Zuletzt: ${new Date().toLocaleString('de-DE')}`;
            if (meta) {
                const avgAfter = typeof formatAvgLine === 'function' ? formatAvgLine('backlinks', 'de') : lastAvg;
                meta.innerHTML = `<strong>100%</strong> — fertig in ${typeof formatDurationMs === 'function' ? formatDurationMs(elapsed) : Math.round(elapsed / 1000) + 's'}<br><span style="opacity:0.85">${esc(avgAfter)}</span>`;
            }
            await loadBacklinks();
            setTimeout(() => { if (panel) panel.style.display = 'none'; }, 6000);
        } else {
            const err = data.error || 'Synchronisation fehlgeschlagen';
            showToast(err, 'error');
            if (status) {
                status.textContent = data.action === 'activate'
                    ? 'Backlinks-API bei DataForSEO prüfen.'
                    : err;
            }
            if (meta) meta.innerHTML = `<strong style="color:#ff5050">Fehler</strong> — ${esc(err)}<br><span style="opacity:0.85">${esc(lastAvg)}</span>`;
        }
    } catch (e) {
        clearBlSyncUi();
        showToast('Netzwerkfehler', 'error');
        if (status) status.textContent = 'Fehler';
        if (meta) meta.innerHTML = '<strong style="color:#ff5050">Netzwerkfehler</strong>';
        if (fill) {
            fill.classList.remove('indeterminate');
            fill.style.width = '0%';
        }
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-link"></i> Sync Backlinks';
}

// ── Stats strip + kompakte Projekt-Kacheln ─────────────────────
function filterByProjectDomain(domain) {
    const sel = document.getElementById('domainFilter');
    if (sel) sel.value = domain || '';
    filterTable();
    const tw = document.querySelector('#backlinksTable')?.closest('.table-wrap');
    tw?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
window.filterByProjectDomain = filterByProjectDomain;
window.applyOfflineQuickFilter = applyOfflineQuickFilter;

function filterByBrand(brand) {
    pendingToolbarStatusSelection = new Set(deriveLinkStatusOptionsFromRows(allRows));
    renderToolbarStatusFilter(deriveLinkStatusOptionsFromRows(allRows));
    const sel = document.getElementById('brandFilter');
    if (sel) sel.value = brand || '';
    filterTable();
    document.querySelector('#backlinksTable')?.closest('.table-wrap')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
window.filterByBrand = filterByBrand;

function renderMiniProjectCard(p) {
    const t = p.total || 0;
    const df = p.dofollow || 0;
    const nf = p.nofollow || 0;
    const ot = p.other_type || 0;
    const wDf = t ? (df / t) * 100 : 0;
    const wNf = t ? (nf / t) * 100 : 0;
    const wOt = t ? (ot / t) * 100 : 0;
    const brandCls = (p.brand || '').toLowerCase();
    const domainJson = JSON.stringify(p.domain);
    const dfShare = t ? ((df / t) * 100).toFixed(1) : '0.0';
    const nfShare = t ? ((nf / t) * 100).toFixed(1) : '0.0';
    const sumDfNf = df + nf;
    const dfOfRel = sumDfNf ? ((df / sumDfNf) * 100).toFixed(0) : '—';
    const nfOfRel = sumDfNf ? ((nf / sumDfNf) * 100).toFixed(0) : '—';

    return `<button type="button" class="bl-mproj-card" onclick="filterByProjectDomain(${domainJson})" title="Tabelle nach diesem Ziel-Projekt filtern">
        <div class="bl-mproj-head">
            <span class="brand-badge ${brandCls}">${esc(p.brand)}</span>
            <span class="bl-mproj-total">${fmtNum(t)}</span>
        </div>
        <div class="bl-mproj-name">${esc(p.name)}</div>
        <div class="bl-mproj-domain">${esc(p.domain)}</div>
        <div class="bl-ratio-bar bl-ratio-bar--sm">
            <div class="bl-ratio-seg df" style="width:${wDf.toFixed(2)}%"></div>
            <div class="bl-ratio-seg nf" style="width:${wNf.toFixed(2)}%"></div>
            <div class="bl-ratio-seg ot" style="width:${wOt.toFixed(2)}%"></div>
        </div>
        <div class="bl-mproj-ratios">
            <span>DF/NF (Anteil): ${dfShare}% / ${nfShare}%</span>
            <span class="bl-mproj-ratios-sub">nur DF+NF: ${dfOfRel}% / ${nfOfRel}%</span>
        </div>
    </button>`;
}

function renderStats() {
    const el = document.getElementById('blStats');
    if (!el) return;

    const total = allRows.length;
    const online = allRows.filter((r) => r.is_online == 1).length;
    const offline = allRows.filter((r) => r.is_online == 0).length;
    const zapCount = allRows.filter((r) => String(r.brand || '').toUpperCase() === 'ZAP').length;
    const dmcCount = allRows.filter((r) => String(r.brand || '').toUpperCase() === 'DMC').length;
    const dofollow = allRows.filter((r) => String(r.link_type || '').toLowerCase() === 'dofollow').length;
    const nofollow = allRows.filter((r) => String(r.link_type || '').toLowerCase() === 'nofollow').length;
    const dfNfTotal = dofollow + nofollow;
    const dofollowPct = dfNfTotal ? ((dofollow / dfNfTotal) * 100).toFixed(1) : '0.0';
    const nofollowPct = dfNfTotal ? ((nofollow / dfNfTotal) * 100).toFixed(1) : '0.0';

    const strip = (projectInsights && projectInsights.length)
        ? projectInsights.map(renderMiniProjectCard).join('')
        : '<p class="bl-strip-empty">Keine Projekt-Kennzahlen (keine Zuordnung zu Monitoring-Domains).</p>';

    el.innerHTML = `
        <div class="stats-grid stats-grid--bl-top">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-link"></i></div>
                <div class="stat-info">
                    <div class="stat-value">${fmtNum(total)}</div>
                    <div class="stat-label">Backlinks gesamt</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon cyan"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-value">${fmtNum(online)}</div>
                    <div class="stat-label">Online</div>
                </div>
            </div>
            <button type="button" class="stat-card stat-card--link stat-card--offline-link" onclick="applyOfflineQuickFilter()" title="Nur derzeit nicht erreichbare Backlinks anzeigen" aria-label="Offline-Backlinks in der Tabelle anzeigen">
                <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-value">${fmtNum(offline)}</div>
                    <div class="stat-label">Offline</div>
                    <span class="stat-card-link-hint">Zeigt nur aktuell nicht erreichbare Links</span>
                </div>
            </button>
            <button type="button" class="stat-card stat-card--link" onclick="filterByBrand('ZAP')" title="Nur ZAP Backlinks anzeigen">
                <div class="stat-icon blue"><i class="fas fa-bolt"></i></div>
                <div class="stat-info">
                    <div class="stat-value">${fmtNum(zapCount)}</div>
                    <div class="stat-label">ZAP</div>
                    <span class="stat-card-link-hint">Filtert auf ZAP Backlinks</span>
                </div>
            </button>
            <button type="button" class="stat-card stat-card--link" onclick="filterByBrand('DMC')" title="Nur DMC Backlinks anzeigen">
                <div class="stat-icon purple"><i class="fas fa-fire"></i></div>
                <div class="stat-info">
                    <div class="stat-value">${fmtNum(dmcCount)}</div>
                    <div class="stat-label">DMC</div>
                    <span class="stat-card-link-hint">Filtert auf DMC Backlinks</span>
                </div>
            </button>
            <div class="stat-card">
                <div class="stat-icon cyan"><i class="fas fa-scale-balanced"></i></div>
                <div class="stat-info">
                    <div class="stat-value">${fmtNum(dofollow)} / ${fmtNum(nofollow)}</div>
                    <div class="stat-label">Dofollow zu Nofollow</div>
                    <span class="stat-card-link-hint">${dofollowPct}% zu ${nofollowPct}%${dfNfTotal ? ` (${fmtNum(dfNfTotal)} Links)` : ''}</span>
                </div>
            </div>
        </div>
        <div class="bl-strip-head">
            <h3 class="bl-strip-title">Je Ziel-Projekt (ZAP &amp; DMC einzeln)</h3>
            <span class="bl-strip-hint">Klick auf eine Kachel setzt den Domain-Filter für die Tabelle unten.</span>
        </div>
        <div class="bl-project-strip-grid">${strip}</div>
    `;
}

// ── Filter ──────────────────────────────────────────────────────
function filterTable() {
    const global = document.getElementById('globalSearch').value.toLowerCase();
    const domain = document.getElementById('domainFilter').value.toLowerCase();
    const brand = document.getElementById('brandFilter').value.toLowerCase();
    const type = document.getElementById('typeFilter').value.toLowerCase();
    const httpState = getHttpFilterState();
    const toolbarStatusState = getToolbarStatusFilterState();
    const linkStatusColState = getLinkStatusColumnFilterState();
    const colSearches = [...document.querySelectorAll('.col-search-input')].map((i) => ({col: +i.dataset.col, val: i.value.toLowerCase()}));

    filteredRows = allRows.filter((row) => {
        const cells = getCellTexts(row);
        if (global && !cells.join(' ').includes(global)) return false;
        if (domain) {
            const src = (row.source_url || '').toLowerCase();
            const tgt = (row.target_url || '').toLowerCase();
            if (!src.includes(domain) && !tgt.includes(domain)) return false;
        }
        if (brand && (row.brand || '').toLowerCase() !== brand) return false;
        if (toolbarStatusState?.mode === 'none') return false;
        if (toolbarStatusState?.mode === 'some') {
            const statusKey = rowLinkStatusKey(row);
            if (!toolbarStatusState.values.has(statusKey)) return false;
        }
        if (type && (row.link_type || '').toLowerCase() !== type) return false;
        if (httpState?.mode === 'none') return false;
        if (httpState?.mode === 'some') {
            const httpValue = row.http_status == null || String(row.http_status).trim() === '' ? '_none' : String(Number(row.http_status));
            if (!httpState.values.has(httpValue)) return false;
        }
        if (linkStatusColState?.mode === 'none') return false;
        if (linkStatusColState?.mode === 'some') {
            const k = rowLinkStatusKey(row);
            if (!linkStatusColState.values.has(k)) return false;
        }
        for (const cs of colSearches) {
            if (cs.val && !(cells[cs.col] || '').includes(cs.val)) return false;
        }
        return true;
    });
    currentPage = 1;
    renderTable();
}

if (new URLSearchParams(window.location.search).get('offline') === '1') {
    pendingToolbarStatusSelection = new Set(['offline']);
}

function getCellTexts(row) {
    return [
        (row.brand || '').toLowerCase(),
        (row.source_url || '').toLowerCase(),
        (row.target_url || '').toLowerCase(),
        (row.anchor_text || '').toLowerCase(),
        (row.link_type || '').toLowerCase(),
        String(row.domain_rating || ''),
        String(row.domain_authority || ''),
        rowLinkStatusKey(row),
        String(row.http_status || ''),
        (row.first_seen || '').toLowerCase(),
        (row.last_checked || '').toLowerCase(),
        (row.notes || '').toLowerCase(),
    ];
}

// ── Sort ──────────────────────────────────────────────────────
function sortTable(col) {
    if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortCol = col; sortDir = 'asc'; }
    filteredRows.sort((a, b) => {
        const ta = getCellTexts(a)[col];
        const tb = getCellTexts(b)[col];
        const cmp = ta.localeCompare(tb, undefined, { numeric: true });
        return sortDir === 'asc' ? cmp : -cmp;
    });
    document.querySelectorAll('.data-table thead th').forEach((th, i) => {
        th.classList.remove('sort-asc', 'sort-desc');
        if (i === col) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });
    renderTable();
}

// ── Render ────────────────────────────────────────────────────
function renderTable() {
    const tbody = document.getElementById('tableBody');
    const start = (currentPage - 1) * perPage;
    const pageRows = filteredRows.slice(start, start + perPage);

    if (pageRows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="13" style="text-align:center;padding:50px;color:var(--zap-text-muted);">
            <i class="fas fa-link" style="font-size:2.5rem;opacity:0.2;display:block;margin-bottom:12px;"></i>
            ${allRows.length === 0
                ? 'Noch keine Backlinks vorhanden. <br><strong>CSV importieren</strong> oder manuell hinzufügen.'
                : 'Keine Backlinks gefunden.'}
        </td></tr>`;
    } else {
        tbody.innerHTML = pageRows.map(row => renderRow(row)).join('');
    }
    renderPagination();
    document.getElementById('tableInfo').textContent =
        `${filteredRows.length === allRows.length
            ? `${fmtNum(allRows.length)} Backlinks`
            : `${fmtNum(filteredRows.length)} von ${fmtNum(allRows.length)}`}`;
}

function drBadge(val) {
    if (val === null || val === undefined || val === '') return '<span style="color:var(--zap-text-muted)">—</span>';
    const v = parseFloat(val);
    const cls = v >= 60 ? 'high' : v >= 30 ? 'medium' : v > 0 ? 'low' : 'none';
    return `<span class="dr-badge ${cls}">${Math.round(v)}</span>`;
}

function renderRow(row) {
    const isOnline = row.is_online == 1;
    const statusBadge = row.last_checked
        ? `<span class="status-badge ${isOnline ? 'online' : 'offline'}"><span class="status-dot ${isOnline ? 'online' : 'offline'}"></span>${isOnline ? 'Online' : 'Offline'}</span>`
        : `<span class="status-badge unknown"><span class="status-dot" style="background:#ffc800;"></span>Unbekannt</span>`;

    const httpCode = row.http_status
        ? `<span class="http-code ${row.http_status < 300 ? 'ok' : row.http_status < 400 ? 'redirect' : 'error'}">${row.http_status}</span>`
        : '<span style="color:var(--zap-text-muted)">—</span>';

    const lastChecked = row.last_checked
        ? `<span class="date-cell" title="${row.last_checked}">${formatDate(row.last_checked)}</span>`
        : '<span style="color:var(--zap-text-muted)">Nie</span>';

    const firstSeen = row.first_seen
        ? `<span class="date-cell" title="Seit: ${row.first_seen}">${row.first_seen}</span>`
        : '<span style="color:var(--zap-text-muted)">—</span>';

    return `<tr data-id="${row.id}">
        <td><span class="brand-badge ${(row.brand||'').toLowerCase()}">${esc(row.brand)}</span></td>
        <td class="url-cell"><a href="${esc(row.source_url)}" target="_blank" class="url-link" title="${esc(row.source_url)}">${esc(truncUrl(row.source_url))}</a></td>
        <td class="url-cell"><a href="${esc(row.target_url)}" target="_blank" class="url-link" title="${esc(row.target_url)}">${esc(truncUrl(row.target_url))}</a></td>
        <td>${esc(row.anchor_text) || '<span style="color:var(--zap-text-muted)">—</span>'}</td>
        <td><span class="link-type-badge ${row.link_type}">${esc(row.link_type)}</span></td>
        <td>${drBadge(row.domain_rating)}</td>
        <td>${row.domain_authority ? `<strong>${parseFloat(row.domain_authority).toFixed(0)}</strong>` : '<span style="color:var(--zap-text-muted)">—</span>'}</td>
        <td>${statusBadge}</td>
        <td>${httpCode}</td>
        <td>${firstSeen}</td>
        <td>${lastChecked}</td>
        <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--zap-text-muted);" title="${esc(row.notes)}">${esc(row.notes) || '—'}</td>
        <td>
            <div class="action-btns">
                <button class="action-btn" title="Bearbeiten" onclick="editBacklink(${row.id})"><i class="fas fa-pen"></i></button>
                <button class="action-btn" title="Link prüfen" onclick="checkSingleLink(${row.id})"><i class="fas fa-sync-alt"></i></button>
                <button class="action-btn delete" title="Löschen" onclick="openDeleteModal(${row.id})"><i class="fas fa-trash"></i></button>
            </div>
        </td>
    </tr>`;
}

function renderPagination() {
    const totalPages = Math.ceil(filteredRows.length / perPage);
    const pag = document.getElementById('pagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    let html = `<button class="page-btn" onclick="goPage(${currentPage-1})" ${currentPage===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
    const range = 2;
    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= currentPage - range && p <= currentPage + range)) {
            html += `<button class="page-btn ${p===currentPage?'active':''}" onclick="goPage(${p})">${p}</button>`;
        } else if (p === currentPage - range - 1 || p === currentPage + range + 1) {
            html += `<span style="color:var(--zap-text-muted);padding:0 4px;">…</span>`;
        }
    }
    html += `<button class="page-btn" onclick="goPage(${currentPage+1})" ${currentPage===totalPages?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
    pag.innerHTML = html;
}

function goPage(p) {
    const totalPages = Math.ceil(filteredRows.length / perPage);
    if (p < 1 || p > totalPages) return;
    currentPage = p;
    renderTable();
}

// ── Add/Edit CRUD ─────────────────────────────────────────────
function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Backlink hinzufügen';
    document.getElementById('editId').value = '';
    document.getElementById('backlinkForm').reset();
    document.getElementById('fFirstSeen').value = new Date().toISOString().split('T')[0];
    openModal('backlinkModal');
}

function editBacklink(id) {
    const row = allRows.find(r => r.id == id);
    if (!row) return;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen"></i> Backlink bearbeiten';
    document.getElementById('editId').value = id;
    document.getElementById('fBrand').value    = row.brand;
    document.getElementById('fLinkType').value = row.link_type;
    document.getElementById('fSourceUrl').value = row.source_url;
    document.getElementById('fTargetUrl').value = row.target_url;
    document.getElementById('fAnchor').value   = row.anchor_text || '';
    document.getElementById('fDR').value       = row.domain_rating || '';
    document.getElementById('fDA').value       = row.domain_authority || '';
    document.getElementById('fFirstSeen').value = row.first_seen || '';
    document.getElementById('fNotes').value    = row.notes || '';
    openModal('backlinkModal');
}

async function saveBacklink() {
    const id = document.getElementById('editId').value;
    const payload = {
        brand:            document.getElementById('fBrand').value,
        link_type:        document.getElementById('fLinkType').value,
        source_url:       document.getElementById('fSourceUrl').value,
        target_url:       document.getElementById('fTargetUrl').value,
        anchor_text:      document.getElementById('fAnchor').value,
        domain_rating:    document.getElementById('fDR').value || null,
        domain_authority: document.getElementById('fDA').value || null,
        first_seen:       document.getElementById('fFirstSeen').value || null,
        notes:            document.getElementById('fNotes').value,
    };
    if (!payload.source_url || !payload.target_url) { showToast('Source URL und Target URL sind Pflicht', 'error'); return; }
    const method = id ? 'PUT' : 'POST';
    const url    = id ? `api/backlinks.php?id=${id}` : 'api/backlinks.php';
    try {
        const res  = await fetch(url, { method, headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) { closeModal('backlinkModal'); showToast(id ? 'Backlink aktualisiert!' : 'Backlink hinzugefügt!'); loadBacklinks(); }
        else showToast(data.error || 'Fehler beim Speichern', 'error');
    } catch (e) { showToast('Netzwerkfehler', 'error'); }
}

function openDeleteModal(id) { deleteId = id; openModal('deleteModal'); }

async function confirmDelete() {
    if (!deleteId) return;
    try {
        const res  = await fetch(`api/backlinks.php?id=${deleteId}`, { method: 'DELETE' });
        const data = await res.json();
        if (data.success) { closeModal('deleteModal'); showToast('Backlink gelöscht'); loadBacklinks(); }
        else showToast(data.error || 'Fehler', 'error');
    } catch (e) { showToast('Netzwerkfehler', 'error'); }
    deleteId = null;
}

async function checkSingleLink(id) {
    showToast('Link wird geprüft...');
    try {
        const res  = await fetch(`api/check_links.php?id=${id}`);
        const data = await res.json();
        if (data.success) { showToast('Status aktualisiert!'); loadBacklinks(); }
        else showToast(data.error || 'Fehler', 'error');
    } catch (e) { showToast('Netzwerkfehler', 'error'); }
}

async function checkAllLinks() {
    showToast('Alle Links werden geprüft (läuft im Hintergrund)...');
    try {
        await fetch('api/check_links.php');
        setTimeout(() => { showToast('Links geprüft!'); loadBacklinks(); }, 4000);
    } catch (e) { showToast('Netzwerkfehler', 'error'); }
}

// ── CSV Import ────────────────────────────────────────────────
let selectedFile = null;

function handleFileSelect(input) {
    const file = input.files[0];
    if (!file) return;
    selectedFile = file;
    const drop = document.getElementById('fileDrop');
    drop.classList.add('has-file');
    document.getElementById('fileDropText').textContent = `✓ ${file.name} (${(file.size/1024).toFixed(1)} KB)`;
    document.getElementById('importBtn').disabled = false;

    // Preview first few lines
    const reader = new FileReader();
    reader.onload = e => {
        const lines = e.target.result.split('\n').slice(0, 4);
        const preview = document.getElementById('importPreview');
        preview.style.display = 'block';
        preview.innerHTML = `<strong>Vorschau (erste 3 Zeilen):</strong><br><code style="font-size:0.7rem;word-break:break-all;">${
            lines.map(l => esc(l.substring(0, 150))).join('<br>')
        }</code>`;
    };
    reader.readAsText(file, 'UTF-8');
}

// Drag & drop
document.addEventListener('DOMContentLoaded', () => {
    const drop = document.getElementById('fileDrop');
    if (!drop) return;
    drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag-over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
    drop.addEventListener('drop', e => {
        e.preventDefault(); drop.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (file) { const dt = new DataTransfer(); dt.items.add(file); document.getElementById('csvFile').files = dt.files; handleFileSelect(document.getElementById('csvFile')); }
    });
});

async function runImport() {
    if (!selectedFile) { showToast('Keine Datei ausgewählt', 'error'); return; }
    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importiere...';

    const form = new FormData();
    form.append('csv',    selectedFile);
    form.append('brand',  document.getElementById('importBrand').value);
    form.append('target', document.getElementById('importTarget').value);

    try {
        const res  = await fetch('api/import_backlinks.php', { method: 'POST', body: form });
        const data = await res.json();
        const result = document.getElementById('importResult');
        result.style.display = 'block';
        if (data.success) {
            result.className = 'import-result success';
            result.innerHTML = `
                <strong style="color:var(--zap-green)">✓ Import erfolgreich!</strong><br>
                <span>Neu: <strong>${data.inserted}</strong> &nbsp;|&nbsp; Aktualisiert: <strong>${data.updated}</strong> &nbsp;|&nbsp; Übersprungen: <strong>${data.skipped}</strong></span>
                ${data.errors?.length ? `<br><span style="color:#ff8080;font-size:0.75rem;">Fehler: ${data.errors.join('; ')}</span>` : ''}
                ${Object.keys(data.detected_columns||{}).length ? `<br><span style="font-size:0.75rem;color:var(--zap-text-muted);">Erkannte Spalten: ${Object.keys(data.detected_columns).join(', ')}</span>` : ''}
            `;
            showToast(`Import fertig: ${data.inserted} neu, ${data.updated} aktualisiert`);
            loadBacklinks();
        } else {
            result.className = 'import-result error';
            result.innerHTML = `<strong style="color:#ff5050">✗ Fehler:</strong> ${esc(data.error)}`;
        }
    } catch (e) { showToast('Netzwerkfehler beim Import', 'error'); }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-upload"></i> Importieren';
}

// ── Utils ─────────────────────────────────────────────────────
function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function truncUrl(url, len = 45) {
    if (!url) return '';
    return url.length > len ? url.substring(0, len) + '…' : url;
}
function fmtNum(n) { return Number(n||0).toLocaleString('de-DE'); }
function formatDate(dt) {
    if (!dt) return '—';
    const d = new Date(dt), now = new Date(), diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'gerade eben';
    if (diff < 3600) return Math.floor(diff/60) + ' Min. ago';
    if (diff < 86400) return Math.floor(diff/3600) + ' Std. ago';
    if (diff < 604800) return Math.floor(diff/86400) + ' T. ago';
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: 'short', year: 'numeric' });
}

// ── Sort headers ──────────────────────────────────────────────
document.querySelectorAll('.data-table thead th.sortable').forEach((th, i) => {
    th.addEventListener('click', () => sortTable(i));
});

/** Offline-Kachel: Link-Ziel bleibt für Strg/Mittelklick; normaler Klick filtert die Tabelle (kein Inline-Handler, CSP-freundlich). */
(function initOfflineStatDelegation() {
    const root = document.getElementById('blStats');
    if (!root) return;
    root.addEventListener('click', (e) => {
        const a = e.target.closest('a.stat-card--offline-link');
        if (!a || !root.contains(a)) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        if (e.button !== 0) return;
        e.preventDefault();
        const sel = document.getElementById('statusFilter');
        if (sel) sel.value = 'offline';
        filterTable();
        document.querySelector('#backlinksTable')?.closest('.table-wrap')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
})();

loadBacklinks();



