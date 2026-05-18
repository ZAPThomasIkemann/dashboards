<?php
require_once __DIR__ . '/../config.php';

function dashboard_cache_dir(): string {
    return DASHBOARD_CACHE_DIR;
}

function dashboard_cache_ensure_dir(): void {
    $dir = dashboard_cache_dir();
    if (is_dir($dir)) {
        return;
    }
    @mkdir($dir, 0775, true);
}

function dashboard_cache_path(string $name): string {
    return dashboard_cache_dir() . '/' . preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower($name)) . '.json';
}

function dashboard_cache_read(string $name): ?array {
    $path = dashboard_cache_path($name);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function dashboard_cache_write(string $name, array $payload): void {
    dashboard_cache_ensure_dir();
    $path = dashboard_cache_path($name);
    $temp = $path . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Cache payload could not be encoded.');
    }
    file_put_contents($temp, $json, LOCK_EX);
    @chmod($temp, 0664);
    rename($temp, $path);
}

function dashboard_cache_with_meta(array $payload, string $type): array {
    $payload['cache_meta'] = [
        'type' => $type,
        'generated_at' => date('c'),
        'source' => 'json-cache',
    ];
    return $payload;
}

function dashboard_rankings_ensure_history_table(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rankings_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ranking_id BIGINT UNSIGNED NULL,
            domain VARCHAR(255) NOT NULL,
            brand VARCHAR(50) NULL,
            keyword VARCHAR(255) NOT NULL,
            url TEXT NULL,
            position INT NULL,
            previous_position INT NULL,
            search_volume INT NULL,
            cpc DECIMAL(10,2) NULL,
            location_code VARCHAR(20) NULL,
            language_code VARCHAR(20) NULL,
            checked_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rankings_history_lookup (domain, keyword(191), location_code, checked_at),
            KEY idx_rankings_history_checked (checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

function dashboard_backlinks_target_matches_project(string $targetUrl, string $projectDomain): bool {
    if ($targetUrl === '') {
        return false;
    }
    $host = '';
    $parts = @parse_url($targetUrl);
    if (!empty($parts['host'])) {
        $host = strtolower($parts['host']);
    } else {
        $t = preg_replace('#^[a-z]+://#i', '', $targetUrl);
        if (preg_match('#^([^/]+)#', $t, $m)) {
            $host = strtolower($m[1]);
        }
    }
    $host = preg_replace('/^www\./', '', $host);
    $pd = strtolower($projectDomain);
    if ($host === '' || $pd === '') {
        return false;
    }
    if ($host === $pd) {
        return true;
    }
    $suffix = '.' . $pd;
    return strlen($host) > strlen($pd) && substr($host, -strlen($suffix)) === $suffix;
}

function dashboard_backlinks_dr_bucket($drRaw): string {
    if ($drRaw === null || $drRaw === '') {
        return 'k.A.';
    }
    if (!is_numeric($drRaw)) {
        return 'k.A.';
    }
    $value = max(0, min(100, (float) $drRaw));
    if ($value <= 10) return '0-10';
    if ($value <= 20) return '11-20';
    if ($value <= 30) return '21-30';
    if ($value <= 40) return '31-40';
    if ($value <= 50) return '41-50';
    if ($value <= 60) return '51-60';
    if ($value <= 70) return '61-70';
    if ($value <= 80) return '71-80';
    if ($value <= 90) return '81-90';
    return '91-100';
}

function dashboard_backlinks_projects(array $rows): array {
    $bucketOrder = ['0-10', '11-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80', '81-90', '91-100', 'k.A.'];
    $projects = [];
    foreach (MONITORED_DOMAINS as $domain => $meta) {
        $projects[$domain] = [
            'domain' => $domain,
            'name' => $meta['name'],
            'brand' => $meta['brand'],
            'total' => 0,
            'dofollow' => 0,
            'nofollow' => 0,
            'other_type' => 0,
            'dr_buckets' => array_fill_keys($bucketOrder, 0),
            '_anchors' => [],
        ];
    }

    foreach ($rows as $row) {
        $target = (string) ($row['target_url'] ?? '');
        $matched = null;
        foreach (array_keys(MONITORED_DOMAINS) as $domain) {
            if (dashboard_backlinks_target_matches_project($target, $domain)) {
                $matched = $domain;
                break;
            }
        }
        if ($matched === null) {
            continue;
        }

        $project = &$projects[$matched];
        $project['total']++;
        $type = strtolower((string) ($row['link_type'] ?? 'dofollow'));
        if ($type === 'dofollow') {
            $project['dofollow']++;
        } elseif ($type === 'nofollow') {
            $project['nofollow']++;
        } else {
            $project['other_type']++;
        }

        $bucket = dashboard_backlinks_dr_bucket($row['domain_rating'] ?? null);
        $project['dr_buckets'][$bucket] = ($project['dr_buckets'][$bucket] ?? 0) + 1;

        $anchor = trim((string) ($row['anchor_text'] ?? ''));
        if ($anchor === '') {
            $anchor = '(ohne Ankertext)';
        }
        $project['_anchors'][$anchor] = ($project['_anchors'][$anchor] ?? 0) + 1;
        unset($project);
    }

    $out = [];
    foreach ($projects as $project) {
        $anchors = $project['_anchors'];
        unset($project['_anchors']);
        arsort($anchors);
        $project['top_anchors'] = [];
        foreach (array_slice($anchors, 0, 10, true) as $anchor => $count) {
            $project['top_anchors'][] = ['anchor' => $anchor, 'count' => (int) $count];
        }
        $sum = $project['dofollow'] + $project['nofollow'];
        $project['dofollow_pct'] = $sum > 0 ? round(100 * $project['dofollow'] / $sum, 1) : null;
        $project['nofollow_pct'] = $sum > 0 ? round(100 * $project['nofollow'] / $sum, 1) : null;
        $out[] = $project;
    }

    return $out;
}

function dashboard_backlinks_http_status_options(array $rows): array {
    $nums = [];
    $hasEmpty = false;
    foreach ($rows as $row) {
        $value = $row['http_status'] ?? null;
        if ($value === null || $value === '') {
            $hasEmpty = true;
            continue;
        }
        $nums[] = (int) $value;
    }
    $nums = array_values(array_unique($nums));
    sort($nums, SORT_NUMERIC);
    $out = $hasEmpty ? [null] : [];
    foreach ($nums as $num) {
        $out[] = $num;
    }
    return $out;
}

function dashboard_build_backlinks_cache_payload(PDO $pdo): array {
    $rows = $pdo->query('SELECT * FROM backlinks ORDER BY last_checked DESC, created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    return dashboard_cache_with_meta([
        'success' => true,
        'data' => $rows,
        'projects' => dashboard_backlinks_projects($rows),
        'http_status_options' => dashboard_backlinks_http_status_options($rows),
    ], 'backlinks');
}

function dashboard_build_rankings_cache_payload(PDO $pdo): array {
    dashboard_rankings_ensure_history_table($pdo);
    $rows = $pdo->query('SELECT * FROM rankings ORDER BY position ASC, search_volume DESC LIMIT 10000')->fetchAll(PDO::FETCH_ASSOC);
    $totalCount = (int) $pdo->query('SELECT COUNT(*) FROM rankings')->fetchColumn();
    $history = $pdo->query("
        SELECT domain, brand, keyword, position, search_volume, location_code, language_code, checked_at
        FROM rankings_history
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 730 DAY)
        ORDER BY checked_at ASC, domain ASC, keyword ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $summary = $pdo->query("
        SELECT domain, brand,
               COUNT(*) as total_keywords,
               MIN(position) as best_position,
               SUM(CASE WHEN position <= 3 THEN 1 ELSE 0 END) as top3,
               SUM(CASE WHEN position <= 10 THEN 1 ELSE 0 END) as top10,
               SUM(CASE WHEN position <= 100 THEN 1 ELSE 0 END) as top100,
               SUM(COALESCE(search_volume, 0)) as total_volume,
               MAX(last_checked) as last_checked
        FROM rankings
        GROUP BY domain, brand
        ORDER BY brand, domain
    ")->fetchAll(PDO::FETCH_ASSOC);

    return dashboard_cache_with_meta([
        'success' => true,
        'total' => $totalCount,
        'data' => $rows,
        'summary' => $summary,
        'history' => $history,
    ], 'rankings');
}

function dashboard_refresh_backlinks_cache(?PDO $pdo = null): array {
    $payload = dashboard_build_backlinks_cache_payload($pdo ?? db());
    dashboard_cache_write('backlinks', $payload);
    return $payload;
}

function dashboard_refresh_rankings_cache(?PDO $pdo = null): array {
    $payload = dashboard_build_rankings_cache_payload($pdo ?? db());
    dashboard_cache_write('rankings', $payload);
    return $payload;
}

function dashboard_get_backlinks_cache(?PDO $pdo = null): array {
    return dashboard_cache_read('backlinks') ?? dashboard_refresh_backlinks_cache($pdo);
}

function dashboard_get_rankings_cache(?PDO $pdo = null): array {
    return dashboard_cache_read('rankings') ?? dashboard_refresh_rankings_cache($pdo);
}
