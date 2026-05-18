<?php
/**
 * ZAP 4 — Email Enrichment Worker  (pure PHP, no Python required)
 *
 * For each domain in `competitor_domains` with status='queued':
 *   1. Fetch the domain homepage (HTTPS/HTTP)
 *   2. Extract any email addresses found directly on the homepage
 *   3. Find links to Impressum / Kontakt / Contact / Legal pages
 *      (supports: de / en / nl / fr / es / it / pl / cz)
 *   4. Fetch those sub-pages and extract emails
 *   5. Update competitor_domains + all matching competitor_backlink_inventory rows
 *
 * Can be triggered:
 *   - Via dashboard button (POST mode=run_batch)
 *   - Via cron: php /path/to/api/zap4_email_worker.php
 *   - Single domain: POST {"mode":"run_one","domain":"example.com"}
 *
 * Returns JSON.
 */
require_once '../config.php';

set_time_limit(300);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$pdo  = db();
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$mode = (string) ($body['mode'] ?? ($_GET['mode'] ?? 'run_batch'));

// Check pause flag
try {
    $controls = $pdo->query("SELECT control_key, control_value FROM pipeline_controls")->fetchAll(PDO::FETCH_KEY_PAIR);
    $paused   = ($controls['pause_enrichment'] ?? '0') === '1';
} catch (Throwable $e) {
    $paused = false;
}

if ($paused && $mode !== 'status') {
    echo json_encode(['success' => false, 'error' => 'ZAP 4 is paused (pause_enrichment=1).']);
    exit;
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

/**
 * Fetch a URL with cURL. Returns ['status' => int, 'body' => string, 'final_url' => string]
 * or ['status' => 0, 'body' => '', 'error' => '...'] on failure.
 */
function zap4_fetch(string $url, int $timeoutSec = 10): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ZAPBot/1.0; +https://zap-hosting.com)',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de,en;q=0.8',
        ],
        CURLOPT_ENCODING       => 'gzip, deflate',
    ]);
    $body     = (string) curl_exec($ch);
    $status   = (int)    curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($status === 0 || $error) {
        return ['status' => 0, 'body' => '', 'final_url' => $url, 'error' => $error];
    }
    return ['status' => $status, 'body' => $body, 'final_url' => $finalUrl];
}

// ── Email extractor ───────────────────────────────────────────────────────────

/**
 * Extract email addresses from HTML/text. Filters out common false positives
 * (image filenames, CSS classes, w3.org addresses, etc.).
 */
function zap4_extract_emails(string $html): array {
    // Decode HTML entities first
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Also check raw HTML for mailto: links
    preg_match_all('/mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i', $html, $mMailto);
    // Plain text regex
    preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $text, $mText);

    $all = array_merge($mMailto[1], $mText[0]);
    $all = array_unique(array_map('strtolower', $all));

    $blacklist = [
        'example.com', 'example.org', 'example.net',
        'w3.org', 'schema.org', 'sentry.io',
        'yoursite.com', 'domain.com', 'email.com',
    ];

    $filtered = [];
    foreach ($all as $email) {
        // Skip if it looks like a filename (e.g. logo@2x.png treated as email)
        if (preg_match('/\.(png|jpg|jpeg|gif|svg|webp|ico|css|js)$/i', $email)) continue;
        // Skip blacklisted domains
        $emailDomain = strtolower(explode('@', $email)[1] ?? '');
        if (in_array($emailDomain, $blacklist, true)) continue;
        // Skip very short local parts (e.g. a@b.de)
        if (strlen(explode('@', $email)[0]) < 3) continue;
        // Skip if local part is all digits (spam bait)
        if (ctype_digit(explode('@', $email)[0])) continue;
        $filtered[] = $email;
    }

    return array_values(array_unique($filtered));
}

// ── Imprint/contact link finder ───────────────────────────────────────────────

/**
 * Scan HTML for links that likely lead to Impressum / Contact / Legal pages.
 * Returns an array of absolute URLs (relative links resolved against $baseUrl).
 */
