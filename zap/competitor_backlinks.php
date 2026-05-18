<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3. Competitor Backlinks - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260507a">
    <style>
        .step-grid { display:grid; gap:18px; }
        .step-toolbar { display:grid; grid-template-columns: minmax(180px,240px) minmax(160px,220px) minmax(220px,1fr) minmax(220px,1fr); gap:12px; align-items:end; }
        .step-field { display:grid; gap:8px; }
        .step-field label { color:var(--zap-text); font-size:.86rem; font-weight:600; }
        .step-empty { padding:34px; border:1px dashed rgba(255,255,255,.10); border-radius:18px; text-align:center; color:var(--zap-text-muted); }
        .step-link { color:var(--zap-text); text-decoration:none; word-break:break-word; }
        .step-link:hover { color:var(--zap-green); }
        .step-badge { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; font-size:.78rem; font-weight:700; }
        .step-badge.ok { background:rgba(87,188,84,.14); color:#d7ffd5; border:1px solid rgba(87,188,84,.3); }
        .step-badge.muted { background:rgba(255,255,255,.05); color:var(--zap-text-muted); border:1px solid rgba(255,255,255,.08); }
        .step-badge.warn { background:rgba(255,183,77,.14); color:#ffe2a8; border:1px solid rgba(255,183,77,.34); }
        .step-badge.info { background:rgba(0,200,255,.12); color:#bff6ff; border:1px solid rgba(0,200,255,.28); }
        .step-badge.error { background:rgba(255,92,92,.14); color:#ffd4d4; border:1px solid rgba(255,92,92,.30); }
        .step-table td, .step-table th { vertical-align:top; }
        .queue-grid { display:grid; gap:18px; }
        .queue-live-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:14px; }
        .queue-live-title { display:grid; gap:6px; }
        .queue-meta { color:var(--zap-text-muted); font-size:.8rem; text-align:right; min-width:180px; }
        .queue-table td, .queue-table th { vertical-align:top; }
        .queue-status-cell { white-space:nowrap; }
        .queue-filter-row { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
        .queue-filter-buttons { display:flex; gap:10px; flex-wrap:wrap; }
        .queue-filter-button { border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04); color:var(--zap-text); border-radius:999px; padding:8px 12px; cursor:pointer; font:inherit; font-weight:700; }
        .queue-filter-button.is-selected { background:rgba(24,232,136,.14); border-color:rgba(24,232,136,.30); color:#d9ffd8; }
        .control-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; }
        .control-card { padding:16px 18px; border-radius:18px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.03); display:grid; gap:10px; }
        .control-card h3 { margin:0; font-size:1rem; }
        .control-row { display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .control-button { border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04); color:var(--zap-text); border-radius:999px; padding:8px 12px; cursor:pointer; font:inherit; font-weight:700; }
        .control-button.is-active { background:rgba(255,92,92,.14); border-color:rgba(255,92,92,.30); color:#ffd7d7; }
        .control-button.is-idle { background:rgba(87,188,84,.14); border-color:rgba(87,188,84,.30); color:#d9ffd8; }
        .control-meta { color:var(--zap-text-muted); font-size:.82rem; }
        @media (max-width: 1200px) { .step-toolbar { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .queue-live-head { flex-direction:column; } .queue-meta { text-align:left; min-width:0; } }
        .pipeline-status-bar { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:12px; border:1px solid rgba(255,255,255,.08); background:rgba(0,0,0,.15); font-size:.86rem; flex-wrap:wrap; margin-bottom:16px; transition:border-color .3s; }
        .pipeline-status-bar .ps-icon { font-size:1.1rem; flex-shrink:0; }
        .pipeline-status-bar .ps-text { flex:1; min-width:200px; line-height:1.5; }
        .pipeline-status-bar .ps-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .pipeline-trigger-btn { border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.05); color:var(--zap-text); border-radius:999px; padding:7px 14px; cursor:pointer; font:inherit; font-size:.82rem; font-weight:700; white-space:nowrap; transition:background .15s,border-color .15s; }
        .pipeline-trigger-btn:hover { border-color:rgba(24,232,136,.4); color:#d9ffd8; background:rgba(24,232,136,.08); }
        .pipeline-trigger-btn.is-danger { border-color:rgba(255,120,120,.3); color:#ffd4d4; }
        .pipeline-trigger-btn.is-danger:hover { border-color:rgba(255,92,92,.5); background:rgba(255,92,92,.1); }
        .pipeline-trigger-btn:disabled { opacity:.4; cursor:default; pointer-events:none; }
        /* ── Live Log Viewer ── */
        .log-viewer-card { font-size:.82rem; }
        .log-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
        .log-toolbar label { color:var(--zap-text-muted); font-size:.8rem; white-space:nowrap; }
        .log-select { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); color:var(--zap-text); border-radius:8px; padding:6px 10px; font:inherit; font-size:.82rem; cursor:pointer; }
        .log-badge { font-size:.75rem; padding:3px 8px; border-radius:999px; font-weight:700; }
        .log-badge.running { background:rgba(24,232,136,.15); color:#18e888; border:1px solid rgba(24,232,136,.3); }
        .log-badge.idle    { background:rgba(255,255,255,.06); color:var(--zap-text-muted); border:1px solid rgba(255,255,255,.1); }
        .log-badge.error   { background:rgba(255,92,92,.15); color:#ff7070; border:1px solid rgba(255,92,92,.3); }
        .log-meta { font-size:.78rem; color:var(--zap-text-muted); }
        .log-terminal { background:#0d1117; border:1px solid rgba(255,255,255,.1); border-radius:12px; padding:14px 16px; overflow-y:auto; height:380px; font-family:'Fira Code','Cascadia Code','Consolas',monospace; font-size:.78rem; line-height:1.65; white-space:pre-wrap; word-break:break-all; color:#c9d1d9; scroll-behavior:smooth; }
        .log-terminal .log-ts    { color:#6e7681; }
        .log-terminal .log-tag   { color:#79c0ff; font-weight:700; }
        .log-terminal .log-error { color:#ff7b72; font-weight:700; }
        .log-terminal .log-warn  { color:#d29922; }
        .log-terminal .log-info  { color:#3fb950; }
        .log-terminal .log-debug { color:#6e7681; }
        .log-terminal .log-hl    { color:#e3b341; font-weight:700; }
        .log-empty { color:#6e7681; font-style:italic; }
        .log-auto-scroll-btn { font-size:.75rem; padding:5px 10px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header">
                <h1><i class="fas fa-link"></i> 3. Competitor Backlinks</h1>
                <span class="page-subtitle">Vollautomatische Pipeline (ZAP 2–4): SERP → Backlinks → E-Mail-Enrichment. Alle Worker laufen als Python-Daemons — kein manuelles Dispatching nötig. Die Tabellen aktualisieren sich automatisch alle 5 Sekunden.</span>
            </div>

            <div class="step-grid">
                <!-- ── Pipeline Controls ── -->
                <div class="card">
                    <div class="queue-live-head" style="margin-bottom:14px;">
                        <div class="queue-live-title">
                            <h2 class="rankings-summary-title"><i class="fas fa-sliders-h"></i> Pipeline Controls</h2>
                            <span class="page-subtitle" id="cbControlsSummaryText" style="margin:0;">Loading status...</span>
                        </div>
                        <div class="queue-meta">
                            <div id="cbControlsRefreshText"></div>
                        </div>
                    </div>
                    <div class="pipeline-status-bar" id="cbPipelineStatusBar">
                        <span class="ps-icon" id="cbStatusIcon"><i class="fas fa-circle-notch fa-spin"></i></span>
                        <span class="ps-text" id="cbStatusText">Lade Pipeline-Status…</span>
                        <div class="ps-actions">
                            <button class="pipeline-trigger-btn" id="cbRunZap2Btn" onclick="triggerZap2Worker()" title="ZAP 2: SERP-Check für alle Keywords mit Position > 10. Findet Competitor-Backlinks und befüllt die Enrichment-Queue.">
                                <i class="fas fa-search"></i> ZAP 2: SERP Check
                            </button>
                            <button class="pipeline-trigger-btn" id="cbRunZap4Btn" onclick="triggerZap4Worker()" title="ZAP 4: E-Mail-Enrichment für queued Domains (5 Stück). Besucht Homepage, Impressum, Kontakt und extrahiert E-Mails.">
                                <i class="fas fa-at"></i> ZAP 4: Enrich Emails
                            </button>
                            <button class="pipeline-trigger-btn" id="cbTriggerSerpBtn" onclick="triggerSerpCheck()" title="Startet den täglichen SERP-Daemon sofort (füllt competitor_backlink_queue mit neuen Keywords)">
                                <i class="fas fa-sync-alt"></i> SERP Daemon Now
                            </button>
                            <button class="pipeline-trigger-btn is-danger" id="cbFixStuckBtn" onclick="triggerFixStuckDomains()" title="Setzt Domains mit veraltetem 'batch_claimed'-Status zurück in die Queue" style="display:none;">
                                <i class="fas fa-wrench"></i> Fix Stuck Domains (<span id="cbStuckCount">0</span>)
                            </button>
                            <button class="pipeline-trigger-btn is-danger" id="cbResetQueueBtn" onclick="triggerResetQueue()" title="Setzt die komplette ZAP-3-Backlink-Queue zurück auf pending — nur bei komplettem Neustart verwenden">
                                <i class="fas fa-redo"></i> Reset Backlink-Queue
                            </button>
                        </div>
                    </div>
                    <div id="cbStuckWarning" style="display:none; margin-top:10px; padding:10px 14px; border-radius:10px; border:1px solid rgba(255,183,77,.4); background:rgba(255,183,77,.08); color:#ffe2a8; font-size:.84rem; line-height:1.6;">
                        <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
                        <strong>Feststeckende Domains erkannt:</strong> <span id="cbStuckDetails"></span>
                        Diese Domains haben seit über einer Stunde den Status <code>processing / batch_claimed</code> — ein alter n8n-Workflow-Überbleibsel. Der Python-Poller verarbeitet sie nicht.
                        Klicke auf <strong>Fix Stuck Domains</strong>, um sie zurück in den Queue zu setzen.
                    </div>
                    <div class="control-grid" id="cbControlGrid">
                        <div class="step-empty">Loading controls...</div>
                    </div>
                </div>

                <div class="card queue-grid">
                    <div class="queue-live-head">
                        <div class="queue-live-title">
                            <h2 class="rankings-summary-title"><i class="fas fa-at"></i> Domain Processing Queue</h2>
                            <span class="page-subtitle" style="margin:0;">Der Python E-Mail-Poller verarbeitet queued Domains automatisch (alle ~4 Minuten, ~20 parallel). Status wechselt von <em>queued → enriching → found/no_email/no_imprint</em>. Pause stoppt den nächsten Batch. Die Tabelle aktualisiert sich alle 5 Sekunden.</span>
                            <span class="page-subtitle" id="cbQueueSummaryText" style="margin:0;">Loading queue summary...</span>
                        </div>
                        <div class="queue-meta">
                            <div id="cbQueueRefreshText">Refreshing queue...</div>
                            <div id="cbQueueHeartbeatText">Refreshing the domain queue every 5 seconds</div>
                        </div>
                    </div>
                    <div class="queue-filter-row">
                        <div class="queue-filter-buttons">
                            <button type="button" class="queue-filter-button is-selected" id="cbQueueFilterAll">All Entries</button>
                            <button type="button" class="queue-filter-button" id="cbQueueFilterProcessing">Currently Processing</button>
                        </div>
                        <div class="table-footer" style="padding:0;border:0;margin:0;">
                            <span id="cbQueueFilterInfo">Loading queue view...</span>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table queue-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Domain</th>
                                    <th>Homepage</th>
                                    <th>Example Backlink URL</th>
                                    <th>Backlink URLs</th>
                                    <th>Competitors</th>
                                    <th>Email</th>
                                    <th>Imprint URL</th>
                                    <th>Updated</th>
                                </tr>
                            </thead>
                            <tbody id="cbQueueBody">
                                <tr class="loading-row"><td colspan="9"><div class="spinner"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="step-toolbar">
                        <div class="step-field">
                            <label for="cbCompetitorSelect">Competitor</label>
                            <select id="cbCompetitorSelect" class="search-input"></select>
                        </div>
                        <div class="step-field">
                            <label for="cbFollowSelect">Follow</label>
                            <select id="cbFollowSelect" class="search-input">
                                <option value="">All</option>
                                <option value="dofollow">dofollow</option>
                                <option value="nofollow">nofollow</option>
                            </select>
                        </div>
                        <div class="step-field">
                            <label for="cbKeywordSearch">Keyword / Domain</label>
                            <input type="text" id="cbKeywordSearch" class="search-input" placeholder="Search keyword, competitor, backlink domain...">
                        </div>
                        <div class="step-field">
                            <label for="cbBacklinkUrlSearch">Backlink URL contains</label>
                            <input type="text" id="cbBacklinkUrlSearch" class="search-input" placeholder="Filter backlink URL...">
                        </div>
                    </div>
                    <div class="table-footer" style="margin-top:12px;padding:0;border:0;">
                        <span id="cbInfoText">Loading...</span>
                    </div>
                </div>

                <div class="card">
                    <div class="queue-live-head" style="margin-bottom:12px;">
                        <div class="queue-live-title">
                            <h2 class="rankings-summary-title"><i class="fas fa-database"></i> Backlink Inventory</h2>
                            <span class="page-subtitle" id="cbInventorySummaryText" style="margin:0;">Loading inventory summary...</span>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table step-table">
                            <thead>
                                <tr>
                                    <th>Discovered</th>
                                    <th>Keyword</th>
                                    <th>SV</th>
                                    <th>Competitor</th>
                                    <th>Competitor URL</th>
                                    <th>Backlink Domain</th>
                                    <th>Backlink URL</th>
                                    <th>Target URL</th>
                                    <th>DR</th>
                                    <th>Follow</th>
                                    <th>Email Status</th>
                                    <th>First Seen</th>
                                </tr>
                            </thead>
                            <tbody id="cbBody">
                                <tr class="loading-row"><td colspan="12"><div class="spinner"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="queue-live-head">
                        <div class="queue-live-title">
                            <h2 class="rankings-summary-title"><i class="fas fa-ban"></i> Ignored Domains</h2>
                            <span class="page-subtitle" style="margin:0;">Domains auto-classified as likely linkfarms or manually removed from further follow-up remain visible here.</span>
                            <span class="page-subtitle" id="cbIgnoredSummaryText" style="margin:0;">Loading ignored summary...</span>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table queue-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Domain</th>
                                    <th>Reason</th>
                                    <th>DR Max</th>
                                    <th>Spam Max</th>
                                    <th>Backlinks</th>
                                    <th>Updated</th>
                                </tr>
                            </thead>
                            <tbody id="cbIgnoredBody">
                                <tr class="loading-row"><td colspan="7"><div class="spinner"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ── Live Log Viewer ── -->
                <div class="card log-viewer-card">
                    <div class="queue-live-head" style="margin-bottom:0;">
                        <div class="queue-live-title">
                            <h2 class="rankings-summary-title"><i class="fas fa-terminal"></i> Live Worker Log</h2>
                        </div>
                        <div class="queue-meta">
                            <div id="logRefreshText" class="log-meta">Connecting...</div>
                        </div>
                    </div>

                    <div class="log-toolbar">
                        <label>Worker:</label>
                        <select id="logWorkerSelect" class="log-select" onchange="switchLogWorker()">
                            <option value="backlink">Backlink Worker (ZAP 3)</option>
                            <option value="serp">SERP Check (ZAP 2 / täglich)</option>
                            <option value="enrichment">E-Mail Enrichment (ZAP 4)</option>
                            <option value="discovery">Discovery (ZAP 1)</option>
                        </select>
                        <label>Zeilen:</label>
                        <select id="logLinesSelect" class="log-select" onchange="reloadLog()">
                            <option value="50">50</option>
                            <option value="100" selected>100</option>
                            <option value="200">200</option>
                            <option value="500">500</option>
                        </select>
                        <span id="logStatusBadge" class="log-badge idle">Idle</span>
                        <button class="pipeline-trigger-btn log-auto-scroll-btn" id="logAutoScrollBtn" onclick="toggleAutoScroll()">
                            <i class="fas fa-arrow-down"></i> Auto-Scroll AN
                        </button>
                        <span id="logFileMeta" class="log-meta"></span>
                    </div>

                    <div id="logTerminal" class="log-terminal">
                        <span class="log-empty">Loading log...</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260507a"></script>
    <script src="assets/js/competitor_backlinks.js?v=20260518h"></script>
</body>
</html>
