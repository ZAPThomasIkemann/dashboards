<?php
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$pdo = db();

// Scope params
$brand   = dashboard_scope_brand();
$domains = array_keys(dashboard_scope_domains());
$where   = [];
$params  = [];
if ($brand)   { $where[] = 'brand = ?';   $params[] = (string) $brand; }
if ($domains) {
    $where[] = 'domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
    foreach ($domains as $d) $params[] = (string) $d;
}
$wSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Daily aggregated trend (last 60 days)
$trendSql = "
    SELECT
        DATE(checked_at)                                          AS day,
        COUNT(*)                                                   AS total_checks,
        COUNT(DISTINCT keyword)                                    AS unique_keywords,
        SUM(CASE WHEN position BETWEEN 1 AND 3  THEN 1 ELSE 0 END) AS top3,
        SUM(CASE WHEN position BETWEEN 1 AND 10 THEN 1 ELSE 0 END) AS top10,
        SUM(CASE WHEN position BETWEEN 11 AND 20 THEN 1 ELSE 0 END) AS pos11_20,
        SUM(CASE WHEN position > 20 THEN 1 ELSE 0 END)             AS beyond20,
        ROUND(AVG(position), 1)                                    AS avg_pos,
        ROUND(AVG(CASE WHEN position BETWEEN 1 AND 100 THEN position ELSE NULL END), 1) AS avg_pos_top100
    FROM rankings_history
    $wSql
    GROUP BY DATE(checked_at)
    ORDER BY day ASC
";

$stmt = $pdo->prepare($trendSql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cast numeric strings to numbers
$rows = array_map(function ($r) {
    return [
        'day'              => $r['day'],
        'total_checks'     => (int)   $r['total_checks'],
        'unique_keywords'  => (int)   $r['unique_keywords'],
        'top3'             => (int)   $r['top3'],
        'top10'            => (int)   $r['top10'],
        'pos11_20'         => (int)   $r['pos11_20'],
        'beyond20'         => (int)   $r['beyond20'],
        'avg_pos'          => (float) $r['avg_pos'],
        'avg_pos_top100'   => (float) $r['avg_pos_top100'],
    ];
}, $rows);

echo json_encode([
    'success' => true,
    'rows'    => $rows,
    'total'   => count($rows),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
