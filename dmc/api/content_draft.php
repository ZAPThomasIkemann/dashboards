<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DMC_SHARED_DRAFT_KEY = 'content_form';

function ensure_draft_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dmc_content_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            draft_key VARCHAR(64) NOT NULL,
            assignee VARCHAR(120) NOT NULL DEFAULT '',
            country_label VARCHAR(64) NOT NULL DEFAULT '',
            country_key VARCHAR(16) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            prompt_text MEDIUMTEXT NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'empty',
            updated_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_draft_key (draft_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function read_request_data(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function fetch_draft(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT draft_key, assignee, country_label, country_key, title, prompt_text, source, updated_by, created_at, updated_at
         FROM dmc_content_drafts
         WHERE draft_key = :draft_key
         LIMIT 1"
    );
    $stmt->execute([':draft_key' => DMC_SHARED_DRAFT_KEY]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'key' => (string) $row['draft_key'],
        'assignee' => (string) ($row['assignee'] ?? ''),
        'country' => (string) ($row['country_label'] ?? ''),
        'countryKey' => (string) ($row['country_key'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'prompt' => (string) ($row['prompt_text'] ?? ''),
        'source' => (string) ($row['source'] ?? 'empty'),
        'updatedBy' => (string) ($row['updated_by'] ?? ''),
        'createdAt' => isset($row['created_at']) ? gmdate('c', strtotime((string) $row['created_at'] . ' UTC')) : null,
        'updatedAt' => isset($row['updated_at']) ? gmdate('c', strtotime((string) $row['updated_at'] . ' UTC')) : null,
    ];
}

function persist_draft(PDO $pdo, array $body): ?array
{
    $assignee = trim((string) ($body['assignee'] ?? ''));
    $countryLabel = trim((string) ($body['country'] ?? ''));
    $countryKey = strtolower(trim((string) ($body['countryKey'] ?? '')));
    $title = trim((string) ($body['title'] ?? ''));
    $prompt = trim((string) ($body['prompt'] ?? ''));
    $source = trim((string) ($body['source'] ?? 'draft'));
    $updatedBy = trim((string) ($body['updatedBy'] ?? $assignee));

    $isEmpty = $assignee === '' && $countryLabel === '' && $title === '' && $prompt === '';
    if ($isEmpty) {
        $stmt = $pdo->prepare("DELETE FROM dmc_content_drafts WHERE draft_key = :draft_key");
        $stmt->execute([':draft_key' => DMC_SHARED_DRAFT_KEY]);
        return null;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO dmc_content_drafts
            (draft_key, assignee, country_label, country_key, title, prompt_text, source, updated_by)
         VALUES
            (:draft_key, :assignee, :country_label, :country_key, :title, :prompt_text, :source, :updated_by)
         ON DUPLICATE KEY UPDATE
            assignee = VALUES(assignee),
            country_label = VALUES(country_label),
            country_key = VALUES(country_key),
            title = VALUES(title),
            prompt_text = VALUES(prompt_text),
            source = VALUES(source),
            updated_by = VALUES(updated_by)"
    );
    $stmt->execute([
        ':draft_key' => DMC_SHARED_DRAFT_KEY,
        ':assignee' => $assignee,
        ':country_label' => $countryLabel,
        ':country_key' => $countryKey,
        ':title' => $title,
        ':prompt_text' => $prompt,
        ':source' => $source !== '' ? $source : 'draft',
        ':updated_by' => $updatedBy,
    ]);

    return fetch_draft($pdo);
}

$pdo = db();
ensure_draft_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse([
        'success' => true,
        'draft' => fetch_draft($pdo),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = read_request_data();
if (!is_array($body)) {
    jsonResponse(['success' => false, 'error' => 'Ungueltiger JSON-Body'], 400);
}

$draft = persist_draft($pdo, $body);
jsonResponse([
    'success' => true,
    'draft' => $draft,
]);
