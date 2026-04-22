<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 8L18 4L30 8V20C30 26.627 24.627 32 18 32C11.373 32 6 26.627 6 20V8Z" fill="url(#zapGradient)"/>
                <path d="M20 11L14 19H18L16 25L22 17H18L20 11Z" fill="#1B1434"/>
                <defs>
                    <linearGradient id="zapGradient" x1="6" y1="4" x2="30" y2="32" gradientUnits="userSpaceOnUse">
                        <stop offset="0%" stop-color="#18e888"/>
                        <stop offset="100%" stop-color="#00c48b"/>
                    </linearGradient>
                </defs>
            </svg>
            <div class="sidebar-logo-text">
                <span class="logo-zap">ZAP</span>
                <span class="logo-dash">Dashboard</span>
            </div>
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
        <a href="backlinks.php" class="nav-item <?= $current_page === 'backlinks' ? 'active' : '' ?>">
            <i class="fas fa-link"></i>
            <span>Backlinks</span>
        </a>
        <a href="rankings.php" class="nav-item <?= $current_page === 'rankings' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i>
            <span>Rankings</span>
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
