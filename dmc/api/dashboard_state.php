<?php
$configCandidates = [
    __DIR__ . '/../../zap/config.php',
    __DIR__ . '/../../dashboards/zap/config.php',
    __DIR__ . '/../../config.php',
];

$loadedConfig = false;
foreach ($configCandidates as $configPath) {
    if (is_file($configPath)) {
        require_once $configPath;
        $loadedConfig = true;
        break;
    }
}

if (!$loadedConfig) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'ZAP-Konfiguration konnte nicht geladen werden',
    ]);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DMC_DASHBOARD_STATE_COOKIE = 'dmc_dashboard_state_id';
const DMC_DASHBOARD_STATE_KEY = 'dmc_content_dashboard_v6';

function ensure_dashboard_state_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dmc_dashboard_states (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            state_key VARCHAR(128) NOT NULL,
            client_token CHAR(64) NOT NULL,
            state_json LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_state_client (state_key, client_token),
            KEY idx_updated_at (updated_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function current_client_token(): string
{
    $cookie = trim((string) ($_COOKIE[DMC_DASHBOARD_STATE_COOKIE] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $cookie) === 1) {
        return $cookie;
    }

    $token = bin2hex(random_bytes(32));
    setcookie(DMC_DASHBOARD_STATE_COOKIE, $token, [
        'expires' => time() + (86400 * 365),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $token;
}

function fetch_dashboard_state(PDO $pdo, string $stateKey, string $clientToken): ?array
{
    $stmt = $pdo->prepare(
        "SELECT state_json, updated_at
         FROM dmc_dashboard_states
         WHERE state_key = :state_key AND client_token = :client_token
         LIMIT 1"
    );
    $stmt->execute([
        ':state_key' => $stateKey,
        ':client_token' => $clientToken,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $decoded = json_decode((string) $row['state_json'], true);
    return [
        'state' => is_array($decoded) ? $decoded : [],
        'updatedAt' => $row['updated_at'] ?? null,
    ];
}

function save_dashboard_state(PDO $pdo, string $stateKey, string $clientToken, array $state): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO dmc_dashboard_states (state_key, client_token, state_json)
         VALUES (:state_key, :client_token, :state_json)
         ON DUPLICATE KEY UPDATE
           state_json = VALUES(state_json),
           updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':state_key' => $stateKey,
        ':client_token' => $clientToken,
        ':state_json' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

$pdo = db();
ensure_dashboard_state_table($pdo);
$clientToken = current_client_token();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $payload = fetch_dashboard_state($pdo, DMC_DASHBOARD_STATE_KEY, $clientToken);
    jsonResponse([
        'success' => true,
        'state' => $payload['state'] ?? null,
        'updatedAt' => $payload['updatedAt'] ?? null,
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    if (!is_array($body['state'] ?? null)) {
        jsonResponse([
            'success' => false,
            'error' => 'Ungueltiger State-Body',
        ], 400);
    }

    save_dashboard_state($pdo, DMC_DASHBOARD_STATE_KEY, $clientToken, $body['state']);
    $payload = fetch_dashboard_state($pdo, DMC_DASHBOARD_STATE_KEY, $clientToken);
    jsonResponse([
        'success' => true,
        'state' => $payload['state'] ?? [],
        'updatedAt' => $payload['updatedAt'] ?? null,
    ]);
}

jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
