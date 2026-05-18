<?php
// ================================================================
// workflow_content.php
// Lokaler Ersatz fuer n8n Workflow: QIUYQPna33L0KD96
// DMC Content Dashboard -> DataForSEO -> AI Content
// ================================================================

set_time_limit(0);
ignore_user_abort(true);

foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../zap/config.php'] as $cp) {
    if (is_file($cp)) { require_once $cp; break; }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Nur POST erlaubt']);
    exit;
}

// ── Parse body ──────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody ?: '', true);
if (!is_array($body)) { parse_str($rawBody ?: '', $body); }
$body = is_array($body) ? $body : [];

function wc_field(array $b, string ...$keys): string {
    foreach ($keys as $k) {
        if (isset($b[$k])        && $b[$k] !== '')        return (string) $b[$k];
        if (isset($b['body'][$k]) && $b['body'][$k] !== '') return (string) $b['body'][$k];
    }
    return '';
}

$topic          = wc_field($body, 'topic', 'title', 'thema');
$targetAudience = wc_field($body, 'target_audience')
    ?: 'Autofahrer, Reisende und Transportunternehmen mit Informationsbedarf zu Maut, Vignetten und Streckenregelungen in Europa';
$language       = wc_field($body, 'language')  ?: 'German';
$location       = wc_field($body, 'location')  ?: 'Germany';
$prompt         = wc_field($body, 'prompt');
$systemPrompt   = wc_field($body, 'systemPrompt');
$assignee       = wc_field($body, 'assignee', 'mitarbeiter', 'employee');
$formTitle      = wc_field($body, 'title', 'topic', 'thema') ?: $topic;
$threadId       = (int) wc_field($body, 'dashboardThreadId', 'threadId', 'id');
$dashboardApiUrl = wc_field($body, 'dashboardApiUrl');
$contentType    = strtolower(trim(wc_field($body, 'contentType', 'content_type')));
$executionId    = 'local-' . uniqid();

// ── Validate ────────────────────────────────────────────────────
if ($dashboardApiUrl !== '' && $threadId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Fehlende Thread-ID im Webhook-Payload.']);
    exit;
}

// ── Antwort sofort senden ────────────────────────────────────────
http_response_code(200);
echo json_encode(['success' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

// ================================================================
// AB HIER: HINTERGRUNDVERARBEITUNG
// ================================================================

// ── Hilfsfunktionen ─────────────────────────────────────────────

function wc_dashboard_update(string $url, int $tid, string $status, string $label, int $pct, string $wfId, string $execId, array $extra = []): void {
    if (!$url || $tid <= 0) return;
    $data = array_merge([
        'action'          => 'update_thread_status',
        'threadId'        => $tid,
        'status'          => $status,
        'statusLabel'     => $label,
        'progressPercent' => $pct,
        'workflowId'      => $wfId,
        'executionId'     => $execId,
    ], $extra);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function wc_openai(string $model, array $messages, array $opts = []): string {
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') return '';
    $payload = array_merge(['model' => $model, 'messages' => $messages], $opts);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
    ]);
    $resp    = curl_exec($ch);
    $decoded = json_decode($resp ?: '', true);
    curl_close($ch);
    return (string) ($decoded['choices'][0]['message']['content'] ?? '');
}

function wc_dataforseo(string $endpoint, array $payload): array {
    if (!defined('DFS_LOGIN') || !defined('DFS_PASSWORD')) return [];
    $ch = curl_init('https://api.dataforseo.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(DFS_LOGIN . ':' . DFS_PASSWORD),
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp ?: '', true) ?? [];
}

// ── SERP word-count helpers ──────────────────────────────────
function wc_count_words_from_url(string $url): ?int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 8,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 2,
        CURLOPT_USERAGENT       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => 0,
        CURLOPT_ENCODING        => 'gzip, deflate, br',
        CURLOPT_HTTPHEADER      => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: de-DE,de;q=0.9'],
    ]);
    $html     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $httpCode < 200 || $httpCode >= 300 || strlen($html) < 500) return null;

    // Strip non-content sections
    $html = preg_replace('/<(head|script|style|nav|footer|header|aside|noscript|iframe|figure)[^>]*>.*?<\/\1>/si', '', $html);
    $text = html_entity_decode(strip_tags($html), ENT_HTML5 | ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim($text));

    // Unicode word count (handles German umlauts)
    preg_match_all('/\b[\w\xc3\x84\xc3\x96\xc3\x9c\xc3\xa4\xc3\xb6\xc3\xbc\xc3\x9f]+\b/u', $text, $m);
    $count = count($m[0] ?? []);
    return $count > 80 ? $count : null;
}

