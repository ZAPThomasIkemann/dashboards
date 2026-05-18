<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260506a">
    <style>
        .recent-details-card {
            margin-top: 20px;
        }
        .recent-details-list {
            display: grid;
            gap: 14px;
        }
        .recent-details-item {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(0, 1.2fr) auto;
            gap: 14px;
            align-items: center;
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
        }
        .recent-details-main {
            min-width: 0;
        }
        .recent-source {
            margin: 0;
            font-weight: 600;
            color: var(--zap-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .recent-source a {
            color: inherit;
            text-decoration: none;
        }
        .recent-source a:hover {
            color: var(--zap-green);
        }
        .recent-target {
            margin-top: 5px;
            font-size: .92rem;
            color: var(--zap-text-muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .recent-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .metric-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.05);
            color: var(--zap-text-muted);
            font-size: .85rem;
            white-space: nowrap;
        }
        .metric-chip strong {
            color: var(--zap-text);
            font-weight: 600;
        }
        .recent-status {
            justify-self: end;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        .recent-status-meta {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            justify-self: end;
        }
        .recent-checked-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.05);
            color: var(--zap-text-muted);
            font-size: .85rem;
            white-space: nowrap;
        }
        .recent-checked-pill strong {
            color: var(--zap-text);
            font-weight: 600;
        }
        .recent-status.online {
            color: #22c55e;
        }
        .recent-status.offline {
            color: #f87171;
        }
        .dashboard-daily-section {
            margin-top: 20px;
        }
        .dashboard-daily-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
            min-width: 0;
        }
        .dashboard-daily-column {
            display: grid;
            gap: 18px;
            min-width: 0;
        }
        .dashboard-daily-toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .daily-tree {
            display: grid;
            gap: 16px;
        }
        .hour-group,
        .event-card,
        .competitor-card,
        .backlink-mini {
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
            border-radius: 18px;
        }
        .day-group {
            border-left: 2px solid rgba(255,255,255,.08);
            padding-left: 12px;
        }
        .day-header,
        .hour-header {
            width: 100%;
            border: 0;
            background: transparent;
            color: inherit;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            cursor: pointer;
        }
        .day-header {
            justify-content: flex-start;
            gap: 10px;
        }
        .day-header strong,
        .hour-header strong {
            font-size: 1rem;
        }
        .day-header .page-subtitle {
            text-align: left;
        }
        .day-body,
        .hour-body {
            padding: 6px 0 14px 0;
            display: grid;
            gap: 12px;
        }
        .event-card {
            padding: 14px 16px;
            transition: border-color .18s ease, transform .18s ease, background .18s ease;
        }
        .event-card:hover {
            border-color: rgba(57,255,20,.35);
            background: rgba(57,255,20,.08);
            transform: translateY(-1px);
        }
        .event-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .event-main {
            min-width: 0;
            flex: 1 1 320px;
        }
        .event-keyword {
            margin: 0;
            font-weight: 700;
            color: var(--zap-text);
        }
        .event-time {
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(57,255,20,.10);
            color: var(--zap-text);
            font-size: .82rem;
            font-weight: 600;
        }
        .event-url {
            margin-top: 6px;
            font-size: .88rem;
            color: var(--zap-text-muted);
            word-break: break-word;
        }
        .event-ranks {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex: 1 1 280px;
        }
        .event-chip,
        .detail-chip {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            white-space: nowrap;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.05);
            color: var(--zap-text-muted);
            font-size: .82rem;
        }
        .event-chip strong,
        .detail-chip strong {
            color: var(--zap-text);
        }
        .event-chip.is-danger,
        .detail-chip.is-danger {
            background: rgba(239,68,68,.18);
            color: #fecaca;
            border: 1px solid rgba(239,68,68,.35);
        }
        .event-chip.is-danger strong,
        .detail-chip.is-danger strong {
            color: #fee2e2;
        }
        .top3-list {
            display: grid;
            gap: 8px;
            margin-top: 12px;
        }
        .drop-list {
            display: grid;
            gap: 14px;
        }
        .top3-row {
            display: grid;
            grid-template-columns: 28px minmax(0,1fr);
            gap: 10px;
            padding: 9px 10px;
            border-radius: 14px;
            background: rgba(255,255,255,.04);
        }
        .top3-rank {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            background: rgba(57,255,20,.14);
            color: var(--zap-green);
        }
        .top3-domain {
            font-weight: 600;
            color: var(--zap-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .top3-link {
            display: block;
            margin-top: 3px;
            color: var(--zap-text-muted);
            font-size: .84rem;
            word-break: break-word;
        }
        .detail-empty,
        .detail-placeholder {
            padding: 30px;
            text-align: center;
            color: var(--zap-text-muted);
        }
        .detail-header {
            display: grid;
            gap: 14px;
        }
        .detail-title {
            margin: 0;
            font-size: 1.4rem;
        }
        .detail-subtitle {
            color: var(--zap-text-muted);
            font-size: .95rem;
        }
        .detail-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .detail-top3-grid,
        .detail-competitors {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }
        .drop-card {
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
            border-radius: 18px;
            padding: 16px;
        }
        .competitor-card {
            padding: 16px;
        }
        .competitor-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }
        .competitor-title {
            margin: 0;
            font-size: 1rem;
        }
        .competitor-url {
            margin-top: 6px;
            color: var(--zap-text-muted);
            font-size: .88rem;
            word-break: break-word;
        }
        .backlink-list {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }
        .backlink-mini {
            padding: 12px 14px;
        }
        .backlink-source {
            font-weight: 600;
            color: var(--zap-text);
            word-break: break-word;
        }
        .backlink-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .badge-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255,255,255,.06);
            color: var(--zap-text);
            font-size: .8rem;
            font-weight: 700;
        }
        @media (max-width: 960px) {
            .recent-details-item {
                grid-template-columns: 1fr;
            }
            .recent-status {
                justify-self: start;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <?php
            $dashboardBrand = dashboard_scope_brand();
            $dashboardDomains = dashboard_scope_domains();
            $domainNames = array_map(static fn(array $meta): string => (string) ($meta['name'] ?? ''), $dashboardDomains);
            $primaryDomainName = '';
            if (!empty($domainNames)) {
                $primaryDomainName = (string) reset($domainNames);
            }
            $recent = [];

            try {
                $pdo = db();
                $brandSql = $pdo->quote((string) $dashboardBrand);
                $total = $pdo->query("SELECT COUNT(*) FROM backlinks WHERE brand = {$brandSql}")->fetchColumn();
                $online = $pdo->query("SELECT COUNT(*) FROM backlinks WHERE brand = {$brandSql} AND is_online = 1")->fetchColumn();
                $offline = $pdo->query("SELECT COUNT(*) FROM backlinks WHERE brand = {$brandSql} AND is_online = 0")->fetchColumn();
                $zap_count = $total;

                $recentStmt = $pdo->prepare(
                    "SELECT brand, source_url, target_url, link_type, domain_rating, is_online, first_seen, last_checked
                     FROM backlinks
                     WHERE brand = ?
                     ORDER BY COALESCE(last_checked, first_seen) DESC
                     LIMIT 200"
                );
                $recentStmt->execute([(string) $dashboardBrand]);
                $recentRows = $recentStmt->fetchAll();
                $recent = [];
                $recentHosts = [];
                foreach ($recentRows as $recentRow) {
                    $recentSourceUrl = (string) ($recentRow['source_url'] ?? '');
                    $recentSourceHost = parse_url($recentSourceUrl, PHP_URL_HOST) ?: $recentSourceUrl;
                    $recentHostKey = strtolower(preg_replace('/^www\./i', '', (string) $recentSourceHost));
                    if ($recentHostKey === '' || isset($recentHosts[$recentHostKey])) {
                        continue;
                    }
                    $recentHosts[$recentHostKey] = true;
                    $recent[] = $recentRow;
                    if (count($recent) >= 10) {
                        break;
                    }
                }
            } catch (Exception $e) {
                $total = $online = $offline = $zap_count = 0;
                $recent = [];
            }
            ?>

            <div class="page-header">
                <h1><i class="fas fa-tachometer-alt"></i> Overview</h1>
                <span class="page-subtitle">Welcome to the ZAP Marketing Dashboard</span>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-link"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format((int) $total, 0, ',', '.') ?></div>
                        <div class="stat-label">Total Backlinks</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon cyan"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format((int) $online, 0, ',', '.') ?></div>
                        <div class="stat-label">Online Links</div>
                    </div>
                </div>
                <a href="backlinks.php?offline=1" class="stat-card stat-card--link stat-card--offline-link" title="Open offline backlinks only" aria-label="Offline backlinks: open only links with offline status">
                    <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format((int) $offline, 0, ',', '.') ?></div>
                        <div class="stat-label">Offline Links</div>
                        <span class="stat-card-link-hint">Alle anzeigen &rarr;</span>
                    </div>
                </a>
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-bolt"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format((int) $zap_count, 0, ',', '.') ?></div>
                        <div class="stat-label">Backlinks</div>
                    </div>
                </div>
            </div>

            <div class="card recent-details-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Recently Checked</h3>
                    <a href="backlinks.php" class="btn-sm">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent)): ?>
                        <div class="empty-state"><i class="fas fa-inbox"></i><p>No backlinks tracked yet.<br><a href="backlinks.php">Add your first backlink</a></p></div>
                    <?php else: ?>
                        <div class="recent-details-list">
                            <?php foreach ($recent as $row): ?>
                                <?php
                                $sourceUrl = (string) ($row['source_url'] ?? '');
                                $targetUrl = (string) ($row['target_url'] ?? '');
                                $sourceHost = parse_url($sourceUrl, PHP_URL_HOST) ?: $sourceUrl;
                                $targetHost = parse_url($targetUrl, PHP_URL_HOST) ?: $targetUrl;
                                $checkedAt = !empty($row['last_checked']) ? strtotime((string) $row['last_checked']) : false;
                                $firstSeenAt = !empty($row['first_seen']) ? strtotime((string) $row['first_seen']) : false;
                                ?>
                                <div class="recent-details-item">
                                    <div class="recent-details-main">
                                        <p class="recent-source">
                                            <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                <?= htmlspecialchars((string) $sourceHost) ?>
                                            </a>
                                        </p>
                                        <div class="recent-target">
                                            Target: <?= htmlspecialchars((string) $targetHost) ?>
                                        </div>
                                    </div>
                                    <div class="recent-metrics">
                                        <span class="metric-chip"><strong>Brand</strong> <?= htmlspecialchars((string) ($row['brand'] ?: $dashboardBrand)) ?></span>
                                        <span class="metric-chip"><strong>Type</strong> <?= htmlspecialchars((string) ($row['link_type'] ?: 'n/a')) ?></span>
                                        <span class="metric-chip"><strong>DR</strong> <?= is_numeric($row['domain_rating'] ?? null) ? number_format((float) $row['domain_rating'], 1, '.', '') : 'n/a' ?></span>
                                        <span class="metric-chip"><strong>First Seen</strong> <?= $firstSeenAt ? date('d.m.Y', $firstSeenAt) : 'n/a' ?></span>
                                        <span class="metric-chip"><strong>Checked</strong> <?= $checkedAt ? date('d.m.Y H:i', $checkedAt) : 'n/a' ?></span>
                                    </div>
                                    <div class="recent-status-meta">
                                        <div class="recent-status <?= !empty($row['is_online']) ? 'online' : 'offline' ?>">
                                            <span class="status-dot <?= !empty($row['is_online']) ? 'online' : 'offline' ?>"></span>
                                            <?= !empty($row['is_online']) ? 'Online' : 'Offline' ?>
                                        </div>
                                        <span class="recent-checked-pill">
                                            <strong>Checked</strong>
                                            <?= $checkedAt ? date('d.m.Y H:i', $checkedAt) : 'n/a' ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-daily-section">
                <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <div>
                        <h1><i class="fas fa-clock-rotate-left"></i> Daily Data</h1>
                <span class="page-subtitle">Daily and hourly drop data with rankings, top 3 results, and backlink details</span>
                    </div>
                    <div class="dashboard-daily-toolbar">
                        <select id="dailyDateSelect" class="search-input search-input--compact"></select>
                        <select id="dailyHourSelect" class="search-input search-input--compact"></select>
                        <input type="text" id="dailyKeywordSearch" class="search-input" placeholder="Search keyword...">
                        <button class="btn btn-secondary" id="dailyReloadBtn"><i class="fas fa-sync-alt"></i> Reload</button>
                        <a href="daily_data.php" class="btn btn-secondary"><i class="fas fa-up-right-from-square"></i> Full View</a>
                    </div>
                </div>

                <div class="dashboard-daily-layout">
                    <div class="dashboard-daily-column">
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-calendar-days"></i> Daily Data</h3>
                                <span id="dailyDataInfo" class="page-subtitle">Loading...</span>
                            </div>
                            <div class="card-body">
                                <div id="dailyDataTree" class="daily-tree">
                                    <div class="detail-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading timeline...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-daily-column">
                        <div class="card">
                            <div class="card-header">
                <h3><i class="fas fa-triangle-exclamation"></i> Outside Top 10</h3>
                                <span id="dailyDropInfo" class="page-subtitle">Loading...</span>
                            </div>
                            <div class="card-body">
                                <div id="dailyDropTree" class="drop-list">
                                    <div class="detail-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading drop details...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/main.js?v=20260506a"></script>
    <script src="assets/js/daily_data.js?v=20260427j"></script>
</body>
</html>

