<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function competitor_queue_dashboard_scope(array &$params): string {
    $where = [];
    $brand = dashboard_scope_brand();
    $domains = array_keys(dashboard_scope_domains());

    if ($brand) {
        $where[] = 'brand = ?';
        $params[] = (string) $brand;
    }

    if ($domains) {
        $where[] = 'domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
        foreach ($domains as $domain) {
            $params[] = (string) $domain;
        }
    }

    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}

function competitor_queue_dashboard_normalize_status(string $status): string {
    $status = strtolower(trim($status));
    if ($status === 'done' || $status === 'completed' || $status === 'complete') {
        return 'done';
    }
    if ($status === 'processing' || $status === 'claimed' || $status === 'running') {
        return 'processing';
    }
    if ($status === 'failed' || $status === 'error') {
        return 'failed';
    }
    return 'open';
}

$pdo = db();
$limit = min(max((int) ($_GET['limit'] ?? 200), 20), 500);

$params = [];
$whereSql = competitor_queue_dashboard_scope($params);

$countStmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM competitor_backlink_queue' . $whereSql . ' GROUP BY status');
$countStmt->execute($params);

$summary = [
    'open' => 0,
    'processing' => 0,
    'done' => 0,
    'failed' => 0,
    'total' => 0,
];

foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $count = (int) ($row['cnt'] ?? 0);
    $bucket = competitor_queue_dashboard_normalize_status((string) ($row['status'] ?? ''));
    $summary[$bucket] += $count;
    $summary['total'] += $count;
}

$activeStmt = $pdo->prepare(
    'SELECT competitor_domain, COUNT(*) AS cnt, MAX(claimed_at) AS last_claimed_at
     FROM competitor_backlink_queue' . $whereSql . ($whereSql ? ' AND ' : ' WHERE ') . "LOWER(status) IN ('processing','claimed','running')
     GROUP BY competitor_domain
     ORDER BY last_claimed_at DESC, cnt DESC, competitor_domain ASC
     LIMIT 12"
);
$activeStmt->execute($params);
$activeDomains = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

$rowStmt = $pdo->prepare(
    'SELECT id, status, domain, keyword, search_volume, competitor_domain, competitor_landing_url, attempts, claimed_at, completed_at, error_message, created_at
     FROM competitor_backlink_queue' . $whereSql . '
     ORDER BY
        CASE
            WHEN LOWER(status) IN (\'processing\',\'claimed\',\'running\') THEN 0
            WHEN LOWER(status) IN (\'pending\',\'open\',\'queued\') THEN 1
            WHEN LOWER(status) IN (\'failed\',\'error\') THEN 2
            ELSE 3
        END,
        COALESCE(claimed_at, created_at) DESC,
        id DESC
     LIMIT ' . $limit
);
$rowStmt->execute($params);

echo json_encode([
    'success' => true,
    'summary' => $summary,
    'active_domains' => $activeDomains,
    'rows' => $rowStmt->fetchAll(PDO::FETCH_ASSOC),
    'limit' => $limit,
    'generated_at' => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
