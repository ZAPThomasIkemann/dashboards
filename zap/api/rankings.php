<?php
require_once '../config.php';
require_once '../includes/dashboard_cache.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function rankings_apply_filters(array $payload, array $query): array {
    $rows = $payload['data'] ?? [];
    $history = $payload['history'] ?? [];
    $summary = $payload['summary'] ?? [];

    if (!empty($query['domain'])) {
        $domain = (string) $query['domain'];
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) ($row['domain'] ?? '') === $domain));
        $summary = array_values(array_filter($summary, static fn(array $row): bool => (string) ($row['domain'] ?? '') === $domain));
        $history = array_values(array_filter($history, static fn(array $row): bool => (string) ($row['domain'] ?? '') === $domain));
    }

    if (!empty($query['brand'])) {
        $brand = (string) $query['brand'];
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) ($row['brand'] ?? '') === $brand));
        $summary = array_values(array_filter($summary, static fn(array $row): bool => (string) ($row['brand'] ?? '') === $brand));
        $history = array_values(array_filter($history, static fn(array $row): bool => (string) ($row['brand'] ?? '') === $brand));
    }

    if (!empty($query['keyword'])) {
        $keyword = mb_strtolower((string) $query['keyword']);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => mb_stripos((string) ($row['keyword'] ?? ''), $keyword) !== false));
        $historyKeys = [];
        foreach ($rows as $row) {
            $historyKeys[(string) ($row['domain'] ?? '') . '||' . (string) ($row['keyword'] ?? '') . '||' . (string) ($row['location_code'] ?? '')] = true;
        }
        $history = array_values(array_filter($history, static function (array $row) use ($historyKeys): bool {
            $key = (string) ($row['domain'] ?? '') . '||' . (string) ($row['keyword'] ?? '') . '||' . (string) ($row['location_code'] ?? '');
            return isset($historyKeys[$key]);
        }));
    }

    if (isset($query['min_pos']) && $query['min_pos'] !== '') {
        $minPos = (int) $query['min_pos'];
        $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($row['position']) && (int) $row['position'] >= $minPos));
    }
    if (isset($query['max_pos']) && $query['max_pos'] !== '') {
        $maxPos = (int) $query['max_pos'];
        $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($row['position']) && (int) $row['position'] <= $maxPos));
    }

    usort($rows, static function (array $a, array $b): int {
        $positionCmp = ((int) ($a['position'] ?? 999999)) <=> ((int) ($b['position'] ?? 999999));
        if ($positionCmp !== 0) {
            return $positionCmp;
        }
        return ((int) ($b['search_volume'] ?? -1)) <=> ((int) ($a['search_volume'] ?? -1));
    });

    $total = count($rows);
    $limit = min((int) ($query['limit'] ?? 5000), 10000);
    $offset = max(0, (int) ($query['offset'] ?? 0));
    $rows = array_slice($rows, $offset, $limit);

    return [
        'success' => true,
        'total' => $total,
        'data' => $rows,
        'summary' => array_values($summary),
        'history' => array_values($history),
        'cache_meta' => $payload['cache_meta'] ?? null,
    ];
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(rankings_apply_filters(dashboard_get_rankings_cache($pdo), $_GET));
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare("INSERT INTO rankings (domain, brand, keyword, url, position, search_volume, cpc, competition, location_code, language_code, last_checked)
        VALUES (:domain, :brand, :keyword, :url, :position, :search_volume, :cpc, :competition, :location_code, :language_code, NOW())");
    $stmt->execute([
        ':domain' => $body['domain'],
        ':brand' => $body['brand'],
        ':keyword' => $body['keyword'],
        ':url' => $body['url'] ?? null,
        ':position' => $body['position'] ?? null,
        ':search_volume' => $body['search_volume'] ?? null,
        ':cpc' => $body['cpc'] ?? null,
        ':competition' => $body['competition'] ?? null,
        ':location_code' => $body['location_code'] ?? 2276,
        ':language_code' => $body['language_code'] ?? 'de',
    ]);
    dashboard_refresh_rankings_cache($pdo);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID required']);
        exit;
    }
    $pdo->prepare('DELETE FROM rankings WHERE id = ?')->execute([$id]);
    dashboard_refresh_rankings_cache($pdo);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
