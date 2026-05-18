let dailyEvents = [];
let filteredDailyEvents = [];
let dailyDropEvents = [];
let dailyAutoRefreshHandle = null;
let dailyLastPayloadSignature = '';
let selectedDailyDate = '';
let selectedDailyHour = '';
const DAILY_AUTO_REFRESH_MS = 10000;

document.addEventListener('DOMContentLoaded', () => {
  if (!document.getElementById('dailyDataTree')) {
    return;
  }

  document.getElementById('dailyReloadBtn')?.addEventListener('click', () => loadDailyTimeline({ forceRender: true }));
  document.getElementById('dailyKeywordSearch')?.addEventListener('input', filterDailyEvents);
  document.getElementById('dailyDateSelect')?.addEventListener('change', handleDailyDateChange);
  document.getElementById('dailyHourSelect')?.addEventListener('change', handleDailyHourChange);
  document.addEventListener('visibilitychange', handleDailyVisibilityChange);

  loadDailyTimeline({ forceRender: true });
  startDailyAutoRefresh();
});

function startDailyAutoRefresh() {
  stopDailyAutoRefresh();
  dailyAutoRefreshHandle = window.setInterval(() => {
    if (document.hidden) return;
    loadDailyTimeline({ silent: true });
  }, DAILY_AUTO_REFRESH_MS);
}

function stopDailyAutoRefresh() {
  if (dailyAutoRefreshHandle) {
    window.clearInterval(dailyAutoRefreshHandle);
    dailyAutoRefreshHandle = null;
  }
}

function handleDailyVisibilityChange() {
  if (!document.hidden) {
    loadDailyTimeline({ silent: true });
  }
}

function buildDailyPayloadSignature(events) {
  return JSON.stringify((events || []).map((event) => [
    event.event_key || '',
    event.linked_drop_event_key || '',
    event.keyword || '',
    event.event_date || '',
    event.event_hour || '',
    event.previous_rank ?? '',
    event.current_rank ?? '',
    event.backlink_rows ?? 0,
    event.checked_at_local || event.checked_at_utc || '',
  ]));
}

function setDailyInfoText(extra = '') {
  const info = document.getElementById('dailyDataInfo');
  if (!info) return;
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  const base = `Live · updated ${hh}:${mm}:${ss}`;
  info.textContent = extra ? `${extra} · ${base}` : base;
}

function setDropInfoText(extra = '') {
  const info = document.getElementById('dailyDropInfo');
  if (!info) return;
    info.textContent = extra || 'No drop data';
}

async function loadDailyTimeline(options = {}) {
  const { silent = false, forceRender = false } = options;
  const info = document.getElementById('dailyDataInfo');
  const tree = document.getElementById('dailyDataTree');
  const dropTree = document.getElementById('dailyDropTree');
  if (!info || !tree || !dropTree) return;

  if (!silent) {
    info.textContent = 'Loading...';
    tree.innerHTML = '<div class="detail-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading timeline...</div>';
    dropTree.innerHTML = '<div class="detail-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading drop details...</div>';
  }

  const params = new URLSearchParams({
    mode: 'timeline',
    limit: '10000',
  });

  try {
    const res = await fetch(`api/daily_data.php?${params.toString()}`);
    const payload = await res.json();
    const nextEvents = Array.isArray(payload.data) ? payload.data : [];
    const nextSignature = buildDailyPayloadSignature(nextEvents);
    const hasChanged = forceRender || nextSignature !== dailyLastPayloadSignature;

    dailyEvents = nextEvents;
    dailyLastPayloadSignature = nextSignature;

    if (hasChanged) {
      await filterDailyEvents();
    } else {
      setDailyInfoText(`${filteredDailyEvents.length} keyword${filteredDailyEvents.length === 1 ? '' : 's'}`);
      setDropInfoText(`${dailyDropEvents.length} Drop${dailyDropEvents.length === 1 ? '' : 's'}`);
    }
  } catch (error) {
    if (!silent) {
      info.textContent = 'Load failed';
      tree.innerHTML = '<div class="detail-empty"><i class="fas fa-triangle-exclamation"></i><br>Failed to load daily data.</div>';
      dropTree.innerHTML = '<div class="detail-empty"><i class="fas fa-triangle-exclamation"></i><br>Failed to load drop details.</div>';
    }
  }
}

