<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>0. Zentrale - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260507a">
    <style>
        .control-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; }
        .control-card { padding:18px 20px; border-radius:18px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.03); display:grid; gap:12px; }
        .control-card h3 { margin:0; font-size:1rem; }
        .control-row { display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .control-button { border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04); color:var(--zap-text); border-radius:999px; padding:8px 12px; cursor:pointer; font:inherit; font-weight:700; }
        .control-button.is-active { background:rgba(255,92,92,.14); border-color:rgba(255,92,92,.30); color:#ffd7d7; }
        .control-button.is-idle { background:rgba(87,188,84,.14); border-color:rgba(87,188,84,.30); color:#d9ffd8; }
        .control-meta { color:var(--zap-text-muted); font-size:.82rem; }
        .queue-live-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:14px; }
        .queue-live-title { display:grid; gap:6px; }
        .queue-meta { color:var(--zap-text-muted); font-size:.8rem; text-align:right; min-width:180px; }
        .step-badge { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; font-size:.78rem; font-weight:700; }
        .step-badge.ok { background:rgba(87,188,84,.14); color:#d7ffd5; border:1px solid rgba(87,188,84,.3); }
        .step-badge.error { background:rgba(255,92,92,.14); color:#ffd4d4; border:1px solid rgba(255,92,92,.30); }
        @media (max-width: 768px) { .queue-live-head { flex-direction:column; } .queue-meta { text-align:left; min-width:0; } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header">
                <h1><i class="fas fa-sliders"></i> 0. Zentrale</h1>
                <span class="page-subtitle">Hier steuerst du zentral, ob neue Keywords aufgenommen, Wettbewerber-Backlinks geclaimt, E-Mail-Adressen gesucht oder Outreach-Schritte fortgesetzt werden.</span>
            </div>

            <div class="card">
                <div class="queue-live-head">
                    <div class="queue-live-title">
                        <h2 class="rankings-summary-title"><i class="fas fa-toggle-on"></i> Pipeline Steuerung</h2>
                        <span class="page-subtitle" style="margin:0;">Die Schalter stoppen oder starten jeweils nur neue Arbeit in der betreffenden Stufe. Bereits laufende n8n-Executions werden dadurch nicht hart beendet.</span>
                    </div>
                    <div class="queue-meta">
                        <div id="cbControlsRefreshText">Lade Steuerung...</div>
                        <div id="cbControlsSummaryText"></div>
                    </div>
                </div>
                <div class="control-grid" id="cbControlGrid">
                    <div class="control-card"><div class="spinner"></div></div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260507a"></script>
    <script src="assets/js/competitor_backlinks.js?v=20260512a"></script>
</body>
</html>
