<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DMC Rankings</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--bg:#08111c;--surface:#0e1b2b;--surface2:#111f31;--border:rgba(255,255,255,.09);--text:#e9f2fb;--muted:#9fb4c9;--cyan:#00d7ff;--green:#18e888;--red:#ff4d6d;--orange:#ffaa00;--fs:13px}
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
td{padding:8px 10px;vertical-align:middle;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.url-cell a{color:var(--cyan);text-decoration:none;font-size:12px}
.url-cell a:hover{text-decoration:underline}
.pos{font-weight:700;font-size:15px;text-align:center}
.pos-top3{color:var(--green)}
.pos-top10{color:var(--cyan)}
.pos-top20{color:var(--orange)}
.change-up{color:var(--green);font-size:11px}
.change-dn{color:var(--red);font-size:11px}
.change-eq{color:var(--muted);font-size:11px}
.change-new{color:var(--cyan);font-size:10px;font-weight:700}
.empty{text-align:center;padding:40px;color:var(--muted)}
.spinner{width:28px;height:28px;border:3px solid var(--border);border-top-color:var(--cyan);border-radius:50%;animation:spin .7s linear infinite;margin:40px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.footer{padding:8px 14px;color:var(--muted);font-size:11px;border-top:1px solid var(--border)}
</style>
</head>
<body>

<?php $prefilterDomain = trim($_GET['domain'] ?? ''); ?>

<div class="toolbar">
    <input type="text" id="kwSearch" placeholder="Keyword suchen…" oninput="applyFilters()">
    <select id="domainFilter" onchange="applyFilters()">
        <option value="">Alle Domains</option>
        <?php foreach (DMC_DOMAINS as $d => $label): ?>
        <option value="<?= htmlspecialchars($d) ?>" <?= $d === $prefilterDomain ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?> (<?= htmlspecialchars($d) ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <select id="posFilter" onchange="applyFilters()">
        <option value="">Alle Positionen</option>
        <option value="1-3">Top 3</option>
        <option value="1-10">Top 10</option>
        <option value="1-20">Top 20</option>
        <option value="1-50">Top 50</option>
        <option value="1-100">Top 100</option>
    </select>
    <span class="count-badge" id="countBadge">…</span>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th onclick="sortBy(0)">Domain <span class="si" id="si0"></span></th>
    <th onclick="sortBy(1)">Keyword <span class="si" id="si1"></span></th>
    <th onclick="sortBy(2)">Position <span class="si" id="si2"></span></th>
    <th>Änderung</th>
    <th onclick="sortBy(4)">Volumen <span class="si" id="si4"></span></th>
    <th onclick="sortBy(5)">CPC <span class="si" id="si5"></span></th>
    <th>URL</th>
    <th onclick="sortBy(7)">Geprüft <span class="si" id="si7"></span></th>
</tr>
</thead>
<tbody id="tbody">
<tr><td colspan="8"><div class="spinner"></div></td></tr>
</tbody>
</table>
</div>
<div class="footer" id="footer"></div>

<script>
let allRows = [], sortCol = 2, sortDir = 1;
const PREFILTER_DOMAIN = <?= json_encode($prefilterDomain) ?>;

const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const dateStr = v => v ? new Date(v).toLocaleDateString('de-DE') : '-';
const num = v => v != null ? Number(v).toLocaleString('de-DE') : '—';

function posClass(p) {
    if (!p) return '';
    p = parseInt(p);
    if (p <= 3) return 'pos-top3';
    if (p <= 10) return 'pos-top10';
    if (p <= 20) return 'pos-top20';
    return '';
}
function changeHtml(pos, prev) {
    if (pos == null) return '<span class="change-eq">—</span>';
    if (prev == null) return '<span class="change-new">NEU</span>';
    const d = parseInt(prev) - parseInt(pos);
    if (d > 0) return `<span class="change-up">▲ ${d}</span>`;
    if (d < 0) return `<span class="change-dn">▼ ${Math.abs(d)}</span>`;
    return '<span class="change-eq">—</span>';
}

async function load() {
    const url = '/dashboards/dmc/api/rankings.php' + (PREFILTER_DOMAIN ? '?domain=' + encodeURIComponent(PREFILTER_DOMAIN) : '');
    const res = await fetch(url).then(r => r.json()).catch(() => ({success:false, data:[]}));
    allRows = res.success ? res.data : [];
    document.getElementById('domainFilter').value = PREFILTER_DOMAIN;
    applyFilters();
}

function applyFilters() {
    const q   = document.getElementById('kwSearch').value.toLowerCase();
    const dom = document.getElementById('domainFilter').value;
    const pos = document.getElementById('posFilter').value;

    let rows = allRows.filter(r => {
        if (q && !String(r.keyword||'').toLowerCase().includes(q)) return false;
        if (dom && r.domain !== dom) return false;
        if (pos) {
            const [lo, hi] = pos.split('-').map(Number);
            const p = parseInt(r.position);
            if (isNaN(p) || p < lo || p > hi) return false;
        }
        return true;
    });

    if (sortCol >= 0) rows = sortRows(rows);

    document.getElementById('countBadge').textContent = rows.length + ' Keywords';
    document.getElementById('footer').textContent = rows.length + ' von ' + allRows.length + ' Einträgen';

    const tbody = document.getElementById('tbody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty"><i class="fas fa-chart-line"></i><br>Keine Ranking-Daten vorhanden.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(r => `<tr>
        <td title="${esc(r.domain)}">${esc(r.domain)}</td>
        <td title="${esc(r.keyword)}">${esc(r.keyword)}</td>
        <td class="pos ${posClass(r.position)}" style="text-align:center">${r.position != null ? r.position : '—'}</td>
        <td>${changeHtml(r.position, r.previous_position)}</td>
        <td>${num(r.search_volume)}</td>
        <td>${r.cpc != null ? parseFloat(r.cpc).toFixed(2) + ' €' : '—'}</td>
        <td class="url-cell">${r.url ? `<a href="${esc(r.url)}" target="_blank" rel="noreferrer" title="${esc(r.url)}">${esc(r.url)}</a>` : '—'}</td>
        <td>${dateStr(r.checked_at)}</td>
    </tr>`).join('');
}

const COLS = ['domain','keyword','position',null,'search_volume','cpc',null,'checked_at'];
function sortRows(rows) {
    const col = COLS[sortCol];
    if (!col) return rows;
    return [...rows].sort((a,b) => {
        let av = a[col] ?? '', bv = b[col] ?? '';
        if (!isNaN(parseFloat(av)) && !isNaN(parseFloat(bv))) { av = parseFloat(av); bv = parseFloat(bv); }
        return (av > bv ? 1 : av < bv ? -1 : 0) * sortDir;
    });
}
function sortBy(col) {
    if (COLS[col] == null) return;
    if (sortCol === col) sortDir = -sortDir; else { sortCol = col; sortDir = 1; }
    document.querySelectorAll('[id^="si"]').forEach(el => el.textContent = '');
    document.getElementById('si' + col).textContent = sortDir === 1 ? '↑' : '↓';
    applyFilters();
}

load();
</script>
</body>
</html>
