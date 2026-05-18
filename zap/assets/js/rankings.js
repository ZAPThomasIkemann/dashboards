let allRankings = [];
let filteredRankings = [];
let rankingsSummary = [];
let rankingsHistory = [];
let rankingHistoryByKey = new Map();
let rankingsTrendChart = null;
let lowHangingOnly = false;
let rPage = 1;
let rSortCol = 4;
let rSortDir = 'asc';
const BRAND_KEYWORD_TERMS = ['zap', 'dmc', 'europamaut'];
const rankingPageParams = new URLSearchParams(window.location.search);
const DASHBOARD_BRAND_SCOPE = String(window.DASHBOARD_BRAND_SCOPE || '').toUpperCase();
const DASHBOARD_DOMAIN_SCOPE = Array.isArray(window.DASHBOARD_DOMAIN_SCOPE)
    ? window.DASHBOARD_DOMAIN_SCOPE.map((domain) => String(domain || '').toLowerCase())
    : [];
const DASHBOARD_API_BASE = String(window.DASHBOARD_API_BASE || 'api').replace(/\/$/, '');
const RANKINGS_API_PATH = String(window.RANKINGS_API_PATH || 'rankings.php');
const RANKINGS_SYNC_ENABLED = window.RANKINGS_SYNC_ENABLED !== false;
const RANKINGS_READ_ONLY = window.RANKINGS_READ_ONLY === true;
const RANKINGS_VIEW = String(window.RANKINGS_VIEW || 'discovery');
const IS_DAILY_VIEW = RANKINGS_VIEW === 'daily';
const DAILY_DEFAULT_DATE = String(window.DAILY_DEFAULT_DATE || '');
let rankingsChartHidden = false;

function apiUrl(path) {
    return `${DASHBOARD_API_BASE}/${String(path || '').replace(/^\/+/, '')}`;
}

