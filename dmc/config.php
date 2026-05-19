<?php
// ================================================================
// DMC Dashboard – Konfiguration
// Eigenständige Config ohne Abhängigkeit vom ZAP-Verzeichnis.
// API-Keys und AI-Provider-Einstellungen kommen aus secrets.php (gitignored).
// ================================================================

// Secrets laden (gitignored, nie im Repository)
$_secretsFile = __DIR__ . '/../secrets.php';
if (is_file($_secretsFile)) {
    require_once $_secretsFile;
}
unset($_secretsFile);

// Fallback auf Umgebungsvariablen wenn secrets.php fehlt (z. B. CI/CD)
if (!defined('OPENAI_API_KEY'))      define('OPENAI_API_KEY',      getenv('OPENAI_API_KEY')      ?: '');
if (!defined('ANTHROPIC_API_KEY'))   define('ANTHROPIC_API_KEY',   getenv('ANTHROPIC_API_KEY')   ?: '');
if (!defined('AI_PROVIDER'))         define('AI_PROVIDER',         getenv('AI_PROVIDER')         ?: 'openai');
if (!defined('OPENAI_MODEL_FAST'))   define('OPENAI_MODEL_FAST',   'gpt-4.1-mini');
if (!defined('OPENAI_MODEL_STRONG')) define('OPENAI_MODEL_STRONG', 'gpt-5.5');
if (!defined('ANTHROPIC_MODEL_FAST'))   define('ANTHROPIC_MODEL_FAST',   'claude-3-5-haiku-20241022');
if (!defined('ANTHROPIC_MODEL_STRONG')) define('ANTHROPIC_MODEL_STRONG', 'claude-opus-4-5');

define('DB_HOST', 'localhost');
define('DB_USER', 'zapdash');
define('DB_PASS', 'Zap@Dashboard2026!');
define('DB_NAME', 'zap_dashboard');

// DataForSEO API
define('DFS_LOGIN',    'm.kluck@zap-hosting.com');
define('DFS_PASSWORD', '3c7d2b936b3f59d2');

// DMC-Domains (Europamaut-Produktfamilie)
define('DMC_DOMAINS', [
    'digitale-vignette-online.at'    => 'Digitale Vignette AT',
    'digitale-vignette-online.cz'    => 'Digitale Vignette CZ',
    'digitale-vignette-schweiz.de'   => 'Digitale Vignette Schweiz',
    'digitale-vignette-slowenien.de' => 'Digitale Vignette Slowenien',
    'digitale-vignette-ro.online'    => 'Digitale Vignette RO',
    'digitale-vignette-ungarn.de'    => 'Digitale Vignette Ungarn',
    'digitale-vignette-slowakei.de'  => 'Digitale Vignette Slowakei',
    'digitale-vignette-kroatien.de'  => 'Digitale Vignette Kroatien',
    'digitale-vignette-bulgarien.de' => 'Digitale Vignette Bulgarien',
    'europamaut.com'                 => 'EuropaMaut',
]);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
