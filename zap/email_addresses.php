<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>4. E-Mail Addresses - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260507a">
    <style>
        .step-grid { display:grid; gap:18px; }
        .step-toolbar { display:grid; grid-template-columns: minmax(220px,1fr) minmax(220px,1fr) minmax(160px,220px); gap:12px; align-items:end; }
        .step-field { display:grid; gap:8px; }
        .step-field label { color:var(--zap-text); font-size:.86rem; font-weight:600; }
        .step-link { color:var(--zap-text); text-decoration:none; word-break:break-word; }
        .step-link:hover { color:var(--zap-green); }
        .step-empty { padding:34px; border:1px dashed rgba(255,255,255,.10); border-radius:18px; text-align:center; color:var(--zap-text-muted); }
        @media (max-width: 1100px) { .step-toolbar { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header">
                <h1><i class="fas fa-at"></i> 4. E-Mail Addresses</h1>
                <span class="page-subtitle">All discovered combinations of unique referring domains and e-mail addresses, grouped from the backlink inventory.</span>
            </div>

            <div class="step-grid">
                <div class="card">
                    <div class="step-toolbar">
                        <div class="step-field">
                            <label for="eaDomainSearch">Domain</label>
                            <input type="text" id="eaDomainSearch" class="search-input" placeholder="Search referring domain...">
                        </div>
                        <div class="step-field">
                            <label for="eaEmailSearch">E-Mail</label>
                            <input type="text" id="eaEmailSearch" class="search-input" placeholder="Search e-mail address...">
                        </div>
                        <div class="step-field">
                            <label>&nbsp;</label>
                            <button type="button" class="btn btn-secondary" id="eaRefreshBtn"><i class="fas fa-filter"></i> Refresh</button>
                        </div>
                    </div>
                    <div class="table-footer" style="margin-top:12px;padding:0;border:0;">
                        <span id="eaInfoText">Loading...</span>
                    </div>
                </div>

                <div class="card">
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>E-Mail</th>
                                    <th>Imprint URL</th>
                                    <th>Backlinks</th>
                                    <th>Competitors</th>
                                    <th>First Found</th>
                                    <th>Last Checked</th>
                                    <th>Example Backlink</th>
                                </tr>
                            </thead>
                            <tbody id="eaBody">
                                <tr class="loading-row"><td colspan="8"><div class="spinner"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260507a"></script>
    <script src="assets/js/email_addresses.js?v=20260507a"></script>
</body>
</html>
