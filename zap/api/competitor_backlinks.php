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

function competitor_inventory_scope(array &$params): string {
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

$pdo = db();
$mode = (string) ($_GET['mode'] ?? 'rows');
if ($mode === 'filters') {
    $params = [];
    $whereSql = competitor_inventory_scope($params);
    $sql = 'SELECT competitor_domain, COUNT(*) AS cnt FROM competitor_backlink_inventory ' . $whereSql . ' AND competitor_domain IS NOT NULL AND competitor_domain <> "" GROUP BY competitor_domain ORDER BY competitor_domain ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'competitors' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$params = [];
$whereSql = competitor_inventory_scope($params);
$where = $whereSql ? [substr($whereSql, 7)] : [];
$competitor = trim((string) ($_GET['competitor'] ?? ''));
$follow = trim((string) ($_GET['follow'] ?? ''));
$refInclude = trim((string) ($_GET['ref_include'] ?? ''));
$limit = min(max((int) ($_GET['limit'] ?? 5000), 1), 5000);
if ($competitor !== '') {
    $where[] = 'competitor_domain = ?';
    $params[] = $competitor;
}
if ($follow === 'dofollow') {
    $where[] = 'is_dofollow = 1';
} elseif ($follow === 'nofollow') {
    $where[] = 'is_dofollow = 0';
}
if ($refInclude !== '') {
    $where[] = 'LOWER(referring_page_url) LIKE ?';
    $params[] = '%' . strtolower($refInclude) . '%';
}
$finalWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM competitor_backlink_inventory' . $finalWhere);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$stmt = $pdo->prepare('SELECT * FROM competitor_backlink_inventory' . $finalWhere . ' ORDER BY checked_at_local DESC, domain_rank_0_100 DESC, search_volume DESC, id DESC LIMIT ' . $limit);
$stmt->execute($params);
echo json_encode([
    'success' => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    'total_count' => $totalCount,
    'limit' => $limit,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
