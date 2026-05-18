let allRows = [];
let filteredRows = [];
let currentPage = 1;
const perPage = 25;
let deleteId = null;
const offlinePageParams = new URLSearchParams(window.location.search);
const DASHBOARD_BRAND_SCOPE = String(window.DASHBOARD_BRAND_SCOPE || '').toUpperCase();
const DASHBOARD_DOMAIN_SCOPE = Array.isArray(window.DASHBOARD_DOMAIN_SCOPE)
    ? window.DASHBOARD_DOMAIN_SCOPE.map((domain) => String(domain || '').toLowerCase())
    : [];
let initialHttpApplied = false;

function getInitialLinkType() {
    const value = String(offlinePageParams.get('link_type') || '').toLowerCase();
    if (value === 'nofollow' || value === 'dofollow') return value;
    return 'dofollow';
}

function getInitialHttpFilter() {
    const value = String(offlinePageParams.get('http_status') || '').trim().toLowerCase();
    if (value === 'none' || value === '_none') return '_none';
    return value !== '' ? value : '';
}

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
    const sel = document.getElementById('httpFilter');
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '<option value="">All HTTP Statuses</option>';
    (options || []).forEach((v) => {
        if (v === null || v === undefined) {
            sel.insertAdjacentHTML('beforeend', '<option value="_none">- / no status</option>');
        } else {
            sel.insertAdjacentHTML('beforeend', `<option value="${Number(v)}">${Number(v)}</option>`);
        }
    });
    let nextValue = prev;
    if (!initialHttpApplied && !prev) {
        nextValue = getInitialHttpFilter();
        initialHttpApplied = true;
    }
    const ok = [...sel.options].some((o) => o.value === nextValue);
    sel.value = ok ? nextValue : '';
}

function buildOfflineApiUrl() {
    let url = 'api/backlinks.php?status=offline&sort=dr_desc';
    const lt = document.getElementById('linkTypeFilter')?.value || '';
    if (lt === 'dofollow' || lt === 'nofollow') {
        url += '&link_type=' + encodeURIComponent(lt);
    }
    const http = document.getElementById('httpFilter')?.value || '';
    if (http === '_none') {
        url += '&http_status=none';
    } else if (http !== '') {
        url += '&http_status=' + encodeURIComponent(http);
    }
    return url;
}