async function filterDailyEvents() {
  const keyword = String(document.getElementById('dailyKeywordSearch')?.value || '').toLowerCase().trim();
  syncDailySelectors();

  const scopedEvents = dailyEvents.filter((event) => {
    const dateOk = !selectedDailyDate || String(event.event_date || '') === selectedDailyDate;
    const hourOk = !selectedDailyHour || String(event.event_hour || '') === selectedDailyHour;
    return dateOk && hourOk;
  });

  filteredDailyEvents = scopedEvents.filter((event) => {
    if (!keyword) return true;
    return String(event.keyword || '').toLowerCase().includes(keyword);
  });

  filteredDailyEvents.sort((a, b) => {
    const aTime = Date.parse(a.checked_at_local || a.checked_at_utc || '') || 0;
    const bTime = Date.parse(b.checked_at_local || b.checked_at_utc || '') || 0;
    return bTime - aTime;
  });

  renderDailyTimeline();
  await loadDropDetailsForCurrentSlot();
}

function renderDailyTimeline() {
  const tree = document.getElementById('dailyDataTree');
  if (!tree) return;

  setDailyInfoText(`${filteredDailyEvents.length} keyword${filteredDailyEvents.length === 1 ? '' : 's'}`);

  if (!filteredDailyEvents.length) {
    tree.innerHTML = '<div class="detail-empty"><i class="fas fa-inbox"></i><br>No ranking pushes found for the selected slot.</div>';
    return;
  }

  tree.innerHTML = filteredDailyEvents.map(renderEventCard).join('');
}

async function loadDropDetailsForCurrentSlot() {
  const dropTree = document.getElementById('dailyDropTree');
  if (!dropTree) return;

  const dropCandidates = filteredDailyEvents.filter((event) => {
    const prev = event.previous_rank;
    const curr = event.current_rank;
    return Boolean(event.has_competitor_data) || (prev !== null && prev !== undefined && curr !== null && curr !== undefined && Number(prev) <= 10 && Number(curr) > 10);
  });

  setDropInfoText(`${dropCandidates.length} Drop${dropCandidates.length === 1 ? '' : 's'}`);

  if (!dropCandidates.length) {
    dailyDropEvents = [];
      dropTree.innerHTML = '<div class="detail-empty"><i class="fas fa-check-circle"></i><br>No keyword dropped out of the top 10 in this run.</div>';
    return;
  }

  dropTree.innerHTML = '<div class="detail-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading drop details...</div>';

  const details = await Promise.all(dropCandidates.map(async (event) => {
    try {
      const res = await fetch(`api/daily_data.php?mode=event_details&event_key=${encodeURIComponent(event.event_key)}`);
      const payload = await res.json();
      if (!payload.success) return null;
      return payload;
    } catch {
      return null;
    }
  }));

  dailyDropEvents = details.filter(Boolean);

  if (!dailyDropEvents.length) {
      dropTree.innerHTML = '<div class="detail-empty"><i class="fas fa-circle-info"></i><br>Drop cases were detected, but competitor/backlink details are not available yet.</div>';
    return;
  }

  dropTree.innerHTML = dailyDropEvents.map((entry) => renderDropCard(entry.event, entry.competitors || [])).join('');
}

