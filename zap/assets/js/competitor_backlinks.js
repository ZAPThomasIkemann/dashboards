let competitorBacklinkRows = [];
let competitorBacklinkMeta = { totalCount: 0 };
let competitorQueuePollTimer = null;
let competitorQueueRows = [];
let competitorQueueView = 'all';
const competitorPipelineApiBase = '/api';

document.addEventListener('DOMContentLoaded', () => {
  const hasCompetitorBacklinksPage = Boolean(document.getElementById('cbBody'));
  const hasControlPage = Boolean(document.getElementById('cbControlGrid'));

  if (hasControlPage) {
    loadCompetitorControls();
  }

  if (!hasCompetitorBacklinksPage) return;

  document.getElementById('cbCompetitorSelect')?.addEventListener('change', loadCompetitorBacklinkRows);
  document.getElementById('cbFollowSelect')?.addEventListener('change', loadCompetitorBacklinkRows);
  document.getElementById('cbKeywordSearch')?.addEventListener('input', renderCompetitorBacklinkRows);
  document.getElementById('cbBacklinkUrlSearch')?.addEventListener('input', debounceCompetitorBacklinkReload);
  document.getElementById('cbQueueFilterAll')?.addEventListener('click', () => setCompetitorQueueView('all'));
  document.getElementById('cbQueueFilterProcessing')?.addEventListener('click', () => setCompetitorQueueView('processing'));
  document.addEventListener('visibilitychange', handleCompetitorQueueVisibilityChange);
  loadCompetitorBacklinkQueue();
  loadIgnoredDomains();
  startCompetitorQueuePolling();
  loadCompetitorBacklinkFilters();
});

async function loadCompetitorBacklinkFilters() {
  const info = document.getElementById('cbInfoText');
  if (info) info.textContent = 'Loading filters...';
  try {
    const res = await fetch(`api/competitor_backlinks.php?mode=filters&_t=${Date.now()}`, { cache: 'no-store' });
    const payload = await res.json();
    if (!payload || payload.success !== true) throw new Error('Filters could not be loaded');
    const select = document.getElementById('cbCompetitorSelect');
    if (select) {
      const options = ['<option value="">All competitors</option>'];
      for (const row of (payload.competitors || [])) {
        options.push(`<option value="${escapeHtml(row.competitor_domain)}">${escapeHtml(row.competitor_domain)} (${escapeHtml(String(row.cnt || 0))})</option>`);
      }
      select.innerHTML = options.join('');
    }
    loadCompetitorBacklinkRows();
  } catch (error) {
    renderCompetitorBacklinkEmpty('Competitor backlink filters could not be loaded.');
    if (info) info.textContent = 'Loading failed';
  }
}

async function loadCompetitorBacklinkRows() {
  const info = document.getElementById('cbInfoText');
  if (info) info.textContent = 'Loading backlink inventory grouped for domain outreach...';
  const params = new URLSearchParams({ mode: 'rows', limit: '5000' });
  const competitor = String(document.getElementById('cbCompetitorSelect')?.value || '').trim();
  const follow = String(document.getElementById('cbFollowSelect')?.value || '').trim();
  const backlink = String(document.getElementById('cbBacklinkUrlSearch')?.value || '').trim();
  if (competitor) params.set('competitor', competitor);
  if (follow) params.set('follow', follow);
  if (backlink) params.set('ref_include', backlink);

  try {
    const res = await fetch(`api/competitor_backlinks.php?${params.toString()}&_t=${Date.now()}`, { cache: 'no-store' });
    const payload = await res.json();
    if (!payload || payload.success !== true) throw new Error('Rows could not be loaded');
    competitorBacklinkRows = Array.isArray(payload.rows) ? payload.rows : [];
    competitorBacklinkMeta = { totalCount: Number(payload.total_count || 0) };
    renderCompetitorBacklinkRows();
  } catch (error) {
    renderCompetitorBacklinkEmpty('Unique competitor backlinks could not be loaded.');
    if (info) info.textContent = 'Loading failed';
  }
}

