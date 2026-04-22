<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rankings – ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1><i class="fas fa-chart-line"></i> Rankings</h1>
                    <span class="page-subtitle">Keyword rankings for all monitored domains via DataForSEO</span>
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

            <div class="card rankings-summary-card">
                <div class="rankings-summary-toolbar">
                    <div>
                        <h2 class="rankings-summary-title"><i class="fas fa-folders"></i> Projekte</h2>
                        <span class="rankings-summary-subtitle">Per Pfeil durch die Projekt-Kacheln scrollen oder per Klick direkt filtern.</span>
                    </div>
                    <div class="rankings-summary-actions">
                        <button type="button" class="summary-arrow" id="summaryPrevBtn" aria-label="Vorherige Projekte"><i class="fas fa-chevron-left"></i></button>
                        <button type="button" class="summary-arrow" id="summaryNextBtn" aria-label="Weitere Projekte"><i class="fas fa-chevron-right"></i></button>
                        <button type="button" class="btn btn-secondary" id="toggleSummaryBtn" aria-expanded="true"><i class="fas fa-chevron-up"></i> Projekte einklappen</button>
                    </div>
                </div>
                <div id="rankingsSummaryViewport" class="rankings-summary-viewport">
                    <div id="domainSummaryGrid" class="domain-summary-grid domain-summary-grid--slider"></div>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="card" style="margin-bottom:20px;">
                <div class="table-controls">
                    <input type="text"   id="kwSearch"     class="search-input" placeholder="Search keyword..." oninput="filterRankings()">
                    <input type="text"   id="urlSearch"    class="search-input" placeholder="Filter URL..." oninput="filterRankings()">
                    <select id="domainFilter" class="search-input" onchange="filterRankings()">
                        <option value="">All Domains</option>
                        <?php foreach (MONITORED_DOMAINS as $d => $m): ?>
                        <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($m['name']) ?> (<?= $d ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select id="brandFilterR" class="search-input" onchange="filterRankings()">
                        <option value="">All Brands</option>
                        <option value="ZAP">ZAP</option>
                        <option value="DMC">DMC</option>
                    </select>
                    <label class="rankings-toggle-filter">
                        <input type="checkbox" id="excludeBrandKeywords" checked onchange="filterRankings()">
                        <span>Brand-Keywords ausblenden</span>
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
                    <button type="button" class="btn btn-secondary rankings-quick-btn" id="lowHangingBtn" onclick="toggleLowHangingFilter()"><i class="fas fa-seedling"></i> Low Hanging Fruits</button>
                    <select id="perPageR" class="search-input" onchange="filterRankings()">
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100" selected>100 / page</option>
                        <option value="250">250 / page</option>
                    </select>
                </div>
            </div>

            <div class="card rankings-chart-card">
                <div class="rankings-chart-head">
                    <div>
                        <h2 class="rankings-summary-title"><i class="fas fa-chart-line"></i> Rankingverlauf</h2>
                        <span class="rankings-summary-subtitle" id="rankingsChartInfo">Verlauf der aktuellen Auswahl.</span>
                    </div>
                    <div class="rankings-chart-controls">
                        <select id="trendRange" class="search-input search-input--compact" onchange="handleTrendRangeChange()">
                            <option value="7d">1 Woche</option>
                            <option value="1m">1 Monat</option>
                            <option value="3m">3 Monate</option>
                            <option value="6m" selected>6 Monate</option>
                            <option value="12m">12 Monate</option>
                            <option value="custom">Benutzerdefiniert</option>
                        </select>
                        <label class="rankings-toggle-filter">
                            <input type="checkbox" id="comparePrevYear" onchange="renderTrendChart()">
                            <span>Mit Vorjahr vergleichen</span>
                        </label>
                        <input type="date" id="trendStartDate" class="search-input search-input--compact" onchange="handleTrendDateChange()">
                        <input type="date" id="trendEndDate" class="search-input search-input--compact" onchange="handleTrendDateChange()">
                    </div>
                </div>
                <div class="rankings-chart-wrap">
                    <canvas id="rankingsTrendChart" height="110"></canvas>
                </div>
            </div>

            <!-- Rankings Table -->
            <div class="card">
                <div class="table-wrap">
                    <table class="data-table" id="rankingsTable">
                        <thead>
                            <tr>
                                <th class="sortable-r" data-col="0"># <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="1">Brand <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="2">Domain <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="3">Keyword <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="4">URL <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="5">Position <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="6">Change <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="7">Volume <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="8">CPC <span class="sort-icon"></span></th>
                                <th class="sortable-r" data-col="9">Last Checked <span class="sort-icon"></span></th>
                                <th>Actions</th>
                            </tr>
                            <tr class="col-search-row">
                                <th></th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-rcol-op="1" onchange="filterRankings()">
                                            <option value="contains">enthält</option>
                                            <option value="not_contains">enthält nicht</option>
                                        </select>
                                        <input class="col-search-input" placeholder="Brand" data-rcol="1" oninput="filterRankings()">
                                    </div>
                                </th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-rcol-op="2" onchange="filterRankings()">
                                            <option value="contains">enthält</option>
                                            <option value="not_contains">enthält nicht</option>
                                        </select>
                                        <input class="col-search-input" placeholder="Domain" data-rcol="2" oninput="filterRankings()">
                                    </div>
                                </th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-rcol-op="3" onchange="filterRankings()">
                                            <option value="contains">enthält</option>
                                            <option value="not_contains">enthält nicht</option>
                                        </select>
                                        <input class="col-search-input" placeholder="Keyword" data-rcol="3" oninput="filterRankings()">
                                    </div>
                                </th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-rcol-op="4" onchange="filterRankings()">
                                            <option value="contains">enthält</option>
                                            <option value="not_contains">enthält nicht</option>
                                        </select>
                                        <input class="col-search-input" placeholder="URL" data-rcol="4" oninput="filterRankings()">
                                    </div>
                                </th>
                                <th><input class="col-search-input" placeholder="Position" data-rcol="5" oninput="filterRankings()"></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rankingsBody">
                            <tr class="loading-row"><td colspan="11"><div class="spinner"></div></td></tr>
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

    <script src="assets/js/main.js"></script>
    <script>window.SYNC_RANKING_DOMAINS = <?= json_encode(array_keys(MONITORED_DOMAINS), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="assets/js/sync_stats.js"></script>
    <script src="assets/js/rankings.js"></script>
</body>
</html>