function syncDailySelectors() {
  const dateSelect = document.getElementById('dailyDateSelect');
  const hourSelect = document.getElementById('dailyHourSelect');
  if (!dateSelect || !hourSelect) return;

  const dateKeys = Array.from(new Set(dailyEvents.map((event) => String(event.event_date || '')).filter(Boolean)));
  if (!selectedDailyDate || !dateKeys.includes(selectedDailyDate)) {
    selectedDailyDate = dateKeys[0] || '';
  }

  dateSelect.innerHTML = dateKeys.map((dateKey) => `
    <option value="${escapeHtml(dateKey)}"${dateKey === selectedDailyDate ? ' selected' : ''}>${escapeHtml(formatDateLabel(dateKey))}</option>
  `).join('');

  const hourKeys = Array.from(new Set(
    dailyEvents
      .filter((event) => !selectedDailyDate || String(event.event_date || '') === selectedDailyDate)
      .map((event) => String(event.event_hour || ''))
      .filter(Boolean)
  ));
  if (!selectedDailyHour || !hourKeys.includes(selectedDailyHour)) {
    selectedDailyHour = hourKeys[0] || '';
  }

  hourSelect.innerHTML = hourKeys.map((hourKey) => `
    <option value="${escapeHtml(hourKey)}"${hourKey === selectedDailyHour ? ' selected' : ''}>${escapeHtml(getRunStartLabel(selectedDailyDate, hourKey))}</option>
  `).join('');
}

function handleDailyDateChange(event) {
  selectedDailyDate = String(event.target?.value || '');
  selectedDailyHour = '';
  filterDailyEvents();
}

function handleDailyHourChange(event) {
  selectedDailyHour = String(event.target?.value || '');
  filterDailyEvents();
}

function renderEventCard(event) {
  const top3 = Array.isArray(event.top3) ? event.top3.slice(0, 3) : [];
  const top3Html = top3.length
    ? `
      <div class="top3-list">
        ${top3.map((row) => `
          <div class="top3-row">
            <div class="top3-rank">${escapeHtml(String(row.rank_group ?? '?'))}</div>
            <div>
              <div class="top3-domain">${escapeHtml(row.domain || '')}</div>
              <span class="top3-link">${escapeHtml(row.url || '')}</span>
            </div>
          </div>
        `).join('')}
      </div>
    `
    : '';

  return `
    <div class="event-card">
      <div class="event-top">
        <div class="event-main">
          <p class="event-keyword">${escapeHtml(event.keyword || '')}</p>
          <div class="event-time"><i class="fas fa-clock"></i> ${escapeHtml(formatTimeLabel(event.checked_at_local || event.checked_at_utc || ''))}</div>
          <div class="event-url">${escapeHtml(event.landing_url_zap || event.current_url || '')}</div>
        </div>
        <div class="event-ranks">
          <span class="event-chip"><strong>Prev</strong> ${formatRank(event.previous_rank)}</span>
          <span class="event-chip${Number(event.current_rank) > 10 ? ' is-danger' : ''}"><strong>Now</strong> ${formatRank(event.current_rank)}</span>
          <span class="event-chip"><strong>Delta</strong> ${formatRank(event.rank_delta)}</span>
          <span class="event-chip"><strong>Rows</strong> ${escapeHtml(String(event.backlink_rows || 0))}</span>
        </div>
      </div>
      ${top3Html}
    </div>
  `;
}

function renderDropCard(event, competitors) {
  const top3 = Array.isArray(event.top3) ? event.top3 : [];
  const top3Html = top3.length
    ? `
      <div class="detail-top3-grid">
        ${top3.map((row) => `
          <div class="top3-row">
            <div class="top3-rank">${escapeHtml(String(row.rank_group ?? '?'))}</div>
            <div>
              <div class="top3-domain">${escapeHtml(row.domain || '')}</div>
              <span class="top3-link">${escapeHtml(row.url || '')}</span>
            </div>
          </div>
        `).join('')}
      </div>
    `
    : '<div class="detail-empty">No competitor snapshot data available.</div>';

  return `
    <div class="drop-card">
      <div class="detail-header">
        <div>
          <h2 class="detail-title">${escapeHtml(event.keyword || '')}</h2>
          <div class="detail-subtitle">${escapeHtml(event.landing_url_zap || event.current_url || '')}</div>
        </div>
        <div class="detail-meta">
            <span class="detail-chip"><strong>Time</strong> ${escapeHtml(formatTimeLabel(event.checked_at_local || ''))}</span>
          <span class="detail-chip"><strong>Prev</strong> ${formatRank(event.previous_rank)}</span>
          <span class="detail-chip${Number(event.current_rank) > 10 ? ' is-danger' : ''}"><strong>Now</strong> ${formatRank(event.current_rank)}</span>
        </div>
      </div>
      ${top3Html}
      <div class="detail-competitors">
        ${competitors.length ? competitors.map(renderCompetitorCard).join('') : '<div class="detail-empty">No backlink details are available for this drop.</div>'}
      </div>
    </div>
  `;
}