function renderCompetitorBacklinkRows() {
  const body = document.getElementById('cbBody');
  const info = document.getElementById('cbInfoText');
  const summary = document.getElementById('cbInventorySummaryText');
  if (!body || !info) return;

  const query = String(document.getElementById('cbKeywordSearch')?.value || '').trim().toLowerCase();
  const rows = competitorBacklinkRows.filter((row) => {
    if (!query) return true;
    return [row.keyword, row.competitor_domain, row.competitor_landing_url, row.referring_domain, row.referring_page_url]
      .some((value) => String(value || '').toLowerCase().includes(query));
  });

  info.textContent = `${rows.length.toLocaleString('de-DE')} of ${competitorBacklinkMeta.totalCount.toLocaleString('de-DE')} backlink inventory rows (email enrichment runs domain by domain)`;
  if (summary) summary.textContent = `${competitorBacklinkMeta.totalCount.toLocaleString('de-DE')} URLs in the inventory, ${rows.length.toLocaleString('de-DE')} visible with the current filters.`;

  if (!rows.length) {
    renderCompetitorBacklinkEmpty('No unique backlinks match the current filters.');
    return;
  }

  body.innerHTML = rows.map((row) => `
    <tr>
      <td>${escapeHtml(formatDateTimeGerman(row.checked_at_local || row.updated_at || row.created_at || ''))}</td>
      <td>${escapeHtml(row.keyword || '')}</td>
      <td>${escapeHtml(formatNumber(row.search_volume))}</td>
      <td>${escapeHtml(row.competitor_domain || '')}</td>
      <td><a class="step-link" href="${escapeAttr(row.competitor_landing_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.competitor_landing_url || '')}</a></td>
      <td>${escapeHtml(row.referring_domain || '')}</td>
      <td><a class="step-link" href="${escapeAttr(row.referring_page_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.referring_page_url || '')}</a></td>
      <td><a class="step-link" href="${escapeAttr(row.target_url_to || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.target_url_to || '')}</a></td>
      <td>${escapeHtml(formatNumber(row.domain_rank_0_100, 0))}</td>
      <td>${Number(row.is_dofollow || 0) === 1 ? 'dofollow' : 'nofollow'}</td>
      <td>${renderEmailStatus(row)}</td>
      <td>${escapeHtml(formatFlexibleGermanDate(row.first_seen_raw || ''))}</td>
    </tr>
  `).join('');
}

function renderEmailStatus(row) {
  const email = String(row.email || '').trim();
  const status = String(row.email_status || '').trim().toLowerCase();
  if (email) return `<span class="step-badge ok">${escapeHtml(email)}</span>`;
  if (status) return `<span class="step-badge muted">${escapeHtml(status)}</span>`;
  return '<span class="step-badge muted">pending</span>';
}

