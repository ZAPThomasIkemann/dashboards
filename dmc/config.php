<?php
// ================================================================
// DMC Dashboard – Konfiguration
// Eigenständige Config ohne Abhängigkeit vom ZAP-Verzeichnis.
// ================================================================

define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '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');

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