function renderCompetitorCard(group) {
  const backlinks = Array.isArray(group.backlinks) ? group.backlinks : [];
  return `
    <div class="competitor-card">
      <div class="competitor-head">
        <div>
          <h3 class="competitor-title">${escapeHtml(group.competitor_domain || 'Unknown competitor')}</h3>
          <div class="competitor-url">${escapeHtml(group.competitor_landing_url || '')}</div>
        </div>
        <div class="detail-meta">
          <span class="detail-chip"><strong>Rank</strong> ${formatRank(group.competitor_serp_position)}</span>
          <span class="detail-chip"><strong>Backlinks</strong> ${backlinks.length}</span>
        </div>
      </div>
      <div class="backlink-list">
        ${backlinks.length ? backlinks.map(renderBacklinkMini).join('') : '<div class="detail-empty">No backlinks were found for this landing page.</div>'}
      </div>
    </div>
  `;
}

function renderBacklinkMini(row) {
  return `
    <div class="backlink-mini">
      <div class="backlink-source">${escapeHtml(row.referring_domain || row.referring_page_url || row.note || 'No backlink row')}</div>
      <div class="event-url">${escapeHtml(row.referring_page_url || row.note || '')}</div>
      <div class="backlink-meta">
        <span class="detail-chip"><strong>Anchor</strong> ${escapeHtml(row.anchor || '—')}</span>
        <span class="detail-chip"><strong>Type</strong> ${row.is_dofollow === null ? '—' : (Number(row.is_dofollow) === 1 ? 'dofollow' : 'nofollow')}</span>
        <span class="detail-chip"><strong>DR</strong> ${escapeHtml(String(row.domain_rank_0_100 ?? '—'))}</span>
        <span class="detail-chip"><strong>PR</strong> ${escapeHtml(String(row.page_rank_0_100 ?? '—'))}</span>
      </div>
    </div>
  `;
}

function formatRank(value) {
  return value === null || value === undefined || value === '' ? '—' : escapeHtml(String(value));
}

function formatDateLabel(value) {
  if (!value) return 'Unknown date';
  const [y, m, d] = String(value).split('-');
  if (!y || !m || !d) return value;
  return `${d}.${m}.${y}`;
}

function formatTimeLabel(value) {
  if (!value) return 'Unbekannt';
  const match = String(value).match(/(\d{2}):(\d{2})(?::(\d{2}))?/);
  if (!match) return String(value);
  const hh = match[1];
  const mm = match[2];
  const ss = match[3] || '00';
  return `${hh}:${mm}:${ss}`;
}

function getRunStartLabel(dateKey, hourKey) {
  const slotEvents = dailyEvents
    .filter((event) => String(event.event_date || '') === String(dateKey || '') && String(event.event_hour || '') === String(hourKey || ''))
    .sort((a, b) => {
      const aTime = Date.parse(a.checked_at_local || a.checked_at_utc || '') || 0;
      const bTime = Date.parse(b.checked_at_local || b.checked_at_utc || '') || 0;
      return aTime - bTime;
    });

  const firstEvent = slotEvents[0];
  if (!firstEvent) {
    return hourKey || 'Unbekannt';
  }

  return formatTimeLabel(firstEvent.checked_at_local || firstEvent.checked_at_utc || hourKey || '');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