function renderCompetitorBacklinkEmpty(message) {
  const body = document.getElementById('cbBody');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="12"><div class="step-empty"><i class="fas fa-inbox"></i><br>${escapeHtml(message)}</div></td></tr>`;
}

async function loadCompetitorBacklinkQueue() {
  const refresh = document.getElementById('cbQueueRefreshText');
  if (refresh) refresh.textContent = 'Refreshing queue...';

  try {
    const res = await fetch(`${competitorPipelineApiBase}/competitor_domains.php?mode=queue&limit=200&_t=${Date.now()}`, { cache: 'no-store' });
    const payload = await res.json();
    if (!payload || payload.success !== true) throw new Error('Queue could not be loaded');
    renderCompetitorBacklinkQueue(payload);
    if (refresh) refresh.textContent = `Last refresh: ${formatDateTimeGerman(payload.generated_at || '')}`;
  } catch (error) {
    renderCompetitorBacklinkQueueEmpty('The competitor backlink queue could not be loaded.');
    if (refresh) refresh.textContent = 'Queue refresh failed';
  }
}

function renderCompetitorBacklinkQueue(payload) {
  renderControlSummary(payload.summary || {}, payload.controls || {});
  renderQueueSummary(payload.summary || {});
  competitorQueueRows = Array.isArray(payload.rows) ? payload.rows : [];
  renderCompetitorBacklinkQueueRows(competitorQueueRows);

  // Detect and surface stuck batch_claimed domains (n8n orphans)
  checkForStuckRun(payload.active_run || null);
}

// Stuck-run detection: surfaces an orange warning + "Fix" button when the
// active processing run has been sitting unfinished for more than 1 hour.
// These are n8n batch_claimed orphans that no worker will ever complete.
const STUCK_RUN_THRESHOLD_MS = 60 * 60 * 1000; // 1 hour

function checkForStuckRun(activeRun) {
  const fixBtn  = document.getElementById('cbFixStuckBtn');
  const warning = document.getElementById('cbStuckWarning');
  const count   = document.getElementById('cbStuckCount');
  const details = document.getElementById('cbStuckDetails');

  if (!activeRun || !activeRun.started_at) {
    if (fixBtn)  fixBtn.style.display  = 'none';
    if (warning) warning.style.display = 'none';
    return;
  }

  const startedAt = parseUtcLikeDate(activeRun.started_at);
  const ageMs     = startedAt ? (Date.now() - startedAt.getTime()) : 0;
  const stuckRows = Array.isArray(activeRun.rows) ? activeRun.rows : [];
  const stuckCount = Number(activeRun.counts?.processing || stuckRows.length || 0);

  if (ageMs < STUCK_RUN_THRESHOLD_MS || stuckCount === 0) {
    if (fixBtn)  fixBtn.style.display  = 'none';
    if (warning) warning.style.display = 'none';
    return;
  }

  // Show the "Fix" button and warning banner
  const ageHours = Math.round(ageMs / 3600000);
  const domainList = stuckRows.map((r) => escapeHtml(r.domain_key || '')).join(', ');

  if (count)   count.textContent = String(stuckCount);
  if (fixBtn)  fixBtn.style.display  = '';
  if (warning) warning.style.display = '';
  if (details) {
    details.textContent = stuckCount === 1
      ? `${domainList || stuckCount + ' Domain(s)'} (seit ${ageHours} Std.) — `
      : `${stuckCount} Domains (${domainList ? domainList.substring(0, 80) + (domainList.length > 80 ? '…' : '') : ''}) seit ${ageHours} Std. — `;
  }

  // Auto-reset if stuck for more than 24 hours — no user interaction needed
  if (ageMs > 24 * 60 * 60 * 1000 && !autoResetDone) {
    autoResetDone = true;
    resetProcessingDomains('stuck_batches').then((result) => {
      if (result && result.reset_count > 0) {
        showToast(`Auto-Reset: ${result.reset_count} feststeckende Domain(s) zurückgesetzt.`, 'success');
        loadCompetitorBacklinkQueue();
      }
    });
  }
}

let autoResetDone = false;

function renderProcessingStep(value) {
  const step = String(value || '').trim();
  if (!step) return '<span class="step-badge muted">waiting</span>';
  const labels = {
    batch_claimed: 'batch claimed',
    fetch_homepage: 'fetch homepage',
    find_legal_page: 'find legal page',
    fetch_legal_page: 'fetch legal page',
    extract_email: 'extract email',
  };
  return `<span class="step-badge info">${escapeHtml(labels[step] || step.replace(/_/g, ' '))}</span>`;
}

function renderQueueSummary(summary) {
  const el = document.getElementById('cbQueueSummaryText');
  if (!el) return;

  const parts = [
    `${formatNumber(summary.total || 0)} domains total`,
    `${formatNumber(summary.queued || 0)} queued`,
  ];

  // Actively-enriching counts from the Python DB poller
  const activeNow = (summary.fetching_imprint || 0) + (summary.extracting || 0);
  if (activeNow > 0) {
    parts.push(`${formatNumber(activeNow)} enriching now`);
  }

  // Stuck n8n batch_claimed orphans
  if (summary.processing > 0) {
    parts.push(`${formatNumber(summary.processing)} stuck (batch_claimed)`);
  }

  parts.push(`${formatNumber(summary.found_email || 0)} with email`);
  el.textContent = parts.join(', ') + '.';
}

function renderCompetitorBacklinkQueueRows(rows) {
  const body = document.getElementById('cbQueueBody');
  const info = document.getElementById('cbQueueFilterInfo');
  if (!body) return;

  const filteredRows = rows.filter((row) => {
    if (competitorQueueView === 'processing') {
      return Number(row.batch_processing || 0) === 1 || String(row.status || row.email_status || '').trim().toLowerCase() === 'processing';
    }
    return true;
  });

  syncCompetitorQueueButtons();
  if (info) {
    info.textContent = competitorQueueView === 'processing'
      ? `${filteredRows.length.toLocaleString('de-DE')} entries from the active processing batch`
      : `${rows.length.toLocaleString('de-DE')} queue entries shown`;
  }

  if (!filteredRows.length) {
    renderCompetitorBacklinkQueueEmpty(
      competitorQueueView === 'processing'
        ? 'No active batch is currently visible.'
        : 'The queue is currently empty.'
    );
    return;
  }

  body.innerHTML = filteredRows.map((row) => `
    <tr>
      <td class="queue-status-cell">${renderQueueStatus(row.status || row.email_status, row.processing_step)}</td>
      <td>${escapeHtml(row.domain_key || row.referring_domain || '')}</td>
      <td><a class="step-link" href="${escapeAttr(row.domain_home_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.domain_home_url || '')}</a></td>
      <td><a class="step-link" href="${escapeAttr(row.sample_referring_page_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.sample_referring_page_url || '')}</a></td>
      <td>${escapeHtml(formatNumber(row.backlink_count))}</td>
      <td>${escapeHtml(formatNumber(row.competitor_count))}</td>
      <td>${escapeHtml(String(row.latest_email || row.email || '').trim() || '-')}</td>
      <td>${row.latest_imprint_url || row.imprint_url ? `<a class="step-link" href="${escapeAttr(row.latest_imprint_url || row.imprint_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.latest_imprint_url || row.imprint_url)}</a>` : '-'}</td>
      <td>${escapeHtml(formatDateTimeGerman(row.updated_at || ''))}</td>
    </tr>
  `).join('');
}

function setCompetitorQueueView(view) {
  competitorQueueView = view === 'processing' ? 'processing' : 'all';
  renderCompetitorBacklinkQueueRows(competitorQueueRows);
}

function syncCompetitorQueueButtons() {
  const allBtn = document.getElementById('cbQueueFilterAll');
  const processingBtn = document.getElementById('cbQueueFilterProcessing');
  if (allBtn) allBtn.classList.toggle('is-selected', competitorQueueView === 'all');
  if (processingBtn) processingBtn.classList.toggle('is-selected', competitorQueueView === 'processing');
}

function renderQueueStatus(statusValue, processingStep) {
  const status = String(statusValue || '').trim().toLowerCase();
  const step   = String(processingStep || '').trim().toLowerCase();

  if (status === 'approved_for_outreach') {
    return '<span class="step-badge ok">approved</span>';
  }
  if (status === 'ignored_linkfarm') {
    return '<span class="step-badge error">ignored</span>';
  }
  if (status === 'dispatching') {
    return '<span class="step-badge info">dispatching</span>';
  }
  // Python DB-poller active statuses — domain is actively being enriched right now
  if (status === 'fetching_imprint') {
    return '<span class="step-badge info">fetching imprint</span>';
  }
  if (status === 'extracting') {
    return '<span class="step-badge info">extracting email</span>';
  }
  if (status === 'processing' || status === 'claimed' || status === 'running') {
    // Distinguish between an active DB-poll claim and a stuck n8n batch_claimed orphan
    if (step === 'batch_claimed') {
      return '<span class="step-badge warn" title="Feststeckend seit über einer Stunde — klicke \'Fix Stuck Domains\'">⚠ stuck</span>';
    }
    if (step === 'db_poll') {
      return '<span class="step-badge info">enriching</span>';
    }
    return '<span class="step-badge info">processing</span>';
  }
  if (status === 'found' || status === 'found_email') {
    return '<span class="step-badge ok">found</span>';
  }
  if (status === 'no_email') {
    return '<span class="step-badge warn">no_email</span>';
  }
  if (status === 'no_imprint') {
    return '<span class="step-badge muted">no_imprint</span>';
  }
  if (status === 'failed' || status === 'error') {
    return '<span class="step-badge error">failed</span>';
  }
  return '<span class="step-badge warn">pending</span>';
}

function renderCompetitorBacklinkQueueEmpty(message) {
  const body = document.getElementById('cbQueueBody');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="9"><div class="step-empty"><i class="fas fa-stream"></i><br>${escapeHtml(message)}</div></td></tr>`;
}

