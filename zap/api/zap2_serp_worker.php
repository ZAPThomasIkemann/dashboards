<?php
/**
 * ZAP 2 — SERP Check & Competitor Discovery Worker
 *
 * For every keyword where ZAP ranks on position > 10 (or not at all):
 *   1. Run a live SERP check via DataForSEO
 *   2. Update ZAP's position in the `rankings` table
 *   3. Identify the top 3 organic competitors (positions 1–3)
 *   4. For each competitor fetch backlinks pointing to their exact landing page
 *   5. Deduplicate against `competitor_backlink_inventory`
 *   6. Write new entries into `competitor_backlink_inventory` + `competitor_domains`
 *
 * Can be triggered:
 *   - Via dashboard button (POST mode=run_batch)
 *   - Via cron: php /path/to/api/zap2_serp_worker.php
 *   - Via GET/POST for a single keyword: ?mode=run_one&keyword=xxx
 *
 * Returns JSON.
 */
require_once '../config.php';
require_once '../includes/dataforseo.php';
require_once '../includes/dashboard_cache.php';

set_time_limit(300);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$isCli  = PHP_SAPI === 'cli';
$pdo    = db();
$dfs    = new DataForSEO();

if (!$dfs->configured) {
    echo json_encode(['success' => false, 'error' => 'DataForSEO not configured. Set DFS_LOGIN and DFS_PASSWORD.']);
    exit;
}

// ── Schema helpers ────────────────────────────────────────────────────────────

