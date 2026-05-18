/* ── Email Send / Kanban JS ──────────────────────────────────────────────── */

const KANBAN_COLS = [
  { status: 'to_send',        label: 'To Send',    icon: 'fa-paper-plane', color: '#18e888' },
  { status: 'sent',           label: 'Sent',       icon: 'fa-check',       color: '#00c8ff' },
  { status: 'replied',        label: 'Reply',      icon: 'fa-reply',       color: '#a78bfa' },
  { status: 'in_discussion',  label: 'Discussion', icon: 'fa-comments',    color: '#fbbf24' },
  { status: 'content_agreed', label: 'Content',    icon: 'fa-file-alt',    color: '#f472b6' },
  { status: 'done',           label: 'Done',       icon: 'fa-flag',        color: '#4ade80' },
];

let kbDomainFilter = '';
let kbKeywordFilter = '';
let kbColumnOffsets = {};
let kbColumnTotals  = {};
let smtpConfigCache = null;

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (!document.getElementById('kanbanBoard')) return;

  buildKanbanBoard();
  loadStats();
  loadSmtpConfig();

  document.getElementById('kbDomainFilter')?.addEventListener('input', debounce(() => {
    kbDomainFilter = document.getElementById('kbDomainFilter').value.trim();
    refreshAllColumns();
  }, 400));
  document.getElementById('kbKeywordFilter')?.addEventListener('input', debounce(() => {
    kbKeywordFilter = document.getElementById('kbKeywordFilter').value.trim();
    refreshAllColumns();
  }, 400));
  document.getElementById('kbRefreshBtn')?.addEventListener('click', () => {
    loadStats();
    refreshAllColumns();
  });

  // SMTP form
  document.getElementById('smtpForm')?.addEventListener('submit', saveSmtpConfig);

  // Toggle SMTP card
  document.getElementById('smtpToggle')?.addEventListener('click', () => {
    const body = document.getElementById('smtpBody');
    const icon = document.getElementById('smtpToggleIcon');
    if (body.style.display === 'none') {
      body.style.display = 'block';
      icon.classList.replace('fa-chevron-down', 'fa-chevron-up');
    } else {
      body.style.display = 'none';
      icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
    }
  });

  // Drag-and-drop setup (HTML5)
  document.getElementById('kanbanBoard').addEventListener('dragover', (e) => {
    e.preventDefault();
    const col = e.target.closest('.kb-col-cards');
    if (col) col.classList.add('kb-drag-over');
  });
  document.getElementById('kanbanBoard').addEventListener('dragleave', (e) => {
    const col = e.target.closest('.kb-col-cards');
    if (col) col.classList.remove('kb-drag-over');
  });
  document.getElementById('kanbanBoard').addEventListener('drop', async (e) => {
    e.preventDefault();
    const col = e.target.closest('[data-col-status]');
    if (!col) return;
    col.querySelector('.kb-col-cards')?.classList.remove('kb-drag-over');
    const prospectId = e.dataTransfer.getData('prospectId');
    const fromStatus = e.dataTransfer.getData('fromStatus');
    const toStatus = col.dataset.colStatus;
    if (prospectId && fromStatus !== toStatus) {
      await moveCard(parseInt(prospectId), fromStatus, toStatus);
    }
  });
});

// ── Build Kanban Board HTML ──────────────────────────────────────────────────
function buildKanbanBoard() {
  const board = document.getElementById('kanbanBoard');
  board.innerHTML = KANBAN_COLS.map(col => `
    <div class="kb-col" data-col-status="${col.status}">
      <div class="kb-col-header" style="border-color:${col.color}20">
        <span class="kb-col-icon" style="color:${col.color}"><i class="fas ${col.icon}"></i></span>
        <span class="kb-col-title">${col.label}</span>
        <span class="kb-col-count badge-count" id="kbCount-${col.status}">—</span>
      </div>
      <div class="kb-col-cards" id="kbCards-${col.status}">
        <div class="kb-loading"><div class="spinner"></div></div>
      </div>
      <div class="kb-col-footer" id="kbFooter-${col.status}" style="display:none">
        <button class="btn btn-secondary kb-load-more" data-col="${col.status}">
          Load more
        </button>
      </div>
    </div>
  `).join('');

  // Load more buttons
  board.querySelectorAll('.kb-load-more').forEach(btn => {
    btn.addEventListener('click', () => loadColumn(btn.dataset.col, true));
  });

  // Load all columns
  KANBAN_COLS.forEach(col => loadColumn(col.status, false));
}

