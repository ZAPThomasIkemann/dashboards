let allRows = [];
let filteredRows = [];
let projectInsights = [];
let currentPage = 1;
const perPage = 25;
let sortCol = 5;
let sortDir = 'desc';
let deleteId = null;
const backlinkPageParams = new URLSearchParams(window.location.search);
const DASHBOARD_BRAND_SCOPE = String(window.DASHBOARD_BRAND_SCOPE || '').toUpperCase();
const DASHBOARD_DOMAIN_SCOPE = Array.isArray(window.DASHBOARD_DOMAIN_SCOPE)
    ? window.DASHBOARD_DOMAIN_SCOPE.map((domain) => String(domain || '').toLowerCase())
    : [];
const DASHBOARD_API_BASE = String(window.DASHBOARD_API_BASE || 'api').replace(/\/$/, '');

function apiUrl(path) {
    return `${DASHBOARD_API_BASE}/${String(path || '').replace(/^\/+/, '')}`;
}

/** Kept for compatibility with older cached data. */
const BL_DR_ORDER = ['0-10', '11-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80', '81-90', '91-100', 'n/a'];

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
    const prevValue = getHttpFilterState();
    const normalized = (options || []).map((v) => (v === null || v === undefined ? '_none' : String(Number(v))));
    const optionMarkup = [
        '<option value="">HTTP</option>',
        ...normalized.map((value) => {
            const label = value === '_none' ? 'No Status' : value;
            return `<option value="${esc(value)}">${esc(label)}</option>`;
        }),
    ].join('');

    root.innerHTML = `<select class="col-search-input" id="httpStatusSelect">${optionMarkup}</select>`;
    const select = document.getElementById('httpStatusSelect');
    if (select && normalized.includes(prevValue)) select.value = prevValue;
    select?.addEventListener('change', filterTable);
}

