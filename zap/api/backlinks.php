<?php
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM backlinks ORDER BY last_checked DESC, created_at DESC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || !isset($body['source_url'], $body['target_url'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'source_url and target_url are required']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO backlinks (brand, source_url, target_url, anchor_text, domain_rating, domain_authority, link_type, first_seen, notes)
        VALUES (:brand, :source_url, :target_url, :anchor_text, :domain_rating, :domain_authority, :link_type, :first_seen, :notes)");
    $stmt->execute([
        ':brand'            => $body['brand'] ?? 'ZAP',
        ':source_url'       => $body['source_url'],
        ':target_url'       => $body['target_url'],
        ':anchor_text'      => $body['anchor_text'] ?? null,
        ':domain_rating'    => isset($body['domain_rating'])    && $body['domain_rating']    !== '' ? $body['domain_rating']    : null,
        ':domain_authority' => isset($body['domain_authority']) && $body['domain_authority'] !== '' ? $body['domain_authority'] : null,
        ':link_type'        => $body['link_type'] ?? 'dofollow',
        ':first_seen'       => $body['first_seen'] ?? null,
        ':notes'            => $body['notes'] ?? null,
    ]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

if ($method === 'PUT') {
    if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'ID required']); exit; }
    $body = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare("UPDATE backlinks SET
        brand = :brand, source_url = :source_url, target_url = :target_url,
        anchor_text = :anchor_text, domain_rating = :domain_rating, domain_authority = :domain_authority,
        link_type = :link_type, first_seen = :first_seen, notes = :notes
        WHERE id = :id");
    $stmt->execute([
        ':brand'            => $body['brand'] ?? 'ZAP',
        ':source_url'       => $body['source_url'],
        ':target_url'       => $body['target_url'],
        ':anchor_text'      => $body['anchor_text'] ?? null,
        ':domain_rating'    => isset($body['domain_rating'])    && $body['domain_rating']    !== '' ? $body['domain_rating']    : null,
        ':domain_authority' => isset($body['domain_authority']) && $body['domain_authority'] !== '' ? $body['domain_authority'] : null,
        ':link_type'        => $body['link_type'] ?? 'dofollow',
        ':first_seen'       => $body['first_seen'] ?? null,
        ':notes'            => $body['notes'] ?? null,
        ':id'               => $id,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'ID required']); exit; }
    $pdo->prepare("DELETE FROM backlinks WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