function rankingsApiUrl() {
    const basePath = (/^(?:https?:)?\/\//.test(RANKINGS_API_PATH) || RANKINGS_API_PATH.startsWith('/'))
        ? RANKINGS_API_PATH
        : apiUrl(RANKINGS_API_PATH);
    const url = new URL(basePath, window.location.origin);

    if (IS_DAILY_VIEW) {
        const mode = document.getElementById('dailyViewMode')?.value || 'day';
        const snapshotDate = document.getElementById('dailySnapshotDate')?.value || DAILY_DEFAULT_DATE;
        const fromDate = document.getElementById('dailyFromDate')?.value || snapshotDate || DAILY_DEFAULT_DATE;
        const toDate = document.getElementById('dailyToDate')?.value || fromDate || DAILY_DEFAULT_DATE;

        url.searchParams.delete('snapshot_date');
        url.searchParams.delete('from_date');
        url.searchParams.delete('to_date');

        if (mode === 'range') {
            if (fromDate) url.searchParams.set('from_date', fromDate);
            if (toDate) url.searchParams.set('to_date', toDate);
        } else if (snapshotDate) {
            url.searchParams.set('snapshot_date', snapshotDate);
        }
    }

    return url.toString();
}

async function loadRankings() {
    try {
        const res = await fetch(rankingsApiUrl());
        const data = await res.json();
        allRankings = (data.data || []).filter(isRankingInScope);
        rankingsSummary = (data.summary || []).filter(isRankingSummaryInScope);
        rankingsHistory = (data.history || []).filter(isRankingHistoryInScope);
        buildHistoryIndex();
        initTrendControls();
        applyInitialRankingFilters();
        filteredRankings = [...allRankings];
        renderDomainSummary();
        filterRankings();
    } catch (e) {
        document.getElementById('rankingsBody').innerHTML =
            '<tr><td colspan="10" style="text-align:center;padding:40px;color:#ff5050;"><i class="fas fa-exclamation-circle"></i> Failed to load rankings</td></tr>';
    }
}

function normalizeScopeDomain(value) {
    return String(value || '').toLowerCase().replace(/^www\./, '');
}

function isScopedDomain(domain) {
    if (!DASHBOARD_DOMAIN_SCOPE.length) return true;
    return DASHBOARD_DOMAIN_SCOPE.includes(normalizeScopeDomain(domain));
}

function isScopedBrand(brand) {
    if (!DASHBOARD_BRAND_SCOPE) return true;
    return String(brand || '').toUpperCase() === DASHBOARD_BRAND_SCOPE;
}

function isRankingInScope(row) {
    return isScopedBrand(row?.brand) && isScopedDomain(row?.domain);
}

function isRankingSummaryInScope(row) {
    return isScopedBrand(row?.brand) && isScopedDomain(row?.domain);
}

function isRankingHistoryInScope(row) {
    return isScopedBrand(row?.brand) && isScopedDomain(row?.domain);
}

function applyInitialRankingFilters() {
    const brand = rankingPageParams.get('brand');
    if (brand) {
        const brandSelect = document.getElementById('brandFilterR');
        if (brandSelect) brandSelect.value = brand.toUpperCase();
    }
}

function buildHistoryIndex() {
    rankingHistoryByKey = new Map();
    rankingsHistory.forEach((row) => {
        const key = historyKey(row);
        if (!rankingHistoryByKey.has(key)) rankingHistoryByKey.set(key, []);
        rankingHistoryByKey.get(key).push(row);
    });
    rankingHistoryByKey.forEach((rows) => rows.sort((a, b) => String(a.checked_at || '').localeCompare(String(b.checked_at || ''))));
}

function initTrendControls() {
    if (document.getElementById('trendStartDate')?.value) return;
    const range = getPresetRange('6m');
    document.getElementById('trendStartDate').value = toInputDate(range.start);
    document.getElementById('trendEndDate').value = toInputDate(range.end);
}

function handleTrendRangeChange() {
    const preset = document.getElementById('trendRange')?.value || '6m';
    if (preset !== 'custom') {
        const range = getPresetRange(preset);
        document.getElementById('trendStartDate').value = toInputDate(range.start);
        document.getElementById('trendEndDate').value = toInputDate(range.end);
    }
    renderTrendChart();
}

function handleTrendDateChange() {
    const rangeSelect = document.getElementById('trendRange');
    if (rangeSelect) rangeSelect.value = 'custom';
    renderTrendChart();
}

function handleDailyFilterModeChange() {
    const mode = document.getElementById('dailyViewMode')?.value || 'day';
    const dayInput = document.getElementById('dailySnapshotDate');
    const fromInput = document.getElementById('dailyFromDate');
    const toInput = document.getElementById('dailyToDate');
    if (dayInput) dayInput.style.display = mode === 'day' ? '' : 'none';
    if (fromInput) fromInput.style.display = mode === 'range' ? '' : 'none';
    if (toInput) toInput.style.display = mode === 'range' ? '' : 'none';
    updateDailyFilterInfo();
}

function handleDailyFilterInputChange() {
    updateDailyFilterInfo();
}

function updateDailyFilterInfo() {
    const info = document.getElementById('dailyFilterInfo');
    if (!info || !IS_DAILY_VIEW) return;
    const mode = document.getElementById('dailyViewMode')?.value || 'day';
    const day = document.getElementById('dailySnapshotDate')?.value || DAILY_DEFAULT_DATE;
    const from = document.getElementById('dailyFromDate')?.value || day || DAILY_DEFAULT_DATE;
    const to = document.getElementById('dailyToDate')?.value || from || DAILY_DEFAULT_DATE;
    info.textContent = mode === 'range'
        ? `Showing snapshots from ${from} to ${to}`
        : `Showing snapshots for ${day}`;
}

function applyDailyFilters() {
    if (!IS_DAILY_VIEW) return;
    const mode = document.getElementById('dailyViewMode')?.value || 'day';
    const url = new URL(window.location.href);
    url.searchParams.set('view', 'daily');
    url.searchParams.set('daily_mode', mode);
    url.searchParams.delete('snapshot_date');
    url.searchParams.delete('from_date');
    url.searchParams.delete('to_date');
    if (mode === 'range') {
        const from = document.getElementById('dailyFromDate')?.value || '';
        const to = document.getElementById('dailyToDate')?.value || '';
        if (from) url.searchParams.set('from_date', from);
        if (to) url.searchParams.set('to_date', to);
    } else {
        const day = document.getElementById('dailySnapshotDate')?.value || '';
        if (day) url.searchParams.set('snapshot_date', day);
    }
    window.history.replaceState({}, '', url.toString());
    loadRankings();
}

function toggleRankingsChart() {
    rankingsChartHidden = !rankingsChartHidden;
    const wrap = document.querySelector('.rankings-chart-wrap');
    const controls = document.querySelector('.rankings-chart-controls');
    const info = document.getElementById('rankingsChartInfo');
    const btn = document.getElementById('toggleChartBtn');
    if (wrap) wrap.style.display = rankingsChartHidden ? 'none' : '';
    if (controls) {
        controls.querySelectorAll('select, input, label').forEach((el) => {
            if (el === btn) return;
            el.style.display = rankingsChartHidden ? 'none' : '';
        });
    }
    if (info && rankingsChartHidden) info.textContent = 'Chart hidden.';
    if (btn) btn.innerHTML = rankingsChartHidden
        ? '<i class="fas fa-eye"></i> Show Chart'
        : '<i class="fas fa-eye-slash"></i> Hide Chart';
    if (!rankingsChartHidden) renderTrendChart();
}

function renderDomainSummary() {
    const grid = document.getElementById('domainSummaryGrid');
    if (!grid) return;
    if (!rankingsSummary.length) {
        grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;padding:30px;">
            <i class="fas fa-chart-line"></i>
            <p>No rankings data yet. Click <strong>Sync Rankings</strong> to fetch data from DataForSEO.</p>
        </div>`;
        updateSummarySliderControls();
        return;
    }
    grid.innerHTML = rankingsSummary.map((s) => `
        <div class="domain-card" onclick="setDomainFilter('${esc(s.domain)}')">
            <div class="domain-card-header">
                <span class="brand-badge ${(s.brand || '').toLowerCase()}">${esc(s.brand)}</span>
                <span class="domain-card-name" title="${esc(s.domain)}">${esc(truncDomain(s.domain))}</span>
            </div>
            <div class="domain-stats">
                <div class="domain-stat"><span class="domain-stat-val">${s.total_keywords || 0}</span><span class="domain-stat-lbl">Keywords</span></div>
                <div class="domain-stat green"><span class="domain-stat-val">${s.top3 || 0}</span><span class="domain-stat-lbl">Top 3</span></div>
                <div class="domain-stat cyan"><span class="domain-stat-val">${s.top10 || 0}</span><span class="domain-stat-lbl">Top 10</span></div>
                <div class="domain-stat"><span class="domain-stat-val">${s.top100 || 0}</span><span class="domain-stat-lbl">Top 100</span></div>
            </div>
            <div class="domain-card-footer">
                <span>Volume: <strong>${fmtNum(s.total_volume)}</strong></span>
                <span style="color:var(--zap-text-muted);font-size:0.7rem;">${s.last_checked ? 'Updated ' + fmtDate(s.last_checked) : 'Never synced'}</span>
            </div>
        </div>
    `).join('');
    updateSummarySliderControls();
}

function setDomainFilter(domain) {
    filterRankings();
    document.querySelector('#rankingsTable')?.closest('.card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleLowHangingFilter() {
    lowHangingOnly = !lowHangingOnly;
    document.getElementById('lowHangingBtn')?.classList.toggle('is-active', lowHangingOnly);
    if (lowHangingOnly) {
        document.getElementById('posFilter').value = '11-20';
    }
    filterRankings();
}

function applyRankingSortFilter() {
    const sortValue = document.getElementById('sortFilterR')?.value || 'position_asc';
    switch (sortValue) {
        case 'position_desc':
            rSortCol = 4;
            rSortDir = 'desc';
            break;
        case 'updated_desc':
            rSortCol = 8;
            rSortDir = 'desc';
            break;
        case 'updated_asc':
            rSortCol = 8;
            rSortDir = 'asc';
            break;
        case 'change_desc':
            rSortCol = 6;
            rSortDir = 'desc';
            break;
        case 'change_asc':
            rSortCol = 6;
            rSortDir = 'asc';
            break;
        case 'volume_desc':
            rSortCol = 6;
            rSortDir = 'desc';
            break;
        case 'volume_asc':
            rSortCol = 6;
            rSortDir = 'asc';
            break;
        case 'position_asc':
        default:
            rSortCol = 4;
            rSortDir = 'asc';
            break;
    }

    document.querySelectorAll('.data-table thead th').forEach((th, i) => {
        th.classList.remove('sort-asc', 'sort-desc');
        if (i === rSortCol) th.classList.add(rSortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });

    sortAndRender();
}

function filterRankings() {
    const kw = document.getElementById('kwSearch').value.toLowerCase();
    const url = document.getElementById('urlSearch').value.toLowerCase();


    const brand = document.getElementById('brandFilterR').value;
    const posVal = document.getElementById('posFilter').value;
    const changeFilter = document.getElementById('changeFilter').value;
    const volMinRaw = document.getElementById('volMin').value;
    const volMaxRaw = document.getElementById('volMax').value;
    const excludeBrandKeywords = document.getElementById('excludeBrandKeywords')?.checked;
    const colSearches = [...document.querySelectorAll('.col-search-input[data-rcol]')].map((input) => {
        const col = Number(input.dataset.rcol);
        const op = document.querySelector(`.col-filter-op[data-rcol-op="${col}"]`)?.value || 'contains';
        return { col, op, val: input.value.toLowerCase() };
    });

    let [minPos, maxPos] = [null, null];
    if (posVal) {
        const parts = posVal.split('-');
        minPos = Number(parts[0]);
        maxPos = Number(parts[1]);
    }
    const volMin = volMinRaw === '' ? null : Number(volMinRaw);
    const volMax = volMaxRaw === '' ? null : Number(volMaxRaw);

    filteredRankings = allRankings.filter((r) => {
        if (kw && !(r.keyword || '').toLowerCase().includes(kw)) return false;
        if (url && !(r.url || '').toLowerCase().includes(url)) return false;


        if (brand && r.brand !== brand) return false;
        if (excludeBrandKeywords && isBrandKeyword(r.keyword)) return false;
        if (minPos !== null && (r.position === null || r.position < minPos)) return false;
        if (maxPos !== null && (r.position === null || r.position > maxPos)) return false;
        if (lowHangingOnly && (r.position === null || r.position < 11 || r.position > 20)) return false;
        if (volMin !== null && ((r.search_volume ?? null) === null || Number(r.search_volume) < volMin)) return false;
        if (volMax !== null && ((r.search_volume ?? null) === null || Number(r.search_volume) > volMax)) return false;

        const change = posChange(r);
        if (changeFilter === 'up' && !(change > 0)) return false;
        if (changeFilter === 'down' && !(change < 0)) return false;
        if (changeFilter === 'same' && change !== 0) return false;

        for (const cs of colSearches) {
            if (!cs.val) continue;
            const cell = getRCell(r, cs.col).toLowerCase();
            const contains = cell.includes(cs.val);
            if (cs.op === 'not_contains' && contains) return false;
            if (cs.op !== 'not_contains' && !contains) return false;
        }
        return true;
    });

    rPage = 1;
    applyRankingSortFilter();
}

function getRCell(r, col) {
    switch (col) {
        case 1: return r.brand || '';
        case 2: return r.keyword || '';
        case 3: return r.url || '';
        case 4: return String(r.position ?? '');
        case 6: return String(r.search_volume ?? '');
        default: return '';
    }
}

function sortAndRender() {
    filteredRankings.sort((a, b) => {
        let ta;
        let tb;
        switch (rSortCol) {
            case 0: ta = a.id; tb = b.id; break;
            case 1: ta = a.brand || ''; tb = b.brand || ''; break;
            case 2: ta = a.keyword || ''; tb = b.keyword || ''; break;
            case 3: ta = a.url || ''; tb = b.url || ''; break;
            case 4: ta = a.position ?? 9999; tb = b.position ?? 9999; break;
            case 5: ta = posChange(a); tb = posChange(b); break;
            case 6: ta = a.search_volume ?? -1; tb = b.search_volume ?? -1; break;
            case 7: ta = a.cpc ?? -1; tb = b.cpc ?? -1; break;
            case 8: ta = a.last_checked || ''; tb = b.last_checked || ''; break;
            default: ta = a.position ?? 9999; tb = b.position ?? 9999;
        }
        const cmp = typeof ta === 'number'
            ? ta - tb
            : String(ta).localeCompare(String(tb), undefined, { numeric: true });
        return rSortDir === 'asc' ? cmp : -cmp;
    });
    renderRankings();
}

function posChange(r) {
    if (r.previous_position === null || r.position === null) return 0;
    return r.previous_position - r.position;
}

function renderRankings() {
    const perPage = parseInt(document.getElementById('perPageR').value, 10) || 100;
    const start = (rPage - 1) * perPage;
    const rows = filteredRankings.slice(start, start + perPage);
    const tbody = document.getElementById('rankingsBody');

    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--zap-text-muted);">
            <i class="fas fa-chart-bar" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
            No rankings found${allRankings.length === 0 ? ' - click <strong>Sync Rankings</strong> to fetch data.' : ''}
        </td></tr>`;
    } else {
        tbody.innerHTML = rows.map((r, i) => renderRankingRow(r, start + i + 1)).join('');
    }

    const totalPages = Math.ceil(filteredRankings.length / perPage);
    renderRPagination(totalPages);
    document.getElementById('rankingsInfo').textContent =
        `Showing ${Math.min(start + 1, filteredRankings.length)}-${Math.min(start + perPage, filteredRankings.length)} of ${filteredRankings.length} (${allRankings.length} total)`;
    updateDailyKeywordSummary();
    renderTrendChart();
}

function updateDailyKeywordSummary() {
    const summary = document.getElementById('dailyKeywordSummary');
    if (!summary) return;

    if (allRankings.length === 0) {
        summary.textContent = 'No keywords are available for the selected daily check yet.';
        return;
    }

    if (filteredRankings.length === allRankings.length) {
        summary.textContent = `${fmtNum(allRankings.length)} keywords are included in this daily check.`;
        return;
    }

    summary.textContent = `${fmtNum(filteredRankings.length)} of ${fmtNum(allRankings.length)} keywords are currently shown in this daily check.`;
}

function renderRankingRow(r, idx) {
    const previousPosition = r.previous_position ?? null;
    const currentPosition = r.position ?? null;
    const change = previousPosition !== null && currentPosition !== null
        ? previousPosition - currentPosition
        : null;
    const currentStamp = r.last_checked || r.snapshot_date || r.updated_at || null;
    const currentDateLabel = currentStamp ? formatDateLabel(currentStamp) : '-';
    const previousDate = getPreviousCheckedAt(r);
    const previousDateLabel = previousDate ? formatDateLabel(previousDate) : '-';
    const changeBadge = change === null
        ? '<span style="color:var(--zap-text-muted)">-</span>'
        : change > 0
            ? `<span style="color:var(--zap-green)">▲ ${change}</span>`
            : change < 0
                ? `<span style="color:#ff5050">▼ ${Math.abs(change)}</span>`
                : `<span style="color:var(--zap-text-muted)">- 0</span>`;

    const posBadge = r.position === null
        ? '<span style="color:var(--zap-text-muted)">-</span>'
        : `<span class="pos-badge ${r.position <= 10 ? 'green' : r.position <= 20 ? 'cyan' : ''}">${r.position}</span><div class="ranking-date-note">As of: ${esc(currentDateLabel)}</div>`;

    const vol = r.search_volume !== null
        ? `<span class="vol-badge">${fmtNum(r.search_volume)}</span>`
        : '<span style="color:var(--zap-text-muted)">-</span>';

    const cpc = r.cpc !== null
        ? `<span style="font-family:'Rubik',monospace;font-size:0.75rem;">€${parseFloat(r.cpc).toFixed(2)}</span>`
        : '<span style="color:var(--zap-text-muted)">-</span>';

    return `<tr>
        <td style="color:var(--zap-text-muted);font-size:0.75rem;">${idx}</td>
        <td><span class="brand-badge ${(r.brand || '').toLowerCase()}">${esc(r.brand)}</span></td>
        <td style="font-weight:500;max-width:280px;">${esc(r.keyword)}</td>
        <td class="url-cell"><a href="${esc(r.url)}" target="_blank" class="url-link" title="${esc(r.url)}">${esc(truncUrl(formatUrlPath(r.url)))}</a></td>
        <td>${posBadge}</td>
        <td>${changeBadge}${change !== null ? `<div class="ranking-date-note">${esc(previousDateLabel)} -> ${esc(currentDateLabel)}</div>` : ''}</td>
        <td>${vol}</td>
        <td>${cpc}</td>
        <td>${currentStamp ? `<span class="date-cell">${fmtDate(currentStamp)}</span>` : '<span style="color:var(--zap-text-muted)">Never</span>'}</td>
        <td>${RANKINGS_READ_ONLY ? '<span style="color:var(--zap-text-muted)">-</span>' : `<div class="action-btns"><button class="action-btn delete" title="Delete" onclick="deleteRanking(${r.id})"><i class="fas fa-trash"></i></button></div>`}</td>
    </tr>`;
}

function renderTrendChart() {
    const canvas = document.getElementById('rankingsTrendChart');
    const info = document.getElementById('rankingsChartInfo');
    if (!canvas || typeof Chart === 'undefined') return;
    if (rankingsChartHidden) return;

    if (rankingsTrendChart) {
        rankingsTrendChart.destroy();
        rankingsTrendChart = null;
    }

    const range = getActiveTrendRange();
    if (!range) {
        if (info) info.textContent = 'Please choose a valid start and end date.';
        return;
    }

    const currentPoints = aggregateTrendPoints(filteredRankings, range.start, range.end, 0);
    const comparePrevYear = document.getElementById('comparePrevYear')?.checked;
    const previousPoints = comparePrevYear ? aggregateTrendPoints(filteredRankings, range.start, range.end, -1) : [];

    if (!currentPoints.some((p) => p.value !== null) && !previousPoints.some((p) => p.value !== null)) {
        if (info) info.textContent = 'No ranking history is available for the selected date range yet.';
        return;
    }

    const datasets = [];
    if (currentPoints.length) {
        datasets.push({
            label: 'Current period',
            data: currentPoints.map((p) => p.value),
            keywordCounts: currentPoints.map((p) => p.keywordCount),
            borderColor: '#18e888',
            backgroundColor: 'rgba(24,232,136,0.18)',
            fill: true,
            tension: 0.28,
            pointRadius: 3,
            pointHoverRadius: 5,
        });
    }
    if (previousPoints.length) {
        datasets.push({
            label: 'Previous year period',
            data: previousPoints.map((p) => p.value),
            keywordCounts: previousPoints.map((p) => p.keywordCount),
            borderColor: '#00c8ff',
            backgroundColor: 'rgba(0,200,255,0.08)',
            fill: false,
            tension: 0.28,
            pointRadius: 2,
            pointHoverRadius: 4,
            borderDash: [6, 4],
        });
    }

    if (info) {
        const baseText = `Daily trend for ${filteredRankings.length} currently filtered keywords from ${formatDateLabel(range.start)} to ${formatDateLabel(range.end)}.`;
        info.textContent = comparePrevYear
            ? `${baseText} The comparison line shows the same period in the previous year.`
            : baseText;
    }

    const labelSource = currentPoints.length ? currentPoints : previousPoints;

    rankingsTrendChart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: labelSource.map((p) => p.label),
            datasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: {
                    reverse: true,
                    ticks: { color: '#9aa4b2' },
                    grid: { color: 'rgba(255,255,255,0.06)' },
                    title: { display: true, text: 'Average position', color: '#cfd7e3' },
                },
                x: {
                    ticks: { color: '#9aa4b2', maxRotation: 0, autoSkip: true },
                    grid: { color: 'rgba(255,255,255,0.04)' },
                },
            },
            plugins: {
                legend: { display: datasets.length > 1 },
                tooltip: {
                    callbacks: {
                        label(ctx) {
                            if (ctx.parsed.y === null || ctx.parsed.y === undefined) {
                                return `${ctx.dataset.label}: no data`;
                            }
                            const keywordCount = Number(ctx.dataset.keywordCounts?.[ctx.dataIndex] || 0);
                            const keywordLabel = keywordCount === 1 ? 'keyword' : 'keywords';
                            return `${ctx.dataset.label}: ${Number(ctx.parsed.y).toFixed(1)} (${keywordCount} ${keywordLabel})`;
                        },
                    },
                },
            },
        },
    });
}

