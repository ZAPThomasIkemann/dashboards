<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DMC Backlinks</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--bg:#08111c;--surface:#0e1b2b;--surface2:#111f31;--border:rgba(255,255,255,.09);--text:#e9f2fb;--muted:#9fb4c9;--cyan:#00d7ff;--cyan2:#00a8c8;--green:#18e888;--red:#ff4d6d;--radius:10px;--fs:13px}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:Poppins,system-ui,sans-serif;font-size:var(--fs);min-height:100vh}
.toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px 14px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:10}
.toolbar input,.toolbar select{background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:5px 10px;font-size:12px;outline:none;min-width:130px}
.toolbar input:focus,.toolbar select:focus{border-color:var(--cyan)}
.count-badge{margin-left:auto;background:rgba(0,215,255,.12);color:var(--cyan);border:1px solid rgba(0,215,255,.25);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;white-space:nowrap}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{background:var(--surface);color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding:9px 10px;border-bottom:1px solid var(--border);white-space:nowrap;cursor:pointer;user-select:none}
thead th:hover{color:var(--text)}
thead th .si{display:inline-block;width:12px;text-align:center;opacity:.4}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:var(--surface2)}
td{padding:8px 10px;vertical-align:middle;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.url-cell{max-width:200px}
.url-cell a{color:var(--cyan);text-decoration:none;font-size:12px}
.url-cell a:hover{text-decoration:underline}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase}
.badge-df{background:rgba(24,232,136,.15);color:var(--green)}
.badge-nf{background:rgba(255,77,109,.12);color:var(--red)}
.badge-sp{background:rgba(255,165,0,.12);color:#ffaa00}
.badge-ug{background:rgba(160,160,255,.12);color:#b0b0ff}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot-on{background:var(--green)}
.dot-off{background:var(--red)}
.dot-unk{background:var(--muted)}
.status-cell{display:flex;align-items:center;gap:5px}
.empty{text-align:center;padding:40px;color:var(--muted)}
.spinner{width:28px;height:28px;border:3px solid var(--border);border-top-color:var(--cyan);border-radius:50%;animation:spin .7s linear infinite;margin:40px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.footer{padding:8px 14px;color:var(--muted);font-size:11px;border-top:1px solid var(--border)}
</style>
</head>
<body>

<?php $prefilterDomain = trim($_GET['domain'] ?? ''); ?>

<div class="toolbar">
    <input type="text" id="searchInput" placeholder="URL / Anchor suchen…" oninput="applyFilters()">
    <select id="domainFilter" onchange="applyFilters()">
        <option value="">Alle Domains</option>
        <?php foreach (DMC_DOMAINS as $d => $label): ?>
        <option value="<?= htmlspecialchars($d) ?>" <?= $d === $prefilterDomain ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?> (<?= htmlspecialchars($d) ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <select id="typeFilter" onchange="applyFilters()">
        <option value="">Alle Typen</option>
        <option value="dofollow">Dofollow</option>
        <option value="nofollow">Nofollow</option>
        <option value="sponsored">Sponsored</option>
        <option value="ugc">UGC</option>
    </select>
    <select id="statusFilter" onchange="applyFilters()">
        <option value="">Alle Status</option>
        <option value="1">Online</option>
        <option value="0">Offline</option>
    </select>
    <span class="count-badge" id="countBadge">…</span>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th onclick="sortBy(0)">Source URL <span class="si" id="si0"></span></th>
    <th onclick="sortBy(1)">Target URL <span class="si" id="si1"></span></th>
    <th onclick="sortBy(2)">Anchor <span class="si" id="si2"></span></th>
    <th onclick="sortBy(3)">Typ <span class="si" id="si3"></span></th>
    <th onclick="sortBy(4)">DR <span class="si" id="si4"></span></th>
    <th onclick="sortBy(5)">DA <span class="si" id="si5"></span></th>
    <th onclick="sortBy(6)">Status <span class="si" id="si6"></span></th>
    <th onclick="sortBy(7)">First Seen <span class="si" id="si7"></span></th>
    <th onclick="sortBy(8)">Geprüft <span class="si" id="si8"></span></th>
</tr>
</thead>
<tbody id="tbody">
<tr><td colspan="9"><div class="spinner"></div></td></tr>
</tbody>
</table>
</div>
<div class="footer" id="footer"></div>

<script>
let allRows = [], sortCol = -1, sortDir = 1;
const PREFILTER_DOMAIN = <?= json_encode($prefilterDomain) ?>;

const badgeClass = t => ({dofollow:'badge-df',nofollow:'badge-nf',sponsored:'badge-sp',ugc:'badge-ug'}[t] || 'badge-df');
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const host = u => { try { return new URL(u).hostname; } catch { return u; } };
const dateStr = v => v ? new Date(v).toLocaleDateString('de-DE') : '-';

async function load() {
    const url = '/dashboards/dmc/api/backlinks.php' + (PREFILTER_DOMAIN ? '?domain=' + encodeURIComponent(PREFILTER_DOMAIN) : '');
    const res = await fetch(url).then(r => r.json()).catch(() => ({success:false}));
    allRows = res.success ? res.data : [];
    document.getElementById('domainFilter').value = PREFILTER_DOMAIN;
    applyFilters();
}

function applyFilters() {
    const q    = document.getElementById('searchInput').value.toLowerCase();
    const dom  = document.getElementById('domainFilter').value;
    const typ  = document.getElementById('typeFilter').value;
    const stat = document.getElementById('statusFilter').value;

    let rows = allRows.filter(r => {
        if (q && ![r.source_url,r.target_url,r.anchor_text].some(v => String(v||'').toLowerCase().includes(q))) return false;
        if (dom && !String(r.target_url||'').includes(dom) && !String(r.source_url||'').includes(dom)) return false;
        if (typ && r.link_type !== typ) return false;
        if (stat !== '' && String(r.is_online) !== stat) return false;
        return true;
    });

    if (sortCol >= 0) rows = sortRows(rows);

    document.getElementById('countBadge').textContent = rows.length + ' Backlinks';
    document.getElementById('footer').textContent = rows.length + ' von ' + allRows.length + ' Einträgen';

    const tbody = document.getElementById('tbody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="empty"><i class="fas fa-link"></i><br>Keine Backlinks gefunden.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(r => `<tr>
        <td class="url-cell"><a href="${esc(r.source_url)}" target="_blank" rel="noreferrer" title="${esc(r.source_url)}">${esc(host(r.source_url))}</a></td>
        <td class="url-cell"><a href="${esc(r.target_url)}" target="_blank" rel="noreferrer" title="${esc(r.target_url)}">${esc(host(r.target_url))}</a></td>
        <td title="${esc(r.anchor_text)}">${esc(r.anchor_text) || '<span style="color:var(--muted)">—</span>'}</td>
        <td><span class="badge ${badgeClass(r.link_type)}">${esc(r.link_type||'dofollow')}</span></td>
        <td>${r.domain_rating != null ? parseFloat(r.domain_rating).toFixed(0) : '—'}</td>
        <td>${r.domain_authority != null ? parseFloat(r.domain_authority).toFixed(0) : '—'}</td>
        <td><div class="status-cell"><span class="dot ${r.is_online==1?'dot-on':r.is_online==0?'dot-off':'dot-unk'}"></span>${r.is_online==1?'Online':r.is_online==0?'Offline':'—'}</div></td>
        <td>${dateStr(r.first_seen)}</td>
        <td>${dateStr(r.last_checked)}</td>
    </tr>`).join('');
}

const COLS = ['source_url','target_url','anchor_text','link_type','domain_rating','domain_authority','is_online','first_seen','last_checked'];
function sortRows(rows) {
    const col = COLS[sortCol];
    return [...rows].sort((a,b) => {
        const av = a[col] ?? '', bv = b[col] ?? '';
        return (av > bv ? 1 : av < bv ? -1 : 0) * sortDir;
    });
}
function sortBy(col) {
    if (sortCol === col) sortDir = -sortDir; else { sortCol = col; sortDir = 1; }
    document.querySelectorAll('[id^="si"]').forEach(el => el.textContent = '');
    document.getElementById('si' + col).textContent = sortDir === 1 ? '↑' : '↓';
    applyFilters();
}

load();
</script>
</body>
</html>
