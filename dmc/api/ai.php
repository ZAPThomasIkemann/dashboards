<?php
// ================================================================
// ai.php – Einheitlicher AI-Helper für OpenAI und Anthropic
// Wird von allen Workflow-Skripten eingebunden.
//
// Verwendung:
//   $text = ai_chat($messages, 'fast');    // schnelles Modell
//   $text = ai_chat($messages, 'strong');  // leistungsstarkes Modell
//
// Der aktive Anbieter wird über AI_PROVIDER gesteuert ('openai' | 'anthropic').
// ================================================================

/**
 * Sendet eine Chat-Anfrage an den konfigurierten AI-Anbieter.
 *
 * @param  array  $messages  Array von ['role' => ..., 'content' => ...]-Objekten
 * @param  string $quality   'fast' (günstig/schnell) | 'strong' (leistungsstark)
 * @param  array  $opts      Zusätzliche anbieter-spezifische Optionen (werden gefiltert)
 * @return string            Generierter Text oder '' bei Fehler / fehlendem Key
 */
function ai_chat(array $messages, string $quality = 'fast', array $opts = []): string
{
    $provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'openai';
    return $provider === 'anthropic'
        ? _ai_anthropic($messages, $quality, $opts)
        : _ai_openai($messages, $quality, $opts);
}

// ── OpenAI ────────────────────────────────────────────────────────
function _ai_openai(array $messages, string $quality, array $opts): string
{
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') return '';

    $model   = $quality === 'strong'
        ? (defined('OPENAI_MODEL_STRONG') ? OPENAI_MODEL_STRONG : 'gpt-5.5')
        : (defined('OPENAI_MODEL_FAST')   ? OPENAI_MODEL_FAST   : 'gpt-4.1-mini');
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

// ── Anthropic ─────────────────────────────────────────────────────
function _ai_anthropic(array $messages, string $quality, array $opts): string
{
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') return '';

    $model     = $quality === 'strong'
        ? (defined('ANTHROPIC_MODEL_STRONG') ? ANTHROPIC_MODEL_STRONG : 'claude-opus-4-5')
        : (defined('ANTHROPIC_MODEL_FAST')   ? ANTHROPIC_MODEL_FAST   : 'claude-sonnet-4-5');
    $maxTokens = $quality === 'strong' ? 8096 : 4096;

    // Anthropic erwartet system-Nachrichten als eigenen Parameter
    $system   = '';
    $filtered = [];
    foreach ($messages as $msg) {
        if (($msg['role'] ?? '') === 'system') {
            $system .= ($system ? "\n\n" : '') . (string) ($msg['content'] ?? '');
        } else {
            $filtered[] = $msg;
        }
    }
    if (empty($filtered)) {
        return '';
    }

    $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $filtered];
    if ($system !== '') {
        $payload['system'] = $system;
    }
    // OpenAI-spezifische Opts (z. B. reasoning_effort) werden ignoriert

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp    = curl_exec($ch);
    $decoded = json_decode($resp ?: '', true);
    curl_close($ch);
    return (string) ($decoded['content'][0]['text'] ?? '');
}
