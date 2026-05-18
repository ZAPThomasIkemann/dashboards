<?php
$configCandidates = [
    __DIR__ . '/../../zap/config.php',
    __DIR__ . '/../../dashboards/zap/config.php',
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
        'error' => 'Konfiguration konnte nicht geladen werden',
    ]);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DMC_PROMPT_COUNTRIES = [
    'at' => 'Oesterreich',
    'cz' => 'Tschechien',
    'ch' => 'Schweiz',
    'si' => 'Slowenien',
    'ro' => 'Rumaenien',
    'hu' => 'Ungarn',
    'hr' => 'Kroatien',
    'bg' => 'Bulgarien',
    'sk' => 'Slowakei',
];

function ensure_prompt_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dmc_system_prompt_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            country_key VARCHAR(16) NOT NULL,
            country_label VARCHAR(64) NOT NULL,
            prompt_text MEDIUMTEXT NOT NULL,
            save_mode VARCHAR(32) NOT NULL DEFAULT 'manual',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_country_created (country_key, created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function normalize_country_key(?string $value): ?string
{
    $key = strtolower(trim((string) $value));
    return array_key_exists($key, DMC_PROMPT_COUNTRIES) ? $key : null;
}

function fetch_latest_prompt(PDO $pdo, string $countryKey): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, country_key, country_label, prompt_text, save_mode, created_at
         FROM dmc_system_prompt_versions
         WHERE country_key = :country_key
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':country_key' => $countryKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_versions(PDO $pdo, string $countryKey): array
{
    $stmt = $pdo->prepare(
        "SELECT id, country_key, country_label, prompt_text, save_mode, created_at
         FROM dmc_system_prompt_versions
         WHERE country_key = :country_key
         ORDER BY id DESC"
    );
    $stmt->execute([':country_key' => $countryKey]);
    return $stmt->fetchAll();
}

function latest_map(PDO $pdo): array
{
    $map = [];
    foreach (DMC_PROMPT_COUNTRIES as $countryKey => $countryLabel) {
        $map[$countryKey] = fetch_latest_prompt($pdo, $countryKey);
    }
    return $map;
}

function delete_version(PDO $pdo, string $countryKey, int $id): bool
{
    $stmt = $pdo->prepare(
        "DELETE FROM dmc_system_prompt_versions
         WHERE id = :id AND country_key = :country_key
         LIMIT 1"
    );
    $stmt->execute([
        ':id' => $id,
        ':country_key' => $countryKey,
    ]);
    return $stmt->rowCount() > 0;
}

$pdo = db();
ensure_prompt_table($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $countryKey = normalize_country_key($_GET['country'] ?? null);
    if ($countryKey) {
        jsonResponse([
            'success' => true,
            'country' => [
                'key' => $countryKey,
                'label' => DMC_PROMPT_COUNTRIES[$countryKey],
            ],
            'latest' => fetch_latest_prompt($pdo, $countryKey),
            'versions' => fetch_versions($pdo, $countryKey),
        ]);
    }

    $countries = [];
    foreach (DMC_PROMPT_COUNTRIES as $countryKey => $countryLabel) {
        $countries[] = [
            'key' => $countryKey,
            'label' => $countryLabel,
        ];
    }

    jsonResponse([
        'success' => true,
        'countries' => $countries,
        'latestByCountry' => latest_map($pdo),
    ]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        jsonResponse(['success' => false, 'error' => 'Ungueltiger JSON-Body'], 400);
    }

    $countryKey = normalize_country_key($body['country'] ?? null);
    if (!$countryKey) {
        jsonResponse(['success' => false, 'error' => 'Ungueltiges Land'], 400);
    }

    $promptText = trim((string) ($body['prompt'] ?? ''));
    if ($promptText === '') {
        jsonResponse(['success' => false, 'error' => 'Prompt darf nicht leer sein'], 400);
    }

    $saveMode = strtolower(trim((string) ($body['saveMode'] ?? 'manual')));
    if (!in_array($saveMode, ['manual', 'autosave', 'restore'], true)) {
        $saveMode = 'manual';
    }

    $latest = fetch_latest_prompt($pdo, $countryKey);
    if ($latest && trim((string) $latest['prompt_text']) === $promptText) {
        jsonResponse([
            'success' => true,
            'skipped' => true,
            'latest' => $latest,
            'versions' => fetch_versions($pdo, $countryKey),
        ]);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO dmc_system_prompt_versions (country_key, country_label, prompt_text, save_mode)
         VALUES (:country_key, :country_label, :prompt_text, :save_mode)"
    );
    $stmt->execute([
        ':country_key' => $countryKey,
        ':country_label' => DMC_PROMPT_COUNTRIES[$countryKey],
        ':prompt_text' => $promptText,
        ':save_mode' => $saveMode,
    ]);

    jsonResponse([
        'success' => true,
        'latest' => fetch_latest_prompt($pdo, $countryKey),
        'versions' => fetch_versions($pdo, $countryKey),
    ]);
}

if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        jsonResponse(['success' => false, 'error' => 'Ungueltiger JSON-Body'], 400);
    }

    $countryKey = normalize_country_key($body['country'] ?? null);
    if (!$countryKey) {
        jsonResponse(['success' => false, 'error' => 'Ungueltiges Land'], 400);
    }

    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Ungueltige Versions-ID'], 400);
    }

    $deleted = delete_version($pdo, $countryKey, $id);
    if (!$deleted) {
        jsonResponse(['success' => false, 'error' => 'Version wurde nicht gefunden'], 404);
    }

    jsonResponse([
        'success' => true,
        'latest' => fetch_latest_prompt($pdo, $countryKey),
        'versions' => fetch_versions($pdo, $countryKey),
    ]);
}

jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
