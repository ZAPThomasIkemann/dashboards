<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Ingest-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function daily_data_require_ingest_secret(): void {
    $expected = getenv('DASHBOARD_INGEST_SECRET') ?: (defined('DASHBOARD_INGEST_SECRET') ? DASHBOARD_INGEST_SECRET : '');
    if ($expected === '') {
        return;
    }

    $provided = $_SERVER['HTTP_X_INGEST_SECRET'] ?? '';
    if (!hash_equals($expected, $provided)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid ingest secret']);
        exit;
    }
}

function daily_data_is_scoped_brand(?string $brand): bool {
    $scope = strtoupper((string) (dashboard_scope_brand() ?? ''));
    if ($scope === '') {
        return true;
    }
    return strtoupper((string) $brand) === $scope;
}

function daily_data_is_scoped_domain(?string $domain): bool {
    $scopeDomains = array_keys(dashboard_scope_domains());
    if (count($scopeDomains) === 0) {
        return true;
    }

    $normalized = strtolower((string) $domain);
    return in_array($normalized, array_map('strtolower', $scopeDomains), true);
}

function daily_data_parse_utc(?string $value): DateTimeImmutable {
    try {
        if ($value) {
            return new DateTimeImmutable($value);
        }
    } catch (Throwable $e) {
    }
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function daily_data_normalize_row_key(string $rowKey): string {
    $rowKey = trim($rowKey);
    if ($rowKey === '') {
        return '';
    }

    if (strlen($rowKey) <= 191) {
        return $rowKey;
    }

    return 'rk::' . sha1($rowKey);
}

function daily_data_normalize_rank($value): ?int {
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    $rank = (int) $value;
    if ($rank < 1 || $rank > 100) {
        return null;
    }

    return $rank;
}

function daily_data_ensure_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ranking_drop_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(191) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            brand VARCHAR(64) DEFAULT NULL,
            keyword VARCHAR(255) NOT NULL,
            landing_url_zap TEXT DEFAULT NULL,
            current_url TEXT DEFAULT NULL,
            previous_rank INT DEFAULT NULL,
            current_rank INT DEFAULT NULL,
            location_code VARCHAR(16) DEFAULT NULL,
            language_code VARCHAR(16) DEFAULT NULL,
            checked_at_utc DATETIME NOT NULL,
            checked_at_local DATETIME NOT NULL,
            event_date DATE NOT NULL,
            event_hour VARCHAR(5) NOT NULL,
            top3_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_key (event_key),
            KEY idx_event_scope_time (domain, event_date, event_hour),
            KEY idx_event_checked_local (checked_at_local)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ranking_drop_backlinks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            row_key VARCHAR(191) NOT NULL,
            event_key VARCHAR(191) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            brand VARCHAR(64) DEFAULT NULL,
            keyword VARCHAR(255) NOT NULL,
            competitor_domain VARCHAR(255) DEFAULT NULL,
            competitor_landing_url TEXT DEFAULT NULL,
            competitor_serp_position INT DEFAULT NULL,
            top3_index INT DEFAULT NULL,
            referring_domain VARCHAR(255) DEFAULT NULL,
            referring_page_url TEXT DEFAULT NULL,
            target_url_to TEXT DEFAULT NULL,
            anchor TEXT DEFAULT NULL,
            is_dofollow TINYINT(1) DEFAULT NULL,
            domain_rank_0_100 DECIMAL(10,4) DEFAULT NULL,
            page_rank_0_100 DECIMAL(10,4) DEFAULT NULL,
            backlink_rank_0_100 DECIMAL(10,4) DEFAULT NULL,
            spam_score DECIMAL(10,4) DEFAULT NULL,
            first_seen_raw VARCHAR(64) DEFAULT NULL,
            last_seen_raw VARCHAR(64) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_row_key (row_key),
            KEY idx_backlinks_event (event_key),
            KEY idx_backlinks_scope (domain, keyword(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function daily_data_upsert_event(PDO $pdo, array $body): array {
    $domain = (string) ($body['domain'] ?? '');
    $brand = (string) ($body['brand'] ?? '');
    $keyword = trim((string) ($body['keyword'] ?? ''));
    $eventKey = trim((string) ($body['event_key'] ?? ''));

    if ($domain === '' || $keyword === '' || $eventKey === '') {
        throw new RuntimeException('domain, keyword and event_key are required');
    }

    $checkedUtc = daily_data_parse_utc((string) ($body['checked_at_utc'] ?? ''));
    $checkedLocal = $checkedUtc->setTimezone(new DateTimeZone('Europe/Berlin'));

    $top3 = $body['top3'] ?? [];
    if (!is_array($top3)) {
        $top3 = [];
    }

    $previousRank = daily_data_normalize_rank($body['previous_rank'] ?? null);
    $currentRank = daily_data_normalize_rank($body['current_rank'] ?? null);

    $stmt = $pdo->prepare("
        INSERT INTO ranking_drop_events (
            event_key, domain, brand, keyword, landing_url_zap, current_url,
            previous_rank, current_rank, location_code, language_code,
            checked_at_utc, checked_at_local, event_date, event_hour, top3_json
        ) VALUES (
            :event_key, :domain, :brand, :keyword, :landing_url_zap, :current_url,
            :previous_rank, :current_rank, :location_code, :language_code,
            :checked_at_utc, :checked_at_local, :event_date, :event_hour, :top3_json
        )
        ON DUPLICATE KEY UPDATE
            brand = VALUES(brand),
            landing_url_zap = VALUES(landing_url_zap),
            current_url = VALUES(current_url),
            previous_rank = VALUES(previous_rank),
            current_rank = VALUES(current_rank),
            location_code = VALUES(location_code),
            language_code = VALUES(language_code),
            checked_at_utc = VALUES(checked_at_utc),
            checked_at_local = VALUES(checked_at_local),
            event_date = VALUES(event_date),
            event_hour = VALUES(event_hour),
            top3_json = VALUES(top3_json),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':event_key' => $eventKey,
        ':domain' => $domain,
        ':brand' => $brand !== '' ? $brand : null,
        ':keyword' => $keyword,
        ':landing_url_zap' => $body['landing_url_zap'] ?? null,
        ':current_url' => $body['current_url'] ?? null,
        ':previous_rank' => $previousRank,
        ':current_rank' => $currentRank,
        ':location_code' => (string) ($body['location_code'] ?? '2276'),
        ':language_code' => (string) ($body['language_code'] ?? 'de'),
        ':checked_at_utc' => $checkedUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ':checked_at_local' => $checkedLocal->format('Y-m-d H:i:s'),
        ':event_date' => $checkedLocal->format('Y-m-d'),
        ':event_hour' => $checkedLocal->format('H:00'),
        ':top3_json' => json_encode($top3, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    return ['event_key' => $eventKey];
}

function daily_data_upsert_backlink(PDO $pdo, array $body): array {
    $eventKey = trim((string) ($body['event_key'] ?? ''));
    $domain = (string) ($body['domain'] ?? '');
    $keyword = trim((string) ($body['keyword'] ?? ''));
    if ($eventKey === '' || $domain === '' || $keyword === '') {
        throw new RuntimeException('event_key, domain and keyword are required');
    }

    $rowKey = trim((string) ($body['row_key'] ?? ''));
    if ($rowKey === '') {
        $rowKey = sha1(json_encode([
            $eventKey,
            $body['competitor_landing_url'] ?? '',
            $body['referring_page_url'] ?? '',
            $body['anchor'] ?? '',
            $body['note'] ?? '',
        ]));
    }
    $rowKey = daily_data_normalize_row_key($rowKey);

    $stmt = $pdo->prepare("
        INSERT INTO ranking_drop_backlinks (
            row_key, event_key, domain, brand, keyword,
            competitor_domain, competitor_landing_url, competitor_serp_position, top3_index,
            referring_domain, referring_page_url, target_url_to, anchor, is_dofollow,
            domain_rank_0_100, page_rank_0_100, backlink_rank_0_100, spam_score,
            first_seen_raw, last_seen_raw, note
        ) VALUES (
            :row_key, :event_key, :domain, :brand, :keyword,
            :competitor_domain, :competitor_landing_url, :competitor_serp_position, :top3_index,
            :referring_domain, :referring_page_url, :target_url_to, :anchor, :is_dofollow,
            :domain_rank_0_100, :page_rank_0_100, :backlink_rank_0_100, :spam_score,
            :first_seen_raw, :last_seen_raw, :note
        )
        ON DUPLICATE KEY UPDATE
            competitor_domain = VALUES(competitor_domain),
            competitor_landing_url = VALUES(competitor_landing_url),
            competitor_serp_position = VALUES(competitor_serp_position),
            top3_index = VALUES(top3_index),
            referring_domain = VALUES(referring_domain),
            referring_page_url = VALUES(referring_page_url),
            target_url_to = VALUES(target_url_to),
            anchor = VALUES(anchor),
            is_dofollow = VALUES(is_dofollow),
            domain_rank_0_100 = VALUES(domain_rank_0_100),
            page_rank_0_100 = VALUES(page_rank_0_100),
            backlink_rank_0_100 = VALUES(backlink_rank_0_100),
            spam_score = VALUES(spam_score),
            first_seen_raw = VALUES(first_seen_raw),
            last_seen_raw = VALUES(last_seen_raw),
            note = VALUES(note),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':row_key' => $rowKey,
        ':event_key' => $eventKey,
        ':domain' => $domain,
        ':brand' => (string) ($body['brand'] ?? 'ZAP'),
        ':keyword' => $keyword,
        ':competitor_domain' => $body['competitor_domain'] ?? null,
        ':competitor_landing_url' => $body['competitor_landing_url'] ?? null,
        ':competitor_serp_position' => $body['competitor_serp_position'] ?? null,
        ':top3_index' => $body['top3_index'] ?? null,
        ':referring_domain' => $body['referring_domain'] ?? null,
        ':referring_page_url' => $body['referring_page_url'] ?? null,
        ':target_url_to' => $body['target_url_to'] ?? null,
        ':anchor' => $body['anchor'] ?? null,
        ':is_dofollow' => array_key_exists('is_dofollow', $body) ? (($body['is_dofollow'] === null || $body['is_dofollow'] === '') ? null : ((int) !!$body['is_dofollow'])) : null,
        ':domain_rank_0_100' => $body['domain_rank_0_100'] ?? null,
        ':page_rank_0_100' => $body['page_rank_0_100'] ?? null,
        ':backlink_rank_0_100' => $body['backlink_rank_0_100'] ?? null,
        ':spam_score' => $body['spam_score'] ?? null,
        ':first_seen_raw' => $body['first_seen'] ?? null,
        ':last_seen_raw' => $body['last_seen'] ?? null,
        ':note' => $body['note'] ?? null,
    ]);

    return ['row_key' => $rowKey];
}

function daily_data_local_meta_from_utc(?string $utcValue): array {
    $utc = daily_data_parse_utc($utcValue);
    $local = $utc->setTimezone(new DateTimeZone('Europe/Berlin'));
    return [
        'checked_at_utc' => $utc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'checked_at_local' => $local->format('Y-m-d H:i:s'),
        'event_date' => $local->format('Y-m-d'),
        'event_hour' => $local->format('H:00'),
    ];
}

function daily_data_history_range_filters(array $query): array {
    $where = [];
    $params = [];
    $berlin = new DateTimeZone('Europe/Berlin');
    $utc = new DateTimeZone('UTC');

    if (!empty($query['from_date'])) {
        $fromLocal = new DateTimeImmutable((string) $query['from_date'] . ' 00:00:00', $berlin);
        $where[] = 'h.checked_at >= ?';
        $params[] = $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s');
    }

    if (!empty($query['to_date'])) {
        $toLocalExclusive = (new DateTimeImmutable((string) $query['to_date'] . ' 00:00:00', $berlin))->modify('+1 day');
        $where[] = 'h.checked_at < ?';
        $params[] = $toLocalExclusive->setTimezone($utc)->format('Y-m-d H:i:s');
    }

    $scopeDomains = array_keys(dashboard_scope_domains());
    if (count($scopeDomains) > 0) {
        $placeholders = implode(',', array_fill(0, count($scopeDomains), '?'));
        $where[] = "h.domain IN ($placeholders)";
        foreach ($scopeDomains as $scopeDomain) {
            $params[] = (string) $scopeDomain;
        }
    }

    $scopeBrand = dashboard_scope_brand();
    if ($scopeBrand) {
        $where[] = 'h.brand = ?';
        $params[] = (string) $scopeBrand;
    }

    if (!empty($query['keyword'])) {
        $where[] = 'LOWER(h.keyword) LIKE ?';
        $params[] = '%' . strtolower((string) $query['keyword']) . '%';
    }

    return [$where, $params];
}

function daily_data_drop_events_map(PDO $pdo, array $query): array {
    $where = [];
    $params = [];

    if (!empty($query['from_date'])) {
        $where[] = 'e.event_date >= ?';
        $params[] = (string) $query['from_date'];
    }
    if (!empty($query['to_date'])) {
        $where[] = 'e.event_date <= ?';
        $params[] = (string) $query['to_date'];
    }

    $scopeDomains = array_keys(dashboard_scope_domains());
    if (count($scopeDomains) > 0) {
        $placeholders = implode(',', array_fill(0, count($scopeDomains), '?'));
        $where[] = "e.domain IN ($placeholders)";
        foreach ($scopeDomains as $scopeDomain) {
            $params[] = (string) $scopeDomain;
        }
    }

    $scopeBrand = dashboard_scope_brand();
    if ($scopeBrand) {
        $where[] = 'e.brand = ?';
        $params[] = (string) $scopeBrand;
    }

    $whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            e.*,
            (
                SELECT COUNT(*)
                FROM ranking_drop_backlinks b
                WHERE b.event_key = e.event_key
            ) AS backlink_rows
        FROM ranking_drop_events e
        $whereSql
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $key = implode('||', [
            strtolower((string) ($row['domain'] ?? '')),
            (string) ($row['keyword'] ?? ''),
            (string) ($row['location_code'] ?? '2276'),
            (string) ($row['event_date'] ?? ''),
            (string) ($row['event_hour'] ?? ''),
        ]);
        $map[$key] = $row;
    }

    return $map;
}

function daily_data_build_timeline_row(array $historyRow, ?array $dropRow): array {
    $local = daily_data_local_meta_from_utc($historyRow['checked_at'] ?? null);
    $top3 = [];
    $backlinkRows = 0;
    $linkedDropEventKey = null;
    if ($dropRow) {
        $top3 = json_decode((string) ($dropRow['top3_json'] ?? '[]'), true) ?: [];
        $backlinkRows = (int) ($dropRow['backlink_rows'] ?? 0);
        $linkedDropEventKey = (string) ($dropRow['event_key'] ?? '');
    }

    $previousRank = daily_data_normalize_rank($historyRow['previous_position'] ?? null);
    $currentRank = daily_data_normalize_rank($historyRow['position'] ?? null);
    $rankDelta = null;
    if ($previousRank !== null && $currentRank !== null) {
        $rankDelta = $previousRank - $currentRank;
    }

    return [
        'event_key' => 'history::' . (string) $historyRow['id'],
        'linked_drop_event_key' => $linkedDropEventKey ?: null,
        'source_type' => 'ranking_push',
        'has_competitor_data' => $linkedDropEventKey !== null && $linkedDropEventKey !== '',
        'domain' => (string) ($historyRow['domain'] ?? ''),
        'brand' => (string) ($historyRow['brand'] ?? ''),
        'keyword' => (string) ($historyRow['keyword'] ?? ''),
        'landing_url_zap' => $historyRow['landing_url_zap'] ?? ($historyRow['url'] ?? null),
        'current_url' => $historyRow['url'] ?? null,
        'previous_rank' => $previousRank,
        'current_rank' => $currentRank,
        'rank_changed' => $previousRank !== $currentRank,
        'rank_delta' => $rankDelta,
        'location_code' => (string) ($historyRow['location_code'] ?? '2276'),
        'language_code' => (string) ($historyRow['language_code'] ?? 'de'),
        'checked_at_utc' => $local['checked_at_utc'],
        'checked_at_local' => $local['checked_at_local'],
        'event_date' => $local['event_date'],
        'event_hour' => $local['event_hour'],
        'top3' => $top3,
        'backlink_rows' => $backlinkRows,
    ];
}

function daily_data_fetch_timeline(PDO $pdo, array $query): array {
    [$where, $params] = daily_data_history_range_filters($query);
    $whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';
    $limit = min((int) ($query['limit'] ?? 5000), 20000);

    $sql = "
        SELECT
            h.*,
            r.url AS landing_url_zap
        FROM rankings_history h
        LEFT JOIN rankings r ON r.id = h.ranking_id
        $whereSql
        ORDER BY h.checked_at DESC, h.id DESC
        LIMIT $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $historyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dropMap = daily_data_drop_events_map($pdo, $query);
    $rows = [];
    foreach ($historyRows as $historyRow) {
        $local = daily_data_local_meta_from_utc($historyRow['checked_at'] ?? null);
        $dropKey = implode('||', [
            strtolower((string) ($historyRow['domain'] ?? '')),
            (string) ($historyRow['keyword'] ?? ''),
            (string) ($historyRow['location_code'] ?? '2276'),
            $local['event_date'],
            $local['event_hour'],
        ]);
        $rows[] = daily_data_build_timeline_row($historyRow, $dropMap[$dropKey] ?? null);
    }

    return [
        'success' => true,
        'data' => $rows,
    ];
}

function daily_data_fetch_event_details(PDO $pdo, string $eventKey): array {
    if (str_starts_with($eventKey, 'history::')) {
        $historyId = (int) substr($eventKey, strlen('history::'));
        $stmt = $pdo->prepare('
            SELECT
                h.*,
                r.url AS landing_url_zap
            FROM rankings_history h
            LEFT JOIN rankings r ON r.id = h.ranking_id
            WHERE h.id = ?
            LIMIT 1
        ');
        $stmt->execute([$historyId]);
        $historyRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$historyRow) {
            http_response_code(404);
            return ['success' => false, 'error' => 'Event not found'];
        }

        if (!daily_data_is_scoped_brand($historyRow['brand'] ?? null) || !daily_data_is_scoped_domain($historyRow['domain'] ?? null)) {
            http_response_code(403);
            return ['success' => false, 'error' => 'Forbidden'];
        }

        $local = daily_data_local_meta_from_utc($historyRow['checked_at'] ?? null);
        $dropStmt = $pdo->prepare('
            SELECT
                e.*,
                (
                    SELECT COUNT(*)
                    FROM ranking_drop_backlinks b
                    WHERE b.event_key = e.event_key
                ) AS backlink_rows
            FROM ranking_drop_events e
            WHERE e.domain = ?
              AND e.keyword = ?
              AND COALESCE(e.location_code, "2276") = COALESCE(?, "2276")
              AND e.event_date = ?
              AND e.event_hour = ?
            ORDER BY e.checked_at_local DESC, e.id DESC
            LIMIT 1
        ');
        $dropStmt->execute([
            (string) ($historyRow['domain'] ?? ''),
            (string) ($historyRow['keyword'] ?? ''),
            (string) ($historyRow['location_code'] ?? '2276'),
            $local['event_date'],
            $local['event_hour'],
        ]);
        $drop = $dropStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $top3 = $drop ? (json_decode((string) ($drop['top3_json'] ?? '[]'), true) ?: []) : [];
        $competitors = [];
        if ($drop) {
            $dropDetails = daily_data_fetch_event_details($pdo, (string) $drop['event_key']);
            if (($dropDetails['success'] ?? false) === true) {
                $top3 = $dropDetails['event']['top3'] ?? $top3;
                $competitors = $dropDetails['competitors'] ?? [];
            }
        }

        $previousRank = daily_data_normalize_rank($historyRow['previous_position'] ?? null);
        $currentRank = daily_data_normalize_rank($historyRow['position'] ?? null);

        return [
            'success' => true,
            'event' => [
                'event_key' => 'history::' . (string) $historyRow['id'],
                'linked_drop_event_key' => $drop['event_key'] ?? null,
                'source_type' => 'ranking_push',
                'domain' => $historyRow['domain'],
                'brand' => $historyRow['brand'],
                'keyword' => $historyRow['keyword'],
                'landing_url_zap' => $historyRow['landing_url_zap'] ?? $historyRow['url'],
                'current_url' => $historyRow['url'],
                'previous_rank' => $previousRank,
                'current_rank' => $currentRank,
                'checked_at_local' => $local['checked_at_local'],
                'event_date' => $local['event_date'],
                'event_hour' => $local['event_hour'],
                'top3' => $top3,
                'has_competitor_data' => $drop !== null,
            ],
            'competitors' => $competitors,
        ];
    }

    $eventStmt = $pdo->prepare('SELECT * FROM ranking_drop_events WHERE event_key = ? LIMIT 1');
    $eventStmt->execute([$eventKey]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        return ['success' => false, 'error' => 'Event not found'];
    }

    if (!daily_data_is_scoped_brand($event['brand'] ?? null) || !daily_data_is_scoped_domain($event['domain'] ?? null)) {
        http_response_code(403);
        return ['success' => false, 'error' => 'Forbidden'];
    }

    $top3 = json_decode((string) ($event['top3_json'] ?? '[]'), true) ?: [];

    $stmt = $pdo->prepare('
        SELECT *
        FROM ranking_drop_backlinks
        WHERE event_key = ?
        ORDER BY top3_index ASC, competitor_serp_position ASC, updated_at DESC, id ASC
    ');
    $stmt->execute([$eventKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($top3 as $entry) {
        $groupKey = (string) ($entry['url'] ?? '') . '||' . (string) ($entry['domain'] ?? '');
        $groups[$groupKey] = [
            'competitor_domain' => $entry['domain'] ?? null,
            'competitor_landing_url' => $entry['url'] ?? null,
            'competitor_serp_position' => $entry['rank_group'] ?? null,
            'top3_index' => $entry['rank_group'] ?? null,
            'title' => $entry['title'] ?? null,
            'backlinks' => [],
        ];
    }

    foreach ($rows as $row) {
        $groupKey = (string) ($row['competitor_landing_url'] ?? '') . '||' . (string) ($row['competitor_domain'] ?? '');
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'competitor_domain' => $row['competitor_domain'] ?? null,
                'competitor_landing_url' => $row['competitor_landing_url'] ?? null,
                'competitor_serp_position' => $row['competitor_serp_position'] ?? null,
                'top3_index' => $row['top3_index'] ?? null,
                'title' => null,
                'backlinks' => [],
            ];
        }
        $groups[$groupKey]['backlinks'][] = $row;
    }

    return [
        'success' => true,
        'event' => [
            'event_key' => $event['event_key'],
            'domain' => $event['domain'],
            'brand' => $event['brand'],
            'keyword' => $event['keyword'],
            'landing_url_zap' => $event['landing_url_zap'],
            'current_url' => $event['current_url'],
            'previous_rank' => daily_data_normalize_rank($event['previous_rank'] ?? null),
            'current_rank' => daily_data_normalize_rank($event['current_rank'] ?? null),
            'checked_at_local' => $event['checked_at_local'],
            'event_date' => $event['event_date'],
            'event_hour' => $event['event_hour'],
            'top3' => $top3,
        ],
        'competitors' => array_values($groups),
    ];
}

$pdo = db();
daily_data_ensure_tables($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $mode = (string) ($_GET['mode'] ?? 'timeline');
    if ($mode === 'event_details') {
        echo json_encode(daily_data_fetch_event_details($pdo, (string) ($_GET['event_key'] ?? '')));
        exit;
    }

    echo json_encode(daily_data_fetch_timeline($pdo, $_GET));
    exit;
}

if ($method === 'POST') {
    daily_data_require_ingest_secret();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    try {
        $type = (string) ($body['type'] ?? 'event');
        if ($type === 'backlink') {
            echo json_encode(['success' => true] + daily_data_upsert_backlink($pdo, $body));
            exit;
        }

        echo json_encode(['success' => true] + daily_data_upsert_event($pdo, $body));
        exit;
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