function zap4_find_legal_links(string $html, string $baseUrl): array {
    // Patterns in multiple languages
    $keywords = [
        // German
        'impressum', 'kontakt', 'datenschutz', 'rechtliches', 'über-uns', 'ueber-uns',
        // English
        'imprint', 'contact', 'legal', 'privacy', 'about-us', 'about',
        // Dutch
        'disclaimer', 'contact', 'over-ons',
        // French
        'mentions-legales', 'contact', 'a-propos',
        // Spanish
        'aviso-legal', 'contacto', 'acerca',
        // Italian
        'contatti', 'chi-siamo',
        // Polish
        'kontakt', 'o-nas',
        // Czech
        'kontakt', 'o-nas', 'imprint',
    ];

    $pattern = implode('|', array_map('preg_quote', $keywords));

    // Extract href from anchor tags matching keywords
    preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches);

    $links = [];
    $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
    $host   = parse_url($baseUrl, PHP_URL_HOST) ?: '';
    $base   = $scheme . '://' . $host;

    foreach ($matches[1] as $idx => $href) {
        $linkText = strip_tags((string) ($matches[2][$idx] ?? ''));
        $hrefLower = strtolower(urldecode($href));
        $textLower = strtolower($linkText);

        $matchesKeyword =
            preg_match('/(' . $pattern . ')/i', $hrefLower) ||
            preg_match('/(' . $pattern . ')/i', $textLower);

        if (!$matchesKeyword) continue;
        if (str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) continue;

        // Resolve relative URLs
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $absolute = $href;
        } elseif (str_starts_with($href, '//')) {
            $absolute = $scheme . ':' . $href;
        } elseif (str_starts_with($href, '/')) {
            $absolute = $base . $href;
        } else {
            $absolute = rtrim($baseUrl, '/') . '/' . $href;
        }

        // Only follow links on the same domain
        $linkHost = parse_url($absolute, PHP_URL_HOST) ?: '';
        if ($linkHost && $host && !str_ends_with(strtolower($linkHost), strtolower($host)) &&
            !str_ends_with(strtolower($host), strtolower($linkHost))) {
            continue;
        }

        $links[] = $absolute;
    }

    // Also try well-known paths directly
    $wellKnown = [
        '/impressum', '/kontakt', '/contact', '/imprint', '/legal',
        '/about', '/about-us', '/datenschutz', '/privacy',
    ];
    foreach ($wellKnown as $path) {
        $links[] = $base . $path;
    }

    return array_unique($links);
}

// ── Core: enrich one domain ───────────────────────────────────────────────────

