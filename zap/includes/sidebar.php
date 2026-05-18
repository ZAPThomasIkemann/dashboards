<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$nav_backlinks_offline = ($current_page === 'backlinks_offline')
    || ($current_page === 'backlinks' && isset($_GET['offline']) && (string) $_GET['offline'] === '1');
$rankings_view = (string) ($_GET['view'] ?? 'discovery');
$nav_daily_live_check = $current_page === 'rankings' && $rankings_view === 'daily';
$email_addresses_count = null;
try {
    $pdo = db();
    $email_addresses_count = (int) $pdo->query('SELECT COUNT(*) FROM competitor_domain_emails')->fetchColumn();
} catch (Throwable $e) {
    $email_addresses_count = null;
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo brand-lockup brand-lockup--sidebar" aria-label="ZAP Hosting">
            <span class="brand-lockup__icon-wrap">
                <img class="brand-lockup__icon" src="https://zap-cdn.com/interface/_images/logo/zaplogo200x200.png" alt="ZAP Logo">
            </span>
            <span class="brand-lockup__text">
                <span class="brand-lockup__title">ZAP</span>
                <span class="brand-lockup__subtitle">HOSTING</span>
            </span>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="index.php" class="nav-item <?= $current_page === 'index' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Overview</span>
        </a>

        <div class="nav-section-label">Process</div>
        <a href="zentrale.php" class="nav-item <?= $current_page === 'zentrale' ? 'active' : '' ?>">
            <i class="fas fa-sliders"></i>
            <span>0. Zentrale</span>
        </a>
        <a href="discovery.php" class="nav-item <?= $current_page === 'discovery' ? 'active' : '' ?>">
            <i class="fas fa-binoculars"></i>
            <span>1. Discovery</span>
        </a>
        <a href="rankings.php?view=daily" class="nav-item <?= $nav_daily_live_check ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i>
            <span>2. Daily Live Check</span>
        </a>
        <a href="competitor_backlinks.php" class="nav-item <?= $current_page === 'competitor_backlinks' ? 'active' : '' ?>">
            <i class="fas fa-link"></i>
            <span>3. Competitor Backlinks</span>
        </a>
        <a href="email_addresses.php" class="nav-item <?= $current_page === 'email_addresses' ? 'active' : '' ?>">
            <i class="fas fa-at"></i>
            <span>4. E-Mail Addresses<?= $email_addresses_count !== null ? ' (' . number_format($email_addresses_count, 0, ',', '.') . ')' : '' ?></span>
        </a>
        <a href="emails_send.php" class="nav-item <?= $current_page === 'emails_send' ? 'active' : '' ?>">
            <i class="fas fa-paper-plane"></i>
            <span>5. E-Mails Send</span>
        </a>

        <div class="nav-section-label">Reference</div>
        <a href="backlinks.php" class="nav-item <?= ($current_page === 'backlinks' && !$nav_backlinks_offline) ? 'active' : '' ?>">
            <i class="fas fa-sitemap"></i>
            <span>Backlinks</span>
        </a>
        <a href="backlinks_offline.php" class="nav-item <?= $nav_backlinks_offline ? 'active' : '' ?>">
            <i class="fas fa-unlink"></i>
            <span>Offline Links</span>
        </a>
        <a href="daily_data.php" class="nav-item <?= $current_page === 'daily_data' ? 'active' : '' ?>">
            <i class="fas fa-clock-rotate-left"></i>
            <span>Daily Data</span>
        </a>
        <a href="landingpage_queue.php" class="nav-item <?= $current_page === 'landingpage_queue' ? 'active' : '' ?>">
            <i class="fas fa-list-check"></i>
            <span>Backlink Queue</span>
        </a>
        <a href="database.php" class="nav-item <?= $current_page === 'database' ? 'active' : '' ?>">
            <i class="fas fa-database"></i>
            <span>Database</span>
        </a>

        <div class="nav-section-label">Tools</div>
        <a href="api/check_links.php" class="nav-item" target="_blank">
            <i class="fas fa-sync-alt"></i>
            <span>Check Links</span>
        </a>
        <a href="api/sync_dataforseo.php?action=all" class="nav-item" target="_blank">
            <i class="fas fa-database"></i>
            <span>Sync DataForSEO</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-footer-info">
            <div class="server-dot online"></div>
            <span>Server Online</span>
        </div>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
