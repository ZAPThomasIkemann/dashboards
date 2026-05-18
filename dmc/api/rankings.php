<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo        = db();
$dmcDomains = array_keys(DMC_DOMAINS);
$domain     = isset($_GET['domain']) && in_array($_GET['domain'], $dmcDomains, true)
    ? $_GET['domain'] : '';
$keyword    = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$limit      = min((int) ($_GET['limit'] ?? 5000), 10000);
$offset     = (int) ($_GET['offset'] ?? 0);

// Ensure rankings_history table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS rankings_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ranking_id BIGINT UNSIGNED NULL,
        domain VARCHAR(255) NOT NULL,
        brand VARCHAR(50) NULL,
        keyword VARCHAR(255) NOT NULL,
        url TEXT NULL,
        position INT NULL,
        previous_position INT NULL,
        search_volume INT NULL,
        cpc DECIMAL(10,2) NULL,
        location_code VARCHAR(20) NULL,
        language_code VARCHAR(20) NULL,
        checked_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rh_lookup (domain, keyword(191), location_code, checked_at),
        KEY idx_rh_checked (checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$where  = ["brand = 'DMC'"];
$params = [];

if ($domain !== '') {
    $where[]  = 'domain = ?';
    $params[] = $domain;
} else {
    $in = implode(',', array_fill(0, count($dmcDomains), '?'));
    $where[]  = "domain IN ($in)";
    $params   = array_merge($params, $dmcDomains);
}

if ($keyword !== '') {
    $where[]  = 'keyword LIKE ?';
    $params[] = '%' . $keyword . '%';
}

$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare(
    "SELECT * FROM rankings_history WHERE $whereStr ORDER BY checked_at DESC LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM rankings_history WHERE $whereStr");
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

echo json_encode(['success' => true, 'data' => $rows, 'total' => $total]);
