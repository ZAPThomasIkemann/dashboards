let emailAddressRows = [];

document.addEventListener('DOMContentLoaded', () => {
  if (!document.getElementById('eaBody')) return;
  document.getElementById('eaRefreshBtn')?.addEventListener('click', loadEmailAddressRows);
  document.getElementById('eaDomainSearch')?.addEventListener('input', debounceEmailAddressReload);
  document.getElementById('eaEmailSearch')?.addEventListener('input', debounceEmailAddressReload);
  loadEmailAddressRows();
});

async function loadEmailAddressRows() {
  const info = document.getElementById('eaInfoText');
  if (info) info.textContent = 'Loading unique domain and e-mail combinations...';
  const params = new URLSearchParams({ limit: '5000' });
  const domainQuery = String(document.getElementById('eaDomainSearch')?.value || '').trim();
  const emailQuery = String(document.getElementById('eaEmailSearch')?.value || '').trim();
  if (domainQuery) params.set('domain', domainQuery);
  if (emailQuery) params.set('email', emailQuery);

  try {
    const res = await fetch(`api/email_addresses.php?${params.toString()}&_t=${Date.now()}`, { cache: 'no-store' });
    const payload = await res.json();
    if (!payload || payload.success !== true) throw new Error('Rows could not be loaded');
    emailAddressRows = Array.isArray(payload.rows) ? payload.rows : [];
    renderEmailAddressRows(Number(payload.total_count || 0));
  } catch (error) {
    renderEmailAddressEmpty('Unique domain/e-mail combinations could not be loaded.');
    if (info) info.textContent = 'Loading failed';
  }
}

function renderEmailAddressRows(totalCount) {
  const body = document.getElementById('eaBody');
  const info = document.getElementById('eaInfoText');
  if (!body || !info) return;
  info.textContent = `${emailAddressRows.length.toLocaleString('de-DE')} of ${totalCount.toLocaleString('de-DE')} unique domain/e-mail combinations`;

  if (!emailAddressRows.length) {
    renderEmailAddressEmpty('No unique domain/e-mail combinations have been stored yet.');
    return;
  }

  body.innerHTML = emailAddressRows.map((row) => `
    <tr>
      <td>${escapeHtml(row.referring_domain || '')}</td>
      <td><a class="step-link" href="mailto:${escapeAttr(row.email || '')}">${escapeHtml(row.email || '')}</a></td>
      <td><a class="step-link" href="${escapeAttr(row.imprint_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.imprint_url || '')}</a></td>
      <td>${escapeHtml(formatNumber(row.backlink_count))}</td>
      <td>${escapeHtml(formatNumber(row.competitor_count))}</td>
      <td>${escapeHtml(formatDateTimeGerman(row.first_found_at || ''))}</td>
      <td>${escapeHtml(formatDateTimeGerman(row.last_checked_at || ''))}</td>
      <td><a class="step-link" href="${escapeAttr(row.example_referring_page_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.example_referring_page_url || '')}</a></td>
    </tr>
  `).join('');
}

function renderEmailAddressEmpty(message) {
  const body = document.getElementById('eaBody');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="8"><div class="step-empty"><i class="fas fa-inbox"></i><br>${escapeHtml(message)}</div></td></tr>`;
}

let emailAddressTimer = null;
function debounceEmailAddressReload() {
  clearTimeout(emailAddressTimer);
  emailAddressTimer = setTimeout(loadEmailAddressRows, 250);
}

function formatDateTimeGerman(value) {
  const text = String(value || '').trim();
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2})/);
  return match ? `${match[3]}.${match[2]}.${match[1]} ${match[4]}` : text;
}

function formatNumber(value) {
  const num = Number(value);
  return Number.isFinite(num) ? num.toLocaleString('de-DE') : String(value || '');
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
