<?php
$allowedActions = [
    'content_generate' => 'https://thomas-dev.zap-srv.com/dashboards/dmc/api/workflow_content.php',
    'content_revision' => 'https://thomas-dev.zap-srv.com/dashboards/dmc/api/workflow_revision.php',
];

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Nur POST ist erlaubt',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody ?: '', true);

if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Ungueltiger JSON-Body',
    ]);
    exit;
}

$action = trim((string) ($decoded['action'] ?? ''));
$payload = $decoded['payload'] ?? null;

if (!isset($allowedActions[$action])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Unbekannte Workflow-Aktion',
    ]);
    exit;
}

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Payload muss ein JSON-Objekt sein',
    ]);
    exit;
}

$targetUrl = $allowedActions[$action];
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($payloadJson === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Payload konnte nicht serialisiert werden',
    ]);
    exit;
}

$statusCode = 0;
$responseBody = '';
$responseHeaders = [];
$transportError = null;

if (function_exists('curl_init')) {
    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payloadJson),
        ],
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_TIMEOUT => 60,
    ]);

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $transportError = curl_error($ch);
    } else {
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);
        $responseHeaders = preg_split("/\r\n|\n|\r/", trim((string) $rawHeaders)) ?: [];
    }
    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payloadJson) . "\r\n",
            'content' => $payloadJson,
            'ignore_errors' => true,
            'timeout' => 60,
        ],
    ]);
    $responseBody = @file_get_contents($targetUrl, false, $context);
    if ($responseBody === false) {
        $transportError = 'Proxy-Request fehlgeschlagen';
    } else {
        $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
            $statusCode = (int) $matches[1];
        }
    }
}

if ($transportError !== null) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => 'Webhook konnte nicht erreicht werden',
        'details' => $transportError,
        'targetUrl' => $targetUrl,
    ]);
    exit;
}

if ($statusCode <= 0) {
    $statusCode = 502;
}

http_response_code($statusCode);
echo json_encode([
    'success' => $statusCode >= 200 && $statusCode < 300,
    'statusCode' => $statusCode,
    'targetUrl' => $targetUrl,
    'body' => $responseBody,
    'headers' => $responseHeaders,
]);
