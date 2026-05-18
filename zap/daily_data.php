<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Data - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/css/main.css?v=20260506a">
    <style>
        .daily-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
            min-width: 0;
        }
        .daily-column {
            display: grid;
            gap: 18px;
            min-width: 0;
        }
        .daily-toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .daily-tree {
            display: grid;
            gap: 16px;
        }
        .hour-group,
        .event-card,
        .competitor-card,
        .backlink-mini {
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
            border-radius: 18px;
        }
        .day-group {
            border-left: 2px solid rgba(255,255,255,.08);
            padding-left: 12px;
        }
        .day-header,
        .hour-header {
            width: 100%;
            border: 0;
            background: transparent;
            color: inherit;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            cursor: pointer;
        }
        .day-header {
            justify-content: flex-start;
            gap: 10px;
        }
        .day-header strong,
        .hour-header strong {
            font-size: 1rem;
        }
        .day-header .page-subtitle {
            text-align: left;
        }
        .day-body,
        .hour-body {
            padding: 6px 0 14px 0;
            display: grid;
            gap: 12px;
        }
        .event-card {
            padding: 14px 16px;
            transition: border-color .18s ease, transform .18s ease, background .18s ease;
        }
        .event-card:hover {
            border-color: rgba(57,255,20,.35);
            background: rgba(57,255,20,.08);
            transform: translateY(-1px);
        }
        .event-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .event-main {
            min-width: 0;
            flex: 1 1 320px;
        }
        .event-keyword {
            margin: 0;
            font-weight: 700;
            color: var(--zap-text);
        }
        .event-time {
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(57,255,20,.10);
            color: var(--zap-text);
            font-size: .82rem;
            font-weight: 600;
        }
        .event-url {
            margin-top: 6px;
            font-size: .88rem;
            color: var(--zap-text-muted);
            word-break: break-word;
        }
        .event-ranks {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex: 1 1 280px;
        }
        .event-chip,
        .detail-chip {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            white-space: nowrap;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.05);
            color: var(--zap-text-muted);
            font-size: .82rem;
        }
        .event-chip strong,
        .detail-chip strong {
            color: var(--zap-text);
        }
        .event-chip.is-danger,
        .detail-chip.is-danger {
            background: rgba(239,68,68,.18);
            color: #fecaca;
            border: 1px solid rgba(239,68,68,.35);
        }
        .event-chip.is-danger strong,
        .detail-chip.is-danger strong {
            color: #fee2e2;
        }
        .top3-list {
            display: grid;
            gap: 8px;
            margin-top: 12px;
        }
        .drop-list {
            display: grid;
            gap: 14px;
        }
        .top3-row {
            display: grid;
            grid-template-columns: 28px minmax(0,1fr);
            gap: 10px;
            padding: 9px 10px;
            border-radius: 14px;
            background: rgba(255,255,255,.04);
        }
        .top3-rank {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            background: rgba(57,255,20,.14);
            color: var(--zap-green);
        }
        .top3-domain {
            font-weight: 600;
            color: var(--zap-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .top3-link {
            display: block;
            margin-top: 3px;
            color: var(--zap-text-muted);
            font-size: .84rem;
            word-break: break-word;
        }
        .detail-empty,
        .detail-placeholder {
            padding: 30px;
            text-align: center;
            color: var(--zap-text-muted);
        }
        .detail-header {
            display: grid;
            gap: 14px;
        }
        .detail-title {
            margin: 0;
            font-size: 1.4rem;
        }
        .detail-subtitle {
            color: var(--zap-text-muted);
            font-size: .95rem;
        }
        .detail-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .detail-top3-grid,
        .detail-competitors {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }
        .drop-card {
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
            border-radius: 18px;
            padding: 16px;
        }
        .competitor-card {
            padding: 16px;
        }
        .competitor-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }
        .competitor-title {
            margin: 0;
            font-size: 1rem;
        }
        .competitor-url {
            margin-top: 6px;
            color: var(--zap-text-muted);
            font-size: .88rem;
            word-break: break-word;
        }
        .backlink-list {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }
        .backlink-mini {
            padding: 12px 14px;
        }
        .backlink-source {
            font-weight: 600;
            color: var(--zap-text);
            word-break: break-word;
        }
        .backlink-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .badge-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255,255,255,.06);
            color: var(--zap-text);
            font-size: .8rem;
            font-weight: 700;
        }
        @media (max-width: 1100px) {
            .daily-layout {
                grid-template-columns: 1fr;
            }
        }
        /* Trend Chart */
        .trend-card { margin-bottom: 0; }
        .trend-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
        .trend-tabs  { display:flex; gap:6px; }
        .trend-tab   { border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:var(--zap-text-muted); border-radius:8px; padding:5px 14px; cursor:pointer; font:inherit; font-size:.8rem; font-weight:600; transition:all .15s; }
        .trend-tab.active { background:rgba(24,232,136,.12); border-color:rgba(24,232,136,.30); color:#d9ffd8; }
        .trend-chips { display:flex; gap:8px; flex-wrap:wrap; }
        .trend-chip  { display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border-radius:999px; background:rgba(255,255,255,.05); font-size:.8rem; white-space:nowrap; }
        .trend-chip strong { color:var(--zap-text); }
        .trend-chip span   { color:var(--zap-text-muted); }
        .trend-canvas-wrap { position:relative; height:240px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1><i class="fas fa-clock-rotate-left"></i> Daily Data</h1>
                <span class="page-subtitle">All hourly ranking pushes with positions, optional top 3 results, and backlink details</span>
                </div>
                <div class="daily-toolbar">
                    <select id="dailyDateSelect" class="search-input search-input--compact"></select>
                    <select id="dailyHourSelect" class="search-input search-input--compact"></select>
                    <input type="text" id="dailyKeywordSearch" class="search-input" placeholder="Search keyword...">
                    <button class="btn btn-secondary" id="dailyReloadBtn"><i class="fas fa-sync-alt"></i> Reload</button>
                </div>
            </div>

            <!-- ── Ranking Trend Chart ── -->
            <div class="card trend-card" style="margin-bottom:18px;">
                <div class="trend-header">
                    <div>
                        <h3 style="margin:0 0 4px;"><i class="fas fa-chart-line"></i> Ranking Trend</h3>
                        <div class="trend-chips" id="trendChips">
                            <div class="trend-chip"><i class="fas fa-spinner fa-spin" style="color:var(--zap-green)"></i><span>Loading...</span></div>
                        </div>
                    </div>
                    <div class="trend-tabs">
                        <button class="trend-tab active" onclick="setTrendMode('top10_top3')" id="trendTabPositions">Top 3 / Top 10</button>
                        <button class="trend-tab" onclick="setTrendMode('avg_pos')" id="trendTabAvg">Ø Position</button>
                        <button class="trend-tab" onclick="setTrendMode('unique_kw')" id="trendTabKw">Keywords</button>
                    </div>
                </div>
                <div class="trend-canvas-wrap">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="daily-layout">
                <div class="daily-column">
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-calendar-days"></i> Daily Data</h3>
                                <span id="dailyDataInfo" class="page-subtitle">Loading...</span>
                            </div>
                        <div class="card-body">
                            <div id="dailyDataTree" class="daily-tree">
                                <div class="detail-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading timeline...</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="daily-column">
                    <div class="card">
                        <div class="card-header">
                <h3><i class="fas fa-triangle-exclamation"></i> Outside Top 10</h3>
                            <span id="dailyDropInfo" class="page-subtitle">Loading...</span>
                        </div>
                        <div class="card-body">
                            <div id="dailyDropTree" class="drop-list">
                                <div class="detail-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading drop details...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260506a"></script>
    <script src="assets/js/daily_data.js?v=20260427j"></script>
    <script>
    // ── Ranking Trend Chart ────────────────────────────────────────────────────
    let trendChart   = null;
    let trendData    = [];
    let trendMode    = 'top10_top3';
    const TREND_API  = '/dashboards/zap/api/ranking_trend.php';

    const TREND_COLORS = {
      top10:  'rgba(24,232,136,1)',
      top3:   'rgba(251,191,36,1)',
      avg:    'rgba(129,140,248,1)',
      unique: 'rgba(56,189,248,1)',
    };

    function setTrendMode(mode) {
      trendMode = mode;
      ['trendTabPositions','trendTabAvg','trendTabKw'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.remove('active');
      });
      const activeId = mode === 'top10_top3' ? 'trendTabPositions'
                     : mode === 'avg_pos'    ? 'trendTabAvg'
                     :                        'trendTabKw';
      document.getElementById(activeId)?.classList.add('active');
      renderTrendChart(trendData);
    }

    function formatDayLabel(iso) {
      if (!iso) return '';
      const [,m,d] = iso.split('-');
      return d + '.' + m + '.';
    }

    async function loadTrendChart() {
      try {
        const res = await fetch(TREND_API + '?_t=' + Date.now(), { cache: 'no-store' });
        const payload = await res.json();
        if (!payload.success) return;
        trendData = payload.rows || [];
        renderTrendChart(trendData);
        renderTrendChips(trendData);
      } catch(e) {
        console.error('Trend chart error:', e);
      }
    }

    function renderTrendChips(rows) {
      const el = document.getElementById('trendChips');
      if (!el || !rows.length) return;
      const last = rows[rows.length - 1];
      const first = rows[0];
      el.innerHTML = `
        <div class="trend-chip"><strong>${(last.top10||0).toLocaleString('de-DE')}</strong><span>Top 10 (heute)</span></div>
        <div class="trend-chip"><strong>${(last.top3||0).toLocaleString('de-DE')}</strong><span>Top 3 (heute)</span></div>
        <div class="trend-chip"><strong>${last.avg_pos||'–'}</strong><span>Ø Position</span></div>
        <div class="trend-chip"><strong>${(last.unique_keywords||0).toLocaleString('de-DE')}</strong><span>Keywords</span></div>
        <div class="trend-chip" style="color:var(--zap-text-muted);font-size:.76rem;">${rows.length} Tage</div>
      `;
    }

    function renderTrendChart(rows) {
      const canvas = document.getElementById('trendChart');
      if (!canvas || !rows.length) return;
      if (typeof Chart === 'undefined') return;

      const labels = rows.map(r => formatDayLabel(r.day));

      let datasets;
      let yScales;

      if (trendMode === 'top10_top3') {
        datasets = [
          {
            label: 'Top 10',
            data: rows.map(r => r.top10),
            borderColor: TREND_COLORS.top10,
            backgroundColor: 'rgba(24,232,136,0.08)',
            fill: true,
            tension: 0.3,
            pointRadius: rows.length > 20 ? 0 : 3,
            borderWidth: 2,
          },
          {
            label: 'Top 3',
            data: rows.map(r => r.top3),
            borderColor: TREND_COLORS.top3,
            backgroundColor: 'rgba(251,191,36,0.06)',
            fill: true,
            tension: 0.3,
            pointRadius: rows.length > 20 ? 0 : 3,
            borderWidth: 2,
          },
        ];
        yScales = {
          y: {
            ticks: { color: '#6b7280', font: { size: 11 } },
            grid:  { color: 'rgba(255,255,255,0.05)' },
          }
        };
      } else if (trendMode === 'avg_pos') {
        datasets = [
          {
            label: 'Ø Position (alle)',
            data: rows.map(r => r.avg_pos),
            borderColor: TREND_COLORS.avg,
            backgroundColor: 'rgba(129,140,248,0.08)',
            fill: true,
            tension: 0.3,
            pointRadius: rows.length > 20 ? 0 : 3,
            borderWidth: 2,
          },
          {
            label: 'Ø Position (Top 100)',
            data: rows.map(r => r.avg_pos_top100),
            borderColor: TREND_COLORS.top10,
            backgroundColor: 'transparent',
            tension: 0.3,
            pointRadius: rows.length > 20 ? 0 : 3,
            borderWidth: 1.5,
            borderDash: [4,3],
          },
        ];
        yScales = {
          y: {
            reverse: true, // lower = better
            ticks: { color: '#6b7280', font: { size: 11 } },
            grid:  { color: 'rgba(255,255,255,0.05)' },
            title: { display: true, text: 'Position (niedriger = besser)', color: '#6b7280', font:{size:10} },
          }
        };
      } else {
        datasets = [
          {
            label: 'Unique Keywords',
            data: rows.map(r => r.unique_keywords),
            borderColor: TREND_COLORS.unique,
            backgroundColor: 'rgba(56,189,248,0.08)',
            fill: true,
            tension: 0.3,
            pointRadius: rows.length > 20 ? 0 : 3,
            borderWidth: 2,
          },
        ];
        yScales = {
          y: {
            ticks: { color: '#6b7280', font: { size: 11 } },
            grid:  { color: 'rgba(255,255,255,0.05)' },
          }
        };
      }

      if (trendChart) {
        trendChart.data.labels   = labels;
        trendChart.data.datasets = datasets;
        trendChart.options.scales = Object.assign({
          x: {
            ticks: {
              color: '#6b7280',
              font:  { size: 10 },
              maxTicksLimit: 14,
              maxRotation: 0,
            },
            grid: { color: 'rgba(255,255,255,0.03)' },
          }
        }, yScales);
        trendChart.update();
        return;
      }

      trendChart = new Chart(canvas, {
        type: 'line',
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: {
              labels: { color: '#9ca3af', font: { size: 12 }, usePointStyle: true, pointStyleWidth: 10 }
            },
            tooltip: {
              backgroundColor: '#1a1f2e',
              borderColor: 'rgba(255,255,255,0.1)',
              borderWidth: 1,
              titleColor: '#e5e7eb',
              bodyColor: '#9ca3af',
            },
          },
          scales: Object.assign({
            x: {
              ticks: {
                color: '#6b7280',
                font:  { size: 10 },
                maxTicksLimit: 14,
                maxRotation: 0,
              },
              grid: { color: 'rgba(255,255,255,0.03)' },
            }
          }, yScales),
        },
      });
    }

    document.addEventListener('DOMContentLoaded', loadTrendChart);
    </script>
</body>
</html>
