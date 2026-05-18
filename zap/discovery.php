<?php
require_once 'config.php';
$pdo = db();
$todayBerlin = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>1. Discovery - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260507a">
    <style>
        /* ── Layout ── */
        .step-grid  { display:grid; gap:18px; }
        .step-field { display:grid; gap:8px; }
        .step-field label { color:var(--zap-text); font-size:.86rem; font-weight:600; }
        .step-empty { padding:34px; border:1px dashed rgba(255,255,255,.10); border-radius:18px; text-align:center; color:var(--zap-text-muted); }
        .step-table td,.step-table th { white-space:nowrap; }
        .step-link  { color:var(--zap-text); text-decoration:none; }
        .step-link:hover { color:var(--zap-green); }

        /* ── View toggle tabs ── */
        .view-tabs  { display:flex; gap:6px; }
        .view-tab   { border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:var(--zap-text-muted); border-radius:10px; padding:8px 18px; cursor:pointer; font:inherit; font-size:.86rem; font-weight:600; transition:all .15s; }
        .view-tab.is-active { background:rgba(24,232,136,.12); border-color:rgba(24,232,136,.30); color:#d9ffd8; }

        /* ── Timeline strip ── */
        .timeline-strip { display:flex; gap:8px; flex-wrap:wrap; align-items:stretch; padding-bottom:4px; }
        .timeline-pill  { display:flex; flex-direction:column; align-items:center; gap:3px; padding:8px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.03); cursor:pointer; transition:all .15s; min-width:90px; }
        .timeline-pill:hover   { border-color:rgba(24,232,136,.3); background:rgba(24,232,136,.06); }
        .timeline-pill.is-selected { border-color:rgba(24,232,136,.5); background:rgba(24,232,136,.12); }
        .tl-date   { font-size:.78rem; font-weight:700; color:var(--zap-text); }
        .tl-count  { font-size:.72rem; color:var(--zap-text-muted); }
        .tl-bar    { width:100%; height:4px; border-radius:2px; background:rgba(24,232,136,.25); margin-top:3px; }
        .tl-bar-fill { height:100%; border-radius:2px; background:#18e888; transition:width .3s; }

        /* ── Stats chips ── */
        .disc-stats { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .disc-chip  { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; background:rgba(255,255,255,.05); font-size:.82rem; white-space:nowrap; }
        .disc-chip strong { color:var(--zap-text); }
        .disc-chip span   { color:var(--zap-text-muted); }

        /* ── Toolbar ── */
        .disc-toolbar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
        .disc-toolbar .step-field { flex:1; min-width:140px; }
        .disc-toolbar .step-field.fixed { flex:0 0 auto; }
        .search-input.sm { padding:8px 12px; font-size:.82rem; }

        @media (max-width: 768px) { .disc-toolbar { flex-direction:column; } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header">
                <h1><i class="fas fa-binoculars"></i> 1. Discovery</h1>
                <span class="page-subtitle">Keyword-Portfolio von ZAP — kumulierte Gesamtliste + tägliche Discovery-Snapshots.</span>
            </div>

            <div class="step-grid">

                <!-- ── Controls / Timeline Card ── -->
                <div class="card">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <div class="view-tabs">
                            <button class="view-tab is-active" id="tabCumulative" onclick="switchView('cumulative')">
                                <i class="fas fa-layer-group"></i> Gesamt-Portfolio
                            </button>
                            <button class="view-tab" id="tabDaily" onclick="switchView('daily')">
                                <i class="fas fa-calendar-day"></i> Tages-Snapshots
                            </button>
                        </div>
                        <div id="discRefreshText" style="font-size:.8rem;color:var(--zap-text-muted);"></div>
                    </div>

                    <!-- Timeline (shown in daily mode) -->
                    <div id="timelineWrap" style="display:none;margin-bottom:16px;">
                        <div style="font-size:.8rem;color:var(--zap-text-muted);margin-bottom:8px;">
                            Discovery-Snapshots — wähle einen Tag:
                        </div>
                        <div class="timeline-strip" id="timelineStrip">
                            <div class="step-empty" style="padding:12px 20px;">Loading timeline...</div>
                        </div>
                    </div>

                    <!-- Summary chips -->
                    <div class="disc-stats" id="discStats">
                        <div class="disc-chip"><i class="fas fa-circle-notch fa-spin" style="color:var(--zap-green)"></i> <span>Loading...</span></div>
                    </div>
                </div>

                <!-- ── Filter toolbar ── -->
                <div class="card">
                    <div class="disc-toolbar">
                        <div class="step-field">
                            <label for="discKeyword">Keyword</label>
                            <input type="text" id="discKeyword" class="search-input sm" placeholder="Suchen...">
                        </div>
                        <div class="step-field">
                            <label for="discUrl">URL</label>
                            <input type="text" id="discUrl" class="search-input sm" placeholder="URL-Filter...">
                        </div>
                        <div class="step-field fixed">
                            <label for="discPos">Position</label>
                            <select id="discPos" class="search-input sm">
                                <option value="">Alle</option>
                                <option value="1-3">Top 3</option>
                                <option value="1-10">Top 10</option>
                                <option value="11-20">11–20</option>
                                <option value="21-100">21–100</option>
                                <option value="101-9999">Über 100</option>
                            </select>
                        </div>
                        <div class="step-field fixed">
                            <label for="discLimit">Zeilen</label>
                            <select id="discLimit" class="search-input sm">
                                <option value="200">200</option>
                                <option value="500" selected>500</option>
                                <option value="1000">1.000</option>
                                <option value="2000">2.000</option>
                                <option value="5000">5.000</option>
                            </select>
                        </div>
                        <div class="step-field fixed" style="justify-content:flex-end;">
                            <label>&nbsp;</label>
                            <button type="button" class="btn btn-secondary" onclick="loadDiscovery()">
                                <i class="fas fa-filter"></i> Anwenden
                            </button>
                        </div>
                    </div>
                    <div style="margin-top:10px;font-size:.82rem;color:var(--zap-text-muted);" id="discTableInfo">Loading...</div>
                </div>

                <!-- ── Data table ── -->
                <div class="card">
                    <div class="table-wrap">
                        <table class="data-table step-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Keyword</th>
                                    <th>URL</th>
                                    <th>Position</th>
                                    <th>Vorherige</th>
                                    <th>Volumen</th>
                                    <th>CPC</th>
                                    <th>Location</th>
                                    <th id="dateColHeader">Zuletzt</th>
                                </tr>
                            </thead>
                            <tbody id="discBody">
                                <tr class="loading-row"><td colspan="9"><div class="spinner"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span id="discPaginationInfo"></span>
                        <div style="display:flex;gap:8px;">
                            <button class="btn btn-secondary" id="discPrevBtn" onclick="discPage(-1)" style="display:none;">
                                <i class="fas fa-chevron-left"></i> Zurück
                            </button>
                            <button class="btn btn-secondary" id="discNextBtn" onclick="discPage(1)" style="display:none;">
                                Weiter <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </div><!-- /step-grid -->
        </div>
    </div>

    <script src="assets/js/main.js?v=20260507a"></script>
    <script src="assets/js/discovery.js?v=20260518b"></script>
</body>
</html>
