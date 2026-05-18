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

function email_address_scope(array &$params): string {
    $where = ['email IS NOT NULL', 'email <> ""'];
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
    return ' WHERE ' . implode(' AND ', $where);
}

function email_address_normalized_domain_sql(string $column): string {
    return "LOWER(CASE WHEN LOWER(TRIM(COALESCE({$column}, ''))) LIKE 'www.%' THEN SUBSTRING(LOWER(TRIM(COALESCE({$column}, ''))), 5) ELSE LOWER(TRIM(COALESCE({$column}, ''))) END)";
}

$pdo = db();
$params = [];
$whereSql = email_address_scope($params);
$domainExpr = email_address_normalized_domain_sql('referring_domain');
$domain = trim((string) ($_GET['domain'] ?? ''));
$email = trim((string) ($_GET['email'] ?? ''));
$limit = min(max((int) ($_GET['limit'] ?? 5000), 1), 5000);
if ($domain !== '') {
    $whereSql .= ' AND ' . $domainExpr . ' LIKE ?';
    $params[] = '%' . strtolower($domain) . '%';
}
if ($email !== '') {
    $whereSql .= ' AND LOWER(email) LIKE ?';
    $params[] = '%' . strtolower($email) . '%';
}
$sql = '
    SELECT
        ' . $domainExpr . ' AS referring_domain,
        email,
        MAX(imprint_url) AS imprint_url,
        MAX(referring_page_url) AS example_referring_page_url,
        COUNT(*) AS backlink_count,
        COUNT(DISTINCT competitor_domain) AS competitor_count,
        MIN(created_at) AS first_found_at,
        MAX(COALESCE(email_checked_at, updated_at, checked_at_local)) AS last_checked_at
    FROM competitor_backlink_inventory
    ' . $whereSql . '
    GROUP BY ' . $domainExpr . ', email
    ORDER BY last_checked_at DESC, backlink_count DESC, referring_domain ASC
    LIMIT ' . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT 1 FROM competitor_backlink_inventory ' . $whereSql . ' GROUP BY ' . $domainExpr . ', email) t');
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
echo json_encode([
    'success' => true,
    'rows' => $rows,
    'total_count' => $totalCount,
    'limit' => $limit,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
