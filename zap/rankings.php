<?php
require_once 'config.php';
$dashboardBrand = dashboard_scope_brand();
$dashboardDomains = dashboard_scope_domains();
$rankingView = ((string) ($_GET['view'] ?? 'discovery') === 'daily') ? 'daily' : 'discovery';
$pdo = db();
$scopeDomains = array_keys($dashboardDomains);
$scopeWhere = [];
$scopeParams = [];
if ($dashboardBrand) {
    $scopeWhere[] = 'brand = ?';
    $scopeParams[] = (string) $dashboardBrand;
}
if ($scopeDomains) {
    $scopeWhere[] = 'domain IN (' . implode(',', array_fill(0, count($scopeDomains), '?')) . ')';
    foreach ($scopeDomains as $scopeDomain) {
        $scopeParams[] = (string) $scopeDomain;
    }
}
$scopeSql = $scopeWhere ? (' WHERE ' . implode(' AND ', $scopeWhere)) : '';
$todayBerlin = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$dailyDefaultDate = $todayBerlin;
$dailyDateStmt = $pdo->prepare('SELECT MAX(snapshot_date) FROM rankings_daily_snapshots' . $scopeSql);
$dailyDateStmt->execute($scopeParams);
$dailyMaxDate = (string) ($dailyDateStmt->fetchColumn() ?: '');
if ($dailyMaxDate !== '') {
    $dailyDefaultDate = $dailyMaxDate;
}
$rankingTitle = $rankingView === 'daily' ? '2. Daily Live Check' : 'Discovery Rankings';
$rankingSubtitle = $rankingView === 'daily'
    ? 'Daily live snapshots for ZAP keywords with date ranges and trend analysis.'
    : 'Historic discovery rankings for ZAP monitored domains.';