function startCompetitorQueuePolling() {
  stopCompetitorQueuePolling();
  competitorQueuePollTimer = setInterval(() => {
    if (!document.hidden) loadCompetitorBacklinkQueue();
  }, 5000);
}

function stopCompetitorQueuePolling() {
  if (competitorQueuePollTimer) {
    clearInterval(competitorQueuePollTimer);
    competitorQueuePollTimer = null;
  }
}

function handleCompetitorQueueVisibilityChange() {
  if (document.hidden) {
    stopCompetitorQueuePolling();
    return;
  }
  loadCompetitorBacklinkQueue();
  startCompetitorQueuePolling();
}

async function loadCompetitorControls() {
  const refresh = document.getElementById('cbControlsRefreshText');
  if (refresh) refresh.textContent = 'Refreshing controls...';
  try {
    const res = await fetch(`${competitorPipelineApiBase}/pipeline_controls.php?_t=${Date.now()}`, { cache: 'no-store' });
    const payload = await res.json();
    if (!payload || payload.success !== true) throw new Error('Controls could not be loaded');
    renderControls(payload.controls || {});
    if (refresh) refresh.textContent = `Last refresh: ${formatDateTimeGerman(new Date().toISOString().slice(0, 19).replace('T', ' '))}`;
  } catch (error) {
    const grid = document.getElementById('cbControlGrid');
    if (grid) grid.innerHTML = '<div class="step-empty">Controls could not be loaded.</div>';
    if (refresh) refresh.textContent = 'Control refresh failed';
  }
}