function getHttpFilterState() {
    return document.getElementById('httpStatusSelect')?.value || '';
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

const LINK_STATUS_LABELS = { online: 'Online', offline: 'Offline', unknown: 'Unknown' };

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
 * Status column dropdown with all statuses that exist in the current list.
 */
function renderLinkStatusColumnFilter(statusKeys) {
    const root = document.getElementById('linkStatusFilterRoot');
    if (!root) return;
    bindLinkStatusFilterOutsideClose();

    const opts = (statusKeys && statusKeys.length) ? statusKeys : deriveLinkStatusOptionsFromRows(allRows);
    if (!opts.length) {
        root.innerHTML = '<span style="font-size:0.72rem;color:var(--zap-text-muted);">-</span>';
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
                <button type="button" class="btn-text-sm" id="linkStatusFilterAll">All</button>
                <button type="button" class="btn-text-sm" id="linkStatusFilterNone">None</button>
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



function applyOfflineQuickFilter() {
    const url = new URL(window.location.href);
    const root = document.getElementById('linkStatusFilterRoot');
    root?.querySelectorAll('.link-status-cb').forEach((cb) => {
        cb.checked = cb.value === 'offline';
    });
    updateLinkStatusFilterButtonLabel();
    filterTable();
    url.searchParams.delete('offline');
    window.history.replaceState({}, '', url.toString());
    const tw = document.querySelector('#backlinksTable')?.closest('.card');
    tw?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// â”€â”€ Load â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async function loadBacklinks() {
    try {
        const requestUrl = new URL(apiUrl('backlinks.php'), window.location.origin);
        if (DASHBOARD_DOMAIN_SCOPE.length === 1) {
            requestUrl.searchParams.set('target_domain', DASHBOARD_DOMAIN_SCOPE[0]);
        }

        const res = await fetch(requestUrl.toString());
        const data = await res.json();
        allRows = (data.data || []).filter(isBacklinkInScope);
        projectInsights = (data.projects || []).filter(isProjectInsightInScope);
        renderStats();
        renderProjectInsights();
        populateSourceDomainFilter(allRows);
        const httpOpts = data.http_status_options && data.http_status_options.length
            ? data.http_status_options
            : deriveHttpOptionsFromRows(allRows);
        populateHttpFilter(httpOpts);
        renderLinkStatusColumnFilter(deriveLinkStatusOptionsFromRows(allRows));
        filteredRows = [...allRows];
        filterTable();
        if (backlinkPageParams.get('offline') === '1') applyOfflineQuickFilter();
    } catch (e) {
        document.getElementById('tableBody').innerHTML =
            '<tr><td colspan="13" style="text-align:center;padding:40px;color:#ff5050;"><i class="fas fa-exclamation-circle"></i> Error while loading</td></tr>';
    }
}

function normalizeHost(value) {
    return String(value || '').toLowerCase().replace(/^www\./, '');
}

function extractHost(url) {
    if (!url) return '';
    try {
        return normalizeHost(new URL(url).hostname);
    } catch (e) {
        return normalizeHost(String(url).replace(/^[a-z]+:\/\//i, '').split('/')[0]);
    }
}

function matchesScopedDomain(url) {
    if (!DASHBOARD_DOMAIN_SCOPE.length) return true;
    const host = extractHost(url);
    return DASHBOARD_DOMAIN_SCOPE.some((domain) => host === domain || host.endsWith(`.${domain}`));
}

function isBacklinkInScope(row) {
    const brand = String(row?.brand || '').toUpperCase();
    if (DASHBOARD_BRAND_SCOPE && brand !== DASHBOARD_BRAND_SCOPE) return false;
    return matchesScopedDomain(row?.target_url || '');
}

function isProjectInsightInScope(project) {
    const brand = String(project?.brand || '').toUpperCase();
    const domain = normalizeHost(project?.domain || '');
    if (DASHBOARD_BRAND_SCOPE && brand !== DASHBOARD_BRAND_SCOPE) return false;
    return !DASHBOARD_DOMAIN_SCOPE.length || DASHBOARD_DOMAIN_SCOPE.includes(domain);
}

function sortFilteredRows() {
    filteredRows.sort((a, b) => {
        if (sortCol === 5 || sortCol === 6 || sortCol === 8) {
            const key = sortCol === 5 ? 'domain_rating' : sortCol === 6 ? 'domain_authority' : 'http_status';
            const na = Number(a?.[key] ?? -1);
            const nb = Number(b?.[key] ?? -1);
            const cmp = na === nb ? 0 : (na < nb ? -1 : 1);
            return sortDir === 'asc' ? cmp : -cmp;
        }
        if (sortCol === 9 || sortCol === 10) {
            const va = Date.parse(sortCol === 9 ? (a?.first_seen || '') : (a?.last_checked || '')) || 0;
            const vb = Date.parse(sortCol === 9 ? (b?.first_seen || '') : (b?.last_checked || '')) || 0;
            const cmp = va === vb ? 0 : (va < vb ? -1 : 1);
            return sortDir === 'asc' ? cmp : -cmp;
        }
        const ta = getCellTexts(a)[sortCol];
        const tb = getCellTexts(b)[sortCol];
        const cmp = ta.localeCompare(tb, undefined, { numeric: true });
        return sortDir === 'asc' ? cmp : -cmp;
    });
}

function renderProjectInsights() {
    return;
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
            <div class="bl-dr-list">${drRows || '<span style="font-size:0.75rem;color:var(--zap-text-muted)">Keine EintrÃ¤ge mit DR.</span>'}</div>
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
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing...';
    if (status) status.textContent = 'Loading backlinks from DataForSEO...';
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
            const pctLabel = avgMs == null ? '...' : `${Math.min(98, Math.round((elapsed / avgMs) * 100))}%`;
            meta.innerHTML = `<strong>${pctLabel}</strong> - running for ${elapsedStr}<br><span style="opacity:0.85">${esc(avgLine)}</span>`;
        }
    };
    blSyncTimer = setInterval(tick, 400);
    tick();

    try {
        const res = await fetch(apiUrl('sync_dataforseo.php?action=backlinks'));
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
            showToast(`Sync complete: ${ins} inserted, ${upd} updated.`);
            if (status) status.textContent = `Last sync: ${new Date().toLocaleString('en-GB', { hour12: false })}`;
            if (meta) {
                const avgAfter = typeof formatAvgLine === 'function' ? formatAvgLine('backlinks', 'de') : lastAvg;
                meta.innerHTML = `<strong>100%</strong> - finished in ${typeof formatDurationMs === 'function' ? formatDurationMs(elapsed) : Math.round(elapsed / 1000) + 's'}<br><span style="opacity:0.85">${esc(avgAfter)}</span>`;
            }
            await loadBacklinks();
            setTimeout(() => { if (panel) panel.style.display = 'none'; }, 6000);
        } else {
            const err = data.error || 'Sync failed';
            showToast(err, 'error');
            if (status) {
                status.textContent = data.action === 'activate'
                    ? 'Please check the backlinks API in DataForSEO.'
                    : err;
            }
            if (meta) meta.innerHTML = `<strong style="color:#ff5050">Error</strong> - ${esc(err)}<br><span style="opacity:0.85">${esc(lastAvg)}</span>`;
        }
    } catch (e) {
        clearBlSyncUi();
        showToast('Network error', 'error');
        if (status) status.textContent = 'Error';
        if (meta) meta.innerHTML = '<strong style="color:#ff5050">Network error</strong>';
        if (fill) {
            fill.classList.remove('indeterminate');
            fill.style.width = '0%';
        }
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-link"></i> Sync Backlinks';
}

// â”€â”€ Stats strip + kompakte Projekt-Kacheln â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function filterByProjectDomain(domain) {
    const sel = document.querySelector('.col-search-input[data-col="2"]');
    if (sel) sel.value = domain || '';
    filterTable();
    const tw = document.querySelector('#backlinksTable')?.closest('.table-wrap');
    tw?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
window.filterByProjectDomain = filterByProjectDomain;
window.applyOfflineQuickFilter = applyOfflineQuickFilter;

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
    const dfOfRel = sumDfNf ? ((df / sumDfNf) * 100).toFixed(0) : '-';
    const nfOfRel = sumDfNf ? ((nf / sumDfNf) * 100).toFixed(0) : '-';

    return `<button type="button" class="bl-mproj-card" onclick="filterByProjectDomain(${domainJson})" title="Filter the table by this target project">
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
            <span>DF/NF (share): ${dfShare}% / ${nfShare}%</span>
            <span class="bl-mproj-ratios-sub">DF+NF only: ${dfOfRel}% / ${nfOfRel}%</span>
        </div>
    </button>`;
}

function renderStats() {
    const el = document.getElementById('topbarBacklinkStats');
    if (!el) return;

    const total = allRows.length;
    const online = allRows.filter((r) => r.is_online == 1).length;
    const offline = allRows.filter((r) => r.is_online == 0).length;
    const dofollow = allRows.filter((r) => String(r.link_type || '').toLowerCase() === 'dofollow').length;
    const nofollow = allRows.filter((r) => String(r.link_type || '').toLowerCase() === 'nofollow').length;
    const dfNfTotal = dofollow + nofollow;
    const dofollowPct = dfNfTotal ? ((dofollow / dfNfTotal) * 100).toFixed(1) : '0.0';
    const nofollowPct = dfNfTotal ? ((nofollow / dfNfTotal) * 100).toFixed(1) : '0.0';

    const items = [
        {
            icon: 'fa-link',
            tone: 'green',
            value: fmtNum(total),
            label: 'Backlinks',
            type: 'static',
        },
        {
            icon: 'fa-check-circle',
            tone: 'cyan',
            value: fmtNum(online),
            label: 'Online',
            type: 'static',
        },
        {
            icon: 'fa-times-circle',
            tone: 'red',
            value: fmtNum(offline),
            label: 'Offline',
            type: 'action',
            action: 'applyOfflineQuickFilter()',
            title: 'Show only backlinks that are currently offline',
        },
        {
            icon: 'fa-scale-balanced',
            tone: 'cyan',
            value: `${fmtNum(dofollow)} / ${fmtNum(nofollow)}`,
            label: `DF/NF ${dofollowPct}%`,
            type: 'static',
        },
    ];

    el.innerHTML = items.map((item) => {
        if (item.type === 'action') {
            return `<button type="button" class="topbar-stat-pill topbar-stat-pill--${item.tone}" onclick="${item.action}" title="${esc(item.title || '')}">
                <i class="fas ${item.icon}"></i>
                <span class="topbar-stat-pill__value">${item.value}</span>
                <span class="topbar-stat-pill__label">${item.label}</span>
            </button>`;
        }
        return `<div class="topbar-stat-pill topbar-stat-pill--${item.tone}">
            <i class="fas ${item.icon}"></i>
            <span class="topbar-stat-pill__value">${item.value}</span>
            <span class="topbar-stat-pill__label">${item.label}</span>
        </div>`;
    }).join('');
}

// â”€â”€ Filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function filterTable() {
    const global = document.getElementById('globalSearch').value.toLowerCase();
    const httpValue = getHttpFilterState();
    const sourceDomainValue = String(document.getElementById('sourceDomainFilter')?.value || '').toLowerCase();
    const linkStatusColState = getLinkStatusColumnFilterState();
    const colSearches = [...document.querySelectorAll('.col-search-input[data-col]')].map((input) => ({
        col: Number(input.dataset.col),
        val: String(input.value || '').toLowerCase(),
        op: document.querySelector(`.col-filter-op[data-col-op="${input.dataset.col}"]`)?.value || 'contains',
    }));

    filteredRows = allRows.filter((row) => {
        const cells = getCellTexts(row);
        if (global && !cells.join(' ').includes(global)) return false;
        if (sourceDomainValue && extractHost(row.source_url || '') !== sourceDomainValue) return false;

        if (httpValue) {
            const rowHttpValue = row.http_status == null || String(row.http_status).trim() === ''
                ? '_none'
                : String(Number(row.http_status));
            if (rowHttpValue !== httpValue) return false;
        }

        if (linkStatusColState?.mode === 'none') return false;
        if (linkStatusColState?.mode === 'some') {
            const statusKey = rowLinkStatusKey(row);
            if (!linkStatusColState.values.has(statusKey)) return false;
        }

        for (const cs of colSearches) {
            if (!cs.val) continue;
            const cellValue = String(cells[cs.col] || '');
            if (cs.col === 3 && cs.op === 'not_contains') {
                if (cellValue.includes(cs.val)) return false;
                continue;
            }
            if (cs.col === 4) {
                if (cellValue !== cs.val) return false;
                continue;
            }
            if (!cellValue.includes(cs.val)) return false;
        }

        return true;
    });
    sortFilteredRows();
    currentPage = 1;
    renderTable();
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

function populateSourceDomainFilter(rows) {
    const select = document.getElementById('sourceDomainFilter');
    if (!select) return;
    const prev = select.value || '';
    const domains = new Map();
    (rows || []).forEach((row) => {
        const domain = extractHost(row.source_url || '');
        if (!domain) return;
        const dr = Number.isFinite(Number(row.domain_rating)) ? Number(row.domain_rating) : 0;
        const current = domains.get(domain);
        if (!current || dr > current.dr) domains.set(domain, { domain, dr });
    });
    const options = [...domains.values()]
        .sort((a, b) => a.domain.localeCompare(b.domain))
        .map(({ domain, dr }) => {
            const drLabel = dr > 0 ? String(Math.round(dr)) : 'n/a';
            return `<option value="${esc(domain)}">${esc(domain)} (${drLabel})</option>`;
        });
    select.innerHTML = ['<option value="">All Domains</option>', ...options].join('');
    if ([...select.options].some((opt) => opt.value === prev)) select.value = prev;
}

// â”€â”€ Sort â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function sortTable(col) {
    if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
    else { sortCol = col; sortDir = col === 5 ? 'desc' : 'asc'; }
    sortFilteredRows();
    document.querySelectorAll('.data-table thead th').forEach((th, i) => {
        th.classList.remove('sort-asc', 'sort-desc');
        if (i === col) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });
    renderTable();
}

// â”€â”€ Render â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function renderTable() {
    const tbody = document.getElementById('tableBody');
    const start = (currentPage - 1) * perPage;
    const pageRows = filteredRows.slice(start, start + perPage);

    if (pageRows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="13" style="text-align:center;padding:50px;color:var(--zap-text-muted);">
            <i class="fas fa-link" style="font-size:2.5rem;opacity:0.2;display:block;margin-bottom:12px;"></i>
            ${allRows.length === 0
                ? 'No backlinks yet. <br><strong>Import a CSV</strong> or add one manually.'
                : 'No backlinks found.'}
        </td></tr>`;
    } else {
        tbody.innerHTML = pageRows.map(row => renderRow(row)).join('');
    }
    renderPagination();
    document.getElementById('tableInfo').textContent =
        `${filteredRows.length === allRows.length
            ? `${fmtNum(allRows.length)} backlinks`
            : `${fmtNum(filteredRows.length)} of ${fmtNum(allRows.length)}`}`;
}

function drBadge(val) {
    const v = Number.isFinite(Number(val)) ? Number(val) : 0;
    const cls = v >= 60 ? 'high' : v >= 30 ? 'medium' : v > 0 ? 'low' : 'none';
    return `<span class="dr-badge ${cls}">${Math.round(v)}</span>`;
}

function renderRow(row) {
    const isOnline = row.is_online == 1;
    const statusBadge = row.last_checked
        ? `<span class="status-badge ${isOnline ? 'online' : 'offline'}"><span class="status-dot ${isOnline ? 'online' : 'offline'}"></span>${isOnline ? 'Online' : 'Offline'}</span>`
        : `<span class="status-badge unknown"><span class="status-dot" style="background:#ffc800;"></span>Unknown</span>`;

    const httpCode = row.http_status
        ? `<span class="http-code ${row.http_status < 300 ? 'ok' : row.http_status < 400 ? 'redirect' : 'error'}">${row.http_status}</span>`
        : '<span style="color:var(--zap-text-muted)">-</span>';

    const lastChecked = row.last_checked
        ? `<span class="date-cell" title="${row.last_checked}">${formatDate(row.last_checked)}</span>`
        : '<span style="color:var(--zap-text-muted)">Never</span>';

    const firstSeen = row.first_seen
        ? `<span class="date-cell" title="Since: ${row.first_seen}">${row.first_seen}</span>`
        : '<span style="color:var(--zap-text-muted)">-</span>';

    const targetPath = formatTargetPath(row.target_url);

    return `<tr data-id="${row.id}">
        <td><span class="brand-badge ${(row.brand||'').toLowerCase()}">${esc(row.brand)}</span></td>
        <td class="url-cell"><a href="${esc(row.source_url)}" target="_blank" class="url-link" title="${esc(row.source_url)}">${esc(truncUrl(row.source_url))}</a></td>
        <td class="url-cell"><a href="${esc(row.target_url)}" target="_blank" class="url-link" title="${esc(row.target_url)}">${esc(targetPath)}</a></td>
        <td>${esc(row.anchor_text) || '<span style="color:var(--zap-text-muted)">-</span>'}</td>
        <td><span class="link-type-badge ${row.link_type}">${esc(row.link_type)}</span></td>
        <td>${drBadge(row.domain_rating)}</td>
        <td>${row.domain_authority ? `<strong>${parseFloat(row.domain_authority).toFixed(0)}</strong>` : '<span style="color:var(--zap-text-muted)">-</span>'}</td>
        <td>${statusBadge}</td>
        <td>${httpCode}</td>
        <td>${firstSeen}</td>
        <td>${lastChecked}</td>
        <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--zap-text-muted);" title="${esc(row.notes)}">${esc(row.notes) || ''}</td>
        <td>
            <div class="action-btns">
                <button class="action-btn" title="Edit" onclick="editBacklink(${row.id})"><i class="fas fa-pen"></i></button>
                <button class="action-btn" title="Check Link" onclick="checkSingleLink(${row.id})"><i class="fas fa-sync-alt"></i></button>
                <button class="action-btn delete" title="Delete" onclick="openDeleteModal(${row.id})"><i class="fas fa-trash"></i></button>
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
            html += `<span style="color:var(--zap-text-muted);padding:0 4px;">...</span>`;
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

// â”€â”€ Add/Edit CRUD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Backlink';
    document.getElementById('editId').value = '';
    document.getElementById('backlinkForm').reset();
    document.getElementById('fFirstSeen').value = new Date().toISOString().split('T')[0];
    openModal('backlinkModal');
}

