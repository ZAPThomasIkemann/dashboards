<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2. Backlink Queue - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260506a">
    <style>
        .lpq-layout { display: grid; gap: 18px; min-width: 0; }
        .lpq-summary { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; }
        .lpq-card {
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
            border-radius: 20px;
            padding: 18px;
        }
        .lpq-metric-label { color: var(--zap-text-muted); font-size: .82rem; margin-bottom: 8px; }
        .lpq-metric-value { color: var(--zap-text); font-size: 1.8rem; font-weight: 800; line-height: 1; }
        .lpq-stack { display: grid; gap: 18px; }
        .lpq-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; }
        .lpq-panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .lpq-panel-title { margin: 0; color: var(--zap-text); font-size: 1rem; font-weight: 700; }
        .lpq-panel-sub { color: var(--zap-text-muted); font-size: .82rem; }
        .lpq-list { display: grid; gap: 12px; }
        .lpq-item {
            border: 1px solid rgba(255,255,255,.07);
            background: rgba(255,255,255,.03);
            border-radius: 16px;
            padding: 14px;
            min-width: 0;
        }
        .lpq-item-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 8px;
        }
        .lpq-keyword { color: var(--zap-text); font-weight: 700; }
        .lpq-domain { color: var(--zap-green); font-size: .82rem; word-break: break-word; }
        .lpq-url { color: var(--zap-text-muted); font-size: .8rem; word-break: break-word; margin-top: 5px; }
        .lpq-chip-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .lpq-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.05);
            color: var(--zap-text-muted);
            font-size: .78rem;
        }
        .lpq-chip strong { color: var(--zap-text); }
        .lpq-chip.is-processing { background: rgba(59,130,246,.16); color: #bfdbfe; }
        .lpq-chip.is-done { background: rgba(34,197,94,.16); color: #bbf7d0; }
        .lpq-chip.is-failed { background: rgba(239,68,68,.18); color: #fecaca; }
        .lpq-empty {
            border: 1px dashed rgba(255,255,255,.10);
            border-radius: 16px;
            padding: 26px;
            text-align: center;
            color: var(--zap-text-muted);
        }
        .lpq-table-wrap {
            overflow: auto;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
        }
        .lpq-table { width: 100%; min-width: 1220px; border-collapse: collapse; }
        .lpq-table th, .lpq-table td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            text-align: left;
            vertical-align: top;
            font-size: .84rem;
        }
        .lpq-table th {
            position: sticky;
            top: 0;
            background: rgba(20,20,31,.96);
            color: var(--zap-text);
            z-index: 1;
            white-space: nowrap;
        }
        .lpq-table td { color: var(--zap-text-muted); }
        .lpq-mini { font-size: .78rem; color: var(--zap-text-muted); }
        .lpq-error { color: #fecaca; white-space: pre-wrap; word-break: break-word; }
        @media (max-width: 1300px) {
            .lpq-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .lpq-two-col { grid-template-columns: 1fr; }
        }
        @media (max-width: 720px) {
            .lpq-summary { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header">
                <h1><i class="fas fa-list-check"></i> 2. Backlink Queue</h1>
                <span class="page-subtitle">Live status of competitor landing pages being processed by the backlink worker.</span>
            </div>

            <div class="lpq-layout">
                <div class="lpq-summary" id="lpqSummary">
                    <div class="lpq-card"><div class="lpq-metric-label">Total</div><div class="lpq-metric-value">-</div></div>
                    <div class="lpq-card"><div class="lpq-metric-label">Pending</div><div class="lpq-metric-value">-</div></div>
                    <div class="lpq-card"><div class="lpq-metric-label">Processing</div><div class="lpq-metric-value">-</div></div>
                    <div class="lpq-card"><div class="lpq-metric-label">Done</div><div class="lpq-metric-value">-</div></div>
                    <div class="lpq-card"><div class="lpq-metric-label">Failed</div><div class="lpq-metric-value">-</div></div>
                </div>

                <div class="card">
                    <div class="table-footer" style="padding:0;border:0;">
                        <span id="lpqInfo">Loading...</span>
                    </div>
                </div>

                <div class="lpq-stack">
                    <div class="lpq-card">
                        <div class="lpq-panel-head">
                            <h2 class="lpq-panel-title">Currently Processing</h2>
                            <span class="lpq-panel-sub">Claimed by the worker</span>
                        </div>
                        <div class="lpq-list" id="lpqProcessing"></div>
                    </div>

                    <div class="lpq-card">
                        <div class="lpq-panel-head">
                            <h2 class="lpq-panel-title">Up Next</h2>
                            <span class="lpq-panel-sub">Queued landing pages</span>
                        </div>
                        <div class="lpq-list" id="lpqPending"></div>
                    </div>
                </div>

                <div class="lpq-card">
                    <div class="lpq-panel-head">
                        <h2 class="lpq-panel-title">Recently Completed</h2>
                        <span class="lpq-panel-sub">Finished queue items</span>
                    </div>
                    <div class="lpq-table-wrap">
                        <table class="lpq-table">
                            <thead>
                                <tr>
                                    <th>Keyword</th>
                                    <th>Competitor</th>
                                    <th>Landing Page</th>
                                    <th>Prev</th>
                                    <th>Now</th>
                                    <th>SV</th>
                                    <th>Attempts</th>
                                    <th>Completed</th>
                                </tr>
                            </thead>
                            <tbody id="lpqDoneRows"></tbody>
                        </table>
                    </div>
                </div>

                <div class="lpq-card">
                    <div class="lpq-panel-head">
                        <h2 class="lpq-panel-title">Failed Queue Items</h2>
                        <span class="lpq-panel-sub">For tracing worker errors</span>
                    </div>
                    <div class="lpq-table-wrap">
                        <table class="lpq-table">
                            <thead>
                                <tr>
                                    <th>Keyword</th>
                                    <th>Competitor</th>
                                    <th>Landing Page</th>
                                    <th>Attempts</th>
                                    <th>Last Error</th>
                                    <th>Last Updated</th>
                                </tr>
                            </thead>
                            <tbody id="lpqFailedRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260506a"></script>
    <script src="assets/js/landingpage_queue.js?v=20260429e"></script>
</body>
</html>
