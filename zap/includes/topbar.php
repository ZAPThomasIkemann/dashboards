<header class="topbar">
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-left">
        <div class="breadcrumb">
            <?php
            $page = basename($_SERVER['PHP_SELF'], '.php');
            if ($page === 'backlinks_offline'
                || ($page === 'backlinks' && isset($_GET['offline']) && (string) $_GET['offline'] === '1')) {
                echo '<span>Offline Backlinks</span>';
            } else {
                $titles = ['index' => 'Overview', 'backlinks' => 'Backlinks', 'rankings' => 'Rankings', 'daily_data' => 'Daily Data', 'landingpage_queue' => '2. Backlink Queue'];
                echo '<span>' . ($titles[$page] ?? ucfirst($page)) . '</span>';
            }
            ?>
        </div>
        <?php if ($page === 'backlinks' || $page === 'backlinks_offline'): ?>
        <div class="topbar-stats-strip" id="topbarBacklinkStats"></div>
        <?php endif; ?>
    </div>
    <div class="topbar-right">
        <div class="topbar-time" id="topbarTime"></div>
        <div class="topbar-user">
            <div class="user-avatar"><i class="fas fa-user"></i></div>
            <span>Thomas</span>
        </div>
    </div>
</header>