function editBacklink(id) {
    const row = allRows.find(r => r.id == id);
    if (!row) return;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Backlink';
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
    if (!payload.source_url || !payload.target_url) { showToast('Source URL and Target URL are required', 'error'); return; }
    const method = id ? 'PUT' : 'POST';
    const url    = id ? apiUrl(`backlinks.php?id=${id}`) : apiUrl('backlinks.php');
    try {
        const res  = await fetch(url, { method, headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) { closeModal('backlinkModal'); showToast(id ? 'Backlink updated!' : 'Backlink added!'); loadBacklinks(); }
        else showToast(data.error || 'Error while saving', 'error');
    } catch (e) { showToast('Network error', 'error'); }
}

function openDeleteModal(id) { deleteId = id; openModal('deleteModal'); }

async function confirmDelete() {
    if (!deleteId) return;
    try {
        const res  = await fetch(apiUrl(`backlinks.php?id=${deleteId}`), { method: 'DELETE' });
        const data = await res.json();
        if (data.success) { closeModal('deleteModal'); showToast('Backlink deleted'); loadBacklinks(); }
        else showToast(data.error || 'Error', 'error');
    } catch (e) { showToast('Network error', 'error'); }
    deleteId = null;
}

async function checkSingleLink(id) {
    showToast('Checking link...');
    try {
        const res  = await fetch(apiUrl(`check_links.php?id=${id}`));
        const data = await res.json();
        if (data.success) { showToast('Status updated!'); loadBacklinks(); }
        else showToast(data.error || 'Error', 'error');
    } catch (e) { showToast('Network error', 'error'); }
}

async function checkAllLinks() {
    showToast('Starting backlink check...');
    try {
        const res = await fetch(apiUrl('check_links.php'));
        const data = await res.json();
        if (data.started) {
            const remaining = Number(data.remaining_before || 0);
            showToast(`Backlink check started${remaining ? ` (${remaining} links due today)` : ''}.`);
            setTimeout(() => { loadBacklinks(); }, 15000);
        } else if (data.already_running) {
            showToast('Backlink check is already running.');
        } else {
            showToast('Could not start backlink check', 'error');
        }
    } catch (e) { showToast('Network error', 'error'); }
}

// â”€â”€ CSV Import â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
let selectedFile = null;

function handleFileSelect(input) {
    const file = input.files[0];
    if (!file) return;
    selectedFile = file;
    const drop = document.getElementById('fileDrop');
    drop.classList.add('has-file');
    document.getElementById('fileDropText').textContent = `Selected: ${file.name} (${(file.size/1024).toFixed(1)} KB)`;
    document.getElementById('importBtn').disabled = false;

    // Preview first few lines
    const reader = new FileReader();
    reader.onload = e => {
        const lines = e.target.result.split('\n').slice(0, 4);
        const preview = document.getElementById('importPreview');
        preview.style.display = 'block';
        preview.innerHTML = `<strong>Preview (first 3 lines):</strong><br><code style="font-size:0.7rem;word-break:break-all;">${
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
    if (!selectedFile) { showToast('No file selected', 'error'); return; }
    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';

    const form = new FormData();
    form.append('csv',    selectedFile);
    form.append('brand',  document.getElementById('importBrand').value);
    form.append('target', document.getElementById('importTarget').value);

    try {
        const res  = await fetch(apiUrl('import_backlinks.php'), { method: 'POST', body: form });
        const data = await res.json();
        const result = document.getElementById('importResult');
        result.style.display = 'block';
        if (data.success) {
            result.className = 'import-result success';
            result.innerHTML = `
                <strong style="color:var(--zap-green)">Import completed successfully!</strong><br>
                <span>Inserted: <strong>${data.inserted}</strong> &nbsp;|&nbsp; Updated: <strong>${data.updated}</strong> &nbsp;|&nbsp; Skipped: <strong>${data.skipped}</strong></span>
                ${data.errors?.length ? `<br><span style="color:#ff8080;font-size:0.75rem;">Errors: ${data.errors.join('; ')}</span>` : ''}
                ${Object.keys(data.detected_columns||{}).length ? `<br><span style="font-size:0.75rem;color:var(--zap-text-muted);">Detected columns: ${Object.keys(data.detected_columns).join(', ')}</span>` : ''}
            `;
            showToast(`Import complete: ${data.inserted} inserted, ${data.updated} updated`);
            loadBacklinks();
        } else {
            result.className = 'import-result error';
            result.innerHTML = `<strong style="color:#ff5050">Error:</strong> ${esc(data.error)}`;
        }
    } catch (e) { showToast('Network error during import', 'error'); }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-upload"></i> Import';
}

// â”€â”€ Utils â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function normalizeBrokenText(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/â€/g, '')
        .replace(/â€“/g, '-')
        .replace(/â€”/g, '-')
        .replace(/â€¦/g, '...')
        .replace(/â€œ|â€|â€ž/g, '"')
        .replace(/â€˜|â€™/g, "'");
}
function esc(str) {
    if (!str) return '';
    return normalizeBrokenText(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function truncUrl(url, len = 45) {
    if (!url) return '';
    const cleanUrl = normalizeBrokenText(url);
    return cleanUrl.length > len ? cleanUrl.substring(0, len) + '...' : cleanUrl;
}
function formatTargetPath(url) {
    if (!url) return '/';
    try {
        const parsed = new URL(url);
        return `${parsed.pathname || '/'}${parsed.search || ''}${parsed.hash || ''}` || '/';
    } catch (e) {
        const stripped = String(url || '').replace(/^[a-z]+:\/\//i, '');
        const slashIndex = stripped.indexOf('/');
        return slashIndex >= 0 ? (stripped.slice(slashIndex) || '/') : '/';
    }
}
function fmtNum(n) { return Number(n||0).toLocaleString('de-DE').replace(/,/g, '.'); }
function formatDate(dt) {
    if (!dt) return '-';
    const d = new Date(dt), now = new Date(), diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
    return d.toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

// â”€â”€ Sort headers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('.data-table thead th.sortable').forEach((th, i) => {
    th.addEventListener('click', () => sortTable(i));
});

/** Offline-Kachel: Link-Ziel bleibt fÃ¼r Strg/Mittelklick; normaler Klick filtert die Tabelle (kein Inline-Handler, CSP-freundlich). */


loadBacklinks();