function aggregateTrendPoints(rows, displayStart, displayEnd, yearOffset) {
    const selectedKeys = new Set(rows.map((row) => historyKey(row)));
    const days = enumerateDays(displayStart, displayEnd);
    if (!selectedKeys.size) {
        return days.map((day) => ({ label: formatShortDateLabel(day), value: null, keywordCount: 0 }));
    }

    const sourceStart = addYears(displayStart, yearOffset);
    const sourceEnd = addYears(displayEnd, yearOffset);
    const buckets = new Map();

    selectedKeys.forEach((key) => {
        const historyRows = rankingHistoryByKey.get(key) || [];
        const dayMap = new Map();

        historyRows.forEach((entry) => {
            if (entry.position === null || entry.position === undefined || entry.position === '') return;
            const checkedAt = parseDateValue(entry.checked_at);
            if (Number.isNaN(checkedAt.getTime())) return;
            if (checkedAt < sourceStart || checkedAt > endOfDay(sourceEnd)) return;

            const dayKey = toInputDate(checkedAt);
            const previous = dayMap.get(dayKey);
            if (!previous || parseDateValue(previous.checked_at) < checkedAt) {
                dayMap.set(dayKey, entry);
            }
        });

        dayMap.forEach((entry, dayKey) => {
            const displayDate = addYears(parseDateValue(dayKey), -yearOffset);
            const displayKey = toInputDate(displayDate);
            if (displayDate < displayStart || displayDate > displayEnd) return;
            if (!buckets.has(displayKey)) buckets.set(displayKey, []);
            buckets.get(displayKey).push(Number(entry.position));
        });
    });

    return days.map((day) => {
        const dayKey = toInputDate(day);
        const positions = buckets.get(dayKey) || [];
        return {
            label: formatShortDateLabel(dayKey),
            value: positions.length
                ? positions.reduce((sum, current) => sum + current, 0) / positions.length
                : null,
            keywordCount: positions.length,
        };
    });
}

