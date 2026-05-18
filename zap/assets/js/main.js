const SIDEBAR_COLLAPSE_KEY = 'zapSidebarCollapsed';

function isDesktopViewport() {
    return window.innerWidth > 900;
}

function getSidebarElements() {
    return {
        sidebar: document.getElementById('sidebar'),
        overlay: document.getElementById('sidebarOverlay'),
    };
}

function closeMobileSidebar() {
    const { sidebar, overlay } = getSidebarElements();
    sidebar?.classList.remove('open');
    overlay?.classList.remove('open');
}

function applyDesktopSidebarState() {
    if (!document.body || !isDesktopViewport()) {
        return;
    }

    const collapsed = window.localStorage.getItem(SIDEBAR_COLLAPSE_KEY) === '1';
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    closeMobileSidebar();
}

function syncSidebarMode() {
    if (isDesktopViewport()) {
        applyDesktopSidebarState();
        return;
    }

    document.body.classList.remove('sidebar-collapsed');
    closeMobileSidebar();
}

// Clock
function updateClock() {
    const el = document.getElementById('topbarTime');
    if (el) {
        const now = new Date();
        el.textContent = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
}
updateClock();
setInterval(updateClock, 1000);

// Sidebar toggle
function toggleSidebar() {
    const { sidebar, overlay } = getSidebarElements();
    if (!sidebar || !overlay) {
        return;
    }

    if (isDesktopViewport()) {
        const nextCollapsed = !document.body.classList.contains('sidebar-collapsed');
        document.body.classList.toggle('sidebar-collapsed', nextCollapsed);
        window.localStorage.setItem(SIDEBAR_COLLAPSE_KEY, nextCollapsed ? '1' : '0');
        closeMobileSidebar();
        return;
    }

    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
}

// Toast notifications
function showToast(message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast ${type !== 'success' ? type : ''}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.3s'; setTimeout(() => toast.remove(), 300); }, 3500);
}

// Modal helpers
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close modal on overlay click
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('open');
    }
});

// Close modal on ESC
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeMobileSidebar();
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
});

document.addEventListener('DOMContentLoaded', syncSidebarMode);
window.addEventListener('resize', syncSidebarMode);
