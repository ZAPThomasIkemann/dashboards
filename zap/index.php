<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZAP Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header">
                <h1><i class="fas fa-tachometer-alt"></i> Overview</h1>
                <span class="page-subtitle">Welcome to the ZAP Marketing Dashboard</span>
            </div>

            <?php
            try {
                $pdo = db();
                $total = $pdo->query("SELECT COUNT(*) FROM backlinks")->fetchColumn();
                $online = $pdo->query("SELECT COUNT(*) FROM backlinks WHERE is_online = 1")->fetchColumn();
                $offline = $pdo->query("SELECT COUNT(*) FROM backlinks WHERE is_online = 0")->fetchColumn();
                $zap_count = $pdo->query("SELECT COUNT(*) FROM backlinks WHERE brand = 'ZAP'")->fetchColumn();
                $dmc_count = $pdo->query("SELECT COUNT(*) FROM backlinks WHERE brand = 'DMC'")->fetchColumn();
            } catch (Exception $e) {
                $total = $online = $offline = $zap_count = $dmc_count = 0;
            }
            ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-link"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total) ?></div>
                        <div class="stat-label">Total Backlinks</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon cyan"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($online) ?></div>
                        <div class="stat-label">Online Links</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($offline) ?></div>
                        <div class="stat-label">Offline Links</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-bolt"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($zap_count) ?></div>
                        <div class="stat-label">ZAP Backlinks</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-fire"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($dmc_count) ?></div>
                        <div class="stat-label">DMC Backlinks</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Brand Distribution</h3>
                    </div>
                    <div class="card-body">
                        <div class="brand-bars">
                            <?php
                            $zap_pct = $total > 0 ? round(($zap_count / $total) * 100) : 0;
                            $dmc_pct = $total > 0 ? round(($dmc_count / $total) * 100) : 0;
                            ?>
                            <div class="brand-bar-item">
                                <div class="brand-bar-label">
                                    <span class="brand-badge zap">ZAP</span>
                                    <span><?= $zap_count ?> links (<?= $zap_pct ?>%)</span>
                                </div>
                                <div class="progress-bar"><div class="progress-fill green" style="width:<?= $zap_pct ?>%"></div></div>
                            </div>
                            <div class="brand-bar-item">
                                <div class="brand-bar-label">
                                    <span class="brand-badge dmc">DMC</span>
                                    <span><?= $dmc_count ?> links (<?= $dmc_pct ?>%)</span>
                                </div>
                                <div class="progress-bar"><div class="progress-fill cyan" style="width:<?= $dmc_pct ?>%"></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Recently Checked</h3>
                        <a href="backlinks.php" class="btn-sm">View All</a>
                    </div>
                    <div class="card-body">
                        <?php
                        try {
                            $recent = $pdo->query("SELECT brand, source_url, is_online, last_checked FROM backlinks ORDER BY last_checked DESC LIMIT 5")->fetchAll();
                        } catch (Exception $e) { $recent = []; }
                        ?>
                        <?php if (empty($recent)): ?>
                            <div class="empty-state"><i class="fas fa-inbox"></i><p>No backlinks tracked yet.<br><a href="backlinks.php">Add your first backlink</a></p></div>
                        <?php else: ?>
                            <div class="recent-list">
                                <?php foreach ($recent as $row): ?>
                                <div class="recent-item">
                                    <span class="brand-badge <?= strtolower($row['brand']) ?>"><?= $row['brand'] ?></span>
                                    <span class="recent-url" title="<?= htmlspecialchars($row['source_url']) ?>"><?= htmlspecialchars(parse_url($row['source_url'], PHP_URL_HOST) ?: $row['source_url']) ?></span>
                                    <span class="status-dot <?= $row['is_online'] ? 'online' : 'offline' ?>"></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card quick-links-card">
                <div class="card-header"><h3><i class="fas fa-rocket"></i> Quick Actions</h3></div>
                <div class="card-body">
                    <div class="quick-links">
                        <a href="backlinks.php" class="quick-link">
                            <div class="quick-link-icon"><i class="fas fa-link"></i></div>
                            <span>Manage Backlinks</span>
                        </a>
                        <a href="backlinks.php?add=1" class="quick-link">
                            <div class="quick-link-icon"><i class="fas fa-plus-circle"></i></div>
                            <span>Add Backlink</span>
                        </a>
                        <a href="rankings.php" class="quick-link">
                            <div class="quick-link-icon"><i class="fas fa-chart-line"></i></div>
                            <span>Rankings</span>
                        </a>
                        <a href="api/check_links.php" class="quick-link" target="_blank">
                            <div class="quick-link-icon"><i class="fas fa-sync-alt"></i></div>
                            <span>Check All Links</span>
                        </a>
                        <a href="api/sync_dataforseo.php?action=all" class="quick-link" target="_blank">
                            <div class="quick-link-icon"><i class="fas fa-database"></i></div>
                            <span>Sync DataForSEO</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/main.js"></script>
</body>
</html>
