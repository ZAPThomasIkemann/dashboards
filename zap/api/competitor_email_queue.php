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

function competitor_email_queue_scope(array &$params): string {
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

function competitor_email_queue_status_bucket(?string $status): string {
    $value = strtolower(trim((string) $status));
    if ($value === '') {
        return 'pending';
    }
    if ($value === 'processing') {
        return 'processing';
    }
    return $value;
}

function competitor_email_queue_normalized_domain_sql(string $column): string {
    return "LOWER(CASE WHEN LOWER(TRIM(COALESCE({$column}, ''))) LIKE 'www.%' THEN SUBSTRING(LOWER(TRIM(COALESCE({$column}, ''))), 5) ELSE LOWER(TRIM(COALESCE({$column}, ''))) END)";
}

$pdo = db();
$limit = min(max((int) ($_GET['limit'] ?? 200), 20), 500);

$params = [];
$whereSql = competitor_email_queue_scope($params);
$domainExpr = competitor_email_queue_normalized_domain_sql('referring_domain');
$groupSql = '
    SELECT
        ' . $domainExpr . ' AS normalized_domain,
        MIN(COALESCE(referring_page_url, "")) AS sample_referring_page_url,
        MAX(keyword) AS keyword,
        MAX(competitor_domain) AS competitor_domain,
        MAX(competitor_landing_url) AS competitor_landing_url,
        MAX(CASE WHEN email IS NOT NULL AND email <> "" THEN email END) AS email,
        MAX(CASE WHEN imprint_url IS NOT NULL AND imprint_url <> "" THEN imprint_url END) AS imprint_url,
        MAX(updated_at) AS updated_at,
        COUNT(*) AS backlink_count,
        COUNT(DISTINCT competitor_domain) AS competitor_count,
        CASE
            WHEN SUM(CASE WHEN LOWER(COALESCE(email_status, "")) = "processing" THEN 1 ELSE 0 END) > 0 THEN "processing"
            WHEN SUM(CASE WHEN LOWER(COALESCE(email_status, "")) = "dispatching" THEN 1 ELSE 0 END) > 0 THEN "dispatching"
            WHEN SUM(CASE WHEN LOWER(COALESCE(email_status, "")) = "found" THEN 1 ELSE 0 END) > 0 THEN "found"
            WHEN SUM(CASE WHEN LOWER(COALESCE(email_status, "")) = "no_email" THEN 1 ELSE 0 END) > 0 THEN "no_email"
            WHEN SUM(CASE WHEN LOWER(COALESCE(email_status, "")) = "no_imprint" THEN 1 ELSE 0 END) > 0 THEN "no_imprint"
            WHEN SUM(CASE WHEN LOWER(COALESCE(email_status, "")) = "failed" THEN 1 ELSE 0 END) > 0 THEN "failed"
            ELSE ""
        END AS email_status
    FROM competitor_backlink_inventory' . $whereSql . ($whereSql ? ' AND ' : ' WHERE ') . $domainExpr . ' <> ""
    GROUP BY ' . $domainExpr;
$rowStmt = $pdo->prepare($groupSql);
$rowStmt->execute($params);
$rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['referring_domain'] = (string) ($row['normalized_domain'] ?? '');
    $row['domain_home_url'] = $row['referring_domain'] !== '' ? ('https://' . $row['referring_domain'] . '/') : '';
}
unset($row);

usort($rows, static function (array $a, array $b): int {
    $priority = static function (string $status): int {
        return match ($status) {
            'processing' => 0,
            'dispatching' => 1,
            '' => 2,
            'found' => 3,
            'no_email' => 4,
            'no_imprint' => 5,
            default => 6,
        };
    };

    $statusA = strtolower(trim((string) ($a['email_status'] ?? '')));
    $statusB = strtolower(trim((string) ($b['email_status'] ?? '')));
    $priorityCompare = $priority($statusA) <=> $priority($statusB);
    if ($priorityCompare !== 0) {
        return $priorityCompare;
    }

    $timeA = strtotime((string) ($a['updated_at'] ?? '')) ?: 0;
    $timeB = strtotime((string) ($b['updated_at'] ?? '')) ?: 0;
    if ($timeA !== $timeB) {
        return $timeB <=> $timeA;
    }

    return strcmp((string) ($a['referring_domain'] ?? ''), (string) ($b['referring_domain'] ?? ''));
});

$rows = array_slice($rows, 0, $limit);

$currentItem = null;
$nextItem = null;
$latestFinished = null;
foreach ($rows as $row) {
    $status = strtolower(trim((string) ($row['email_status'] ?? '')));
    if ($currentItem === null && ($status === 'processing' || $status === 'dispatching')) {
        $currentItem = $row;
    }
    if ($nextItem === null && $status === '') {
        $nextItem = $row;
    }
    if ($latestFinished === null && $status !== '' && $status !== 'processing' && $status !== 'dispatching') {
        $latestFinished = $row;
    }
}

echo json_encode([
    'success' => true,
    'current_item' => $currentItem,
    'next_item' => $nextItem,
    'latest_finished' => $latestFinished,
    'rows' => $rows,
    'limit' => $limit,
    'generated_at' => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