function renderControls(controls) {
  const grid = document.getElementById('cbControlGrid');
  if (!grid) return;

  const cards = [
    { key: 'pause_keywords',  title: 'ZAP 2 — SERP Check',              text: 'Täglicher SERP-Lauf: findet neue Keywords und füllt die Backlink-Queue. Python-Daemon, läuft autonom.' },
    { key: 'pause_backlinks', title: 'ZAP 3 — Backlinks claimen',        text: 'Python-Worker: liest Backlinks via DataForSEO und schreibt sie in die Datenbank. Läuft autonom.' },
    { key: 'pause_enrichment',title: 'ZAP 4 — E-Mail Enrichment',        text: 'Python DB-Poller: verarbeitet queued Domains (Imprint → E-Mail). Läuft autonom alle ~4 Minuten. Pause stoppt den nächsten Batch.' },
    { key: 'pause_outreach',  title: 'ZAP 5 — Outreach',                 text: 'Outreach-Mails versenden. Schalter reserviert für den späteren Versand freigegebener Domains.' },
  ];

  grid.innerHTML = cards.map((card) => {
    const active = String(controls[card.key] || '0') === '1';
    return `
      <div class="control-card">
        <div class="control-row">
          <h3>${escapeHtml(card.title)}</h3>
          <span class="step-badge ${active ? 'error' : 'ok'}">${active ? 'paused' : 'running'}</span>
        </div>
        <div class="control-meta">${escapeHtml(card.text)}</div>
        <button class="control-button ${active ? 'is-active' : 'is-idle'}" data-control-key="${escapeAttr(card.key)}" data-control-next="${active ? '0' : '1'}">
          ${active ? 'Resume' : 'Pause'}
        </button>
      </div>
    `;
  }).join('');

  grid.querySelectorAll('[data-control-key]').forEach((button) => {
    button.addEventListener('click', async () => {
      const controlKey = String(button.getAttribute('data-control-key') || '');
      const nextValue = String(button.getAttribute('data-control-next') || '0');
      await updateControl(controlKey, nextValue);
    });
  });
}

function renderControlSummary(summary, controls) {
  const summaryText = document.getElementById('cbControlsSummaryText');
  if (!summaryText) return;

  const pausedStages = Object.entries(controls || {})
    .filter(([key, value]) => key.startsWith('pause_') && String(value) === '1')
    .map(([key]) => key.replace('pause_', ''))
    .join(', ');

  const activeNow = Number(summary.fetching_imprint || 0) + Number(summary.extracting || 0);
  const parts = [
    `${formatNumber(summary.total || 0)} Domains gesamt`,
    `${formatNumber(summary.queued || 0)} in der Queue`,
  ];
  if (activeNow > 0) parts.push(`${formatNumber(activeNow)} gerade aktiv`);
  if (summary.processing > 0) parts.push(`${formatNumber(summary.processing)} stuck`);
  if (pausedStages) parts.push(`pausiert: ${pausedStages}`);

  summaryText.textContent = parts.join(' · ');
}

