let competitorBacklinkRows = [];
let competitorBacklinkMeta = { totalCount: 0 };
let competitorQueuePollTimer = null;
let competitorQueueRows = [];
let competitorQueueView = 'all';
const competitorPipelineApiBase = '/api';

// Auto-advance: dispatch next batch automatically when the current batch finishes.
// Guards against concurrent or too-frequent dispatches.
let autoDispatchInFlight = false;
let autoDispatchLastAt = 0;
const AUTO_DISPATCH_COOLDOWN_MS = 20000; // minimum gap between auto-dispatches
const DISPATCH_BATCH_SIZE = 5;           // domains per dispatch call

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

  // Auto-advance: when not paused and nothing is processing but queued domains exist,
  // automatically dispatch the next batch so processing continues without manual clicks.
  const paused = String((payload.controls || {}).pause_backlinks || '0') === '1';
  const processing = Number((payload.summary || {}).processing || 0);
  const queued = Number((payload.summary || {}).queued || 0);
  if (!paused && processing === 0 && queued > 0) {
    maybeAutoDispatch();
  }

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
    { key: 'pause_keywords', title: 'Tägliche Keyword Aktualisierung', text: 'Hält ZAP 2 an oder setzt es fort, also die tägliche Aufnahme neuer Keywords aus der Rankings-API.' },
    { key: 'pause_backlinks', title: 'Wettbewerber Backlinks claimen', text: 'Hält ZAP 3 an oder setzt es fort, also das Claimen neuer Wettbewerber-URLs für den Backlink-Abruf.' },
    { key: 'pause_enrichment', title: 'E-Mail Adressen suchen', text: 'Hält neue Domain-Batches für ZAP 4 an oder setzt sie fort.' },
    { key: 'pause_outreach', title: 'E-Mail Outreach', text: 'Reservierter Stop-Schalter für ZAP 5 und den späteren Versand freigegebener Outreach-Mails.' },
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
  summaryText.textContent = `${formatNumber(summary.total || 0)} domains total, ${formatNumber(summary.queued || 0)} queued, ${formatNumber(summary.processing || 0)} processing${pausedStages ? `, paused: ${pausedStages}` : ''}`;
}

