<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260506a">
    <style>
        .db-layout {
            display: grid;
            gap: 18px;
            min-width: 0;
        }
        .db-select-row {
            display: grid;
            grid-template-columns: minmax(180px, 240px) minmax(260px, 1fr);
            gap: 12px;
            align-items: end;
        }
        .db-field {
            display: grid;
            gap: 8px;
        }
        .db-field label {
            color: var(--zap-text);
            font-size: .86rem;
            font-weight: 600;
        }
        .db-select {
            width: 100%;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,.10);
            background: rgba(255,255,255,.04);
            color: var(--zap-text);
            padding: 0 14px;
            font: inherit;
        }
        .db-toolbar,
        .db-rows-root {
            display: grid;
            gap: 12px;
        }
        .db-page-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .db-chip {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.05);
            color: var(--zap-text-muted);
            font-size: .8rem;
        }
        .db-chip strong {
            color: var(--zap-text);
        }
        .db-detail-card {
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
            border-radius: 18px;
            padding: 16px;
            min-width: 0;
        }
        .db-detail-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .db-detail-title {
            margin: 0;
            color: var(--zap-text);
            font-weight: 700;
            font-size: 1.05rem;
        }
        .db-scroll {
            overflow: auto;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,.06);
        }
        .db-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }
        .db-table th,
        .db-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            text-align: left;
            vertical-align: top;
            font-size: .84rem;
        }
        .db-table th {
            position: sticky;
            top: 0;
            background: rgba(20,20,31,.96);
            color: var(--zap-text);
            z-index: 1;
        }
        .db-table td {
            color: var(--zap-text-muted);
            white-space: pre-wrap;
            word-break: break-word;
            max-width: 360px;
        }
        .db-empty {
            padding: 28px;
            text-align: center;
            color: var(--zap-text-muted);
            border: 1px dashed rgba(255,255,255,.10);
            border-radius: 18px;
        }
        @media (max-width: 1180px) {
            .db-select-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header">
                <h1><i class="fas fa-database"></i> Database</h1>
                <span class="page-subtitle">Live table contents for the back office.</span>
            </div>

            <div class="db-layout">
                <div class="card">
                    <div class="db-select-row">
                        <div class="db-field">
                            <label for="dbGroupSelect">Group</label>
                            <select id="dbGroupSelect" class="db-select">
                                <option value="">Loading groups...</option>
                            </select>
                        </div>
                        <div class="db-field">
                            <label for="dbTableSelect">Table</label>
                            <select id="dbTableSelect" class="db-select">
                                <option value="">Loading tables...</option>
                            </select>
                        </div>
                    </div>
                    <div class="db-toolbar">
                        <span id="dbInfoText" class="table-footer" style="padding:0;border:0;">Loading...</span>
                    </div>
                </div>

                <div class="db-rows-root" id="dbRowsRoot">
                    <div class="db-empty"><i class="fas fa-arrow-up"></i><br>Select a group and a table above.</div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260506a"></script>
    <script src="assets/js/database.js?v=20260429a"></script>
</body>
</html>