function zap4_enrich_domain(PDO $pdo, string $domainKey): array {
    $result = [
        'domain'        => $domainKey,
        'status'        => 'failed',
        'email'         => null,
        'imprint_url'   => null,
        'pages_checked' => 0,
        'error'         => null,
    ];

    $homeUrl = 'https://' . ltrim($domainKey, 'https://');
    $homeUrl = preg_replace('/^https?:\/\//', '', $homeUrl);
    $homeUrl = 'https://' . $homeUrl . '/';

    // Mark as processing
    $pdo->prepare("
        UPDATE competitor_domains
        SET status='processing', processing_step='fetch_homepage', updated_at=NOW()
        WHERE domain_key=?
    ")->execute([$domainKey]);

    // ── Fetch homepage ────────────────────────────────────────────────────
    $home = zap4_fetch($homeUrl, 8);
    if ($home['status'] === 0) {
        // Try HTTP fallback
        $homeHttp = zap4_fetch(str_replace('https://', 'http://', $homeUrl), 6);
        if ($homeHttp['status'] > 0) $home = $homeHttp;
    }

    $result['pages_checked']++;

    if ($home['status'] === 0 || empty($home['body'])) {
        $result['error'] = 'Homepage unreachable';
        $pdo->prepare("
            UPDATE competitor_domains
            SET status='failed', processing_step=NULL, updated_at=NOW()
            WHERE domain_key=?
        ")->execute([$domainKey]);
        return $result;
    }

    // Check for email on homepage
    $emails = zap4_extract_emails($home['body']);
    if ($emails) {
        $result['email']      = $emails[0];
        $result['status']     = 'found_email';
        $result['imprint_url']= $home['final_url'];
        zap4_save_result($pdo, $domainKey, $emails[0], $home['final_url'], 'found_email');
        return $result;
    }

    // ── Find and visit imprint/contact pages ──────────────────────────────
    $pdo->prepare("
        UPDATE competitor_domains SET processing_step='find_legal_page', updated_at=NOW() WHERE domain_key=?
    ")->execute([$domainKey]);

    $legalLinks = zap4_find_legal_links($home['body'], $home['final_url']);
    $visited    = [$home['final_url']];
    $foundEmail = null;
    $foundUrl   = null;

    foreach (array_slice($legalLinks, 0, 12) as $link) {
        if (in_array($link, $visited, true)) continue;
        $visited[] = $link;

        $pdo->prepare("
            UPDATE competitor_domains SET processing_step='fetch_legal_page', updated_at=NOW() WHERE domain_key=?
        ")->execute([$domainKey]);

        $page = zap4_fetch($link, 8);
        $result['pages_checked']++;

        if ($page['status'] === 0 || empty($page['body'])) continue;

        $pdo->prepare("
            UPDATE competitor_domains SET processing_step='extract_email', updated_at=NOW() WHERE domain_key=?
        ")->execute([$domainKey]);

        $pageEmails = zap4_extract_emails($page['body']);
        if ($pageEmails) {
            $foundEmail = $pageEmails[0];
            $foundUrl   = $link;
            break;
        }
    }

    if ($foundEmail) {
        $result['email']      = $foundEmail;
        $result['imprint_url']= $foundUrl;
        $result['status']     = 'found_email';
        zap4_save_result($pdo, $domainKey, $foundEmail, $foundUrl, 'found_email');
    } elseif (count($legalLinks) > 0) {
        $result['status'] = 'no_email';
        zap4_save_result($pdo, $domainKey, null, null, 'no_email');
    } else {
        $result['status'] = 'no_imprint';
        zap4_save_result($pdo, $domainKey, null, null, 'no_imprint');
    }

    return $result;
}

function zap4_save_result(PDO $pdo, string $domainKey, ?string $email, ?string $imprintUrl, string $status): void {
    // Update competitor_domains
    $pdo->prepare("
        UPDATE competitor_domains
        SET status              = ?,
            latest_email        = ?,
            latest_imprint_url  = ?,
            last_enriched_at    = NOW(),
            processing_step     = NULL,
            batch_processing    = 0,
            updated_at          = NOW()
        WHERE domain_key = ?
    ")->execute([$status, $email, $imprintUrl, $domainKey]);

    // Update all matching competitor_backlink_inventory rows
    if ($email) {
        $pdo->prepare("
            UPDATE competitor_backlink_inventory
            SET email        = ?,
                imprint_url  = ?,
                email_status = 'found',
                updated_at   = NOW()
            WHERE referring_domain = ? AND (email IS NULL OR email = '')
        ")->execute([$email, $imprintUrl, $domainKey]);
    } else {
        $pdo->prepare("
            UPDATE competitor_backlink_inventory
            SET email_status = ?,
                updated_at   = NOW()
            WHERE referring_domain = ? AND (email_status IS NULL OR email_status = '' OR email_status = 'processing')
        ")->execute([$status, $domainKey]);
    }
}

// ── mode=status ───────────────────────────────────────────────────────────────
if ($mode === 'status') {
    $stats = $pdo->query("
        SELECT
            SUM(status='queued')      AS queued,
            SUM(status='processing')  AS processing,
            SUM(status='found_email') AS found_email,
            SUM(status='no_email')    AS no_email,
            SUM(status='no_imprint')  AS no_imprint,
            SUM(status='failed')      AS failed,
            COUNT(*)                  AS total
        FROM competitor_domains
    ")->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}

// ── mode=run_one ──────────────────────────────────────────────────────────────
if ($mode === 'run_one') {
    $domainKey = trim((string) ($body['domain'] ?? ($_GET['domain'] ?? '')));
    if (!$domainKey) {
        echo json_encode(['success' => false, 'error' => 'Missing domain']);
        exit;
    }
    $result = zap4_enrich_domain($pdo, $domainKey);
    echo json_encode(['success' => true, 'result' => $result]);
    exit;
}

// ── mode=run_batch ────────────────────────────────────────────────────────────
$batchSize  = max(1, min(20, (int) ($body['batch'] ?? ($_GET['batch'] ?? 5))));

// Pick queued domains (oldest first)
$stmt = $pdo->prepare("
    SELECT domain_key FROM competitor_domains
    WHERE status = 'queued'
    ORDER BY created_at ASC
    LIMIT ?
");
$stmt->execute([$batchSize]);
$domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($domains)) {
    echo json_encode(['success' => true, 'processed' => 0, 'message' => 'No queued domains.']);
    exit;
}

$results   = [];
$found     = 0;
$startTime = time();

foreach ($domains as $domainKey) {
    if (time() - $startTime > 240) break;
    $res = zap4_enrich_domain($pdo, $domainKey);
    $results[] = $res;
    if ($res['status'] === 'found_email') $found++;
}

echo json_encode([
    'success'      => true,
    'processed'    => count($results),
    'found_email'  => $found,
    'results'      => $results,
    'generated_at' => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
