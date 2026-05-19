<?php
/**
 * workflow_questions.php
 * Generiert Rückfragen zu einem Content-Brief via OpenAI oder Anthropic.
 */
set_time_limit(30);
ignore_user_abort(false);

foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../zap/config.php'] as $cp) {
    if (is_file($cp)) { require_once $cp; break; }
}
require_once __DIR__ . '/ai.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Nur POST erlaubt']);
    exit;
}

$body    = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
$topic   = trim((string) ($body['topic']   ?? $body['title'] ?? ''));
$prompt  = trim((string) ($body['prompt']  ?? ''));
$country = trim((string) ($body['country'] ?? ''));

if ($topic === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Thema fehlt']);
    exit;
}

$hasOpenAI    = defined('OPENAI_API_KEY')    && OPENAI_API_KEY    !== '';
$hasAnthropic = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
if (!$hasOpenAI && !$hasAnthropic) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Kein AI API Key konfiguriert']);
    exit;
}

$countryLine = $country ? "\nZielland: {$country}" : '';
$promptLine  = $prompt  ? "\n\nBriefing:\n{$prompt}" : '';

$userMsg = "Du hilfst beim Erstellen von SEO-Content für europamaut.com. Ein Mitarbeiter hat folgendes Thema eingegeben:\n\nThema: {$topic}{$countryLine}{$promptLine}\n\nStelle genau 4 präzise Rückfragen, die dir helfen würden, einen deutlich besseren Artikel zu schreiben. Die Fragen sollen kurz, konkret und leicht beantwortbar sein.\n\nAntworte ausschließlich als JSON-Array mit genau 4 deutschen Frage-Strings. Kein Text außenrum.\nBeispiel: [\"Frage 1?\", \"Frage 2?\", \"Frage 3?\", \"Frage 4?\"]";

$content = trim(ai_chat([
    ['role' => 'system', 'content' => 'Du bist ein präziser Content-Strategie-Assistent. Antworte ausschließlich als valides JSON-Array.'],
    ['role' => 'user',   'content' => $userMsg],
], 'fast'));

// Markdown-Code-Fences entfernen
$content = preg_replace('/^```(?:json)?\s*/i', '', $content);
$content = preg_replace('/\s*```$/i', '', $content);

// JSON-Array extrahieren
$s = strpos($content, '[');
$e = strrpos($content, ']');
if ($s !== false && $e !== false) {
    $content = substr($content, $s, $e - $s + 1);
}

$questions = json_decode($content, true);

if (!is_array($questions)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Antwort konnte nicht geparst werden', 'raw' => $content]);
    exit;
}

$questions = array_values(array_filter(array_map('trim', $questions), fn($q) => strlen($q) > 0));

if (count($questions) === 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Keine Fragen erhalten']);
    exit;
}

echo json_encode(['success' => true, 'questions' => $questions], JSON_UNESCAPED_UNICODE);
