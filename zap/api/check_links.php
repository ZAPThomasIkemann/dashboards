<?php
require_once '../config.php';
header('Content-Type: application/json');

$pdo = db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

function checkUrl(string $url): array {
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 10,
            'follow_location' => true,
            'max_redirects'   => 5,
            'user_agent'      => 'Mozilla/5.0 ZAP-Dashboard-Checker/1.0',
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    try {
        $headers = @get_headers($url, false, $ctx);
        if ($headers === false) {
            return ['online' => false, 'status' => null];
        }
        $statusLine = $headers[0] ?? '';
        preg_match('/HTTP\/\S+ (\d{3})/', $statusLine, $m);
        $code = isset($m[1]) ? (int)$m[1] : null;
        return ['online' => $code && $code < 400, 'status' => $code];
    } catch (Throwable $e) {
        return ['online' => false, 'status' => null];
    }
}

if ($id) {
    $row = $pdo->prepare("SELECT id, source_url FROM backlinks WHERE id = ?");
    $row->execute([$id]);
    $link = $row->fetch();
    if (!$link) {
        echo json_encode(['success' => false, 'error' => 'Not found']);
        exit;
    }
    $result = checkUrl($link['source_url']);
    $pdo->prepare("UPDATE backlinks SET is_online = :o, http_status = :s, last_checked = NOW() WHERE id = :id")
        ->execute([':o' => $result['online'] ? 1 : 0, ':s' => $result['status'], ':id' => $id]);
    echo json_encode(['success' => true, 'result' => $result]);
    exit;
}

// Check all
$links = $pdo->query("SELECT id, source_url FROM backlinks")->fetchAll();
$results = [];
foreach ($links as $link) {
    $result = checkUrl($link['source_url']);
    $pdo->prepare("UPDATE backlinks SET is_online = :o, http_status = :s, last_checked = NOW() WHERE id = :id")
        ->execute([':o' => $result['online'] ? 1 : 0, ':s' => $result['status'], ':id' => $link['id']]);
    $results[$link['id']] = $result;
}
echo json_encode(['success' => true, 'checked' => count($results), 'results' => $results]);