function wc_serp_word_count(array $primaryKw, string $location, string $language): array {
    // Use first primary keyword as SERP query
    $serpKw = $primaryKw[0] ?? '';
    if ($serpKw === '') return ['targetWordCount' => null, 'info' => '', 'counts' => []];

    $serpResult = wc_dataforseo('/v3/serp/google/organic/live/advanced', [[
        'keyword'               => $serpKw,
        'location_name'         => $location,
        'language_name'         => $language,
        'device'                => 'desktop',
        'os'                    => 'windows',
        'depth'                 => 10,
        'calculate_rectangles' => false,
    ]]);

    $items = $serpResult['tasks'][0]['result'][0]['items'] ?? [];
    $urls  = [];
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'organic' && !empty($item['url'])) {
            $urls[] = $item['url'];
            if (count($urls) >= 8) break;
        }
    }

    $counts = [];
    foreach ($urls as $url) {
        $wc = wc_count_words_from_url($url);
        if ($wc !== null) $counts[] = $wc;
    }

    if (empty($counts)) return ['targetWordCount' => null, 'info' => '', 'counts' => []];

    $avg    = array_sum($counts) / count($counts);
    $target = (int) round($avg * 1.2);
    $info   = sprintf(
        'Durchschnittliche Textlaenge der Top-%d SERP-Ergebnisse fuer "%s": %d Woerter. Empfohlene Ziel-Laenge (Durchschnitt x 1,2): %d Woerter.',
        count($counts), $serpKw, (int) round($avg), $target
    );
    return ['targetWordCount' => $target, 'info' => $info, 'counts' => $counts, 'keyword' => $serpKw, 'avg' => (int) round($avg)];
}


// ── Google Drive / Docs helper functions ──────────────────────

