<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo    = db();
$domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
$dmcDomains = array_keys(DMC_DOMAINS);

if ($domain !== '' && in_array($domain, $dmcDomains, true)) {
    $stmt = $pdo->prepare(
        "SELECT * FROM backlinks
          WHERE brand = 'DMC'
            AND (target_url LIKE ? OR source_url LIKE ?)
          ORDER BY last_checked DESC, created_at DESC"
    );
    $like = '%' . $domain . '%';
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM backlinks WHERE brand = 'DMC' ORDER BY last_checked DESC, created_at DESC"
    );
    $stmt->execute();
}

echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