function zap2_ensure_schema(PDO $pdo): void {
    // competitor_backlink_queue: tracks which (keyword × competitor) pairs still need backlink fetching
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS competitor_backlink_queue (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            queue_key               VARCHAR(512)  NOT NULL,
            event_key               VARCHAR(512)  NULL,
            status                  VARCHAR(32)   NOT NULL DEFAULT 'pending',
            attempts                INT           NOT NULL DEFAULT 0,
            error_message           TEXT          NULL,
            domain                  VARCHAR(255)  NOT NULL,
            brand                   VARCHAR(50)   NULL,
            keyword                 VARCHAR(255)  NOT NULL,
            search_volume           INT           NULL,
            previous_rank           INT           NULL,
            current_rank            INT           NULL,
            location_code           VARCHAR(20)   NULL,
            language_code           VARCHAR(10)   NULL,
            landing_url_zap         TEXT          NULL,
            current_url             TEXT          NULL,
            competitor_serp_position INT          NULL,
            competitor_domain       VARCHAR(255)  NULL,
            competitor_landing_url  TEXT          NULL,
            competitor_title        TEXT          NULL,
            top3_index              INT           NULL,
            created_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            claimed_at              DATETIME      NULL,
            completed_at            DATETIME      NULL,
            checked_at_utc          DATETIME      NULL,
            UNIQUE KEY uq_queue_key (queue_key(512)),
            KEY idx_status (status),
            KEY idx_domain_kw (domain(100), keyword(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // competitor_backlink_inventory: all fetched backlinks for competitor landing pages
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS competitor_backlink_inventory (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            domain              VARCHAR(255)    NOT NULL,
            brand               VARCHAR(50)     NULL,
            keyword             VARCHAR(255)    NULL,
            search_volume       INT             NULL,
            competitor_domain   VARCHAR(255)    NULL,
            competitor_landing_url TEXT         NULL,
            referring_domain    VARCHAR(255)    NULL,
            referring_page_url  TEXT            NULL,
            target_url_to       TEXT            NULL,
            anchor              TEXT            NULL,
            domain_rank_0_100   DECIMAL(5,2)    NULL,
            is_dofollow         TINYINT(1)      NOT NULL DEFAULT 1,
            email               VARCHAR(255)    NULL,
            email_status        VARCHAR(32)     NULL,
            imprint_url         TEXT            NULL,
            checked_at_local    DATETIME        NULL,
            first_seen_raw      VARCHAR(64)     NULL,
            created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_domain_kw (domain(100), keyword(191)),
            KEY idx_referring (referring_domain(191)),
            KEY idx_email_status (email_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // competitor_domains: per-domain enrichment queue for ZAP 4
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS competitor_domains (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            domain_key          VARCHAR(255)    NOT NULL,
            domain              VARCHAR(255)    NULL,
            brand               VARCHAR(50)     NULL,
            domain_home_url     VARCHAR(512)    NULL,
            sample_referring_page_url TEXT      NULL,
            keyword_sample      VARCHAR(255)    NULL,
            competitor_domain_sample VARCHAR(255) NULL,
            competitor_landing_url_sample TEXT  NULL,
            backlink_count      INT             NOT NULL DEFAULT 0,
            competitor_count    INT             NOT NULL DEFAULT 0,
            domain_rank_max     DECIMAL(5,2)    NULL,
            spam_score_max      DECIMAL(5,2)    NULL,
            status              VARCHAR(32)     NOT NULL DEFAULT 'queued',
            latest_email        VARCHAR(255)    NULL,
            latest_imprint_url  TEXT            NULL,
            approved_for_outreach TINYINT(1)   NOT NULL DEFAULT 0,
            processing_paused   TINYINT(1)      NOT NULL DEFAULT 0,
            processing_step     VARCHAR(64)     NULL,
            processing_run_id   INT             NULL,
            batch_processing    TINYINT(1)      NOT NULL DEFAULT 0,
            ignored_reason      VARCHAR(255)    NULL,
            is_linkfarm         TINYINT(1)      NOT NULL DEFAULT 0,
            notes               TEXT            NULL,
            first_seen_at       DATETIME        NULL,
            last_seen_at        DATETIME        NULL,
            last_enriched_at    DATETIME        NULL,
            created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_domain_key (domain_key(191)),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// ── Helper: normalize domain from URL ────────────────────────────────────────

function zap2_normalize_domain(string $url): string {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: $url);
    return preg_replace('/^www\./', '', $host);
}

// ── Helper: insert or update competitor_backlink_inventory row ───────────────

function zap2_upsert_backlink(PDO $pdo, array $row): bool {
    $stmt = $pdo->prepare("
        INSERT INTO competitor_backlink_inventory
            (domain, brand, keyword, search_volume, competitor_domain, competitor_landing_url,
             referring_domain, referring_page_url, target_url_to, anchor,
             domain_rank_0_100, is_dofollow, first_seen_raw, checked_at_local)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            domain_rank_0_100 = VALUES(domain_rank_0_100),
            is_dofollow       = VALUES(is_dofollow),
            updated_at        = NOW()
    ");
    // Duplicate detection: the combination of referring_page_url + competitor_landing_url
    // We first check existence to avoid the ON DUPLICATE KEY needing a unique index
    $check = $pdo->prepare("
        SELECT id FROM competitor_backlink_inventory
        WHERE referring_domain = ? AND competitor_landing_url = ?
        LIMIT 1
    ");
    $check->execute([$row['referring_domain'], $row['competitor_landing_url']]);
    if ($check->fetch()) {
        return false; // already exists
    }

    $stmt->execute([
        $row['domain'],
        $row['brand'],
        $row['keyword'],
        $row['search_volume'],
        $row['competitor_domain'],
        $row['competitor_landing_url'],
        $row['referring_domain'],
        $row['referring_page_url'],
        $row['target_url_to'],
        $row['anchor'],
        $row['domain_rank_0_100'],
        $row['is_dofollow'],
        $row['first_seen_raw'],
    ]);
    return true;
}

// ── Helper: ensure competitor_domains row exists ──────────────────────────────

function zap2_ensure_domain(PDO $pdo, string $referringDomain, array $ctx): void {
    if (!$referringDomain) return;
    $stmt = $pdo->prepare("
        INSERT INTO competitor_domains
            (domain_key, domain, brand, domain_home_url, keyword_sample,
             competitor_domain_sample, competitor_landing_url_sample, status,
             first_seen_at, last_seen_at)
        VALUES (?,?,?,?,?,?,?, 'queued', NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            backlink_count = backlink_count + 1,
            last_seen_at   = NOW()
    ");
    $stmt->execute([
        $referringDomain,
        $ctx['domain']  ?? '',
        $ctx['brand']   ?? '',
        'https://' . $referringDomain . '/',
        $ctx['keyword'] ?? '',
        $ctx['competitor_domain']       ?? '',
        $ctx['competitor_landing_url']  ?? '',
    ]);
}

// ── Helper: insert competitor_backlink_queue entry ────────────────────────────

function zap2_ensure_queue_entry(PDO $pdo, array $row): bool {
    $queueKey = md5($row['domain'] . '|' . $row['keyword'] . '|' . $row['competitor_landing_url']);
    $check = $pdo->prepare("SELECT id FROM competitor_backlink_queue WHERE queue_key = ? LIMIT 1");
    $check->execute([$queueKey]);
    if ($check->fetch()) return false;

    $pdo->prepare("
        INSERT INTO competitor_backlink_queue
            (queue_key, domain, brand, keyword, search_volume,
             previous_rank, current_rank, location_code, language_code,
             competitor_domain, competitor_landing_url,
             competitor_serp_position, top3_index, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')
    ")->execute([
        $queueKey,
        $row['domain'], $row['brand'], $row['keyword'], $row['search_volume'],
        $row['previous_rank'], $row['current_rank'],
        $row['location_code'], $row['language_code'],
        $row['competitor_domain'], $row['competitor_landing_url'],
        $row['competitor_serp_position'], $row['top3_index'],
    ]);
    return true;
}

// ── Core: process one keyword ─────────────────────────────────────────────────

function zap2_process_keyword(PDO $pdo, DataForSEO $dfs, array $kw, bool $verbose = false): array {
    $domain      = (string) ($kw['domain']         ?? '');
    $brand       = (string) ($kw['brand']          ?? '');
    $keyword     = (string) ($kw['keyword']        ?? '');
    $locCode     = (string) ($kw['location_code']  ?? '2276');
    $langCode    = (string) ($kw['language_code']  ?? 'de');
    $searchVol   = (int)    ($kw['search_volume']  ?? 0);
    $currentPos  = isset($kw['position']) ? (int) $kw['position'] : null;

    $result = [
        'keyword'         => $keyword,
        'previous_rank'   => $currentPos,
        'new_rank'        => null,
        'serp_items'      => 0,
        'competitors'     => 0,
        'backlinks_found' => 0,
        'backlinks_new'   => 0,
        'queue_entries'   => 0,
        'skipped'         => false,
        'error'           => null,
    ];

    // ── Step 1: Run live SERP check ────────────────────────────────────────
    $serpItems = $dfs->getSerpOrganic($keyword, $locCode, $langCode, 20);
    if (empty($serpItems)) {
        $result['error'] = 'No SERP results returned';
        return $result;
    }
    $result['serp_items'] = count($serpItems);

    // ── Step 2: Find ZAP's position in SERP results ─────────────────────────
    $zapPosition = null;
    $zapUrl      = null;
    foreach ($serpItems as $item) {
        $itemDomain = zap2_normalize_domain((string) ($item['url'] ?? ''));
        if ($itemDomain === zap2_normalize_domain($domain) ||
            str_ends_with($itemDomain, '.' . zap2_normalize_domain($domain))) {
            $zapPosition = (int) ($item['rank_group'] ?? $item['rank_absolute'] ?? 999);
            $zapUrl      = (string) ($item['url'] ?? '');
            break;
        }
    }
    $result['new_rank'] = $zapPosition;

    // ── Step 3: Update ZAP's position in rankings table ─────────────────────
    $pdo->prepare("
        UPDATE rankings
        SET position          = ?,
            previous_position = position,
            url               = COALESCE(?, url),
            last_checked      = NOW()
        WHERE domain = ? AND keyword = ? AND location_code = ?
    ")->execute([$zapPosition, $zapUrl, $domain, $keyword, $locCode]);

    // ── Step 4: If ZAP is on page 1 (position 1–10), skip competitor analysis
    if ($zapPosition !== null && $zapPosition <= 10) {
        $result['skipped'] = true;
        return $result;
    }

    // ── Step 5: Identify top-3 organic competitors (not ZAP) ─────────────────
    $competitors = [];
    foreach ($serpItems as $item) {
        if (count($competitors) >= 3) break;
        if (($item['type'] ?? '') !== 'organic') continue;
        $itemDomain = zap2_normalize_domain((string) ($item['url'] ?? ''));
        if ($itemDomain === zap2_normalize_domain($domain)) continue; // skip ZAP itself
        $competitors[] = $item;
    }
    $result['competitors'] = count($competitors);

    if (empty($competitors)) return $result;

    // ── Step 6: For each competitor, fetch backlinks for their landing URL ────
    foreach ($competitors as $idx => $competitor) {
        $compUrl    = (string) ($competitor['url']    ?? '');
        $compDomain = zap2_normalize_domain($compUrl);
        $compPos    = (int)    ($competitor['rank_group'] ?? $competitor['rank_absolute'] ?? 0);

        if (!$compUrl) continue;

        // Add to backlink queue (for async processing by ZAP 3 backlink worker)
        $queued = zap2_ensure_queue_entry($pdo, [
            'domain'                 => $domain,
            'brand'                  => $brand,
            'keyword'                => $keyword,
            'search_volume'          => $searchVol,
            'previous_rank'          => $currentPos,
            'current_rank'           => $zapPosition,
            'location_code'          => $locCode,
            'language_code'          => $langCode,
            'competitor_domain'      => $compDomain,
            'competitor_landing_url' => $compUrl,
            'competitor_serp_position' => $compPos,
            'top3_index'             => $idx,
        ]);
        if ($queued) $result['queue_entries']++;

        // Also fetch backlinks NOW so the inventory is immediately populated
        $backlinks = $dfs->getBacklinksForUrl($compUrl, 500);
        $result['backlinks_found'] += count($backlinks);

        foreach ($backlinks as $bl) {
            $refDomain = zap2_normalize_domain((string) ($bl['url_from'] ?? ''));
            $refUrl    = (string) ($bl['url_from']        ?? '');
            $targetUrl = (string) ($bl['url_to']          ?? $compUrl);
            $anchor    = (string) ($bl['anchor']          ?? '');
            $rank      = isset($bl['domain_from_rank']) ? (float) $bl['domain_from_rank'] : null;
            $dofollow  = isset($bl['dofollow'])          ? (int)   $bl['dofollow']         : 1;
            $firstSeen = (string) ($bl['first_seen'] ?? '');

            if (!$refDomain) continue;

            $isNew = zap2_upsert_backlink($pdo, [
                'domain'                 => $domain,
                'brand'                  => $brand,
                'keyword'                => $keyword,
                'search_volume'          => $searchVol,
                'competitor_domain'      => $compDomain,
                'competitor_landing_url' => $compUrl,
                'referring_domain'       => $refDomain,
                'referring_page_url'     => $refUrl,
                'target_url_to'          => $targetUrl,
                'anchor'                 => $anchor,
                'domain_rank_0_100'      => $rank,
                'is_dofollow'            => $dofollow,
                'first_seen_raw'         => $firstSeen,
            ]);

            if ($isNew) {
                $result['backlinks_new']++;
                zap2_ensure_domain($pdo, $refDomain, [
                    'domain'                 => $domain,
                    'brand'                  => $brand,
                    'keyword'                => $keyword,
                    'competitor_domain'      => $compDomain,
                    'competitor_landing_url' => $compUrl,
                ]);
            }
        }
    }

    return $result;
}

// ── Input handling ────────────────────────────────────────────────────────────

$body       = json_decode((string) file_get_contents('php://input'), true) ?: [];
$mode       = (string) ($body['mode']  ?? ($_GET['mode']  ?? 'run_batch'));
$batchSize  = max(1, min(50, (int) ($body['batch'] ?? ($_GET['batch'] ?? 5))));
$singleKw   = trim((string) ($body['keyword'] ?? ($_GET['keyword'] ?? '')));
$minPos     = max(1, (int) ($body['min_position'] ?? ($_GET['min_position'] ?? 11)));

// Check pause flag before doing any work
$controls   = $pdo->query("SELECT control_key, control_value FROM pipeline_controls")->fetchAll(PDO::FETCH_KEY_PAIR);
$paused     = ($controls['pause_backlinks'] ?? '0') === '1';

if ($paused && $mode !== 'status') {
    echo json_encode([
        'success' => false,
        'error'   => 'ZAP 2 is paused (pause_backlinks=1). Set to 0 in Pipeline Controls to resume.',
    ]);
    exit;
}

// Ensure schema
zap2_ensure_schema($pdo);

// ── mode=status: return queue stats only ─────────────────────────────────────
if ($mode === 'status') {
    $stats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM rankings WHERE COALESCE(position, 999) > {$minPos})   AS keywords_to_check,
            (SELECT COUNT(*) FROM competitor_backlink_queue WHERE status='pending')      AS queue_pending,
            (SELECT COUNT(*) FROM competitor_backlink_queue WHERE status='done')         AS queue_done,
            (SELECT COUNT(*) FROM competitor_backlink_inventory)                         AS inventory_total,
            (SELECT COUNT(*) FROM competitor_domains WHERE status='queued')              AS domains_queued
    ")->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}

// ── mode=run_one: process a single keyword ────────────────────────────────────
if ($mode === 'run_one' && $singleKw !== '') {
    $kw = $pdo->prepare("SELECT * FROM rankings WHERE keyword = ? LIMIT 1");
    $kw->execute([$singleKw]);
    $kwRow = $kw->fetch(PDO::FETCH_ASSOC);
    if (!$kwRow) {
        echo json_encode(['success' => false, 'error' => "Keyword '$singleKw' not found in rankings."]);
        exit;
    }
    $res = zap2_process_keyword($pdo, $dfs, $kwRow);
    echo json_encode(['success' => true, 'processed' => 1, 'result' => $res]);
    exit;
}

// ── mode=run_batch: process N keywords with position > minPos ─────────────────
$kwStmt = $pdo->prepare("
    SELECT * FROM rankings
    WHERE COALESCE(position, 999) >= ?
    ORDER BY COALESCE(search_volume, 0) DESC, COALESCE(position, 999) ASC
    LIMIT ?
");
$kwStmt->execute([$minPos, $batchSize]);
$keywords = $kwStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($keywords)) {
    echo json_encode([
        'success'   => true,
        'processed' => 0,
        'message'   => "No keywords with position >= {$minPos} found.",
    ]);
    exit;
}

$results   = [];
$totalNew  = 0;
$totalQ    = 0;
$startTime = time();

foreach ($keywords as $kw) {
    if (time() - $startTime > 240) break; // safety: don't exceed 4 min
    $res = zap2_process_keyword($pdo, $dfs, $kw);
    $results[]  = $res;
    $totalNew  += $res['backlinks_new'];
    $totalQ    += $res['queue_entries'];
}

// Refresh rankings cache so dashboard shows updated data
try {
    dashboard_refresh_rankings_cache($pdo);
} catch (Throwable $e) {
    // non-critical
}

echo json_encode([
    'success'              => true,
    'processed'            => count($results),
    'total_new_backlinks'  => $totalNew,
    'total_queue_entries'  => $totalQ,
    'results'              => $results,
    'generated_at'         => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
