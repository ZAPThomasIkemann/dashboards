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

$body        = json_decode((string) file_get_contents('php://input'), true) ?: [];
$mode        = (string) ($body['mode'] ?? 'stuck_batches');
$stuckAfterH = max(1, (int) ($body['stuck_after_hours'] ?? 1));

$pdo         = db();
$totalReset  = 0;
$details     = [];

/*
 * Mode: stuck_batches (default / safe)
 * Resets only domains that were claimed by an n8n batch workflow
 * (processing_step = 'batch_claimed') and have not been updated
 * for at least $stuckAfterH hours.
 *
 * This is safe to call at any time because:
 *  - The Python DB poller uses step='db_poll' (not 'batch_claimed')
 *  - Step 'batch_claimed' is set exclusively by the n8n workflow
 *  - An active n8n claim updates the row within seconds; any claim
 *    older than 1 hour has been orphaned.
 *
 * Mode: all_processing
 * Resets ALL rows currently in processing status that have not been
 * updated for at least $stuckAfterH hours.  More aggressive — use
 * only as a recovery tool.
 */

// ── competitor_domains ────────────────────────────────────────────────────
try {
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'competitor_domains'");
    if ($checkStmt && $checkStmt->rowCount() > 0) {

        if ($mode === 'all_processing') {
            $domainSql = "
                UPDATE competitor_domains
                SET status              = 'queued',
                    processing_step     = NULL,
                    processing_run_id   = NULL,
                    batch_processing    = 0,
                    updated_at          = NOW()
                WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('processing', 'dispatching', 'claimed', 'running')
                  AND updated_at < NOW() - INTERVAL ? HOUR
            ";
        } else {
            // stuck_batches: only n8n batch_claimed orphans
            $domainSql = "
                UPDATE competitor_domains
                SET status              = 'queued',
                    processing_step     = NULL,
                    processing_run_id   = NULL,
                    batch_processing    = 0,
                    updated_at          = NOW()
                WHERE LOWER(TRIM(COALESCE(status, ''))) = 'processing'
                  AND LOWER(TRIM(COALESCE(processing_step, ''))) = 'batch_claimed'
                  AND updated_at < NOW() - INTERVAL ? HOUR
            ";
        }

        $domainStmt = $pdo->prepare($domainSql);
        $domainStmt->execute([$stuckAfterH]);
        $count = $domainStmt->rowCount();
        $totalReset += $count;
        $details['competitor_domains'] = $count;

    }
} catch (Exception $e) {
    $details['competitor_domains_error'] = $e->getMessage();
}

// ── competitor_backlink_inventory ─────────────────────────────────────────
// Reset inventory rows whose email_status is stuck in processing/dispatching.
// We only touch rows older than $stuckAfterH hours to avoid interrupting an
// active Python-poller enrichment that keeps the same status for ~10 seconds.
try {
    $inventorySql = "
        UPDATE competitor_backlink_inventory
        SET email_status = NULL,
            updated_at   = NOW()
        WHERE LOWER(TRIM(COALESCE(email_status, ''))) IN ('processing', 'dispatching')
          AND updated_at < NOW() - INTERVAL ? HOUR
    ";
    $inventoryStmt = $pdo->prepare($inventorySql);
    $inventoryStmt->execute([$stuckAfterH]);
    $count = $inventoryStmt->rowCount();
    $totalReset += $count;
    $details['competitor_backlink_inventory'] = $count;
} catch (Exception $e) {
    $details['competitor_backlink_inventory_error'] = $e->getMessage();
}

// ── Response ─────────────────────────────────────────────────────────────
echo json_encode([
    'success'          => true,
    'mode'             => $mode,
    'stuck_after_hours'=> $stuckAfterH,
    'reset_count'      => $totalReset,
    'details'          => $details,
    'message'          => $totalReset > 0
        ? "{$totalReset} feststeckende Domain(s) zurück in den Queue gesetzt."
        : 'Keine feststeckenden Domains gefunden.',
    'generated_at'     => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