function wc_b64u(string $d): string {
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

function wc_google_jwt(array $sa): string {
    $now  = time();
    $h    = wc_b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $c    = wc_b64u(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/documents',
        'aud'   => $sa['token_uri'],
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $base = $h . '.' . $c;
    openssl_sign($base, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
    return $base . '.' . wc_b64u($sig);
}

function wc_google_access_token(string $saPath): ?string {
    $sa = json_decode(file_get_contents($saPath), true);
    if (!is_array($sa) || empty($sa['client_email'])) return null;
    $jwt = wc_google_jwt($sa);
    $ch  = curl_init($sa['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $resp['access_token'] ?? null;
}

function wc_md_inline(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    $text = preg_replace('/\*\*\*(.+?)\*\*\*/u', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/u',     '<strong>$1</strong>',          $text);
    $text = preg_replace('/\*(.+?)\*/u',          '<em>$1</em>',                 $text);
    $text = preg_replace('/`([^`]+)`/u',          '<code>$1</code>',             $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '<a href="$2">$1</a>',   $text);
    return $text;
}

function wc_markdown_to_html(string $md): string {
    $lines   = explode("\n", str_replace("\r\n", "\n", $md));
    $html    = '';
    $inUl    = false;
    $inOl    = false;
    $inTable = false;
    $firstTr = true;

    $closeList = function () use (&$html, &$inUl, &$inOl): void {
        if ($inUl) { $html .= "</ul>\n"; $inUl = false; }
        if ($inOl) { $html .= "</ol>\n"; $inOl = false; }
    };

    foreach ($lines as $raw) {
        $line = $raw;

        // Table row
        if (preg_match('/^\|(.+)\|$/', $line)) {
            // Skip separator rows like |---|---|
            if (preg_match('/^\|[\s\-\|:]+\|$/', $line)) {
                $firstTr = false;
                continue;
            }
            $closeList();
            if (!$inTable) {
                $html .= "<table>\n";
                $inTable = true;
                $firstTr = true;
            }
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            $tag   = $firstTr ? 'th' : 'td';
            $html .= '<tr>' . implode('', array_map(
                fn($c) => "<{$tag}>" . wc_md_inline($c) . "</{$tag}>",
                $cells
            )) . "</tr>\n";
            continue;
        }
        if ($inTable) {
            $html .= "</table>\n";
            $inTable = false;
            $firstTr = true;
        }

        // Headings
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $closeList();
            $lvl  = strlen($m[1]);
            $html .= "<h{$lvl}>" . wc_md_inline($m[2]) . "</h{$lvl}>\n";
            continue;
        }

        // Horizontal rule
        if (preg_match('/^[-*_]{3,}$/', trim($line))) {
            $closeList();
            $html .= "<hr>\n";
            continue;
        }

        // Unordered list
        if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) {
            if ($inOl) { $html .= "</ol>\n"; $inOl = false; }
            if (!$inUl) { $html .= "<ul>\n"; $inUl = true; }
            $html .= '<li>' . wc_md_inline($m[1]) . "</li>\n";
            continue;
        }

        // Ordered list
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            if ($inUl) { $html .= "</ul>\n"; $inUl = false; }
            if (!$inOl) { $html .= "<ol>\n"; $inOl = true; }
            $html .= '<li>' . wc_md_inline($m[1]) . "</li>\n";
            continue;
        }

        // Blank line
        if (trim($line) === '') {
            $closeList();
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s+(.+)$/', $line, $m)) {
            $closeList();
            $html .= '<blockquote>' . wc_md_inline($m[1]) . "</blockquote>\n";
            continue;
        }

        // Paragraph
        $closeList();
        $html .= '<p>' . wc_md_inline($line) . "</p>\n";
    }

    $closeList();
    if ($inTable) $html .= "</table>\n";

    return "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"></head><body>\n{$html}</body></html>";
}

function wc_create_google_doc(string $token, string $title, string $htmlContent, string $folderId): ?string {
    $boundary = 'gdoc_boundary_' . uniqid();
    $meta     = json_encode([
        'name'     => $title,
        'mimeType' => 'application/vnd.google-apps.document',
        'parents'  => [$folderId],
    ]);
    $body =
        "--{$boundary}\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . $meta . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . $htmlContent . "\r\n"
        . "--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            "Content-Type: multipart/related; boundary=\"{$boundary}\"",
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $resp  = json_decode(curl_exec($ch), true);
    $err   = curl_error($ch);
    curl_close($ch);

    if (!empty($resp['webViewLink'])) return $resp['webViewLink'];
    if (!empty($resp['id']))         return 'https://docs.google.com/document/d/' . $resp['id'] . '/edit';
    return null;
}

function wc_country_prompt(string $haystack): array {
    $norm = function (string $v): string {
        $v = iconv('UTF-8', 'ASCII//TRANSLIT', $v) ?: $v;
        return strtolower(trim(preg_replace('/\s+/', ' ', $v)));
    };
    $h = $norm($haystack);
    $generic = implode("\n", [
        'Du bist der Content- und SEO-Agent fuer europamaut.com.',
        'europamaut.com hilft Fahrern, Reisenden und Unternehmen bei Themen wie Vignette, Maut, Streckenregelungen, Sondermaut, Grenzuebergaengen und Reisevorbereitung in Europa.',
        'Schreibe sachlich, hilfreich, vertrauenswuerdig und praezise.',
        'Keine Werbesprache, keine uebertriebenen Versprechen, keine Fuellsaetze.',
        'Inhalte muessen suchmaschinenorientiert, aber natuerlich lesbar sein.',
        'Fokus auf klare Suchintention, saubere Struktur, relevante Zwischenueberschriften und praktische Antworten.',
        'Wenn sinnvoll, nutze tabellarische Uebersichten und Checklisten in Markdown.',
    ]);
    $countries = [
        ['label' => 'Oesterreich', 'aliases' => ['osterreich','oesterreich','austria','österreich'],
         'prompt' => 'Beruecksichtige fuer Oesterreich insbesondere Vignette, digitale Vignette, Streckenmaut, Sondermaut, Tunnel- und Passmaut sowie die Rolle der ASFINAG.'],
        ['label' => 'Tschechien',  'aliases' => ['tschechien','czech republic','czechia','tschechische republik'],
         'prompt' => 'Beruecksichtige fuer Tschechien insbesondere elektronische Vignette, mautpflichtige Autobahnen, Ausnahmen, Fahrzeugklassen und den digitalen Kaufprozess.'],
        ['label' => 'Schweiz',     'aliases' => ['schweiz','switzerland'],
         'prompt' => 'Beruecksichtige fuer die Schweiz insbesondere die Autobahnvignette, Gueltigkeitsdauer und Besonderheiten bei Fahrzeugen und Anhaengern.'],
        ['label' => 'Slowenien',   'aliases' => ['slowenien','slovenia'],
         'prompt' => 'Beruecksichtige fuer Slowenien insbesondere E-Vignette, Autobahn- und Schnellstrassenpflicht, Fahrzeugklassen und typische Transitrouten.'],
        ['label' => 'Rumaenien',   'aliases' => ['rumanien','rumaenien','romania','rumänien'],
         'prompt' => 'Beruecksichtige fuer Rumaenien insbesondere die Rovinieta, digitale Kaufprozesse, Gueltigkeitsdauern und Kontrollmechanismen.'],
        ['label' => 'Ungarn',      'aliases' => ['ungarn','hungary'],
         'prompt' => 'Beruecksichtige fuer Ungarn insbesondere E-Matrica, Fahrzeugkategorien, County-Vignetten und nationale Vignetten.'],
        ['label' => 'Kroatien',    'aliases' => ['kroatien','croatia'],
         'prompt' => 'Beruecksichtige fuer Kroatien insbesondere streckenbezogene Maut, Ticket- oder Spurensysteme, Fahrzeugklassen, Bruecken und Tunnel.'],
        ['label' => 'Bulgarien',   'aliases' => ['bulgarien','bulgaria'],
         'prompt' => 'Beruecksichtige fuer Bulgarien insbesondere E-Vignette, Streckenkontrollen, Gueltigkeitsdauern und mautpflichtige Strecken.'],
        ['label' => 'Slowakei',    'aliases' => ['slowakei','slovakia'],
         'prompt' => 'Beruecksichtige fuer die Slowakei insbesondere E-Vignette, mautpflichtige Autobahnen und Schnellstrassen sowie Fahrzeugkategorien.'],
    ];
    foreach ($countries as $c) {
        foreach ($c['aliases'] as $alias) {
            if (str_contains($h, $norm($alias))) {
                return [
                    'label'  => $c['label'],
                    'prompt' => implode("\n", [$generic, 'Laenderfokus: ' . $c['label'] . '.', $c['prompt']]),
                ];
            }
        }
    }
    return [
        'label'  => 'Allgemein',
        'prompt' => implode("\n", [
            $generic,
            'Laenderfokus: Allgemein Europa.',
            'Wenn das Zielland nicht eindeutig erkennbar ist, bleibe eng beim uebergebenen Thema und triff keine unbelegten laenderspezifischen Aussagen.',
        ]),
    ];
}

// ── Workflow ─────────────────────────────────────────────────────

$wfId = 'local-php-content';

try {
    // 1. Dashboard: running (15 %)
    wc_dashboard_update($dashboardApiUrl, $threadId, 'running', 'Workflow gestartet', 15, $wfId, $executionId);

    // 2. Keywords via GPT-4.1-mini
    $kwPrompt = <<<PROMPT
Du bist ein SEO-Research-Agent fuer europamaut.com. Erstelle fuer das Thema "{$topic}" eine belastbare Startliste fuer eine anschliessende DataForSEO-Recherche.

Rahmen:
- Zielgruppe: {$targetAudience}
- Sprache: {$language}
- Standort: {$location}
- Zusatzkontext: {$prompt}

Liefere exakt ein einziges JSON-Objekt und nichts sonst.
Keine Markdown-Codeblocks. Keine Erklaerungen. Kein Wrapper-Feld wie "output".

Pflichtfelder:
- "primary_keywords": Array mit genau 8 Strings
- "long_tail_keywords": Array mit genau 8 Strings
- "question_keywords": Array mit genau 6 Strings
- "content_angle": String
PROMPT;

    $kwRaw = wc_openai('gpt-4.1-mini', [
        ['role' => 'user', 'content' => $kwPrompt],
    ]);

    // 3. Keywords parsen
    $kwJson = preg_replace('/^```(?:json)?\s*/i', '', trim($kwRaw));
    $kwJson = preg_replace('/\s*```$/i', '', $kwJson);
    $s = strpos($kwJson, '{'); $e = strrpos($kwJson, '}');
    if ($s !== false && $e !== false) $kwJson = substr($kwJson, $s, $e - $s + 1);
    $kw = json_decode($kwJson, true) ?? [];

    $primaryKw  = array_slice(array_values(array_filter(array_map('strval', (array) ($kw['primary_keywords']  ?? [])))), 0, 8);
    $longTailKw = array_slice(array_values(array_filter(array_map('strval', (array) ($kw['long_tail_keywords'] ?? [])))), 0, 8);
    $questionKw = array_slice(array_values(array_filter(array_map('strval', (array) ($kw['question_keywords']  ?? [])))), 0, 6);
    $angle      = (string) ($kw['content_angle'] ?? 'Ratgeber mit Fokus auf Suchintention, Mautregeln und praktische Reisevorbereitung.');

    // 4. DataForSEO – Search Volume & Keyword Difficulty pro Keyword
    $volumeMap = [];
    $diffMap   = [];
    foreach ($primaryKw as $kword) {
        $volR  = wc_dataforseo('/v3/keywords_data/google/search_volume/live', [[
            'location_name' => $location, 'language_name' => $language, 'keywords' => [$kword],
        ]]);
        $vTask = $volR['tasks'][0] ?? [];
        $vRes  = $vTask['result'][0] ?? null;
        $volumeMap[$kword] = [
            'search_volume' => $vRes['search_volume'] ?? null,
            'cpc'           => $vRes['cpc']           ?? null,
            'competition'   => $vRes['competition']   ?? null,
        ];

        $difR  = wc_dataforseo('/v3/dataforseo_labs/google/bulk_keyword_difficulty/live', [[
            'location_name' => $location, 'language_name' => $language, 'keywords' => [$kword],
        ]]);
        $dTask = $difR['tasks'][0] ?? [];
        $dRes  = $dTask['result'][0] ?? null;
        $diffMap[$kword] = $dRes['items'][0]['keyword_difficulty'] ?? null;
    }

    // 5. Dashboard: research (40 %)
    wc_dashboard_update($dashboardApiUrl, $threadId, 'research', 'Recherche laeuft', 40, $wfId, $executionId);

    // 6. Ratgeber: SERP-Wortlaengen-Analyse
    $serpWordData = ['targetWordCount' => null, 'info' => '', 'counts' => []];
    if ($contentType === 'ratgeber' && !empty($primaryKw)) {
        wc_dashboard_update($dashboardApiUrl, $threadId, 'research', 'Wettbewerber-Textlaenge wird analysiert...', 52, $wfId, $executionId);
        $serpWordData = wc_serp_word_count($primaryKw, $location, $language);
    }

    // 7. Laender-Systemprompt bestimmen
    $haystack        = implode(' | ', array_filter([$formTitle, $topic, $prompt, $systemPrompt]));
    $countryResult   = wc_country_prompt($haystack);
    $detectedCountry = $countryResult['label'];
    $defaultSP       = $countryResult['prompt'];
    $effectiveSP     = (trim($systemPrompt) === '' || strtolower(trim($systemPrompt)) === strtolower(trim($defaultSP)))
        ? $defaultSP
        : $systemPrompt;

    // 8. Dashboard: writing (65 %)
    wc_dashboard_update($dashboardApiUrl, $threadId, 'writing', 'Content wird geschrieben', 65, $wfId, $executionId);

    // 9. SEO-Content via GPT-5.5 generieren
    $researchData = [
        'topic'            => $topic,
        'form_title'       => $formTitle,
        'language'         => $language,
        'location'         => $location,
        'target_audience'  => $targetAudience,
        'detected_country' => $detectedCountry,
        'content_type'     => $contentType ?: 'general',
        'primary_keywords' => $primaryKw,
        'long_tail_keywords' => $longTailKw,
        'question_keywords'  => $questionKw,
        'content_angle'    => $angle,
        'seo_metrics'      => $volumeMap,
        'difficulty'       => $diffMap,
        'target_word_count' => $serpWordData['targetWordCount'],
    ];
    $researchJson = json_encode($researchData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $contentTypeHint = '';
    if ($contentType === 'ratgeber') {
        $contentTypeHint = "\nInhaltstyp: Ratgeber (umfassend, strukturiert, FAQ-Pflicht, Tabellen und Checklisten wo sinnvoll)";
        if (!empty($serpWordData['targetWordCount'])) {
            $contentTypeHint .= "\nZiel-Textlaenge: ca. {$serpWordData['targetWordCount']} Woerter (SERP-Analyse: Durchschnitt {$serpWordData['avg']} Woerter x 1,2). Schreibe entsprechend umfangreich und vollstaendig. Kuerze nicht.";
        }
    } elseif ($contentType === 'news') {
        $contentTypeHint = "\nInhaltstyp: News (aktuell, praegnant, direkt auf den Punkt, kein unnoetig langes Fuellwerk)";
    }

    $contentUserPrompt = "Du bist der Content- und SEO-Agent fuer europamaut.com.\n\nVerbindlicher Systemkontext:\n{$effectiveSP}\n\nAufgabe: Erstelle auf Basis des Themas, der Zielgruppe, des User-Prompts und der DataForSEO-Recherche einen SEO-optimierten Beitrag fuer europamaut.com in sauberem Markdown.{$contentTypeHint}\n\nThema: {$topic}\nFormular-Titel: {$formTitle}\nErkanntes Land: {$detectedCountry}\nZielgruppe: {$targetAudience}\nSprache: {$language}\nStandort: {$location}\nUser-Prompt: {$prompt}\n\nDer Inhalt muss enthalten:\n1. SEO-Titel\n2. Meta-Description\n3. H1\n4. Einleitung\n5. Hauptteil mit mehreren H2/H3-Abschnitten\n6. FAQ\n7. Fazit\n\nWichtige Regeln:\n- Schreibe vollstaendig in {$language}.\n- Nutze die recherchierten Keywords sinnvoll und natuerlich.\n- Beantworte die wichtigsten Nutzerfragen direkt und konkret.\n- Gib nur den finalen Markdown-Inhalt zurueck, ohne Vorbemerkung.\n\nDatenbasis:\n```json\n{$researchJson}\n```";

    $generatedContent = wc_openai('gpt-5.5', [
        ['role' => 'system', 'content' => 'Du bist der Content- und SEO-Agent fuer europamaut.com. Liefere nur den finalen Markdown-Inhalt ohne Vorbemerkung.'],
        ['role' => 'user',   'content' => $contentUserPrompt],
    ]);

    // 10. Recherche-Sektion aufbauen
    $fmt = static function ($v): string {
        if ($v === null || $v === '') return '-';
        if (is_int($v))   return (string) $v;
        if (is_float($v)) return number_format($v, 2, '.', '');
        return (string) $v;
    };
    $metricRows = array_map(static function (string $kword) use ($volumeMap, $diffMap, $fmt): string {
        return sprintf('| %s | %s | %s | %s | %s |',
            $kword,
            $fmt($volumeMap[$kword]['search_volume'] ?? null),
            $fmt($volumeMap[$kword]['cpc']           ?? null),
            $fmt($volumeMap[$kword]['competition']   ?? null),
            $fmt($diffMap[$kword]                   ?? null)
        );
    }, $primaryKw);
    $bullets = static fn (array $arr): string => $arr
        ? implode("\n", array_map(static fn ($i) => '- ' . $i, $arr))
        : '- -';

    $researchContent = implode("\n", array_merge(
        ['# Recherche', '', '## Eingabe aus dem Formular', '',
         '- Titel: '        . $formTitle,
         '- Mitarbeiter: '  . ($assignee ?: '-'),
         '- Thema: '        . ($topic    ?: '-'),
         '- Sprache: '      . $language,
         '- Standort: '     . $location,
         '- Zielgruppe: '   . $targetAudience,
         '- Prompt: '       . ($prompt   ?: '-'),
         '',
         '## Primaere Keywords mit DataForSEO-Metriken', '',
         '| Keyword | Suchvolumen | CPC | Wettbewerb | Difficulty |',
         '|---|---:|---:|---:|---:|'],
        $metricRows ?: ['| - | - | - | - | - |'],
        ['',
         '## Long-Tail-Keywords', '', $bullets($longTailKw),
         '',
         '## Fragen-Keywords', '', $bullets($questionKw),
         '',
         '## Content Angle', '', $angle,
        ]
    ));

    if (!empty($serpWordData['info'])) {
        $researchContent .= "\n\n## Wettbewerber-Textlaenge (SERP-Analyse)\n\n" . $serpWordData['info'];
        if (!empty($serpWordData['counts'])) {
            $researchContent .= "\n\nEinzelne Textlaengen: " . implode(', ', array_map(fn($c) => $c . ' Woerter', $serpWordData['counts'])) . '.';
        }
    }

    // 11. Dateiname
    $safeTitle = trim(preg_replace('/\s+/', ' ', preg_replace('/[\\\\\/:\*\?"<>|]/', ' ', $formTitle))) ?: 'content';
    $empMap    = ['michelle'=>'Michelle','milena'=>'Milena','frank'=>'Frank','annchristin'=>'Ann-Christin','thomas'=>'Thomas'];
    $empKey    = preg_replace('/[^a-z]/', '', strtolower($assignee));
    $empName   = $empMap[$empKey] ?? ($assignee ?: '');
    $dateLabel = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y');
    $fileName  = ($empName ? "n8n {$empName}: " : 'n8n: ') . $safeTitle . " ({$dateLabel})";

    // 12. Dashboard: publishing (85 %) – Google Doc hochladen
    wc_dashboard_update($dashboardApiUrl, $threadId, 'publishing', 'Dokument wird erstellt', 85, $wfId, $executionId);

    $documentUrl = '';
    $saPath      = __DIR__ . '/service_account.json';
    $driveFolderId = '1y2YCAzvSV0WpWU-DTEueG3Au_9SiobIu';
    if (is_file($saPath) && $generatedContent !== '') {
        $gToken = wc_google_access_token($saPath);
        if ($gToken) {
            $htmlContent  = wc_markdown_to_html($generatedContent);
            $documentUrl  = wc_create_google_doc($gToken, $fileName, $htmlContent, $driveFolderId) ?? '';
        }
    }

    // 13. Dashboard: completed (100 %)
    wc_dashboard_update($dashboardApiUrl, $threadId, 'completed', 'Content fertig generiert', 100, $wfId, $executionId, [
        'documentUrl'      => $documentUrl,
        'documentName'     => $fileName,
        'generatedContent' => $generatedContent,
    ]);

} catch (Throwable $e) {
    wc_dashboard_update($dashboardApiUrl, $threadId, 'failed', 'Workflow fehlgeschlagen', 100, $wfId, $executionId, [
        'errorMessage' => substr($e->getMessage(), 0, 500),
    ]);
}
