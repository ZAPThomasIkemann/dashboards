<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'zapdash');
define('DB_PASS', 'Zap@Dashboard2026!');
define('DB_NAME', 'zap_dashboard');
define('SITE_NAME', 'ZAP Dashboard');
define('SITE_URL', 'https://thomas-dev.zap-srv.com');

// DataForSEO API credentials
define('DFS_LOGIN',    'm.kluck@zap-hosting.com');
define('DFS_PASSWORD', '3c7d2b936b3f59d2');

define('MONITORED_DOMAINS', [
    'zap-hosting.com'               => ['brand' => 'ZAP',  'name' => 'ZAP-Hosting'],
    'digitale-vignette-online.at'   => ['brand' => 'DMC',  'name' => 'Digitale Vignette AT'],
    'digitale-vignette-online.cz'   => ['brand' => 'DMC',  'name' => 'Digitale Vignette CZ'],
    'digitale-vignette-schweiz.de'  => ['brand' => 'DMC',  'name' => 'Digitale Vignette Schweiz'],
    'digitale-vignette-slowenien.de'=> ['brand' => 'DMC',  'name' => 'Digitale Vignette Slowenien'],
    'digitale-vignette-ro.online'   => ['brand' => 'DMC',  'name' => 'Digitale Vignette RO'],
    'digitale-vignette-ungarn.de'   => ['brand' => 'DMC',  'name' => 'Digitale Vignette Ungarn'],
    'digitale-vignette-slowakei.de' => ['brand' => 'DMC',  'name' => 'Digitale Vignette Slowakei'],
    'digitale-vignette-kroatien.de' => ['brand' => 'DMC',  'name' => 'Digitale Vignette Kroatien'],
    'digitale-vignette-bulgarien.de'=> ['brand' => 'DMC',  'name' => 'Digitale Vignette Bulgarien'],
    'europamaut.com'                => ['brand' => 'DMC',  'name' => 'EuropaMaut'],
]);

function db() {
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

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>
