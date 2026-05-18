<?php
/**
 * ONE-TIME RECOVERY SCRIPT
 * Marks all 'pending' entries in competitor_backlink_queue back to 'done'.
 *
 * Use case: the reset_queue action in zap_trigger.php was accidentally triggered,
 * setting all 1074 previously-completed items back to 'pending'. Since pause_backlinks=1
 * the worker has not started reprocessing them. This script restores the correct state.
 *
 * DELETE OR DISABLE THIS FILE after running it once.
 */
require_once '../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only. Send {"confirm": true} to execute.']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (empty($body['confirm'])) {
    echo json_encode(['success' => false, 'error' => 'Send {"confirm": true} to execute.']);
    exit;
}

$pdo = db();

$countStmt = $pdo->query("SELECT COUNT(*) FROM competitor_backlink_queue WHERE LOWER(status) = 'pending'");
$pendingCount = (int) $countStmt->fetchColumn();

if ($pendingCount === 0) {
    echo json_encode(['success' => true, 'updated' => 0, 'message' => 'No pending items — nothing to restore.']);
    exit;
}

$updateStmt = $pdo->prepare("
    UPDATE competitor_backlink_queue
    SET status       = 'done',
        completed_at = COALESCE(completed_at, NOW())
    WHERE LOWER(status) = 'pending'
");
$updateStmt->execute();
$updated = $updateStmt->rowCount();

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'message' => "{$updated} Einträge von 'pending' zurück auf 'done' gesetzt.",
    'note'    => 'Bitte diese Datei nach einmaliger Ausführung löschen.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
