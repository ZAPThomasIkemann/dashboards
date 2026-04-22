<header class="topbar">
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-left">
        <div class="breadcrumb">
            <?php
            $page = basename($_SERVER['PHP_SELF'], '.php');
            $titles = ['index' => 'Overview', 'backlinks' => 'Backlinks'];
            echo '<span>' . ($titles[$page] ?? ucfirst($page)) . '</span>';
            ?>
        </div>
    </div>
    <div class="topbar-right">
        <div class="topbar-time" id="topbarTime"></div>
        <div class="topbar-user">
            <div class="user-avatar"><i class="fas fa-user"></i></div>
            <span>Thomas</span>
        </div>
    </div>
</header>
