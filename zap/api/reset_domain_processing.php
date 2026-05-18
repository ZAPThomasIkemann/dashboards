<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = db();
$totalReset = 0;
$details = [];

// Reset email_status in competitor_backlink_inventory for rows stuck in 'processing' or 'dispatching'.
// These rows never received a final status update (n8n crashed/hung) and must be re-queued.
try {
    $inventoryStmt = $pdo->prepare("
        UPDATE competitor_backlink_inventory
        SET email_status = NULL,
            updated_at   = NOW()
        WHERE LOWER(TRIM(COALESCE(email_status, ''))) IN ('processing', 'dispatching')
    ");
    $inventoryStmt->execute();
    $count = $inventoryStmt->rowCount();
    $totalReset += $count;
    $details['competitor_backlink_inventory'] = $count;
} catch (Exception $e) {
    $details['competitor_backlink_inventory_error'] = $e->getMessage();
}

// Reset competitor_domains table if it exists (may be managed by /api/competitor_domains.php)
try {
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'competitor_domains'");
    if ($checkStmt && $checkStmt->rowCount() > 0) {
        $domainStmt = $pdo->prepare("
            UPDATE competitor_domains
            SET status           = 'queued',
                batch_processing = 0,
                updated_at       = NOW()
            WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('processing', 'dispatching', 'claimed', 'running')
        ");
        $domainStmt->execute();
        $count = $domainStmt->rowCount();
        $totalReset += $count;
        $details['competitor_domains'] = $count;
    }
} catch (Exception $e) {
    // Table may not exist or may have different schema — not an error
    $details['competitor_domains_skipped'] = $e->getMessage();
}

echo json_encode([
    'success'      => true,
    'reset_count'  => $totalReset,
    'details'      => $details,
    'message'      => $totalReset > 0
        ? "{$totalReset} Domain(s) aus 'processing' zurück in den Queue gesetzt."
        : 'Keine feststeckenden Domains gefunden.',
    'generated_at' => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
