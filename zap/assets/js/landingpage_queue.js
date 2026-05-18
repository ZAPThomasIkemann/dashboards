(function () {
    const API_URL = 'api/landingpage_queue.php?limit=25';
    const ACTIVE_POLL_MS = 1000;
    const IDLE_POLL_MS = 5000;
    const summaryRoot = document.getElementById('lpqSummary');
    const infoNode = document.getElementById('lpqInfo');
    const processingRoot = document.getElementById('lpqProcessing');
    const pendingRoot = document.getElementById('lpqPending');
    const doneRoot = document.getElementById('lpqDoneRows');
    const failedRoot = document.getElementById('lpqFailedRows');
    let pollHandle = null;
    let loading = false;

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function rank(value) {
        return value === null || value === undefined || value === '' ? '-' : String(value);
    }

    function metricCards(counts) {
        const values = [
            ['Total', counts.total ?? 0],
            ['Pending', counts.pending ?? 0],
            ['Processing', counts.processing ?? 0],
            ['Done', counts.done ?? 0],
            ['Failed', counts.failed ?? 0],
        ];
        summaryRoot.innerHTML = values.map(([label, value]) => `
            <div class="lpq-card">
                <div class="lpq-metric-label">${esc(label)}</div>
                <div class="lpq-metric-value">${esc(value)}</div>
            </div>
        `).join('');
    }

    function itemCard(row, stateClass, stateLabel, timeLabel, timeValue) {
        return `
            <div class="lpq-item">
                <div class="lpq-item-top">
                    <div>
                        <div class="lpq-keyword">${esc(row.keyword || '-')}</div>
                        <div class="lpq-domain">${esc(row.competitor_domain || '-')}</div>
                        <div class="lpq-url">${esc(row.competitor_landing_url || '-')}</div>
                    </div>
                    <span class="lpq-chip ${esc(stateClass)}"><strong>${esc(stateLabel)}</strong></span>
                </div>
                <div class="lpq-chip-row">
                    <span class="lpq-chip"><strong>Prev</strong> ${esc(rank(row.previous_rank))}</span>
                    <span class="lpq-chip"><strong>Now</strong> ${esc(rank(row.current_rank))}</span>
                    <span class="lpq-chip"><strong>SV</strong> ${esc(row.search_volume ?? '-')}</span>
                    <span class="lpq-chip"><strong>Top</strong> ${esc(row.competitor_serp_position ?? '-')}</span>
                    <span class="lpq-chip"><strong>Attempts</strong> ${esc(row.attempts ?? 0)}</span>
                </div>
                <div class="lpq-chip-row">
                    <span class="lpq-chip"><strong>Keyword Time</strong> ${esc(row.checked_at_utc || '-')}</span>
                    <span class="lpq-chip"><strong>${esc(timeLabel)}</strong> ${esc(timeValue || '-')}</span>
                </div>
            </div>
        `;
    }

    function renderList(root, rows, emptyText, stateClass, stateLabel, timeLabel, timeKey) {
        if (!rows || !rows.length) {
            root.innerHTML = `<div class="lpq-empty">${esc(emptyText)}</div>`;
            return;
        }
        root.innerHTML = rows.map((row) => itemCard(row, stateClass, stateLabel, timeLabel, row[timeKey])).join('');
    }

    function renderDone(rows) {
        if (!rows || !rows.length) {
            doneRoot.innerHTML = `<tr><td colspan="8"><div class="lpq-empty">No completed landing pages yet.</div></td></tr>`;
            return;
        }
        doneRoot.innerHTML = rows.map((row) => `
            <tr>
                <td><strong>${esc(row.keyword || '-')}</strong></td>
                <td>${esc(row.competitor_domain || '-')}</td>
                <td class="lpq-mini">${esc(row.competitor_landing_url || '-')}</td>
                <td>${esc(rank(row.previous_rank))}</td>
                <td>${esc(rank(row.current_rank))}</td>
                <td>${esc(row.search_volume ?? '-')}</td>
                <td>${esc(row.attempts ?? 0)}</td>
                <td>${esc(row.completed_at || '-')}</td>
            </tr>
        `).join('');
    }

    function renderFailed(rows) {
        if (!rows || !rows.length) {
            failedRoot.innerHTML = `<tr><td colspan="6"><div class="lpq-empty">No failed queue items right now.</div></td></tr>`;
            return;
        }
        failedRoot.innerHTML = rows.map((row) => `
            <tr>
                <td><strong>${esc(row.keyword || '-')}</strong></td>
                <td>${esc(row.competitor_domain || '-')}</td>
                <td class="lpq-mini">${esc(row.competitor_landing_url || '-')}</td>
                <td>${esc(row.attempts ?? 0)}</td>
                <td class="lpq-error">${esc(row.error_message || '-')}</td>
                <td>${esc(row.updated_at || '-')}</td>
            </tr>
        `).join('');
    }

    async function loadQueue() {
        if (loading) return;
        loading = true;
        try {
            infoNode.textContent = 'Loading landing page queue...';
            const res = await fetch(`${API_URL}&_t=${Date.now()}`, { cache: 'no-store' });
            const data = await res.json();
            if (!res.ok || !data || data.success === false) {
                throw new Error(data?.error || `HTTP ${res.status}`);
            }

            metricCards(data.counts || {});
            renderList(processingRoot, data.processing || [], 'No landing page is currently being processed.', 'is-processing', 'processing', 'Claimed', 'claimed_at');
            renderList(pendingRoot, data.pending || [], 'There are currently no additional landing pages waiting in the queue.', '', 'pending', 'Queued', 'created_at');
            renderDone(data.done || []);
            renderFailed(data.failed || []);
            const estimate = data.estimate || {};
            infoNode.textContent = `Last update: ${data.server_time || '-'} | Pending: ${data.counts?.pending ?? 0} | Processing: ${data.counts?.processing ?? 0} | Done: ${data.counts?.done ?? 0} | Mode: ${estimate.items_per_run ?? 1} URL processed sequentially | Avg: ~${estimate.seconds_per_item ?? 2}s/URL | Remaining: ${estimate.remaining_label || '-'} | ETA: ${estimate.eta_berlin_time || '-'}`;
        } catch (error) {
            infoNode.textContent = `Failed to load landing page queue: ${error.message}`;
            processingRoot.innerHTML = '<div class=\"lpq-empty\">Queue data could not be loaded.</div>';
            pendingRoot.innerHTML = '<div class=\"lpq-empty\">Queue data could not be loaded.</div>';
            doneRoot.innerHTML = '<tr><td colspan=\"8\"><div class=\"lpq-empty\">Queue data could not be loaded.</div></td></tr>';
            failedRoot.innerHTML = '<tr><td colspan=\"6\"><div class=\"lpq-empty\">Queue data could not be loaded.</div></td></tr>';
        } finally {
            loading = false;
        }
    }

    function restartPolling() {
        if (pollHandle) {
            window.clearInterval(pollHandle);
        }
        const interval = document.hidden ? IDLE_POLL_MS : ACTIVE_POLL_MS;
        pollHandle = window.setInterval(loadQueue, interval);
    }

    loadQueue();
    restartPolling();
    document.addEventListener('visibilitychange', () => {
        restartPolling();
        if (!document.hidden) loadQueue();
    });
    window.addEventListener('focus', loadQueue);
})();
