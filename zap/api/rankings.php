<?php
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

function ensure_rankings_history_table(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
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
            KEY idx_rankings_history_lookup (domain, keyword(191), location_code, checked_at),
            KEY idx_rankings_history_checked (checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

if ($method === 'GET') {
    ensure_rankings_history_table($pdo);
    $domain   = $_GET['domain']   ?? null;
    $brand    = $_GET['brand']    ?? null;
    $keyword  = $_GET['keyword']  ?? null;
    $minPos   = isset($_GET['min_pos']) ? (int)$_GET['min_pos'] : null;
    $maxPos   = isset($_GET['max_pos']) ? (int)$_GET['max_pos'] : null;
    $limit    = min((int)($_GET['limit'] ?? 5000), 10000);
    $offset   = (int)($_GET['offset'] ?? 0);

    $where = ['1=1'];
    $params = [];

    if ($domain)  { $where[] = 'domain = ?'; $params[] = $domain; }
    if ($brand)   { $where[] = 'brand = ?'; $params[] = $brand; }
    if ($keyword) { $where[] = 'keyword LIKE ?'; $params[] = "%$keyword%"; }
    if ($minPos !== null) { $where[] = 'position >= ?'; $params[] = $minPos; }
    if ($maxPos !== null) { $where[] = 'position <= ?'; $params[] = $maxPos; }

    $whereStr = implode(' AND ', $where);
    $total = $pdo->prepare("SELECT COUNT(*) FROM rankings WHERE $whereStr");
    $total->execute($params);
    $totalCount = (int)$total->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM rankings WHERE $whereStr ORDER BY position ASC, search_volume DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $historyStmt = $pdo->query("
        SELECT domain, brand, keyword, position, search_volume, location_code, language_code, checked_at
        FROM rankings_history
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 730 DAY)
        ORDER BY checked_at ASC, domain ASC, keyword ASC
    ");
    $history = $historyStmt->fetchAll();

    // Domain summary stats
    $summaryStmt = $pdo->query("
        SELECT domain, brand,
               COUNT(*) as total_keywords,
               MIN(position) as best_position,
               SUM(CASE WHEN position <= 3 THEN 1 ELSE 0 END) as top3,
               SUM(CASE WHEN position <= 10 THEN 1 ELSE 0 END) as top10,
               SUM(CASE WHEN position <= 100 THEN 1 ELSE 0 END) as top100,
               SUM(COALESCE(search_volume, 0)) as total_volume,
               MAX(last_checked) as last_checked
        FROM rankings
        GROUP BY domain, brand
        ORDER BY brand, domain
    ");
    $summary = $summaryStmt->fetchAll();

    echo json_encode([
        'success'  => true,
        'total'    => $totalCount,
        'data'     => $rows,
        'summary'  => $summary,
        'history'  => $history,
    ]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare("INSERT INTO rankings (domain, brand, keyword, url, position, search_volume, cpc, competition, location_code, language_code, last_checked)
        VALUES (:domain, :brand, :keyword, :url, :position, :search_volume, :cpc, :competition, :location_code, :language_code, NOW())");
    $stmt->execute([
        ':domain'        => $body['domain'],
        ':brand'         => $body['brand'],
        ':keyword'       => $body['keyword'],
        ':url'           => $body['url'] ?? null,
        ':position'      => $body['position'] ?? null,
        ':search_volume' => $body['search_volume'] ?? null,
        ':cpc'           => $body['cpc'] ?? null,
        ':competition'   => $body['competition'] ?? null,
        ':location_code' => $body['location_code'] ?? 2276,
        ':language_code' => $body['language_code'] ?? 'de',
    ]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'ID required']); exit; }
    $pdo->prepare("DELETE FROM rankings WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