// ── Load column data ─────────────────────────────────────────────────────────
async function loadColumn(status, append) {
  const container = document.getElementById('kbCards-' + status);
  const footerEl  = document.getElementById('kbFooter-' + status);
  const countEl   = document.getElementById('kbCount-' + status);
  if (!container) return;

  if (!append) {
    kbColumnOffsets[status] = 0;
    container.innerHTML = '<div class="kb-loading"><div class="spinner"></div></div>';
  }

  const params = new URLSearchParams({
    mode: 'kanban', status, limit: 30,
    offset: kbColumnOffsets[status] || 0,
  });
  if (kbDomainFilter)  params.set('domain',  kbDomainFilter);
  if (kbKeywordFilter) params.set('keyword', kbKeywordFilter);

  try {
    const res  = await fetch(`api/emails_send.php?${params}&_t=${Date.now()}`, { cache: 'no-store' });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'API error');

    kbColumnTotals[status] = data.total;
    kbColumnOffsets[status] = (kbColumnOffsets[status] || 0) + data.rows.length;

    if (!append) container.innerHTML = '';

    if (data.rows.length === 0 && !append) {
      container.innerHTML = '<div class="kb-empty">No items</div>';
    } else {
      data.rows.forEach(row => {
        const card = buildCard(row, status);
        container.appendChild(card);
      });
    }

    if (countEl) countEl.textContent = data.total.toLocaleString('de-DE');

    // Show/hide load more
    if (footerEl) {
      const shown = kbColumnOffsets[status];
      footerEl.style.display = (shown < data.total) ? 'block' : 'none';
      if (shown < data.total) {
        const btn = footerEl.querySelector('.kb-load-more');
        if (btn) btn.textContent = `Load more (${(data.total - shown).toLocaleString('de-DE')} remaining)`;
      }
    }
  } catch (err) {
    container.innerHTML = `<div class="kb-empty">Error: ${escapeHtml(String(err))}</div>`;
  }
}

// ── Build a Kanban Card ──────────────────────────────────────────────────────
function buildCard(row, currentStatus) {
  const card = document.createElement('div');
  card.className = 'kb-card';
  card.dataset.id = row.id;
  card.dataset.status = currentStatus;
  card.draggable = true;

  card.addEventListener('dragstart', (e) => {
    e.dataTransfer.setData('prospectId', row.id);
    e.dataTransfer.setData('fromStatus', currentStatus);
    card.classList.add('kb-dragging');
  });
  card.addEventListener('dragend', () => card.classList.remove('kb-dragging'));

  const rank = row.domain_rank_0_100 ? parseFloat(row.domain_rank_0_100).toFixed(0) : '—';
  const vol  = row.search_volume ? parseInt(row.search_volume).toLocaleString('de-DE') : '—';

  const otherCols = KANBAN_COLS.filter(c => c.status !== currentStatus);
  const moveOptions = otherCols.map(c =>
    `<option value="${c.status}">${c.label}</option>`
  ).join('');

  card.innerHTML = `
    <div class="kb-card-top">
      <span class="kb-card-domain">${escapeHtml(row.competitor_domain || '')}</span>
      <span class="step-badge info" title="Domain Rank">${rank}</span>
    </div>
    <div class="kb-card-email">${escapeHtml(row.email || '')}</div>
    <div class="kb-card-meta">
      <span class="kb-card-kw" title="Keyword">${escapeHtml(row.keyword || '')}</span>
      <span class="kb-card-vol" title="Search volume">${vol}/mo</span>
    </div>
    ${row.outreach_notes ? `<div class="kb-card-notes">${escapeHtml(row.outreach_notes)}</div>` : ''}
    <div class="kb-card-actions">
      <select class="kb-move-select search-input" title="Move to column">
        <option value="">Move to…</option>
        ${moveOptions}
      </select>
      <button class="btn btn-sm kb-note-btn" title="Add note"><i class="fas fa-sticky-note"></i></button>
    </div>
  `;

  // Move select
  card.querySelector('.kb-move-select').addEventListener('change', async (e) => {
    const newStatus = e.target.value;
    if (!newStatus) return;
    await moveCard(row.id, currentStatus, newStatus, card);
  });

  // Note button
  card.querySelector('.kb-note-btn').addEventListener('click', () => {
    const note = prompt('Note for this prospect:', row.outreach_notes || '');
    if (note !== null) moveCard(row.id, currentStatus, currentStatus, card, note);
  });

  return card;
}

