<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Ingest-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DMC_THREAD_LIMIT = 50;

function ensure_thread_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dmc_content_threads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workflow_kind VARCHAR(32) NOT NULL DEFAULT 'content',
            assignee VARCHAR(120) NOT NULL DEFAULT '',
            country_label VARCHAR(64) NOT NULL DEFAULT '',
            country_key VARCHAR(16) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            prompt_text MEDIUMTEXT NOT NULL,
            system_prompt MEDIUMTEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            status_label VARCHAR(120) NOT NULL DEFAULT 'Wartet auf Workflow',
            progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 8,
            workflow_id VARCHAR(64) NOT NULL DEFAULT '',
            execution_id VARCHAR(64) NOT NULL DEFAULT '',
            document_url VARCHAR(2048) NOT NULL DEFAULT '',
            document_name VARCHAR(255) NOT NULL DEFAULT '',
            generated_content LONGTEXT NULL,
            error_message MEDIUMTEXT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            duration_seconds INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_created_at (created_at DESC),
            KEY idx_status (status),
            KEY idx_workflow_kind (workflow_kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dmc_content_thread_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            parent_message_id BIGINT UNSIGNED NULL,
            message_type VARCHAR(32) NOT NULL,
            author_name VARCHAR(120) NOT NULL DEFAULT '',
            body MEDIUMTEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'completed',
            workflow_id VARCHAR(64) NOT NULL DEFAULT '',
            execution_id VARCHAR(64) NOT NULL DEFAULT '',
            error_message MEDIUMTEXT NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            duration_seconds INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_thread_created (thread_id, created_at),
            KEY idx_parent_message (parent_message_id),
            CONSTRAINT fk_dmc_thread_messages_thread
              FOREIGN KEY (thread_id) REFERENCES dmc_content_threads(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function read_request_data(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    parse_str($raw, $parsed);
    return is_array($parsed) ? $parsed : [];
}

function find_header_value(string $name): string
{
    $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$normalized]) ? trim((string) $_SERVER[$normalized]) : '';
}

function require_ingest_secret(): void
{
    if (!defined('DASHBOARD_INGEST_SECRET') || DASHBOARD_INGEST_SECRET === '') {
        return;
    }

    $provided = find_header_value('X-Ingest-Secret');
    if ($provided === '') {
        $body = read_request_data();
        $provided = trim((string) ($body['ingestSecret'] ?? ''));
    }

    if (!hash_equals((string) DASHBOARD_INGEST_SECRET, $provided)) {
        jsonResponse(['success' => false, 'error' => 'Unauthorised ingest request'], 403);
    }
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function iso_or_now(?string $value): string
{
    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return utc_now();
    }
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function clamp_progress($value): int
{
    $numeric = is_numeric($value) ? (int) $value : 0;
    if ($numeric < 0) {
        return 0;
    }
    if ($numeric > 100) {
        return 100;
    }
    return $numeric;
}

function compute_duration_seconds(?string $startedAt, ?string $completedAt): ?int
{
    if (!$startedAt || !$completedAt) {
        return null;
    }

    $started = strtotime($startedAt . ' UTC');
    $completed = strtotime($completedAt . ' UTC');
    if ($started === false || $completed === false || $completed < $started) {
        return null;
    }

    return (int) ($completed - $started);
}

function insert_thread_message(
    PDO $pdo,
    int $threadId,
    ?int $parentMessageId,
    string $messageType,
    string $authorName,
    string $body,
    string $status = 'completed',
    string $workflowId = '',
    string $executionId = '',
    ?string $startedAt = null,
    ?string $completedAt = null,
    ?int $durationSeconds = null,
    ?string $errorMessage = null
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO dmc_content_thread_messages
            (thread_id, parent_message_id, message_type, author_name, body, status, workflow_id, execution_id, error_message, started_at, completed_at, duration_seconds)
         VALUES
            (:thread_id, :parent_message_id, :message_type, :author_name, :body, :status, :workflow_id, :execution_id, :error_message, :started_at, :completed_at, :duration_seconds)"
    );
    $stmt->execute([
        ':thread_id' => $threadId,
        ':parent_message_id' => $parentMessageId,
        ':message_type' => $messageType,
        ':author_name' => $authorName,
        ':body' => $body,
        ':status' => $status,
        ':workflow_id' => $workflowId,
        ':execution_id' => $executionId,
        ':error_message' => $errorMessage,
        ':started_at' => $startedAt,
        ':completed_at' => $completedAt,
        ':duration_seconds' => $durationSeconds,
    ]);
}