async function updateControl(controlKey, controlValue) {
  await fetch(`${competitorPipelineApiBase}/pipeline_controls.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ control_key: controlKey, control_value: controlValue }),
  });

  if (controlKey === 'pause_backlinks') {
    if (controlValue === '0') {
      // Resume: immediately dispatch the first batch of DISPATCH_BATCH_SIZE domains.
      // Reset the auto-dispatch cooldown so the first batch fires without delay.
      autoDispatchLastAt = 0;
      await silentDispatch();
    } else if (controlValue === '1') {
      // Pause: reset only n8n batch_claimed orphans back to queued.
      // The Python DB-poller domains transition quickly and need no reset.
      await resetProcessingDomains('stuck_batches');
    }
  }

  await Promise.all([loadCompetitorControls(), loadCompetitorBacklinkQueue()]);
}

// Reset domains stuck in 'processing' / 'dispatching' back to queued state.
// mode: 'stuck_batches' (default, safe — only n8n batch_claimed orphans > 1h)
//       'all_processing' (aggressive — all processing rows > 1h)
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
    // Non-critical: don't block the calling action
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
      // Hide warning and button after successful reset
      const warning = document.getElementById('cbStuckWarning');
      if (warning) warning.style.display = 'none';
      if (btn)     btn.style.display     = 'none';
      autoResetDone = false; // allow future auto-resets if needed
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

// Dispatch the next DISPATCH_BATCH_SIZE domains silently (no button state change, no error toast).
async function silentDispatch() {
  autoDispatchInFlight = true;
  autoDispatchLastAt = Date.now();
  try {
    const res = await fetch(zapTriggerApi, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'dispatch_queued_domains', batch: DISPATCH_BATCH_SIZE }),
    });
    const d = await res.json();
    if (d.success) {
      showToast(d.message || `${DISPATCH_BATCH_SIZE} Domains zur Verarbeitung gestartet.`, 'success');
    }
  } catch (e) {
    // Silently ignore network errors during auto/silent dispatch
  } finally {
    autoDispatchInFlight = false;
  }
}

// Throttled auto-dispatch: fires only when no dispatch is already in flight
// and the cooldown period has elapsed.
async function maybeAutoDispatch() {
  const now = Date.now();
  if (autoDispatchInFlight || (now - autoDispatchLastAt) < AUTO_DISPATCH_COOLDOWN_MS) return;
  await silentDispatch();
  setTimeout(loadCompetitorBacklinkQueue, 2000);
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

    const icon = document.getElementById('cbStatusIcon');
    const text = document.getElementById('cbStatusText');
    const bar  = document.getElementById('cbPipelineStatusBar');

    const q       = d.queue || {};
    const pending    = q.pending    || 0;
    const processing = q.processing || 0;
    const done       = q.done       || 0;
    const paused     = String((d.controls || {}).pause_backlinks || '0') === '1';

    let statusColor = '#18e888';
    let iconHtml    = '<i class="fas fa-check-circle"></i>';
    let statusMsg   = '';

    // Domain queue info
    const dq         = d.domain_queue || {};
    const dqQueued    = dq.queued      || 0;
    const dqProcess   = dq.processing  || 0;
    const dqFound     = dq.found_email || 0;
    const dqTotal     = Object.values(dq).reduce((a,b) => a + (b||0), 0);
    const dqPart      = dqQueued > 0
      ? ` | <b>Domain-Queue: ${dqQueued.toLocaleString('de-DE')} queued, ${dqProcess} processing, ${dqFound} E-Mails gefunden</b>`
      : ` | Domain-Queue: ${dqTotal.toLocaleString('de-DE')} Domains verarbeitet`;

    if (paused) {
      statusColor = '#fbbf24';
      iconHtml    = '<i class="fas fa-pause-circle"></i>';
      statusMsg   = 'Backlink-Worker PAUSIERT — ' + done.toLocaleString('de-DE') + ' done, ' + pending.toLocaleString('de-DE') + ' pending' + dqPart;
    } else if (pending > 0 || processing > 0) {
      statusColor = '#18e888';
      iconHtml    = '<i class="fas fa-spinner fa-spin"></i>';
      statusMsg   = 'Verarbeite Backlinks — ' + processing.toLocaleString('de-DE') + ' aktiv, ' + pending.toLocaleString('de-DE') + ' ausstehend, ' + done.toLocaleString('de-DE') + ' fertig' + dqPart;
    } else if (done > 0) {
      statusColor = dqQueued > 0 ? '#fbbf24' : '#00c8ff';
      iconHtml    = dqQueued > 0 ? '<i class="fas fa-hourglass-half"></i>' : '<i class="fas fa-check-double"></i>';
      statusMsg   = 'Backlink-Queue leer (' + done.toLocaleString('de-DE') + ' done)' + dqPart;
    } else {
      statusColor = '#fbbf24';
      iconHtml    = '<i class="fas fa-exclamation-circle"></i>';
      statusMsg   = 'Queue leer. Klicke "SERP Check Now" um die Queue zu befüllen.';
    }

    if (icon) { icon.innerHTML = iconHtml; icon.style.color = statusColor; }
    if (text)  text.textContent = statusMsg;
    if (bar)   bar.style.borderColor = statusColor + '66';

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
  if (!confirm('Reset all completed items back to pending?\nThe backlink worker will reprocess all ' + document.getElementById('cbStatusText')?.textContent?.match(/\d[\.\d]*/)?.[0] + ' domains.')) return;
  const btn = document.getElementById('cbResetQueueBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting…'; }
  try {
    const res = await fetch(zapTriggerApi, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'reset_queue' }),
    });
    const d = await res.json();
    showToast(d.success ? (d.message || 'Queue reset') : (d.error || 'Failed'), d.success ? 'success' : 'error');
    if (d.success) loadCompetitorBacklinkQueue();
  } catch (e) {
    showToast('Request failed: ' + e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Reset Queue'; }
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

async function triggerDispatchDomains() {
  const btn = document.getElementById('cbDispatchDomainsBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Dispatching…'; }
  // Reset auto-dispatch cooldown so the next auto-advance doesn't wait unnecessarily.
  autoDispatchLastAt = Date.now();
  try {
    const res = await fetch(zapTriggerApi, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'dispatch_queued_domains', batch: DISPATCH_BATCH_SIZE }),
    });
    const d = await res.json();
    showToast(d.success ? (d.message || `${DISPATCH_BATCH_SIZE} Domains dispatched`) : (d.error || 'Failed'), d.success ? 'success' : 'error');
  } catch(e) {
    showToast('Request failed: ' + e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Dispatch Domains'; }
    setTimeout(loadPipelineStatus, 2000);
  }
}