$primaryDomain = (string) (array_key_first($dashboardDomains) ?? '');
$dailyApiPath = '/api/rankings.php?mode=daily_snapshots&current_scope=1&location_code=2276&language_code=de' . ($primaryDomain !== '' ? '&domain=' . rawurlencode($primaryDomain) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($rankingTitle) ?> - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/main.css?v=20260507a">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1><i class="fas fa-chart-line"></i> <?= htmlspecialchars($rankingTitle) ?></h1>
                    <span class="page-subtitle"><?= htmlspecialchars($rankingSubtitle) ?></span>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button class="btn btn-secondary" onclick="syncRankings()" id="syncBtn"><i class="fas fa-sync-alt"></i> Sync Rankings</button>
                    <div id="syncStatus" style="font-size:0.75rem;color:var(--zap-text-muted);"></div>
                </div>
            </div>

            <div id="rankingsSyncProgress" class="sync-progress-panel" style="display:none;margin-bottom:16px;">
                <div class="progress-bar"><div class="progress-fill green" id="rankingsProgressFill" style="width:0%"></div></div>
                <div class="sync-progress-meta" id="rankingsProgressMeta"></div>
            </div>

            <div class="card" style="margin-bottom:20px;">
                <div class="table-controls">
                    <input type="text" id="kwSearch" class="search-input" placeholder="Search keyword..." oninput="filterRankings()">
                    <input type="text" id="urlSearch" class="search-input" placeholder="Filter URL..." oninput="filterRankings()">
                    <select id="brandFilterR" class="search-input" onchange="filterRankings()">
                        <option value="">All Brands</option>
                        <option value="<?= htmlspecialchars((string) $dashboardBrand) ?>"><?= htmlspecialchars((string) $dashboardBrand) ?></option>
                    </select>
                    <label class="rankings-toggle-filter">
                        <input type="checkbox" id="excludeBrandKeywords" onchange="filterRankings()">
                        <span>Hide brand keywords</span>
                    </label>
                    <select id="posFilter" class="search-input" onchange="filterRankings()">
                        <option value="">All Positions</option>
                        <option value="1-3">Top 3</option>
                        <option value="1-10">Top 10</option>
                        <option value="1-20">Top 20</option>
                        <option value="11-20">11-20</option>
                        <option value="1-50">Top 50</option>
                        <option value="1-100">Top 100</option>
                        <option value="101-999">Beyond 100</option>
                    </select>
                    <select id="changeFilter" class="search-input" onchange="filterRankings()">
                        <option value="">All Changes</option>
                        <option value="up">Improved only</option>
                        <option value="down">Dropped only</option>
                        <option value="same">Unchanged only</option>
                    </select>
                    <input type="number" id="volMin" class="search-input search-input--compact" placeholder="Min volume" min="0" oninput="filterRankings()">
                    <input type="number" id="volMax" class="search-input search-input--compact" placeholder="Max volume" min="0" oninput="filterRankings()">
                    <select id="sortFilterR" class="search-input" onchange="applyRankingSortFilter()">
                        <option value="position_asc">Sort: Position ascending</option>
                        <option value="position_desc">Sort: Position descending</option>
                        <option value="updated_desc">Sort: Last updated (newest first)</option>
                        <option value="updated_asc">Sort: Last updated (oldest first)</option>
                        <option value="change_desc">Sort: Change descending</option>
                        <option value="change_asc">Sort: Change ascending</option>
                        <option value="volume_desc">Sort: Volume descending</option>
                        <option value="volume_asc">Sort: Volume ascending</option>
                    </select>
                    <button type="button" class="btn btn-secondary rankings-quick-btn" id="lowHangingBtn" onclick="toggleLowHangingFilter()"><i class="fas fa-seedling"></i> Low Hanging Fruits</button>
                    <select id="perPageR" class="search-input" onchange="filterRankings()">
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100" selected>100 / page</option>
                        <option value="250">250 / page</option>
                    </select>
                </div>
                <?php if ($rankingView === 'daily'): ?>
                <div class="table-controls" style="margin-top:12px;border-top:1px solid rgba(255,255,255,0.08);padding-top:12px;">
                    <select id="dailyViewMode" class="search-input" onchange="handleDailyFilterModeChange()">
                        <option value="day">Single Day</option>
                        <option value="range">Date Range</option>
                    </select>
                    <input type="date" id="dailySnapshotDate" class="search-input search-input--compact" value="<?= htmlspecialchars($dailyDefaultDate) ?>" onchange="handleDailyFilterInputChange()">
                    <input type="date" id="dailyFromDate" class="search-input search-input--compact" value="<?= htmlspecialchars($dailyDefaultDate) ?>" onchange="handleDailyFilterInputChange()" style="display:none;">
                    <input type="date" id="dailyToDate" class="search-input search-input--compact" value="<?= htmlspecialchars($dailyDefaultDate) ?>" onchange="handleDailyFilterInputChange()" style="display:none;">
                    <button type="button" class="btn btn-secondary" onclick="applyDailyFilters()"><i class="fas fa-filter"></i> Apply Daily Filter</button>
                    <div id="dailyFilterInfo" style="font-size:0.75rem;color:var(--zap-text-muted);"></div>
                </div>
                <div class="rankings-summary-subtitle" id="dailyKeywordSummary" style="display:block;margin-top:12px;">
                    Loading keyword count...
                </div>
                <?php endif; ?>
            </div>

            <div class="card rankings-chart-card">
                <div class="rankings-chart-head">
                    <div>
                        <h2 class="rankings-summary-title"><i class="fas fa-chart-line"></i> Ranking Trend</h2>
                        <span class="rankings-summary-subtitle" id="rankingsChartInfo">Trend for the current selection.</span>
                    </div>
                    <div class="rankings-chart-controls">
                        <button type="button" class="btn btn-secondary" id="toggleChartBtn" onclick="toggleRankingsChart()"><i class="fas fa-eye-slash"></i> Hide Chart</button>
                        <select id="trendRange" class="search-input search-input--compact" onchange="handleTrendRangeChange()">
                            <option value="7d">1 Week</option>
                            <option value="1m">1 Month</option>
                            <option value="3m">3 Months</option>
                            <option value="6m" selected>6 Months</option>
                            <option value="12m">12 Months</option>
                            <option value="custom">Custom</option>
                        </select>
                        <label class="rankings-toggle-filter">
                            <input type="checkbox" id="comparePrevYear" onchange="renderTrendChart()">
                            <span>Compare with previous year</span>
                        </label>
                        <input type="date" id="trendStartDate" class="search-input search-input--compact" onchange="handleTrendDateChange()">
                        <input type="date" id="trendEndDate" class="search-input search-input--compact" onchange="handleTrendDateChange()">
                    </div>
                </div>
                <div class="rankings-chart-wrap">
                    <canvas id="rankingsTrendChart" height="110"></canvas>
                </div>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table class="data-table" id="rankingsTable">
                        <thead>
                            <tr>
                                <th class="sortable-r" data-col="0"># <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="1">Brand <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="2">Keyword <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="3">URL <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="4">Position <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="5">Change <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="6">Volume <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="7">CPC <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="8">Last Checked <span class="sort-icon"></span></th>
                                <th>Actions</th>
                            </tr>
                            <tr class="col-search-row">
                                <th></th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-rcol-op="1" onchange="filterRankings()">
                                            <option value="contains">contains</option>
                                            <option value="not_contains">does not contain</option>
                                        </select>
                                        <input class="col-search-input" placeholder="Brand" data-rcol="1" oninput="filterRankings()">
                                    </div>
                                </th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-rcol-op="2" onchange="filterRankings()">
                                            <option value="contains">contains</option>
                                            <option value="not_contains">does not contain</option>
                                        </select>
                                        <input class="col-search-input" placeholder="Keyword" data-rcol="2" oninput="filterRankings()">
                                    </div>
                                </th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-rcol-op="3" onchange="filterRankings()">
                                            <option value="contains">contains</option>
                                            <option value="not_contains">does not contain</option>
                                        </select>
                                        <input class="col-search-input" placeholder="URL" data-rcol="3" oninput="filterRankings()">
                                    </div>
                                </th>
                                <th><input class="col-search-input" placeholder="Position" data-rcol="4" oninput="filterRankings()"></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rankingsBody">
                            <tr class="loading-row"><td colspan="10"><div class="spinner"></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">
                    <span id="rankingsInfo">Loading...</span>
                    <div class="pagination" id="rankingsPagination"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260508a"></script>
    <script>
        window.DASHBOARD_BRAND_SCOPE = <?= json_encode($dashboardBrand, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.DASHBOARD_DOMAIN_SCOPE = <?= json_encode(array_keys($dashboardDomains), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.SYNC_RANKING_DOMAINS = <?= json_encode(array_keys($dashboardDomains), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.RANKINGS_API_PATH = <?= json_encode($rankingView === 'daily' ? $dailyApiPath : 'rankings.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.RANKINGS_SYNC_ENABLED = <?= $rankingView === 'daily' ? 'false' : 'true' ?>;
        window.RANKINGS_READ_ONLY = <?= $rankingView === 'daily' ? 'true' : 'false' ?>;
        window.RANKINGS_VIEW = <?= json_encode($rankingView, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.DAILY_DEFAULT_DATE = <?= json_encode($dailyDefaultDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="assets/js/sync_stats.js"></script>
    <script src="assets/js/rankings.js?v=20260506e"></script>
</body>
</html>