function getActiveTrendRange() {
    const startValue = document.getElementById('trendStartDate')?.value;
    const endValue = document.getElementById('trendEndDate')?.value;
    if (!startValue || !endValue) return null;
    const start = parseDateValue(startValue);
    const end = parseDateValue(endValue);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || start > end) return null;
    return { start, end };
}

function getPresetRange(preset) {
    const end = startOfDay(new Date());
    const start = startOfDay(new Date(end));
    if (preset === '7d') start.setDate(start.getDate() - 6);
    else if (preset === '1m') start.setMonth(start.getMonth() - 1);
    else if (preset === '3m') start.setMonth(start.getMonth() - 3);
    else if (preset === '12m') start.setFullYear(start.getFullYear() - 1);
    else start.setMonth(start.getMonth() - 6);
    return { start, end };
}

function historyKey(row) {
    return [row.domain || '', row.keyword || '', row.location_code || ''].join('||');
}

function getPreviousCheckedAt(row) {
    const historyRows = rankingHistoryByKey.get(historyKey(row)) || [];
    const current = String(row.last_checked || '');
    const previousRows = historyRows.filter((entry) => String(entry.checked_at || '') < current);
    return previousRows.length ? previousRows[previousRows.length - 1].checked_at : null;
}

function renderRPagination(totalPages) {
    const pag = document.getElementById('rankingsPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    let html = `<button class="page-btn" onclick="goRPage(${rPage - 1})" ${rPage === 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;
    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= rPage - 2 && p <= rPage + 2)) {
            html += `<button class="page-btn ${p === rPage ? 'active' : ''}" onclick="goRPage(${p})">${p}</button>`;
        } else if (p === rPage - 3 || p === rPage + 3) {
            html += `<span style="color:var(--zap-text-muted);padding:0 4px;">...</span>`;
        }
    }
    html += `<button class="page-btn" onclick="goRPage(${rPage + 1})" ${rPage === totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
    pag.innerHTML = html;
}

function goRPage(p) {
    const perPage = parseInt(document.getElementById('perPageR').value, 10) || 100;
    const totalPages = Math.ceil(filteredRankings.length / perPage);
    if (p < 1 || p > totalPages) return;
    rPage = p;
    renderRankings();
}

function isBrandKeyword(keyword) {
    const value = String(keyword || '').toLowerCase();
    return BRAND_KEYWORD_TERMS.some((term) => value.includes(term));
}

function scrollSummary(direction) {
    const viewport = document.getElementById('rankingsSummaryViewport');
    if (!viewport) return;
    const amount = Math.max(260, Math.floor(viewport.clientWidth * 0.75));
    viewport.scrollBy({ left: direction * amount, behavior: 'smooth' });
}

function updateSummarySliderControls() {
    const viewport = document.getElementById('rankingsSummaryViewport');
    const prev = document.getElementById('summaryPrevBtn');
    const next = document.getElementById('summaryNextBtn');
    if (!viewport || !prev || !next) return;

    const maxScroll = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
    const collapsed = viewport.closest('.rankings-summary-card')?.classList.contains('is-collapsed');
    const disableAll = collapsed || maxScroll <= 4;

    prev.disabled = disableAll || viewport.scrollLeft <= 4;
    next.disabled = disableAll || viewport.scrollLeft >= maxScroll - 4;
}

function toggleRankingsSummary() {
    const card = document.querySelector('.rankings-summary-card');
    const btn = document.getElementById('toggleSummaryBtn');
    if (!card || !btn) return;

    const collapsed = card.classList.toggle('is-collapsed');
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    btn.innerHTML = collapsed
        ? '<i class="fas fa-chevron-down"></i> Expand Projects'
        : '<i class="fas fa-chevron-up"></i> Collapse Projects';

    if (!collapsed) requestAnimationFrame(updateSummarySliderControls);
    else updateSummarySliderControls();
}

async function deleteRanking(id) {
    if (RANKINGS_READ_ONLY) return;
    if (!confirm('Delete this ranking entry?')) return;
    try {
        await fetch(rankingsApiUrl().replace(/\?.*$/, '') + `?id=${id}`, { method: 'DELETE' });
        showToast('Deleted');
        loadRankings();
    } catch (e) {
        showToast('Error', 'error');
    }
}

async function syncRankings() {
    if (!RANKINGS_SYNC_ENABLED) return;
    const btn = document.getElementById('syncBtn');
    const status = document.getElementById('syncStatus');
    const panel = document.getElementById('rankingsSyncProgress');
    const fill = document.getElementById('rankingsProgressFill');
    const meta = document.getElementById('rankingsProgressMeta');
    const domains = window.SYNC_RANKING_DOMAINS || [];

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing...';
    status.textContent = 'Fetching rankings from DataForSEO...';
    if (panel) panel.style.display = 'block';
    if (fill) {
        fill.classList.remove('indeterminate');
        fill.style.width = '0%';
    }

    const avgLine = typeof formatAvgLine === 'function' ? formatAvgLine('rankings', 'en') : '';
    const t0 = performance.now();
    let completedOk = false;
    let totalUpsert = 0;

    try {
        if (!domains.length) {
            showToast('No domains configured', 'error');
            status.textContent = 'No domains';
            if (panel) panel.style.display = 'none';
        } else {
            let syncFailed = false;
            for (let i = 0; i < domains.length; i++) {
                const domain = domains[i];
                const pctBefore = Math.round((i / domains.length) * 100);
                if (meta) {
                    meta.innerHTML = `<strong>${pctBefore}%</strong> - ${i + 1}/${domains.length}: <code>${esc(domain)}</code> (fetching...)<br><span style="opacity:0.85">${esc(avgLine)}</span>`;
                }
                if (fill) fill.style.width = `${pctBefore}%`;

                const res = await fetch(apiUrl(`sync_dataforseo.php?action=rankings_domain&domain=${encodeURIComponent(domain)}`));
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || 'Sync failed', 'error');
                    status.textContent = data.error || 'Sync failed';
                    if (meta) meta.innerHTML = `<strong style="color:#ff5050">Stopped</strong> - ${esc(data.error || 'error')}`;
                    syncFailed = true;
                    break;
                }
                totalUpsert += data.result?.upserted || 0;
                const pctDone = Math.round(((i + 1) / domains.length) * 100);
                if (fill) fill.style.width = `${pctDone}%`;
                if (meta) {
                    const fetched = data.result?.fetched ?? 0;
                    meta.innerHTML = `<strong>${pctDone}%</strong> - ${i + 1}/${domains.length}: <code>${esc(domain)}</code> (${fetched} keywords)<br><span style="opacity:0.85">${esc(avgLine)}</span>`;
                }
            }

            if (!syncFailed) {
                completedOk = true;
                const elapsed = performance.now() - t0;
                if (typeof recordSyncDuration === 'function') recordSyncDuration('rankings', elapsed);
                const lastAvg = typeof formatAvgLine === 'function' ? formatAvgLine('rankings', 'en') : '';
                showToast(`Rankings synced! ${totalUpsert} keywords updated.`);
                status.textContent = `Last sync: ${new Date().toLocaleTimeString()}`;
                if (fill) fill.style.width = '100%';
                if (meta) {
                    meta.innerHTML = `<strong>100%</strong> - finished in ${typeof formatDurationMs === 'function' ? formatDurationMs(elapsed) : Math.round(elapsed / 1000) + 's'}<br><span style="opacity:0.85">${esc(lastAvg)}</span>`;
                }
                loadRankings();
            }
        }
    } catch (e) {
        showToast('Network error', 'error');
        status.textContent = 'Error';
        if (meta) meta.innerHTML = '<strong style="color:#ff5050">Network error</strong>';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-sync-alt"></i> Sync Rankings';

    if (completedOk) {
        setTimeout(() => {
            if (panel) panel.style.display = 'none';
        }, 6000);
    }
}

function initRankingPageMode() {
    if (!RANKINGS_SYNC_ENABLED) {
        const btn = document.getElementById('syncBtn');
        const status = document.getElementById('syncStatus');
        if (btn) btn.style.display = 'none';
        if (status) status.textContent = 'Snapshot view';
    }
    if (IS_DAILY_VIEW) {
        const mode = rankingPageParams.get('daily_mode');
        const day = rankingPageParams.get('snapshot_date') || DAILY_DEFAULT_DATE;
        const from = rankingPageParams.get('from_date') || DAILY_DEFAULT_DATE;
        const to = rankingPageParams.get('to_date') || DAILY_DEFAULT_DATE;
        const modeInput = document.getElementById('dailyViewMode');
        const dayInput = document.getElementById('dailySnapshotDate');
        const fromInput = document.getElementById('dailyFromDate');
        const toInput = document.getElementById('dailyToDate');
        if (modeInput && (mode === 'range' || mode === 'day')) modeInput.value = mode;
        if (dayInput && day) dayInput.value = day;
        if (fromInput && from) fromInput.value = from;
        if (toInput && to) toInput.value = to;
        handleDailyFilterModeChange();
    }
}

document.querySelectorAll('.sortable-r').forEach((th, i) => {
    th.addEventListener('click', () => {
        if (rSortCol === i) rSortDir = rSortDir === 'asc' ? 'desc' : 'asc';
        else {
            rSortCol = i;
            rSortDir = i === 5 ? 'asc' : 'desc';
        }
        document.querySelectorAll('.sortable-r').forEach((header, j) => {
            header.classList.remove('sort-asc', 'sort-desc');
            if (j === i) header.classList.add(rSortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        });
        sortAndRender();
    });
});

document.getElementById('summaryPrevBtn')?.addEventListener('click', () => scrollSummary(-1));
document.getElementById('summaryNextBtn')?.addEventListener('click', () => scrollSummary(1));
document.getElementById('toggleSummaryBtn')?.addEventListener('click', toggleRankingsSummary);
document.getElementById('rankingsSummaryViewport')?.addEventListener('scroll', updateSummarySliderControls, { passive: true });
window.addEventListener('resize', updateSummarySliderControls);

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function truncUrl(u, n = 50) {
    if (!u) return '';
    return u.length > n ? `${u.substring(0, n)}...` : u;
}

function formatUrlPath(u) {
    if (!u) return '/';
    try {
        const parsed = new URL(u);
        return `${parsed.pathname || '/'}${parsed.search || ''}${parsed.hash || ''}` || '/';
    } catch (e) {
        const stripped = String(u || '').replace(/^[a-z]+:\/\//i, '');
        const slashIndex = stripped.indexOf('/');
        return slashIndex >= 0 ? (stripped.slice(slashIndex) || '/') : '/';
    }
}

function truncDomain(d, n = 22) {
    if (!d) return '';
    return d.length > n ? `${d.substring(0, n)}...` : d;
}

function fmtNum(n) {
    if (n === null || n === undefined) return '0';
    return Number(n).toLocaleString('de-DE').replace(/,/g, '.');
}

function fmtDate(dt) {
    if (!dt) return '-';
    const d = new Date(dt);
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateLabel(dt) {
    if (!dt) return '-';
    return parseDateValue(dt).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatShortDateLabel(dt) {
    if (!dt) return '-';
    return parseDateValue(dt).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit' });
}

function toInputDate(date) {
    const normalized = startOfDay(date);
    const year = normalized.getFullYear();
    const month = String(normalized.getMonth() + 1).padStart(2, '0');
    const day = String(normalized.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function parseDateValue(value) {
    if (value instanceof Date) return new Date(value);
    const raw = String(value || '');
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (match) {
        const [, year, month, day, hour = '00', minute = '00', second = '00'] = match;
        return new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second));
    }
    return new Date(value);
}

function addYears(date, years) {
    const copy = parseDateValue(date);
    copy.setFullYear(copy.getFullYear() + years);
    return copy;
}

function startOfDay(date) {
    const copy = new Date(date);
    copy.setHours(0, 0, 0, 0);
    return copy;
}

function endOfDay(date) {
    const copy = startOfDay(date);
    copy.setHours(23, 59, 59, 999);
    return copy;
}

function enumerateDays(start, end) {
    const days = [];
    const cursor = startOfDay(start);
    const last = startOfDay(end);
    while (cursor <= last) {
        days.push(new Date(cursor));
        cursor.setDate(cursor.getDate() + 1);
    }
    return days;
}

initRankingPageMode();
loadRankings();
