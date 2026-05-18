<?php
require_once '../config.php';
require_once '../includes/dashboard_cache.php';

header('Content-Type: application/json');

$pdo = db();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

@set_time_limit(0);
@ignore_user_abort(true);

function isCliRequest(): bool {
    return PHP_SAPI === 'cli';
}

function requestBool(string $key, bool $default = false): bool {
    if (isCliRequest()) {
        global $argv;
        foreach (($argv ?? []) as $arg) {
            if ($arg === '--' . $key) return true;
            if (strpos($arg, '--' . $key . '=') === 0) {
                return filter_var(substr($arg, strlen($key) + 3), FILTER_VALIDATE_BOOLEAN);
            }
        }
        return $default;
    }

    if (!isset($_GET[$key])) {
        return $default;
    }
    return filter_var($_GET[$key], FILTER_VALIDATE_BOOLEAN);
}

function requestInt(string $key, int $default, int $min, int $max): int {
    $value = null;
    if (isCliRequest()) {
        global $argv;
        foreach (($argv ?? []) as $arg) {
            if (strpos($arg, '--' . $key . '=') === 0) {
                $value = substr($arg, strlen($key) + 3);
                break;
            }
        }
    } else {
        $value = $_GET[$key] ?? null;
    }

    $intValue = is_numeric($value) ? (int) $value : $default;
    return max($min, min($max, $intValue));
}

function jsonExit(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function finishRequestIfPossible(): void {
    if (isCliRequest()) {
        return;
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    header('Connection: close');
    header('Content-Encoding: none');
    @ob_end_flush();
    @flush();
}

function checkUrl(string $url): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'follow_location' => true,
            'max_redirects' => 5,
            'user_agent' => 'Mozilla/5.0 ZAP-Dashboard-Checker/2.0',
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
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
        $code = isset($m[1]) ? (int) $m[1] : null;
        return ['online' => $code && $code < 400, 'status' => $code];
    } catch (Throwable $e) {
        return ['online' => false, 'status' => null];
    }
}

function checkLinksLockPath(): string {
    return dashboard_cache_dir() . '/check_links.lock';
}

function openCheckLinksLock() {
    dashboard_cache_ensure_dir();
    $handle = @fopen(checkLinksLockPath(), 'c+');
    return $handle ?: null;
}

function acquireCheckLinksLock($handle): bool {
    return $handle && @flock($handle, LOCK_EX | LOCK_NB);
}

function releaseCheckLinksLock($handle): void {
    if (!$handle) return;
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function todayCheckCutoff(): string {
    return date('Y-m-d 00:00:00');
}

function remainingWhereSql(bool $force): string {
    if ($force) {
        return '1=1';
    }
    return '(last_checked IS NULL OR last_checked < :cutoff)';
}

function countRemainingLinks(PDO $pdo, bool $force): int {
    if ($force) {
        return (int) $pdo->query('SELECT COUNT(*) FROM backlinks')->fetchColumn();
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM backlinks WHERE ' . remainingWhereSql(false));
    $stmt->execute([':cutoff' => todayCheckCutoff()]);
    return (int) $stmt->fetchColumn();
}

function nextLinkBatch(PDO $pdo, int $batchSize, bool $force): array {
    if ($force) {
        $stmt = $pdo->prepare("
            SELECT id, source_url
            FROM backlinks
            ORDER BY COALESCE(last_checked, '1970-01-01 00:00:00') ASC, id ASC
            LIMIT {$batchSize}
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $pdo->prepare("
        SELECT id, source_url
        FROM backlinks
        WHERE " . remainingWhereSql(false) . "
        ORDER BY COALESCE(last_checked, '1970-01-01 00:00:00') ASC, id ASC
        LIMIT {$batchSize}
    ");
    $stmt->execute([':cutoff' => todayCheckCutoff()]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function processLinkBatch(PDO $pdo, array $links): array {
    $update = $pdo->prepare('UPDATE backlinks SET is_online = :o, http_status = :s, last_checked = NOW() WHERE id = :id');
    $checked = 0;
    $results = [];

    foreach ($links as $link) {
        $result = checkUrl((string) ($link['source_url'] ?? ''));
        $update->execute([
            ':o' => $result['online'] ? 1 : 0,
            ':s' => $result['status'],
            ':id' => (int) $link['id'],
        ]);
        $checked++;
        $results[(int) $link['id']] = $result;
    }

    return ['checked' => $checked, 'results' => $results];
}

function processAllDueLinks(PDO $pdo, int $batchSize, bool $force): array {
    $totalChecked = 0;
    $batchCount = 0;
    $sampleResults = [];

    while (true) {
        $links = nextLinkBatch($pdo, $batchSize, $force);
        if (!$links) break;

        $processed = processLinkBatch($pdo, $links);
        $totalChecked += (int) $processed['checked'];
        $batchCount++;

        if (!$sampleResults) {
            $sampleResults = $processed['results'];
        }
    }

    dashboard_refresh_backlinks_cache($pdo);

    return [
        'checked' => $totalChecked,
        'batches' => $batchCount,
        'remaining' => countRemainingLinks($pdo, $force),
        'results' => $sampleResults,
    ];
}

if ($id) {
    $row = $pdo->prepare('SELECT id, source_url FROM backlinks WHERE id = ?');
    $row->execute([$id]);
    $link = $row->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        jsonExit(['success' => false, 'error' => 'Not found'], 404);
    }

    $result = checkUrl((string) $link['source_url']);
    $pdo->prepare('UPDATE backlinks SET is_online = :o, http_status = :s, last_checked = NOW() WHERE id = :id')
        ->execute([':o' => $result['online'] ? 1 : 0, ':s' => $result['status'], ':id' => $id]);
    dashboard_refresh_backlinks_cache($pdo);
    jsonExit(['success' => true, 'result' => $result]);
}

$batchSize = requestInt('batch_size', 500, 50, 1000);
$force = requestBool('force', false);
$wait = requestBool('wait', isCliRequest());

$lockHandle = openCheckLinksLock();
if (!acquireCheckLinksLock($lockHandle)) {
    jsonExit([
        'success' => true,
        'started' => false,
        'already_running' => true,
        'message' => 'Link check is already running.',
    ]);
}

$remainingBefore = countRemainingLinks($pdo, $force);
$respondedEarly = false;

if (!$wait && !isCliRequest()) {
    echo json_encode([
        'success' => true,
        'started' => true,
        'mode' => 'background',
        'batch_size' => $batchSize,
        'remaining_before' => $remainingBefore,
        'force' => $force,
    ]);
    finishRequestIfPossible();
    $respondedEarly = true;
}

$summary = processAllDueLinks($pdo, $batchSize, $force);
releaseCheckLinksLock($lockHandle);

if (isCliRequest()) {
    echo json_encode([
        'success' => true,
        'started' => true,
        'mode' => 'cli',
        'batch_size' => $batchSize,
        'remaining_before' => $remainingBefore,
        'checked' => $summary['checked'],
        'batches' => $summary['batches'],
        'remaining' => $summary['remaining'],
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} elseif (!$respondedEarly) {
    jsonExit([
        'success' => true,
        'started' => true,
        'mode' => 'wait',
        'batch_size' => $batchSize,
        'remaining_before' => $remainingBefore,
        'checked' => $summary['checked'],
        'batches' => $summary['batches'],
        'remaining' => $summary['remaining'],
        'results' => $summary['results'],
    ]);
}