function normalize_thread_row(array $row): array
{
    return [
        'id' => isset($row['id']) ? (int) $row['id'] : null,
        'workflowKind' => (string) ($row['workflow_kind'] ?? 'content'),
        'assignee' => (string) ($row['assignee'] ?? ''),
        'country' => (string) ($row['country_label'] ?? ''),
        'countryKey' => (string) ($row['country_key'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'prompt' => (string) ($row['prompt_text'] ?? ''),
        'systemPrompt' => (string) ($row['system_prompt'] ?? ''),
        'status' => (string) ($row['status'] ?? 'queued'),
        'statusLabel' => (string) ($row['status_label'] ?? ''),
        'progressPercent' => isset($row['progress_percent']) ? (int) $row['progress_percent'] : 0,
        'workflowId' => (string) ($row['workflow_id'] ?? ''),
        'executionId' => (string) ($row['execution_id'] ?? ''),
        'contentType' => (string) ($row['content_type'] ?? ''),
        'documentUrl' => (string) ($row['document_url'] ?? ''),
        'documentName' => (string) ($row['document_name'] ?? ''),
        'generatedContent' => $row['generated_content'] !== null ? (string) $row['generated_content'] : null,
        'errorMessage' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
        'startedAt' => isset($row['started_at']) ? gmdate('c', strtotime((string) $row['started_at'] . ' UTC')) : null,
        'completedAt' => isset($row['completed_at']) && $row['completed_at'] !== null
            ? gmdate('c', strtotime((string) $row['completed_at'] . ' UTC'))
            : null,
        'durationSeconds' => isset($row['duration_seconds']) && $row['duration_seconds'] !== null
            ? (int) $row['duration_seconds']
            : compute_duration_seconds($row['started_at'] ?? null, $row['completed_at'] ?? null),
        'createdAt' => isset($row['created_at']) ? gmdate('c', strtotime((string) $row['created_at'] . ' UTC')) : null,
        'updatedAt' => isset($row['updated_at']) ? gmdate('c', strtotime((string) $row['updated_at'] . ' UTC')) : null,
    ];
}

function normalize_message_row(array $row): array
{
    return [
        'id' => isset($row['id']) ? (int) $row['id'] : null,
        'threadId' => isset($row['thread_id']) ? (int) $row['thread_id'] : null,
        'parentMessageId' => isset($row['parent_message_id']) && $row['parent_message_id'] !== null
            ? (int) $row['parent_message_id']
            : null,
        'messageType' => (string) ($row['message_type'] ?? ''),
        'authorName' => (string) ($row['author_name'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'status' => (string) ($row['status'] ?? 'completed'),
        'workflowId' => (string) ($row['workflow_id'] ?? ''),
        'executionId' => (string) ($row['execution_id'] ?? ''),
        'contentType' => (string) ($row['content_type'] ?? ''),
        'errorMessage' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
        'startedAt' => isset($row['started_at']) && $row['started_at'] !== null
            ? gmdate('c', strtotime((string) $row['started_at'] . ' UTC'))
            : null,
        'completedAt' => isset($row['completed_at']) && $row['completed_at'] !== null
            ? gmdate('c', strtotime((string) $row['completed_at'] . ' UTC'))
            : null,
        'durationSeconds' => isset($row['duration_seconds']) && $row['duration_seconds'] !== null
            ? (int) $row['duration_seconds']
            : compute_duration_seconds($row['started_at'] ?? null, $row['completed_at'] ?? null),
        'createdAt' => isset($row['created_at']) ? gmdate('c', strtotime((string) $row['created_at'] . ' UTC')) : null,
        'updatedAt' => isset($row['updated_at']) ? gmdate('c', strtotime((string) $row['updated_at'] . ' UTC')) : null,
    ];
}

function fetch_threads(PDO $pdo, int $limit = DMC_THREAD_LIMIT): array
{
    $stmt = $pdo->prepare(
        "SELECT *
         FROM dmc_content_threads
         ORDER BY created_at DESC, id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $threads = array_map('normalize_thread_row', $stmt->fetchAll());

    if ($threads === []) {
        return [];
    }

    $threadIds = array_map(
        static fn(array $thread): int => (int) $thread['id'],
        $threads
    );
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
    $messageStmt = $pdo->prepare(
        "SELECT *
         FROM dmc_content_thread_messages
         WHERE thread_id IN ($placeholders)
         ORDER BY created_at ASC, id ASC"
    );
    $messageStmt->execute($threadIds);

    $messagesByThread = [];
    foreach ($messageStmt->fetchAll() as $row) {
        $message = normalize_message_row($row);
        $messagesByThread[$message['threadId']][] = $message;
    }

    foreach ($threads as &$thread) {
        $thread['messages'] = $messagesByThread[$thread['id']] ?? [];
    }
    unset($thread);

    return $threads;
}

function fetch_thread(PDO $pdo, int $threadId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM dmc_content_threads WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $threadId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $thread = normalize_thread_row($row);
    $messageStmt = $pdo->prepare(
        "SELECT *
         FROM dmc_content_thread_messages
         WHERE thread_id = :thread_id
         ORDER BY created_at ASC, id ASC"
    );
    $messageStmt->execute([':thread_id' => $threadId]);
    $thread['messages'] = array_map('normalize_message_row', $messageStmt->fetchAll());
    return $thread;
}

function fetch_stats(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_threads,
            SUM(CASE WHEN status IN ('queued', 'running', 'research', 'writing', 'publishing') THEN 1 ELSE 0 END) AS active_threads,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_threads,
            AVG(CASE WHEN status = 'completed' AND duration_seconds IS NOT NULL THEN duration_seconds END) AS avg_duration_seconds
         FROM dmc_content_threads
         WHERE workflow_kind = 'content'"
    );
    $row = $stmt->fetch() ?: [];

    return [
        'totalThreads' => isset($row['total_threads']) ? (int) $row['total_threads'] : 0,
        'activeThreads' => isset($row['active_threads']) ? (int) $row['active_threads'] : 0,
        'completedThreads' => isset($row['completed_threads']) ? (int) $row['completed_threads'] : 0,
        'avgDurationSeconds' => isset($row['avg_duration_seconds']) && $row['avg_duration_seconds'] !== null
            ? (int) round((float) $row['avg_duration_seconds'])
            : null,
    ];
}

function mark_thread_revision_state(PDO $pdo, int $threadId, string $status, string $statusLabel, int $progressPercent, ?string $errorMessage, ?string $completedAt = null, ?string $systemPrompt = null, ?string $generatedContent = null, ?string $workflowId = null, ?string $executionId = null, ?int $durationSeconds = null): void
{
    $stmt = $pdo->prepare(
        "UPDATE dmc_content_threads
         SET status = :status,
             status_label = :status_label,
             progress_percent = :progress_percent,
             error_message = :error_message,
             completed_at = :completed_at,
             duration_seconds = COALESCE(:duration_seconds, duration_seconds),
             system_prompt = CASE WHEN :system_prompt IS NOT NULL AND :system_prompt <> '' THEN :system_prompt ELSE system_prompt END,
             generated_content = CASE WHEN :generated_content IS NOT NULL AND :generated_content <> '' THEN :generated_content ELSE generated_content END,
             workflow_id = CASE WHEN :workflow_id IS NOT NULL AND :workflow_id <> '' THEN :workflow_id ELSE workflow_id END,
             execution_id = CASE WHEN :execution_id IS NOT NULL AND :execution_id <> '' THEN :execution_id ELSE execution_id END
         WHERE id = :id"
    );
    $stmt->execute([
        ':status' => $status,
        ':status_label' => $statusLabel,
        ':progress_percent' => clamp_progress($progressPercent),
        ':error_message' => $errorMessage,
        ':completed_at' => $completedAt,
        ':duration_seconds' => $durationSeconds,
        ':system_prompt' => $systemPrompt,
        ':generated_content' => $generatedContent,
        ':workflow_id' => $workflowId,
        ':execution_id' => $executionId,
        ':id' => $threadId,
    ]);
}

function respond_with_threads(PDO $pdo, ?int $threadId = null): void
{
    jsonResponse([
        'success' => true,
        'thread' => $threadId ? fetch_thread($pdo, $threadId) : null,
        'threads' => fetch_threads($pdo),
        'stats' => fetch_stats($pdo),
    ]);
}

$pdo = db();
ensure_thread_tables($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$body = read_request_data();

if ($method === 'GET') {
    $threadId = isset($_GET['threadId']) ? (int) $_GET['threadId'] : 0;
    respond_with_threads($pdo, $threadId > 0 ? $threadId : null);
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$action = trim((string) ($body['action'] ?? ''));

if ($action === 'create_thread') {
    $title = trim((string) ($body['title'] ?? ''));
    $prompt = trim((string) ($body['prompt'] ?? ''));
    $systemPrompt = trim((string) ($body['systemPrompt'] ?? ''));
    $assignee = trim((string) ($body['assignee'] ?? ''));
    $countryLabel = trim((string) ($body['country'] ?? ''));
    $countryKey = strtolower(trim((string) ($body['countryKey'] ?? '')));
    $contentType = strtolower(trim((string) ($body['contentType'] ?? '')));

    if ($title === '' || $prompt === '') {
        jsonResponse(['success' => false, 'error' => 'Titel und Prompt sind erforderlich'], 400);
    }

    // Ensure content_type column exists
    try { $pdo->exec("ALTER TABLE dmc_content_threads ADD COLUMN content_type VARCHAR(32) NOT NULL DEFAULT ''"); } catch (PDOException $e) {}

    $startedAt = utc_now();
    $stmt = $pdo->prepare(
        "INSERT INTO dmc_content_threads
            (workflow_kind, assignee, country_label, country_key, title, prompt_text, system_prompt, content_type, status, status_label, progress_percent, started_at)
         VALUES
            ('content', :assignee, :country_label, :country_key, :title, :prompt_text, :system_prompt, :content_type, 'queued', 'Wartet auf Workflow', 8, :started_at)"
    );
    $stmt->execute([
        ':assignee' => $assignee,
        ':country_label' => $countryLabel,
        ':country_key' => $countryKey,
        ':title' => $title,
        ':prompt_text' => $prompt,
        ':system_prompt' => $systemPrompt,
        ':content_type' => $contentType,
        ':started_at' => $startedAt,
    ]);

    respond_with_threads($pdo, (int) $pdo->lastInsertId());
}

if ($action === 'create_comment') {
    $threadId = isset($body['threadId']) ? (int) $body['threadId'] : 0;
    $authorName = trim((string) ($body['authorName'] ?? ''));
    $commentBody = trim((string) ($body['comment'] ?? ''));
    $systemPrompt = trim((string) ($body['systemPrompt'] ?? ''));

    if ($threadId <= 0 || $commentBody === '') {
        jsonResponse(['success' => false, 'error' => 'Thread und Kommentar sind erforderlich'], 400);
    }

    $thread = fetch_thread($pdo, $threadId);
    if (!$thread) {
        jsonResponse(['success' => false, 'error' => 'Thread nicht gefunden'], 404);
    }

    $startedAt = utc_now();
    insert_thread_message(
        $pdo,
        $threadId,
        null,
        'comment',
        $authorName !== '' ? $authorName : ($thread['assignee'] ?: 'DMC Team'),
        $commentBody,
        'processing',
        '',
        '',
        $startedAt,
        null,
        null,
        null
    );

    mark_thread_revision_state(
        $pdo,
        $threadId,
        'running',
        'Revision gestartet',
        5,
        null,
        null,
        $systemPrompt !== '' ? $systemPrompt : null
    );

    jsonResponse([
        'success' => true,
        'commentId' => (int) $pdo->lastInsertId(),
        'thread' => fetch_thread($pdo, $threadId),
        'threads' => fetch_threads($pdo),
        'stats' => fetch_stats($pdo),
    ]);
}

if ($action === 'delete_thread') {
    $threadId = isset($body['threadId']) ? (int) $body['threadId'] : 0;
    if ($threadId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Ungueltige Thread-ID'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM dmc_content_threads WHERE id = :id");
    $stmt->execute([':id' => $threadId]);
    respond_with_threads($pdo);
}

if ($action === 'mark_thread_failed') {
    $threadId = isset($body['threadId']) ? (int) $body['threadId'] : 0;
    if ($threadId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Ungueltige Thread-ID'], 400);
    }

    $completedAt = utc_now();
    $stmt = $pdo->prepare(
        "UPDATE dmc_content_threads
         SET status = 'failed',
             status_label = 'Workflow konnte nicht gestartet werden',
             progress_percent = 100,
             error_message = :error_message,
             completed_at = :completed_at,
             duration_seconds = COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, :completed_at))
         WHERE id = :id"
    );
    $stmt->execute([
        ':error_message' => trim((string) ($body['errorMessage'] ?? 'Workflow konnte nicht gestartet werden')),
        ':completed_at' => $completedAt,
        ':id' => $threadId,
    ]);

    respond_with_threads($pdo, $threadId);
}

if ($action === 'update_thread_status') {
    require_ingest_secret();

    $threadId = isset($body['threadId']) ? (int) $body['threadId'] : 0;
    if ($threadId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Ungueltige Thread-ID'], 400);
    }

    $existing = fetch_thread($pdo, $threadId);
    if (!$existing) {
        jsonResponse(['success' => false, 'error' => 'Thread nicht gefunden'], 404);
    }

    $status = trim((string) ($body['status'] ?? $existing['status']));
    $statusLabel = trim((string) ($body['statusLabel'] ?? $existing['statusLabel']));
    $progressPercent = array_key_exists('progressPercent', $body)
        ? clamp_progress($body['progressPercent'])
        : (int) $existing['progressPercent'];
    $workflowId = trim((string) ($body['workflowId'] ?? $existing['workflowId']));
    $executionId = trim((string) ($body['executionId'] ?? $existing['executionId']));
    $documentUrl = trim((string) ($body['documentUrl'] ?? $existing['documentUrl']));
    $documentName = trim((string) ($body['documentName'] ?? $existing['documentName']));
    $generatedContent = array_key_exists('generatedContent', $body)
        ? trim((string) $body['generatedContent'])
        : ($existing['generatedContent'] ?? null);
    $errorMessage = array_key_exists('errorMessage', $body)
        ? trim((string) $body['errorMessage'])
        : ($existing['errorMessage'] ?? null);
    $completedAt = null;
    $durationSeconds = $existing['durationSeconds'];

    if (in_array($status, ['completed', 'failed'], true)) {
        $completedAt = utc_now();
        $durationSeconds = isset($body['durationSeconds']) && is_numeric($body['durationSeconds'])
            ? (int) $body['durationSeconds']
            : compute_duration_seconds(
                isset($existing['startedAt']) && $existing['startedAt'] ? gmdate('Y-m-d H:i:s', strtotime($existing['startedAt'])) : null,
                $completedAt
            );
        if ($status === 'completed') {
            $progressPercent = 100;
            if ($statusLabel === '') {
                $statusLabel = 'Content fertig generiert';
            }
        }
    }

    $stmt = $pdo->prepare(
        "UPDATE dmc_content_threads
         SET status = :status,
             status_label = :status_label,
             progress_percent = :progress_percent,
             workflow_id = :workflow_id,
             execution_id = :execution_id,
             document_url = :document_url,
             document_name = :document_name,
             generated_content = :generated_content,
             error_message = :error_message,
             completed_at = :completed_at,
             duration_seconds = :duration_seconds
         WHERE id = :id"
    );
    $stmt->execute([
        ':status' => $status !== '' ? $status : $existing['status'],
        ':status_label' => $statusLabel !== '' ? $statusLabel : $existing['statusLabel'],
        ':progress_percent' => $progressPercent,
        ':workflow_id' => $workflowId,
        ':execution_id' => $executionId,
        ':document_url' => $documentUrl,
        ':document_name' => $documentName,
        ':generated_content' => $generatedContent !== '' ? $generatedContent : null,
        ':error_message' => $errorMessage !== '' ? $errorMessage : null,
        ':completed_at' => $completedAt,
        ':duration_seconds' => $durationSeconds,
        ':id' => $threadId,
    ]);

    respond_with_threads($pdo, $threadId);
}

if ($action === 'complete_revision') {
    require_ingest_secret();

    $threadId = isset($body['threadId']) ? (int) $body['threadId'] : 0;
    $parentMessageId = isset($body['parentMessageId']) ? (int) $body['parentMessageId'] : 0;
    $bodyText = trim((string) ($body['body'] ?? ''));
    if ($threadId <= 0 || $parentMessageId <= 0 || $bodyText === '') {
        jsonResponse(['success' => false, 'error' => 'Thread, Kommentar und Ergebnis sind erforderlich'], 400);
    }

    $completedAt = utc_now();
    $durationSeconds = isset($body['durationSeconds']) && is_numeric($body['durationSeconds'])
        ? (int) $body['durationSeconds']
        : null;
    $authorName = trim((string) ($body['authorName'] ?? 'DMC Revision Workflow'));
    $workflowId = trim((string) ($body['workflowId'] ?? ''));
    $executionId = trim((string) ($body['executionId'] ?? ''));
    $aiComment = trim((string) ($body['aiComment'] ?? ''));
    $editorRecommendation = trim((string) ($body['editorRecommendation'] ?? ''));
    $systemPrompt = trim((string) ($body['systemPrompt'] ?? ''));

    insert_thread_message(
        $pdo,
        $threadId,
        $parentMessageId,
        'revision',
        $authorName,
        $bodyText,
        'completed',
        $workflowId,
        $executionId,
        null,
        $completedAt,
        $durationSeconds,
        null
    );

    if ($aiComment !== '') {
        insert_thread_message(
            $pdo,
            $threadId,
            $parentMessageId,
            'ai_comment',
            $authorName,
            $aiComment,
            'completed',
            $workflowId,
            $executionId,
            null,
            $completedAt,
            $durationSeconds,
            null
        );
    }

    if ($editorRecommendation !== '') {
        insert_thread_message(
            $pdo,
            $threadId,
            $parentMessageId,
            'ai_recommendation',
            $authorName,
            $editorRecommendation,
            'completed',
            $workflowId,
            $executionId,
            null,
            $completedAt,
            $durationSeconds,
            null
        );
    }

    $updateParent = $pdo->prepare(
        "UPDATE dmc_content_thread_messages
         SET status = 'completed',
             completed_at = :completed_at,
             duration_seconds = COALESCE(:duration_seconds, duration_seconds),
             error_message = NULL
         WHERE id = :id AND thread_id = :thread_id"
    );
    $updateParent->execute([
        ':completed_at' => $completedAt,
        ':duration_seconds' => $durationSeconds,
        ':id' => $parentMessageId,
        ':thread_id' => $threadId,
    ]);

    mark_thread_revision_state(
        $pdo,
        $threadId,
        'completed',
        'Content ueberarbeitet',
        100,
        null,
        $completedAt,
        $systemPrompt !== '' ? $systemPrompt : null,
        $bodyText,
        $workflowId !== '' ? $workflowId : null,
        $executionId !== '' ? $executionId : null,
        $durationSeconds
    );

    respond_with_threads($pdo, $threadId);
}

if ($action === 'fail_revision') {
    require_ingest_secret();

    $threadId = isset($body['threadId']) ? (int) $body['threadId'] : 0;
    $parentMessageId = isset($body['parentMessageId']) ? (int) $body['parentMessageId'] : 0;
    $errorMessage = trim((string) ($body['errorMessage'] ?? 'Revision fehlgeschlagen'));
    if ($threadId <= 0 || $parentMessageId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Thread und Kommentar sind erforderlich'], 400);
    }

    $completedAt = utc_now();
    $stmt = $pdo->prepare(
        "UPDATE dmc_content_thread_messages
         SET status = 'failed',
             completed_at = :completed_at,
             error_message = :error_message
         WHERE id = :id AND thread_id = :thread_id"
    );
    $stmt->execute([
        ':completed_at' => $completedAt,
        ':error_message' => $errorMessage,
        ':id' => $parentMessageId,
        ':thread_id' => $threadId,
    ]);

    mark_thread_revision_state(
        $pdo,
        $threadId,
        'completed',
        'Letzte Revision fehlgeschlagen, letzter Content bleibt aktiv',
        100,
        $errorMessage,
        $completedAt
    );

    respond_with_threads($pdo, $threadId);
}

if ($action === 'mark_comment_failed') {
    $threadId = isset($body['threadId']) ? (int) $body['threadId'] : 0;
    $parentMessageId = isset($body['parentMessageId']) ? (int) $body['parentMessageId'] : 0;
    if ($threadId <= 0 || $parentMessageId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Thread und Kommentar sind erforderlich'], 400);
    }

    $completedAt = utc_now();
    $stmt = $pdo->prepare(
        "UPDATE dmc_content_thread_messages
         SET status = 'failed',
             completed_at = :completed_at,
             error_message = :error_message
         WHERE id = :id AND thread_id = :thread_id"
    );
    $stmt->execute([
        ':completed_at' => $completedAt,
        ':error_message' => trim((string) ($body['errorMessage'] ?? 'Revisions-Workflow konnte nicht gestartet werden')),
        ':id' => $parentMessageId,
        ':thread_id' => $threadId,
    ]);

    mark_thread_revision_state(
        $pdo,
        $threadId,
        'completed',
        'Letzte Revision fehlgeschlagen, letzter Content bleibt aktiv',
        100,
        trim((string) ($body['errorMessage'] ?? 'Revisions-Workflow konnte nicht gestartet werden')),
        $completedAt
    );

    respond_with_threads($pdo, $threadId);
}

jsonResponse(['success' => false, 'error' => 'Unbekannte Aktion'], 400);