async function loadOffline() {
    try {
        const res = await fetch(buildOfflineApiUrl());
        const data = await res.json();
        allRows = (data.data || []).filter(isBacklinkInScope);
        populateHttpFilter(
            data.http_status_options && data.http_status_options.length
                ? data.http_status_options
                : deriveHttpOptionsFromRows(allRows)
        );
        filteredRows = [...allRows];
        sortByDrDesc(filteredRows);
        currentPage = 1;
        const count = allRows.length;
        const el = document.getElementById('offlineCountBadge');
        if (el) el.textContent = count ? `${fmtNum(count)} Offline` : '0 Offline';
        renderTable();
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

function sortByDrDesc(rows) {
    rows.sort((a, b) => {
        const da = Number(a?.domain_rating ?? -1);
        const db = Number(b?.domain_rating ?? -1);
        if (da === db) {
            const ta = Date.parse(a?.last_checked || a?.first_seen || '') || 0;
            const tb = Date.parse(b?.last_checked || b?.first_seen || '') || 0;
            return tb - ta;
        }
        return db - da;
    });
}

function renderTable() {
    const tbody = document.getElementById('tableBody');
    const start = (currentPage - 1) * perPage;
    const pageRows = filteredRows.slice(start, start + perPage);

    if (pageRows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="13" style="text-align:center;padding:50px;color:var(--zap-text-muted);">
            <i class="fas fa-unlink" style="font-size:2.5rem;opacity:0.2;display:block;margin-bottom:12px;"></i>
            No offline backlinks${allRows.length === 0 ? '' : ' for this filter'}.<br>
            <span style="font-size:0.85rem;">Sorted by Domain Rating (highest first).</span>
        </td></tr>`;
    } else {
        tbody.innerHTML = pageRows.map((row) => renderRow(row)).join('');
    }
    renderPagination();
    const info = document.getElementById('tableInfo');
    if (info) {
        const lt = document.getElementById('linkTypeFilter')?.value || '';
        const ltPart = lt === 'dofollow' ? '. Dofollow only' : lt === 'nofollow' ? '. Nofollow only' : '. All link types';
        info.textContent = `${fmtNum(filteredRows.length)} offline backlink${filteredRows.length === 1 ? '' : 's'}${ltPart} | DR desc`;
    }
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
        : '<span class="status-badge unknown"><span class="status-dot" style="background:#ffc800;"></span>Unknown</span>';

    const httpCode = row.http_status
        ? `<span class="http-code ${row.http_status < 300 ? 'ok' : row.http_status < 400 ? 'redirect' : 'error'}">${row.http_status}</span>`
        : '<span style="color:var(--zap-text-muted)">-</span>';

    const lastChecked = row.last_checked
        ? `<span class="date-cell" title="${row.last_checked}">${formatDate(row.last_checked)}</span>`
        : '<span style="color:var(--zap-text-muted)">Never</span>';

    const firstSeen = row.first_seen
        ? `<span class="date-cell" title="Since: ${row.first_seen}">${row.first_seen}</span>`
        : '<span style="color:var(--zap-text-muted)">-</span>';

    return `<tr data-id="${row.id}">
        <td><span class="brand-badge ${(row.brand || '').toLowerCase()}">${esc(row.brand)}</span></td>
        <td class="url-cell"><a href="${esc(row.source_url)}" target="_blank" class="url-link" title="${esc(row.source_url)}">${esc(truncUrl(row.source_url))}</a></td>
        <td class="url-cell"><a href="${esc(row.target_url)}" target="_blank" class="url-link" title="${esc(row.target_url)}">${esc(truncUrl(row.target_url))}</a></td>
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

function editBacklink(id) {
    const row = allRows.find((r) => r.id == id);
    if (!row) return;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Backlink';
    document.getElementById('editId').value = id;
    document.getElementById('fBrand').value = row.brand;
    document.getElementById('fLinkType').value = row.link_type;
    document.getElementById('fSourceUrl').value = row.source_url;
    document.getElementById('fTargetUrl').value = row.target_url;
    document.getElementById('fAnchor').value = row.anchor_text || '';
    document.getElementById('fDR').value = row.domain_rating || '';
    document.getElementById('fDA').value = row.domain_authority || '';
    document.getElementById('fFirstSeen').value = row.first_seen || '';
    document.getElementById('fNotes').value = row.notes || '';
    openModal('backlinkModal');
}

async function saveBacklink() {
    const id = document.getElementById('editId').value;
    const payload = {
        brand: document.getElementById('fBrand').value,
        link_type: document.getElementById('fLinkType').value,
        source_url: document.getElementById('fSourceUrl').value,
        target_url: document.getElementById('fTargetUrl').value,
        anchor_text: document.getElementById('fAnchor').value,
        domain_rating: document.getElementById('fDR').value || null,
        domain_authority: document.getElementById('fDA').value || null,
        first_seen: document.getElementById('fFirstSeen').value || null,
        notes: document.getElementById('fNotes').value,
    };
    if (!payload.source_url || !payload.target_url) {
        showToast('Source URL and Target URL are required', 'error');
        return;
    }
    const method = id ? 'PUT' : 'POST';
    const url = id ? `api/backlinks.php?id=${id}` : 'api/backlinks.php';
    try {
        const res = await fetch(url, { method, headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) {
            closeModal('backlinkModal');
            showToast(id ? 'Backlink updated!' : 'Backlink added!');
            loadOffline();
        } else {
            showToast(data.error || 'Error while saving', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
}

function openDeleteModal(id) {
    deleteId = id;
    openModal('deleteModal');
}

async function confirmDelete() {
    if (!deleteId) return;
    try {
        const res = await fetch(`api/backlinks.php?id=${deleteId}`, { method: 'DELETE' });
        const data = await res.json();
        if (data.success) {
            closeModal('deleteModal');
            showToast('Backlink deleted');
            loadOffline();
        } else {
            showToast(data.error || 'Error', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
    deleteId = null;
}

async function checkSingleLink(id) {
    showToast('Checking link...');
    try {
        const res = await fetch(`api/check_links.php?id=${id}`);
        const data = await res.json();
        if (data.success) {
            showToast('Status updated!');
            loadOffline();
        } else {
            showToast(data.error || 'Error', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
}

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
    return normalizeBrokenText(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function truncUrl(url, len = 45) {
    if (!url) return '';
    const cleanUrl = normalizeBrokenText(url);
    return cleanUrl.length > len ? cleanUrl.substring(0, len) + '...' : cleanUrl;
}

function fmtNum(n) {
    return Number(n || 0).toLocaleString('de-DE').replace(/,/g, '.');
}

function formatDate(dt) {
    if (!dt) return '-';
    const d = new Date(dt);
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return d.toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const lt = document.getElementById('linkTypeFilter');
    if (lt) lt.value = getInitialLinkType();
    if (lt) lt.addEventListener('change', () => loadOffline());
    const hf = document.getElementById('httpFilter');
    if (hf) hf.addEventListener('change', () => loadOffline());
    loadOffline();
});
