<?php
// ================================================================
// workflow_revision.php
// Lokaler Ersatz fuer n8n Workflow: zq2KYqBhq86nQ8zl
// DMC Thread Revision Workflow
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

function wr_field(array $b, string ...$keys): string {
    foreach ($keys as $k) {
        if (isset($b[$k])        && $b[$k] !== '')        return (string) $b[$k];
        if (isset($b['body'][$k]) && $b['body'][$k] !== '') return (string) $b['body'][$k];
    }
    return '';
}

$threadId           = (int)  wr_field($body, 'threadId');
$parentMessageId    = (int)  wr_field($body, 'parentMessageId');
$title              = wr_field($body, 'title');
$assignee           = wr_field($body, 'assignee');
$country            = wr_field($body, 'country');
$prompt             = wr_field($body, 'prompt');
$systemPrompt       = wr_field($body, 'systemPrompt');
$originalContent    = wr_field($body, 'originalContent');
$comment            = wr_field($body, 'comment');
$latestAiComment    = wr_field($body, 'latestAiComment');
$latestEditorRec    = wr_field($body, 'latestEditorRecommendation');
$authorName         = wr_field($body, 'authorName');
$dashboardApiUrl    = wr_field($body, 'dashboardApiUrl')
    ?: 'https://thomas-dev.zap-srv.com/dashboards/dmc/api/content_history.php';
$startedAt = time();

// ── Sofort antworten ────────────────────────────────────────────
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Revision workflow started.']);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

// ================================================================
// AB HIER: HINTERGRUNDVERARBEITUNG
// ================================================================

function wr_openai(string $model, array $messages, array $opts = []): string {
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

function wr_extract_tag(string $source, string $tag): string {
    preg_match_all('/<' . preg_quote($tag, '/') . '>([\s\S]*?)<\/' . preg_quote($tag, '/') . '>/i', $source, $m);
    return empty($m[1]) ? '' : trim(end($m[1]));
}

function wr_extract_latest_revision(string $source): string {
    preg_match_all('/^## Ueberarbeiteter Text\s*$([\s\S]*?)(?=^##\s|$)/im', $source, $m);
    return empty($m[1]) ? '' : trim(end($m[1]));
}

function wr_dashboard(string $url, array $data): void {
    if (!$url) return;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function wr_progress(string $url, int $threadId, string $status, string $label, int $pct): void {
    if (!$url || $threadId <= 0) return;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'action'          => 'update_thread_status',
            'threadId'        => $threadId,
            'status'          => $status,
            'statusLabel'     => $label,
            'progressPercent' => $pct,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

try {
    // 1. Revisionskontext vorbereiten
    $latestTagged  = wr_extract_tag($originalContent, 'revision_markdown');
    $latestSection = wr_extract_latest_revision($originalContent);
    $currentContent = $latestSection ?: ($latestTagged ?: trim($originalContent));
    $normalizedComment = trim($comment);

    // 2. Revisions-Prompt
    $sysCmt    = $latestAiComment  ?: 'Noch keiner vorhanden.';
    $sysRec    = $latestEditorRec  ?: 'Noch keine vorhanden.';
    $sysPrompt = $systemPrompt     ?: 'Nutze den uebergebenen Stil und bleibe sachlich, hilfreich und SEO-orientiert.';

    $revPrompt = <<<PROMPT
Du ueberarbeitest einen bereits bestehenden DMC-Content fuer europamaut.com.

Systemprompt:
{$sysPrompt}

Kontext:
- Titel: {$title}
- Mitarbeiter: {$assignee}
- Land: {$country}
- Urspruenglicher Prompt: {$prompt}
- Neuer Bearbeiter-Kommentar: {$normalizedComment}
- Letzter KI-Kommentar: {$sysCmt}
- Letzte Empfehlung an Bearbeiter: {$sysRec}

Aufgabe:
1. Lies den aktuellen Textstand.
2. Arbeite die Wuensche aus dem neuen Bearbeiter-Kommentar praezise ein.
3. Nutze vorhandene KI-Learnings aus frueheren Iterationen nur dann, wenn sie hilfreich sind.
4. Behalte Thema, Stil und Struktur bei, sofern der Kommentar nichts anderes verlangt.
5. Formuliere zusaetzlich einen kurzen KI-Kommentar, was verbessert wurde oder worauf noch zu achten ist.
6. Formuliere eine kurze konkrete Empfehlung an Bearbeiter, wie kuenftige Inhalte oder Briefings noch besser werden.
7. Antworte exakt in diesem Format und ohne zusaetzliche Einleitung:
<revision_markdown>
...hier nur der ueberarbeitete finale Markdown-Text...
</revision_markdown>
<ai_comment>
...kurzer KI-Kommentar...
</ai_comment>
<editor_recommendation>
...kurze Empfehlung fuer Bearbeiter...
</editor_recommendation>

Aktueller Textstand:
{$currentContent}
PROMPT;

    // 3. Fortschritt: KI-Verarbeitung gestartet
    wr_progress($dashboardApiUrl, $threadId, 'revision', 'KI ueberarbeitet Content...', 25);

    // 4. OpenAI GPT-5.5 aufrufen (medium reasoning)
    $rawOutput = wr_openai('gpt-5.5', [
        ['role' => 'system', 'content' => 'Du bist der Revisions-Agent fuer das DMC Dashboard. Setze Mitarbeiter-Feedback praezise um und liefere deine Antwort ausschliesslich im geforderten Tag-Format.'],
        ['role' => 'user',   'content' => $revPrompt],
    ], ['reasoning_effort' => 'medium']);

    // 5. Tags extrahieren
    $revisionBody = wr_extract_tag($rawOutput, 'revision_markdown') ?: $rawOutput;
    $aiComment    = wr_extract_tag($rawOutput, 'ai_comment');
    $editorRec    = wr_extract_tag($rawOutput, 'editor_recommendation');

    // 6. Fortschritt: Revision wird gespeichert
    wr_progress($dashboardApiUrl, $threadId, 'revision', 'Revision wird gespeichert...', 85);

    // 7. Body-Text zusammenbauen
    $parts = [];
    if ($normalizedComment !== '') {
        $parts[] = '## Bearbeiter-Kommentar';
        $parts[] = $normalizedComment;
        $parts[] = '';
    }
    $parts[] = '## Ueberarbeiteter Text';
    $parts[] = '';
    $parts[] = $revisionBody;
    $bodyText = trim(implode("\n", $parts));

    // 8. Dauer berechnen
    $durationSeconds = max(0, time() - $startedAt);

    // 9. Dashboard: complete_revision
    wr_dashboard($dashboardApiUrl, [
        'action'               => 'complete_revision',
        'threadId'             => $threadId,
        'parentMessageId'      => $parentMessageId,
        'authorName'           => $authorName ?: ($assignee ?: 'DMC Revision'),
        'body'                 => $bodyText,
        'aiComment'            => $aiComment,
        'editorRecommendation' => $editorRec,
        'workflowId'           => 'local-php-revision',
        'executionId'          => 'local-' . uniqid(),
        'durationSeconds'      => $durationSeconds,
    ]);

} catch (Throwable $e) {
    wr_dashboard($dashboardApiUrl, [
        'action'          => 'fail_revision',
        'threadId'        => $threadId,
        'parentMessageId' => $parentMessageId,
        'errorMessage'    => substr($e->getMessage(), 0, 500),
    ]);
}
