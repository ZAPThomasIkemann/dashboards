let allRows = [];
let filteredRows = [];
let currentPage = 1;
const perPage = 25;
let sortCol = 10;
let sortDir = 'desc';
let deleteId = null;

// ── Load ─────────────────────────────────────────────────────
async function loadBacklinks() {
    try {
        const res = await fetch('api/backlinks.php');
        const data = await res.json();
        allRows = data.data || [];
        renderStats();
        filteredRows = [...allRows];
        filterTable();
    } catch (e) {
        document.getElementById('tableBody').innerHTML =
            '<tr><td colspan="13" style="text-align:center;padding:40px;color:#ff5050;"><i class="fas fa-exclamation-circle"></i> Fehler beim Laden</td></tr>';
    }
}

// ── Stats strip ───────────────────────────────────────────────
function renderStats() {
    const total   = allRows.length;
    const online  = allRows.filter(r => r.is_online == 1).length;
    const offline = allRows.filter(r => r.is_online == 0 && r.last_checked).length;
    const zap     = allRows.filter(r => r.brand === 'ZAP').length;
    const dmc     = allRows.filter(r => r.brand === 'DMC').length;

    document.getElementById('blStats').innerHTML = `
        <div class="stat-card"><div class="stat-icon green"><i class="fas fa-link"></i></div><div class="stat-info"><div class="stat-value">${fmtNum(total)}</div><div class="stat-label">Backlinks gesamt</div></div></div>
        <div class="stat-card"><div class="stat-icon cyan"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="stat-value">${fmtNum(online)}</div><div class="stat-label">Online</div></div></div>
        <div class="stat-card"><div class="stat-icon red"><i class="fas fa-times-circle"></i></div><div class="stat-info"><div class="stat-value">${fmtNum(offline)}</div><div class="stat-label">Offline</div></div></div>
        <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-bolt"></i></div><div class="stat-info"><div class="stat-value">${fmtNum(zap)}</div><div class="stat-label">ZAP</div></div></div>
        <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-fire"></i></div><div class="stat-info"><div class="stat-value">${fmtNum(dmc)}</div><div class="stat-label">DMC</div></div></div>
    `;
}

// ── Filter ────────────────────────────────────────────────────
function filterTable() {
    const global = document.getElementById('globalSearch').value.toLowerCase();
    const domain = document.getElementById('domainFilter').value.toLowerCase();
    const brand  = document.getElementById('brandFilter').value.toLowerCase();
    const status = document.getElementById('statusFilter').value.toLowerCase();
    const type   = document.getElementById('typeFilter').value.toLowerCase();
    const colSearches = [...document.querySelectorAll('.col-search-input')].map(i => ({col: +i.dataset.col, val: i.value.toLowerCase()}));

    filteredRows = allRows.filter(row => {
        const cells = getCellTexts(row);
        if (global && !cells.join(' ').includes(global)) return false;
        if (domain) {
            // Filter by domain in source_url or target_url
            const src = (row.source_url || '').toLowerCase();
            const tgt = (row.target_url || '').toLowerCase();
            if (!src.includes(domain) && !tgt.includes(domain)) return false;
        }
        if (brand && (row.brand || '').toLowerCase() !== brand) return false;
        if (status) {
            const isOnline = row.is_online == 1 ? 'online' : 'offline';
            if (!row.last_checked) return false;
            if (isOnline !== status) return false;
        }
        if (type && (row.link_type || '').toLowerCase() !== type) return false;
        for (const cs of colSearches) {
            if (cs.val && !(cells[cs.col] || '').includes(cs.val)) return false;
        }
        return true;
    });
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
        row.is_online == 1 ? 'online' : 'offline',
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

loadBacklinks();
