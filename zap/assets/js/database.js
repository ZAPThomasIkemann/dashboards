(function () {
  const groupSelect = document.getElementById('dbGroupSelect');
  const tableSelect = document.getElementById('dbTableSelect');
  const rowsRoot = document.getElementById('dbRowsRoot');
  const infoText = document.getElementById('dbInfoText');
  const endpoint = 'api/database.php';

  let overview = null;
  let selectedGroup = 'zap';
  let selectedTable = null;
  let currentOffset = 0;
  const currentLimit = 100;

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function buildGroups() {
    const tables = Array.isArray(overview?.tables) ? overview.tables : [];
    return [
      {
        key: 'zap',
        title: 'ZAP Tables',
        tables: tables.filter((table) => !String(table.table_name || '').toLowerCase().startsWith('dmc_')),
      },
      {
        key: 'dmc',
        title: 'DMC Tables',
        tables: tables.filter((table) => String(table.table_name || '').toLowerCase().startsWith('dmc_')),
      },
    ].filter((group) => group.tables.length > 0);
  }

  function renderSelectors() {
    const groups = buildGroups();
    if (!groups.length) {
      groupSelect.innerHTML = '<option value="">No groups found</option>';
      tableSelect.innerHTML = '<option value="">No tables found</option>';
      return;
    }

    if (!groups.some((group) => group.key === selectedGroup)) {
      selectedGroup = groups[0].key;
    }

    groupSelect.innerHTML = groups.map((group) => `
      <option value="${esc(group.key)}" ${group.key === selectedGroup ? 'selected' : ''}>${esc(group.title)}</option>
    `).join('');

    const currentGroup = groups.find((group) => group.key === selectedGroup) || groups[0];
    const tables = currentGroup?.tables || [];

    if (!tables.some((table) => table.table_name === selectedTable)) {
      selectedTable = tables[0]?.table_name || null;
      currentOffset = 0;
    }

    tableSelect.innerHTML = tables.length
      ? tables.map((table) => `
          <option value="${esc(table.table_name)}" ${table.table_name === selectedTable ? 'selected' : ''}>
            ${esc(table.table_name)} (${esc(table.table_rows_estimate ?? 'n/a')})
          </option>
        `).join('')
      : '<option value="">No tables found</option>';
  }

  function renderRowsSkeleton(message) {
    rowsRoot.innerHTML = `<div class="db-empty">${message}</div>`;
  }

  function renderRows(data) {
    const columns = Array.isArray(data.columns) ? data.columns : [];
    const rows = Array.isArray(data.rows) ? data.rows : [];
    const total = Number(data.total || 0);
    const pageStart = total === 0 ? 0 : currentOffset + 1;
    const pageEnd = Math.min(currentOffset + currentLimit, total);

    const header = columns.map((col) => `<th>${esc(col.name)}${col.key === 'PRI' ? ' <span style="color:var(--zap-green)">PK</span>' : ''}</th>`).join('');
    const body = rows.length
      ? rows.map((row) => `
          <tr>
            ${columns.map((col) => `<td>${esc(row[col.name] ?? '')}</td>`).join('')}
          </tr>
        `).join('')
      : `<tr><td colspan="${Math.max(columns.length, 1)}">No rows found.</td></tr>`;

    rowsRoot.innerHTML = `
      <div class="db-detail-card">
        <div class="db-detail-top">
          <div>
            <h3 class="db-detail-title">${esc(data.table)}</h3>
            <div class="db-page-meta">
              <span class="db-chip"><strong>DB</strong> ${esc(data.current_database)}</span>
              <span class="db-chip"><strong>Rows</strong> ${esc(total)}</span>
              <span class="db-chip"><strong>Order</strong> ${esc(data.order_by || 'n/a')} DESC</span>
              <span class="db-chip"><strong>Page</strong> ${esc(pageStart)}-${esc(pageEnd)}</span>
            </div>
          </div>
          <div class="db-toolbar">
            <button type="button" class="btn btn-secondary" id="dbPrevBtn" ${currentOffset <= 0 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i> Previous</button>
            <button type="button" class="btn btn-secondary" id="dbNextBtn" ${pageEnd >= total ? 'disabled' : ''}>Next <i class="fas fa-chevron-right"></i></button>
          </div>
        </div>
        <div class="db-scroll">
          <table class="db-table">
            <thead><tr>${header}</tr></thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      </div>
    `;

    document.getElementById('dbPrevBtn')?.addEventListener('click', () => {
      currentOffset = Math.max(0, currentOffset - currentLimit);
      loadRows();
    });
    document.getElementById('dbNextBtn')?.addEventListener('click', () => {
      currentOffset += currentLimit;
      loadRows();
    });
  }

  async function loadOverview() {
    infoText.textContent = 'Loading tables...';
    const res = await fetch(`${endpoint}?_t=${Date.now()}`, { cache: 'no-store' });
    const data = await res.json();
    if (!data || data.success !== true) {
      throw new Error(data && data.error ? data.error : 'Unknown error');
    }
    overview = data;
    renderSelectors();
    infoText.textContent = `Updated: ${data.server_time}`;
  }

  async function loadRows() {
    if (!selectedTable) {
      renderRowsSkeleton('<i class="fas fa-arrow-up"></i><br>Select a group and a table above.');
      return;
    }
    renderRowsSkeleton('<i class="fas fa-spinner fa-spin"></i> Loading rows...');
    const url = `${endpoint}?mode=rows&table=${encodeURIComponent(selectedTable)}&limit=${currentLimit}&offset=${currentOffset}&_t=${Date.now()}`;
    const res = await fetch(url, { cache: 'no-store' });
    const data = await res.json();
    if (!data || data.success !== true) {
      throw new Error(data && data.error ? data.error : 'Unknown error');
    }
    renderRows(data);
    infoText.textContent = `Updated: ${data.server_time}`;
  }

  async function refreshAll() {
    try {
      await loadOverview();
      await loadRows();
    } catch (err) {
      groupSelect.innerHTML = '<option value="">Error</option>';
      tableSelect.innerHTML = '<option value="">Error</option>';
      rowsRoot.innerHTML = '';
      infoText.textContent = 'Loading failed';
    }
  }

  groupSelect?.addEventListener('change', () => {
    selectedGroup = groupSelect.value;
    selectedTable = null;
    currentOffset = 0;
    renderSelectors();
    loadRows();
  });

  tableSelect?.addEventListener('change', () => {
    selectedTable = tableSelect.value;
    currentOffset = 0;
    loadRows();
  });

  refreshAll();
})();
