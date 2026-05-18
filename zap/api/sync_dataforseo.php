<?php
require_once '../config.php';
require_once '../includes/dataforseo.php';
require_once '../includes/dashboard_cache.php';

header('Content-Type: application/json');
set_time_limit(600);

function ensure_rankings_history_table(PDO $pdo): void {
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

/**
 * Rankings für eine Domain aus DataForSEO holen und in DB schreiben.
 *
 * @return array{fetched:int, upserted:int}
 */
function sync_rankings_for_domain(PDO $pdo, DataForSEO $dfs, string $domain, array $meta): array {
    ensure_rankings_history_table($pdo);
    $tld         = strtolower(pathinfo($domain, PATHINFO_EXTENSION));
    $locationMap = ['at' => '2040', 'cz' => '2203', 'de' => '2276', 'online' => '2276', 'com' => '2276'];
    $langMap     = ['at' => 'de',   'cz' => 'cs',   'de' => 'de',   'online' => 'de',   'com' => 'de'];
    $locCode     = $locationMap[$tld] ?? '2276';
    $langCode    = $langMap[$tld] ?? 'de';

    $items    = $dfs->getRankedKeywords($domain, $locCode, $langCode, 1000);
    $upserted = 0;

    foreach ($items as $item) {
        $keyword = $item['keyword_data']['keyword'] ?? '';
        if (!$keyword) {
            continue;
        }
        $pos    = $item['ranked_serp_element']['serp_item']['rank_absolute'] ?? null;
        $url    = $item['ranked_serp_element']['serp_item']['url'] ?? null;
        $volume = $item['keyword_data']['keyword_info']['search_volume'] ?? null;
        $cpc    = $item['keyword_data']['keyword_info']['cpc'] ?? null;
        $comp   = $item['keyword_data']['keyword_info']['competition'] ?? null;

        $existing = $pdo->prepare('SELECT id, position FROM rankings WHERE domain=? AND keyword=? AND location_code=?');
        $existing->execute([$domain, $keyword, $locCode]);
        $row = $existing->fetch();

        $rankingId = null;
        $previousPosition = null;
        if ($row) {
            $rankingId = (int) $row['id'];
            $previousPosition = $row['position'] !== null ? (int) $row['position'] : null;
            $pdo->prepare('UPDATE rankings SET position=?,previous_position=?,url=?,search_volume=?,cpc=?,competition=?,last_checked=NOW() WHERE id=?')
                ->execute([$pos, $row['position'], $url, $volume, $cpc, $comp, $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO rankings(domain,brand,keyword,url,position,search_volume,cpc,competition,location_code,language_code,last_checked) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$domain, $meta['brand'], $keyword, $url, $pos, $volume, $cpc, $comp, $locCode, $langCode]);
            $rankingId = (int) $pdo->lastInsertId();
        }

        if ($rankingId) {
            $pdo->prepare('
                INSERT INTO rankings_history
                    (ranking_id, domain, brand, keyword, url, position, previous_position, search_volume, cpc, location_code, language_code, checked_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ')->execute([
                $rankingId,
                $domain,
                $meta['brand'],
                $keyword,
                $url,
                $pos,
                $previousPosition,
                $volume,
                $cpc,
                $locCode,
                $langCode,
            ]);
        }
        $upserted++;
    }

    return ['fetched' => count($items), 'upserted' => $upserted];
}

$pdo    = db();
$dfs    = new DataForSEO();
$action = $_GET['action'] ?? 'all';
$scopedDomains = dashboard_scope_domains();

// ── Backlinks: delegate to Python script ──────────────────────────────────────
if (in_array($action, ['all', 'backlinks'], true)) {
    // Quick subscription check first
    $subscription = $dfs->getBacklinksSubscriptionState('example.com');
    if (!$subscription['available'] && $subscription['reason'] === 'subscription_unavailable') {
        echo json_encode([
            'success' => false,
            'error'   => 'DataForSEO Backlinks API subscription not activated.',
            'action'  => 'activate',
            'link'    => 'https://app.dataforseo.com/backlinks-subscription',
        ]);
        exit;
    }

    // Run the Python script in background, stream output
    $output = shell_exec('python3 /usr/local/bin/dfs_backlinks_sync.py 2>&1');

    // Parse results from output
    $inserted = preg_match_all('/(\d+) new/', $output, $m) ? array_sum(array_map('intval', $m[1])) : 0;
    $updated  = preg_match_all('/(\d+) updated/', $output, $m) ? array_sum(array_map('intval', $m[1])) : 0;

    if ($action === 'backlinks') {
        echo json_encode([
            'success'   => true,
            'inserted'  => $inserted,
            'updated'   => $updated,
            'log'       => $output,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }
}

// ── Rankings: einzelne Domain (für Fortschrittsbalken im Frontend) ────────────
if ($action === 'rankings_domain') {
    $domain = $_GET['domain'] ?? '';
    if ($domain === '' || !isset($scopedDomains[$domain])) {
        echo json_encode(['success' => false, 'error' => 'Invalid domain']);
        exit;
    }
    $meta   = $scopedDomains[$domain];
    $result = sync_rankings_for_domain($pdo, $dfs, $domain, $meta);
    dashboard_refresh_rankings_cache($pdo);
    $all    = array_keys($scopedDomains);
    $index  = array_search($domain, $all, true);

    echo json_encode([
        'success'       => true,
        'domain'        => $domain,
        'result'        => $result,
        'domain_index'  => $index !== false ? $index : 0,
        'total_domains' => count($scopedDomains),
    ]);
    exit;
}

// ── Rankings sync (alle Domains) ─────────────────────────────────────────────
if (in_array($action, ['all', 'rankings'], true)) {
    $results = [];
    foreach ($scopedDomains as $d => $meta) {
        $results[$d] = sync_rankings_for_domain($pdo, $dfs, $d, $meta);
    }
    dashboard_refresh_rankings_cache($pdo);

    echo json_encode([
        'success'   => true,
        'action'    => 'rankings',
        'results'   => $results,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
