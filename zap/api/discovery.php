<?php
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = db();

function disc_scope(array &$params, string $alias = ''): string {
    $p = $alias !== '' ? ($alias . '.') : '';
    $w = [];
    $brand   = dashboard_scope_brand();
    $domains = array_keys(dashboard_scope_domains());
    if ($brand)   { $w[] = $p . 'brand = ?';   $params[] = (string) $brand; }
    if ($domains) {
        $w[] = $p . 'domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
        foreach ($domains as $d) $params[] = (string) $d;
    }
    return $w ? ' WHERE ' . implode(' AND ', $w) : '';
}

$mode = trim((string) ($_GET['mode'] ?? 'daily'));

// ── mode=dates: list all snapshot dates with keyword counts ───────────────────
if ($mode === 'dates') {
    // From daily_snapshots (full/partial discovery runs)
    $snapParams = [];
    $snapWhere  = disc_scope($snapParams);
    $snapRows = $pdo->prepare("
        SELECT snapshot_date, COUNT(*) as keywords,
               SUM(CASE WHEN position BETWEEN 1 AND 3  THEN 1 ELSE 0 END) as top3,
               SUM(CASE WHEN position BETWEEN 1 AND 10 THEN 1 ELSE 0 END) as top10,
               MIN(created_at) as first_seen, MAX(updated_at) as last_seen
        FROM rankings_daily_snapshots
        $snapWhere
        GROUP BY snapshot_date
        ORDER BY snapshot_date DESC
        LIMIT 60
    ");
    $snapRows->execute($snapParams);
    $dates = $snapRows->fetchAll(PDO::FETCH_ASSOC);

    // Cumulative total (current rankings table)
    $cumParams = [];
    $cumWhere  = disc_scope($cumParams);
    $cumStmt   = $pdo->prepare("SELECT COUNT(*) FROM rankings $cumWhere");
    $cumStmt->execute($cumParams);
    $cumTotal  = (int) $cumStmt->fetchColumn();

    $cumParams2 = [];
    $cumWhere2  = disc_scope($cumParams2);
    $top3Stmt   = $pdo->prepare("SELECT COUNT(*) FROM rankings $cumWhere2 AND position BETWEEN 1 AND 3");
    $top3Stmt->execute($cumParams2);
    $cumTop3    = (int) $top3Stmt->fetchColumn();

    $cumParams3 = [];
    $cumWhere3  = disc_scope($cumParams3);
    $top10Stmt  = $pdo->prepare("SELECT COUNT(*) FROM rankings $cumWhere3 AND position BETWEEN 1 AND 10");
    $top10Stmt->execute($cumParams3);
    $cumTop10   = (int) $top10Stmt->fetchColumn();

    echo json_encode([
        'success'       => true,
        'dates'         => $dates,
        'cumulative'    => [
            'total'  => $cumTotal,
            'top3'   => $cumTop3,
            'top10'  => $cumTop10,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── mode=cumulative: full keyword list from rankings table ─────────────────────
if ($mode === 'cumulative') {
    $keyword  = trim((string) ($_GET['keyword']  ?? ''));
    $urlFilter = trim((string) ($_GET['url']     ?? ''));
    $posFilter = trim((string) ($_GET['pos']     ?? ''));
    $limit    = max(50, min(5000, (int) ($_GET['limit']  ?? 500)));
    $offset   = max(0,            (int) ($_GET['offset'] ?? 0));

    // Build WHERE cleanly
    $wParams = [];
    $wClauses = [];
    $brand   = dashboard_scope_brand();
    $domains = array_keys(dashboard_scope_domains());
    if ($brand)    { $wClauses[] = 'brand = ?';        $wParams[] = (string) $brand; }
    if ($domains)  {
        $wClauses[] = 'domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
        foreach ($domains as $d) $wParams[] = (string) $d;
    }
    if ($keyword)  { $wClauses[] = 'keyword LIKE ?';   $wParams[] = '%' . $keyword . '%'; }
    if ($urlFilter){ $wClauses[] = 'url LIKE ?';        $wParams[] = '%' . $urlFilter . '%'; }
    if ($posFilter && strpos($posFilter, '-') !== false) {
        [$mn, $mx] = array_map('intval', explode('-', $posFilter));
        $wClauses[] = 'position >= ? AND position <= ?';
        $wParams[] = $mn; $wParams[] = $mx;
    }
    $wSql = $wClauses ? ' WHERE ' . implode(' AND ', $wClauses) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM rankings $wSql");
    $countStmt->execute($wParams);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT keyword, url, position, previous_position, search_volume, cpc, competition,
               location_code, language_code, last_checked
        FROM rankings $wSql
        ORDER BY COALESCE(position, 99999) ASC, COALESCE(search_volume, 0) DESC, keyword ASC
        LIMIT $limit OFFSET $offset
    ");
    $dataStmt->execute($wParams);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'mode'    => 'cumulative',
        'rows'    => $rows,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── mode=daily: snapshot for a specific date from rankings_daily_snapshots ────
if ($mode === 'daily') {
    // Determine date
    $wParams = [];
    $wClauses = [];
    $brand   = dashboard_scope_brand();
    $domains = array_keys(dashboard_scope_domains());
    if ($brand)   { $wClauses[] = 'brand = ?';   $wParams[] = (string) $brand; }
    if ($domains) {
        $wClauses[] = 'domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
        foreach ($domains as $d) $wParams[] = (string) $d;
    }
    $baseWhere = $wClauses ? ' WHERE ' . implode(' AND ', $wClauses) : '';

    // Default to latest snapshot date
    $dateStmt = $pdo->prepare("SELECT MAX(snapshot_date) FROM rankings_daily_snapshots $baseWhere");
    $dateStmt->execute($wParams);
    $latestDate = (string) ($dateStmt->fetchColumn() ?: '');

    $selectedDate = trim((string) ($_GET['date'] ?? $latestDate));
    if ($selectedDate === '') {
        echo json_encode(['success' => true, 'mode' => 'daily', 'date' => '', 'rows' => [], 'total' => 0]);
        exit;
    }

    $keyword   = trim((string) ($_GET['keyword']  ?? ''));
    $urlFilter = trim((string) ($_GET['url']      ?? ''));
    $posFilter = trim((string) ($_GET['pos']      ?? ''));
    $limit     = max(50, min(5000, (int) ($_GET['limit']  ?? 500)));
    $offset    = max(0,            (int) ($_GET['offset'] ?? 0));

    $qParams = $wParams;
    $qClauses = $wClauses ? array_values($wClauses) : [];
    $qClauses[] = 'snapshot_date = ?'; $qParams[] = $selectedDate;
    if ($keyword)   { $qClauses[] = 'keyword LIKE ?';  $qParams[] = '%' . $keyword . '%'; }
    if ($urlFilter) { $qClauses[] = 'url LIKE ?';       $qParams[] = '%' . $urlFilter . '%'; }
    if ($posFilter && strpos($posFilter, '-') !== false) {
        [$mn, $mx] = array_map('intval', explode('-', $posFilter));
        $qClauses[] = 'position >= ? AND position <= ?';
        $qParams[] = $mn; $qParams[] = $mx;
    }
    $qWhere = $qClauses ? ' WHERE ' . implode(' AND ', $qClauses) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM rankings_daily_snapshots $qWhere");
    $countStmt->execute($qParams);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT keyword, url, position, previous_position, search_volume, cpc, competition,
               location_code, language_code, snapshot_date, snapshot_source, updated_at
        FROM rankings_daily_snapshots $qWhere
        ORDER BY COALESCE(position, 99999) ASC, COALESCE(search_volume, 0) DESC, keyword ASC
        LIMIT $limit OFFSET $offset
    ");
    $dataStmt->execute($qParams);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary stats
    $statsParams = $qParams;
    // Remove limit/offset params (they're inline)
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN position BETWEEN 1 AND 3  THEN 1 ELSE 0 END) as top3,
            SUM(CASE WHEN position BETWEEN 1 AND 10 THEN 1 ELSE 0 END) as top10,
            COUNT(DISTINCT url) as unique_urls,
            MAX(updated_at) as last_updated
        FROM rankings_daily_snapshots $qWhere
    ");
    $statsStmt->execute($qParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success'  => true,
        'mode'     => 'daily',
        'date'     => $selectedDate,
        'rows'     => $rows,
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
        'stats'    => $stats,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown mode: ' . htmlspecialchars($mode)]);