async function updateControl(controlKey, controlValue) {
  await fetch(`${competitorPipelineApiBase}/pipeline_controls.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ control_key: controlKey, control_value: controlValue }),
  });

  // When pausing enrichment, reset any orphaned batch_claimed domains so they
  // re-enter the queue and will be picked up by the Python poller on the next cycle.
  if (controlKey === 'pause_enrichment' && controlValue === '1') {
    await resetProcessingDomains('stuck_batches');
  }

  await Promise.all([loadCompetitorControls(), loadCompetitorBacklinkQueue()]);
}

// Reset domains stuck in 'processing/batch_claimed' back to queued state.
// mode: 'stuck_batches' — only n8n batch_claimed orphans older than 1h (safe)
//       'all_processing' — all processing rows older than 1h (aggressive)
async function resetProcessingDomains(mode = 'stuck_batches') {
  try {
    const res = await fetch('api/reset_domain_processing.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mode }),
    });
    const d = await res.json();
    if (d.success && d.reset_count > 0) {
      showToast(d.message || `${d.reset_count} Domain(s) zurückgesetzt.`, 'success');
    }
    return d;
  } catch (e) {
    return null;
  }
}

// Called by the "Fix Stuck Domains" button in the UI
async function triggerFixStuckDomains() {
  const btn = document.getElementById('cbFixStuckBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting…'; }
  try {
    const result = await resetProcessingDomains('stuck_batches');
    if (result && result.success) {
      showToast(result.message || 'Feststeckende Domains zurückgesetzt.', 'success');
      const warning = document.getElementById('cbStuckWarning');
      if (warning) warning.style.display = 'none';
      if (btn)     btn.style.display     = 'none';
      autoResetDone = false;
      await loadCompetitorBacklinkQueue();
    } else {
      showToast(result?.message || 'Keine feststeckenden Domains gefunden.', 'success');
    }
  } catch (e) {
    showToast('Reset fehlgeschlagen: ' + e.message, 'error');
  } finally {
    if (btn) {
      btn.disabled = false;
      const stuckCount = document.getElementById('cbStuckCount')?.textContent || '0';
      btn.innerHTML = `<i class="fas fa-wrench"></i> Fix Stuck Domains (${stuckCount})`;
    }
  }
}

async function loadIgnoredDomains() {
  try {
    const res = await fetch(`${competitorPipelineApiBase}/competitor_domains.php?mode=ignored&limit=200&_t=${Date.now()}`, { cache: 'no-store' });
    const payload = await res.json();
    if (!payload || payload.success !== true) throw new Error('Ignored domains could not be loaded');
    renderIgnoredDomains(Array.isArray(payload.rows) ? payload.rows : []);
  } catch (error) {
    const body = document.getElementById('cbIgnoredBody');
    if (body) body.innerHTML = '<tr><td colspan="7"><div class="step-empty">Ignored domains could not be loaded.</div></td></tr>';
  }
}

function renderIgnoredDomains(rows) {
  const body = document.getElementById('cbIgnoredBody');
  const summary = document.getElementById('cbIgnoredSummaryText');
  if (!body) return;
  if (!rows.length) {
    if (summary) summary.textContent = '0 ignored domains at the moment.';
    body.innerHTML = '<tr><td colspan="7"><div class="step-empty">No ignored domains yet.</div></td></tr>';
    return;
  }

  if (summary) summary.textContent = `${rows.length.toLocaleString('de-DE')} ignored domains are currently listed here.`;

  body.innerHTML = rows.map((row) => `
    <tr>
      <td>${renderQueueStatus(row.status, row.processing_step)}</td>
      <td>${escapeHtml(row.domain_key || '')}</td>
      <td>${escapeHtml(row.ignored_reason || '-')}</td>
      <td>${escapeHtml(formatNumber(row.domain_rank_max, 0))}</td>
      <td>${escapeHtml(formatNumber(row.spam_score_max, 0))}</td>
      <td>${escapeHtml(formatNumber(row.backlink_count))}</td>
      <td>${escapeHtml(formatDateTimeGerman(row.updated_at || ''))}</td>
    </tr>
  `).join('');
}

let competitorBacklinkTimer = null;
function debounceCompetitorBacklinkReload() {
  clearTimeout(competitorBacklinkTimer);
  competitorBacklinkTimer = setTimeout(loadCompetitorBacklinkRows, 250);
}

function parseUtcLikeDate(value) {
  const text = String(value || '').trim();
  if (!text) return null;
  if (/z$/i.test(text) || /[+-]\d{2}:?\d{2}$/.test(text)) {
    const parsed = new Date(text);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }
  const normalized = text.replace(' ', 'T');
  const parsed = new Date(`${normalized}Z`);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function formatDateTimeGerman(value) {
  const parsed = parseUtcLikeDate(value);
  if (!parsed) return String(value || '').trim();
  return new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(parsed).replace(',', '');
}

function formatFlexibleGermanDate(value) {
  const text = String(value || '').trim();
  if (!text) return '';
  const hasTime = /[ T]\d{2}:\d{2}/.test(text);
  const parsed = parseUtcLikeDate(text);
  if (!parsed) return text;
  const options = {
    timeZone: 'Europe/Berlin',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  };
  if (hasTime) {
    options.hour = '2-digit';
    options.minute = '2-digit';
    options.hour12 = false;
  }
  return new Intl.DateTimeFormat('de-DE', options).format(parsed).replace(',', '');
}

function formatNumber(value, digits = null) {
  const num = Number(value);
  if (!Number.isFinite(num)) return String(value || '');
  if (digits === null) return num.toLocaleString('de-DE');
  return num.toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeAttr(value) {
  return escapeHtml(value);
}

// ── Pipeline Status Banner ──────────────────────────────────────────────────
const zapTriggerApi = '/api/zap_trigger.php';

async function loadPipelineStatus() {
  try {
    const res = await fetch(zapTriggerApi + '?action=worker_status&_t=' + Date.now(), { cache: 'no-store' });
    const d = await res.json();
    if (!d.success) return;

    const controls    = d.controls || {};
    const q           = d.queue    || {};   // competitor_backlink_queue (ZAP 3)
    const dq          = d.domain_queue || {}; // competitor_domains (ZAP 4)

    // ── Derive per-stage status ────────────────────────────────────────────
    const serpRunning    = Boolean(d.serp_running);
    const backlinkPaused = String(controls.pause_backlinks || '0') === '1';
    const enrichPaused   = String(controls.pause_enrichment || '0') === '1';

    const blPending    = Number(q.pending    || 0);
    const blProcessing = Number(q.processing || 0);
    const blDone       = Number(q.done       || 0);

    const dqQueued    = Number(dq.queued      || 0);
    const dqEnriching = Number(dq.processing  || 0) +
                        Number(dq.fetching_imprint || 0) +
                        Number(dq.extracting || 0);
    const dqFound     = Number(dq.found_email || 0);
    const dqTotal     = Object.values(dq).reduce((a, b) => a + Number(b || 0), 0);

    // ── Build human-readable stage chips ──────────────────────────────────
    function stageChip(label, state, detail) {
      const colors = {
        running: 'color:#18e888',
        paused:  'color:#fbbf24',
        idle:    'color:#8b9ab5',
        done:    'color:#00c8ff',
      };
      const icons = {
        running: 'fa-spinner fa-spin',
        paused:  'fa-pause-circle',
        idle:    'fa-circle',
        done:    'fa-check-circle',
      };
      const style = colors[state] || colors.idle;
      const icon  = icons[state]  || icons.idle;
      return `<span style="${style};white-space:nowrap;margin-right:14px"><i class="fas ${icon}" style="margin-right:4px"></i><b>${label}</b>${detail ? ' — ' + detail : ''}</span>`;
    }

    // ZAP 2 — SERP (keywords → queue fill)
    const serp2 = serpRunning
      ? stageChip('ZAP 2 SERP', 'running', 'läuft')
      : stageChip('ZAP 2 SERP', 'idle', d.last_serp_log
          ? d.last_serp_log.replace(/.*INFO\s*/, '').substring(0, 40) + '…'
          : 'idle');

    // ZAP 3 — Backlink Claiming
    let zap3state, zap3detail;
    if (backlinkPaused) {
      zap3state  = 'paused';
      zap3detail = `pausiert, ${blDone.toLocaleString('de-DE')} done`;
    } else if (blProcessing > 0 || blPending > 0) {
      zap3state  = 'running';
      zap3detail = `${blProcessing} aktiv, ${blPending.toLocaleString('de-DE')} pending`;
    } else if (blDone > 0) {
      zap3state  = 'done';
      zap3detail = `${blDone.toLocaleString('de-DE')} done`;
    } else {
      zap3state  = 'idle';
      zap3detail = 'Queue leer';
    }
    const serp3 = stageChip('ZAP 3 Backlinks', zap3state, zap3detail);

    // ZAP 4 — Email Enrichment (Python DB Poller)
    let zap4state, zap4detail;
    if (enrichPaused && dqEnriching === 0) {
      zap4state  = 'paused';
      zap4detail = `pausiert, ${dqQueued.toLocaleString('de-DE')} warten`;
    } else if (dqEnriching > 0) {
      zap4state  = 'running';
      zap4detail = `${dqEnriching} aktiv, ${dqQueued.toLocaleString('de-DE')} queued, ${dqFound.toLocaleString('de-DE')} E-Mails`;
    } else if (dqQueued > 0) {
      zap4state  = enrichPaused ? 'paused' : 'idle';
      zap4detail = `${dqQueued.toLocaleString('de-DE')} queued, ${dqFound.toLocaleString('de-DE')} E-Mails gefunden`;
    } else {
      zap4state  = 'done';
      zap4detail = `${dqTotal.toLocaleString('de-DE')} Domains verarbeitet`;
    }
    const serp4 = stageChip('ZAP 4 E-Mail', zap4state, zap4detail);

    // ── Overall bar color: green if anything running, yellow if paused/idle, etc.
    const anyRunning = serpRunning || blProcessing > 0 || blPending > 0 || dqEnriching > 0;
    const allPaused  = backlinkPaused && enrichPaused;
    const barColor   = anyRunning ? '#18e888' : allPaused ? '#fbbf24' : '#00c8ff';

    const icon = document.getElementById('cbStatusIcon');
    const text = document.getElementById('cbStatusText');
    const bar  = document.getElementById('cbPipelineStatusBar');

    if (icon) {
      icon.innerHTML  = anyRunning
        ? '<i class="fas fa-spinner fa-spin"></i>'
        : allPaused ? '<i class="fas fa-pause-circle"></i>' : '<i class="fas fa-check-circle"></i>';
      icon.style.color = barColor;
    }
    if (text) text.innerHTML = serp2 + serp3 + serp4;
    if (bar)  bar.style.borderColor = barColor + '66';

    const serpBtn = document.getElementById('cbTriggerSerpBtn');
    if (serpBtn) serpBtn.disabled = Boolean(d.serp_running);

    if (d.controls) renderControls(d.controls);
    renderControlSummary(d.queue || {}, d.controls || {});
  } catch (_e) {}
}

async function triggerSerpCheck() {
  const btn = document.getElementById('cbTriggerSerpBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting…'; }
  try {
    const res = await fetch(zapTriggerApi, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'serp_check' }),
    });
    const d = await res.json();
    showToast(d.success ? (d.message || 'SERP check started') : (d.error || 'Failed'), d.success ? 'success' : 'error');
  } catch (e) {
    showToast('Request failed: ' + e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync-alt"></i> SERP Check Now'; }
    setTimeout(loadPipelineStatus, 2500);
  }
}

async function triggerResetQueue() {
  if (!confirm(
    'Achtung: Setzt die gesamte Backlink-Queue (ZAP 3) zurück auf pending.\n' +
    'Der Backlink-Worker verarbeitet alle Einträge erneut.\n\n' +
    'Nur ausführen wenn du ZAP 3 komplett neu starten möchtest!'
  )) return;
  const btn = document.getElementById('cbResetQueueBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting…'; }
  try {
    const res = await fetch(zapTriggerApi, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'reset_queue' }),
    });
    const d = await res.json();
    showToast(d.success ? (d.message || 'Queue zurückgesetzt') : (d.error || 'Failed'), d.success ? 'success' : 'error');
    if (d.success) loadCompetitorBacklinkQueue();
  } catch (e) {
    showToast('Request failed: ' + e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Reset Backlink-Queue'; }
    setTimeout(loadPipelineStatus, 1500);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('cbPipelineStatusBar')) {
    loadPipelineStatus();
    setInterval(loadPipelineStatus, 15000);
  }
});

// ── Live Log Viewer ──────────────────────────────────────────────────────────
let logPollTimer      = null;
let logAutoScroll     = true;
let logLastMtime      = 0;
let logCurrentWorker  = 'backlink';

function switchLogWorker() {
  const sel = document.getElementById('logWorkerSelect');
  if (sel) logCurrentWorker = sel.value;
  logLastMtime = 0; // reset so we reload full log
  reloadLog();
}

function reloadLog() {
  stopLogPolling();
  fetchLog(true);
  startLogPolling();
}

function toggleAutoScroll() {
  logAutoScroll = !logAutoScroll;
  const btn = document.getElementById('logAutoScrollBtn');
  if (btn) btn.innerHTML = logAutoScroll
    ? '<i class="fas fa-arrow-down"></i> Auto-Scroll AN'
    : '<i class="fas fa-pause"></i> Auto-Scroll AUS';
  if (logAutoScroll) scrollLogToBottom();
}

function scrollLogToBottom() {
  const t = document.getElementById('logTerminal');
  if (t) t.scrollTop = t.scrollHeight;
}

async function fetchLog(fullReload = false) {
  const terminal = document.getElementById('logTerminal');
  const badge    = document.getElementById('logStatusBadge');
  const meta     = document.getElementById('logFileMeta');
  const refresh  = document.getElementById('logRefreshText');
  const lines    = document.getElementById('logLinesSelect')?.value || '100';
  const worker   = document.getElementById('logWorkerSelect')?.value || 'backlink';

  const url = `/api/worker_logs.php?worker=${encodeURIComponent(worker)}&lines=${lines}&_t=${Date.now()}`;

  try {
    const res = await fetch(url, { cache: 'no-store' });
    const d   = await res.json();

    if (!d.success || !terminal) return;

    if (!d.exists) {
      terminal.innerHTML = '<span class="log-empty">Log file not found: ' + escapeHtml(d.file) + '</span>';
      if (badge) { badge.textContent = 'No log'; badge.className = 'log-badge idle'; }
      return;
    }

    const newMtime = d.mtime || 0;
    const changed  = newMtime !== logLastMtime;

    if (changed || fullReload) {
      logLastMtime = newMtime;

      const logLines = d.lines || [];
      if (!logLines.length) {
        terminal.innerHTML = '<span class="log-empty">Log file is empty.</span>';
      } else {
        terminal.innerHTML = logLines.join('\n');
        if (logAutoScroll) scrollLogToBottom();
      }

      // Detect activity: last line timestamp < 30s ago
      const lastRaw = (d.raw_lines || []).slice(-1)[0] || '';
      let isActive = false;
      const tsMatch = lastRaw.match(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/);
      if (tsMatch) {
        const lastTs = new Date(tsMatch[1].replace(' ', 'T') + 'Z');
        isActive = (Date.now() - lastTs.getTime()) < 60000; // active if last log < 60s ago
      }

      if (badge) {
        if (isActive) {
          badge.textContent = 'Active';
          badge.className   = 'log-badge running';
        } else {
          const hasError = logLines.some(l => l.includes('log-error'));
          badge.textContent = hasError ? 'Error' : 'Idle';
          badge.className   = 'log-badge ' + (hasError ? 'error' : 'idle');
        }
      }
    }

    // File size display
    const kb = Math.round((d.size || 0) / 1024);
    if (meta)    meta.textContent = `${d.file} (${kb} KB, ${d.total} lines shown)`;
    if (refresh) refresh.textContent = 'Updated ' + new Date().toLocaleTimeString('de-DE');

  } catch (e) {
    if (refresh) refresh.textContent = 'Log fetch failed: ' + e.message;
  }
}

function startLogPolling() {
  stopLogPolling();
  logPollTimer = setInterval(() => {
    if (!document.hidden) fetchLog();
  }, 3000); // every 3 seconds
}

function stopLogPolling() {
  if (logPollTimer) {
    clearInterval(logPollTimer);
    logPollTimer = null;
  }
}

// Init on DOM ready
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('logTerminal')) {
    fetchLog(true);
    startLogPolling();
  }
});

