<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const LPQ_ITEMS_PER_RUN = 1;
const LPQ_DEFAULT_SECONDS_PER_ITEM = 2;

function lpq_fetch_counts(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT status, COUNT(*) AS cnt
        FROM competitor_backlink_queue
        GROUP BY status
    ");
    $counts = [
        'pending' => 0,
        'processing' => 0,
        'done' => 0,
        'failed' => 0,
    ];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string) ($row['status'] ?? '');
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) ($row['cnt'] ?? 0);
        }
    }
    $counts['total'] = array_sum($counts);
    return $counts;
}

function lpq_fetch_rows(PDO $pdo, string $status, int $limit): array {
    $limit = max(1, min($limit, 100));

    $orderBy = match ($status) {
        'pending' => 'id ASC',
        'processing' => 'claimed_at DESC, id DESC',
        'done' => 'completed_at DESC, id DESC',
        'failed' => 'updated_at DESC, id DESC',
        default => 'id DESC',
    };

    $sql = "
        SELECT
            id,
            queue_key,
            event_key,
            status,
            attempts,
            error_message,
            domain,
            brand,
            keyword,
            search_volume,
            previous_rank,
            current_rank,
            location_code,
            language_code,
            landing_url_zap,
            current_url,
            competitor_serp_position,
            competitor_domain,
            competitor_landing_url,
            competitor_title,
            top3_index,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at,
            DATE_FORMAT(claimed_at, '%Y-%m-%d %H:%i:%s') AS claimed_at,
            DATE_FORMAT(completed_at, '%Y-%m-%d %H:%i:%s') AS completed_at,
            DATE_FORMAT(checked_at_utc, '%Y-%m-%d %H:%i:%s') AS checked_at_utc,
            DATE_FORMAT(CONVERT_TZ(checked_at_utc, '+00:00', '+02:00'), '%Y-%m-%d %H:%i:%s') AS checked_at_berlin_guess
        FROM competitor_backlink_queue
        WHERE status = :status
        ORDER BY $orderBy
        LIMIT $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':status' => $status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function lpq_average_seconds_per_item(PDO $pdo): int {
    $value = $pdo->query("
        SELECT AVG(TIMESTAMPDIFF(SECOND, claimed_at, completed_at))
        FROM competitor_backlink_queue
        WHERE status = 'done'
          AND claimed_at IS NOT NULL
          AND completed_at IS NOT NULL
          AND completed_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
    ")->fetchColumn();

    $avg = is_numeric($value) ? (float) $value : 0.0;
    if ($avg <= 0) {
        return LPQ_DEFAULT_SECONDS_PER_ITEM;
    }

    return max(1, (int) round($avg));
}

function lpq_estimate(PDO $pdo, array $counts): array {
    $remainingItems = (int) (($counts['pending'] ?? 0) + ($counts['processing'] ?? 0));
    $secondsPerItem = lpq_average_seconds_per_item($pdo);
    $runsRemaining = $remainingItems > 0 ? (int) ceil($remainingItems / LPQ_ITEMS_PER_RUN) : 0;
    $remainingSeconds = $remainingItems * $secondsPerItem;

    $hours = intdiv($remainingSeconds, 3600);
    $minutes = intdiv($remainingSeconds % 3600, 60);
    $seconds = $remainingSeconds % 60;

    if ($hours > 0) {
        $label = sprintf('%dh %02dm', $hours, $minutes);
    } elseif ($minutes > 0) {
        $label = sprintf('%dm %02ds', $minutes, $seconds);
    } else {
        $label = sprintf('%ds', $seconds);
    }

    $nowBerlin = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $etaBerlin = $nowBerlin->modify('+' . $remainingSeconds . ' seconds');

    return [
        'items_per_run' => LPQ_ITEMS_PER_RUN,
        'seconds_per_item' => $secondsPerItem,
        'remaining_items' => $remainingItems,
        'runs_remaining' => $runsRemaining,
        'remaining_seconds' => $remainingSeconds,
        'remaining_label' => $label,
        'eta_berlin' => $etaBerlin->format('Y-m-d H:i:s'),
        'eta_berlin_time' => $etaBerlin->format('H:i:s'),
    ];
}

$pdo = db();
$limit = max(5, min((int) ($_GET['limit'] ?? 25), 100));
$counts = lpq_fetch_counts($pdo);
$estimate = lpq_estimate($pdo, $counts);

echo json_encode([
    'success' => true,
    'server_time' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s'),
    'counts' => $counts,
    'estimate' => $estimate,
    'processing' => lpq_fetch_rows($pdo, 'processing', $limit),
    'pending' => lpq_fetch_rows($pdo, 'pending', $limit),
    'done' => lpq_fetch_rows($pdo, 'done', $limit),
    'failed' => lpq_fetch_rows($pdo, 'failed', $limit),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