// ── Move Card ────────────────────────────────────────────────────────────────
async function moveCard(id, fromStatus, toStatus, cardEl, notes) {
  try {
    const res = await fetch('api/emails_send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mode: 'move', id, status: toStatus, notes: notes || '' }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Move failed');

    showToast(`Moved to ${KANBAN_COLS.find(c=>c.status===toStatus)?.label || toStatus}`, 'success');

    // Refresh both columns
    loadColumn(fromStatus, false);
    if (fromStatus !== toStatus) loadColumn(toStatus, false);
    loadStats();
  } catch (err) {
    showToast('Move failed: ' + err.message, 'error');
  }
}

// ── Refresh All Columns ──────────────────────────────────────────────────────
function refreshAllColumns() {
  KANBAN_COLS.forEach(col => loadColumn(col.status, false));
}

// ── Load Stats ───────────────────────────────────────────────────────────────
async function loadStats() {
  try {
    const res  = await fetch(`api/emails_send.php?mode=stats&_t=${Date.now()}`, { cache: 'no-store' });
    const data = await res.json();
    if (!data.success) return;
    const s = data.stats;
    const map = {
      'statToSend':    s.to_send,
      'statSent':      s.sent,
      'statReplied':   s.replied,
      'statDiscussion':s.in_discussion,
      'statContent':   s.content_agreed,
      'statDone':      s.done,
    };
    for (const [id, val] of Object.entries(map)) {
      const el = document.getElementById(id);
      if (el) el.textContent = (val || 0).toLocaleString('de-DE');
    }
  } catch {}
}

// ── Load SMTP Config ─────────────────────────────────────────────────────────
async function loadSmtpConfig() {
  try {
    const res  = await fetch('api/emails_send.php?mode=smtp_config', { cache: 'no-store' });
    const data = await res.json();
    if (!data.success || !data.config) return;
    smtpConfigCache = data.config;
    const c = data.config;
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
    set('smtpHost', c.smtp_host);
    set('smtpPort', c.smtp_port);
    set('smtpUser', c.smtp_user);
    set('smtpPass', c.smtp_pass);
    set('smtpFromName',  c.from_name);
    set('smtpFromEmail', c.from_email);
    set('smtpSubject',   c.email_subject);
    set('smtpTemplate',  c.email_template);
    document.getElementById('smtpStatus')?.classList.remove('hidden');
    document.getElementById('smtpStatus').textContent = c.smtp_host ? `✓ Configured: ${c.smtp_host}:${c.smtp_port}` : 'Not configured';
  } catch {}
}

// ── Save SMTP Config ─────────────────────────────────────────────────────────
async function saveSmtpConfig(e) {
  e.preventDefault();
  const getVal = (id) => (document.getElementById(id)?.value || '');
  const payload = {
    mode: 'smtp_config',
    smtp_host: getVal('smtpHost'),
    smtp_port: parseInt(getVal('smtpPort') || '587'),
    smtp_user: getVal('smtpUser'),
    smtp_pass: getVal('smtpPass'),
    from_name:  getVal('smtpFromName'),
    from_email: getVal('smtpFromEmail'),
    email_subject:  getVal('smtpSubject'),
    email_template: getVal('smtpTemplate'),
  };
  try {
    const res  = await fetch('api/emails_send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.success) {
      showToast('SMTP settings saved', 'success');
      const st = document.getElementById('smtpStatus');
      if (st) st.textContent = `✓ Configured: ${payload.smtp_host}:${payload.smtp_port}`;
    } else {
      showToast('Save failed: ' + (data.error || 'unknown'), 'error');
    }
  } catch (err) {
    showToast('Save failed: ' + err.message, 'error');
  }
}

// ── Debounce ─────────────────────────────────────────────────────────────────
function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}
