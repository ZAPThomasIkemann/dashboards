<?php
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = db();

// ── Ensure tables ─────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS email_smtp_config (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        smtp_host VARCHAR(255) NOT NULL DEFAULT '',
        smtp_port INT NOT NULL DEFAULT 587,
        smtp_user VARCHAR(255) NOT NULL DEFAULT '',
        smtp_pass VARCHAR(255) NOT NULL DEFAULT '',
        from_name VARCHAR(255) NOT NULL DEFAULT '',
        from_email VARCHAR(255) NOT NULL DEFAULT '',
        email_subject VARCHAR(500) NOT NULL DEFAULT '',
        email_template TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS email_send_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        prospect_id BIGINT UNSIGNED NOT NULL,
        to_email VARCHAR(255) NOT NULL,
        to_domain VARCHAR(255) NOT NULL,
        subject VARCHAR(500),
        body TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        error_message TEXT,
        sent_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status),
        KEY idx_prospect (prospect_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ensure outreach_status column exists
try {
    $pdo->exec("ALTER TABLE competitor_backlink_prospects ADD COLUMN outreach_status VARCHAR(32) DEFAULT NULL");
    $pdo->exec("ALTER TABLE competitor_backlink_prospects ADD COLUMN outreach_notes TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE competitor_backlink_prospects ADD KEY idx_outreach_status (outreach_status)");
} catch (Exception $e) { /* column likely exists */ }

// ── Input ─────────────────────────────────────────────────────────────────────
$mode   = trim((string) ($_GET['mode'] ?? ($_POST['mode'] ?? 'kanban')));
$method = $_SERVER['REQUEST_METHOD'];

// ─────────────────────────────────────────────────────────────────────────────
// STATS
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'stats') {
    $rows = $pdo->query("
        SELECT
            COALESCE(outreach_status, 'to_send') as status,
            COUNT(*) as cnt
        FROM competitor_backlink_prospects
        WHERE email IS NOT NULL AND email != '' AND email_status = 'found'
        GROUP BY outreach_status
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['to_send' => 0, 'sent' => 0, 'replied' => 0, 'in_discussion' => 0, 'content_agreed' => 0, 'done' => 0, 'rejected' => 0];
    foreach ($rows as $r) {
        $s = $r['status'];
        if (isset($stats[$s])) $stats[$s] = (int) $r['cnt'];
    }

    // Also count send log
    try {
        $sent_total = (int) $pdo->query("SELECT COUNT(*) FROM backlink_email_sends")->fetchColumn();
        $stats['send_log_total'] = $sent_total;
    } catch (Exception $e) { $stats['send_log_total'] = 0; }

    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// KANBAN — list prospects for a column
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'kanban') {
    $status  = trim((string) ($_GET['status'] ?? 'to_send'));
    $domain  = trim((string) ($_GET['domain'] ?? ''));
    $keyword = trim((string) ($_GET['keyword'] ?? ''));
    $limit   = min(max((int) ($_GET['limit'] ?? 50), 1), 200);
    $offset  = max((int) ($_GET['offset'] ?? 0), 0);

    $where = ["email IS NOT NULL", "email != ''", "email_status = 'found'"];
    $params = [];

    if ($status === 'to_send') {
        $where[] = "(outreach_status IS NULL OR outreach_status = 'to_send')";
    } else {
        $where[] = "outreach_status = ?";
        $params[] = $status;
    }

    if ($domain !== '') {
        $where[] = "(competitor_domain LIKE ? OR referring_domain LIKE ?)";
        $params[] = '%' . $domain . '%';
        $params[] = '%' . $domain . '%';
    }
    if ($keyword !== '') {
        $where[] = "keyword LIKE ?";
        $params[] = '%' . $keyword . '%';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM competitor_backlink_prospects $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, competitor_domain, email, keyword, search_volume, outreach_status, outreach_notes,
               previous_rank, current_rank, referring_domain, referring_page_url, anchor,
               domain_rank_0_100, updated_at
        FROM competitor_backlink_prospects
        $whereSql
        ORDER BY domain_rank_0_100 DESC, search_volume DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'rows'    => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'   => $total,
        'status'  => $status,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// MOVE — update outreach_status for one prospect
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'move' && $method === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = (int) ($body['id'] ?? 0);
    $newStatus = trim((string) ($body['status'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $allowed = ['to_send', 'sent', 'replied', 'in_discussion', 'content_agreed', 'done', 'rejected'];

    if (!$id || !in_array($newStatus, $allowed, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid id or status']); exit;
    }

    $statusVal = ($newStatus === 'to_send') ? null : $newStatus;
    $pdo->prepare("UPDATE competitor_backlink_prospects SET outreach_status = ?, outreach_notes = IF(? = '', outreach_notes, ?) WHERE id = ?")
        ->execute([$statusVal, $notes, $notes, $id]);

    echo json_encode(['success' => true, 'id' => $id, 'status' => $newStatus]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SMTP CONFIG — GET
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'smtp_config' && $method === 'GET') {
    $row = $pdo->query("SELECT * FROM email_smtp_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['smtp_pass'] = $row['smtp_pass'] ? '••••••••' : '';
    }
    echo json_encode(['success' => true, 'config' => $row ?: null]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SMTP CONFIG — SAVE
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'smtp_config' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $host     = trim((string) ($body['smtp_host'] ?? ''));
    $port     = max(1, min(65535, (int) ($body['smtp_port'] ?? 587)));
    $user     = trim((string) ($body['smtp_user'] ?? ''));
    $pass     = trim((string) ($body['smtp_pass'] ?? ''));
    $fromName = trim((string) ($body['from_name'] ?? ''));
    $fromEmail = trim((string) ($body['from_email'] ?? ''));
    $subject  = trim((string) ($body['email_subject'] ?? ''));
    $template = trim((string) ($body['email_template'] ?? ''));

    $existing = $pdo->query("SELECT id, smtp_pass FROM email_smtp_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $actualPass = ($pass === '••••••••') ? $existing['smtp_pass'] : $pass;
        $pdo->prepare("UPDATE email_smtp_config SET smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass=?, from_name=?, from_email=?, email_subject=?, email_template=? WHERE id=?")
            ->execute([$host, $port, $user, $actualPass, $fromName, $fromEmail, $subject, $template, $existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO email_smtp_config (smtp_host,smtp_port,smtp_user,smtp_pass,from_name,from_email,email_subject,email_template) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$host, $port, $user, $pass, $fromName, $fromEmail, $subject, $template]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SEND LOG — emails already sent
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'send_log') {
    $limit  = min((int) ($_GET['limit'] ?? 100), 500);
    $domain = trim((string) ($_GET['domain'] ?? ''));
    $where  = [];
    $params = [];
    if ($domain !== '') { $where[] = 'domain LIKE ?'; $params[] = '%' . $domain . '%'; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT * FROM backlink_email_sends $whereSql ORDER BY COALESCE(sent_at, created_at) DESC LIMIT $limit");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_SLASHES);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// QUEUE SEND — mark items for sending (queued_to_send → picked up by ZAP 5)
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'queue_send' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids  = array_filter(array_map('intval', (array) ($body['ids'] ?? [])));

    if (empty($ids)) { echo json_encode(['success' => false, 'error' => 'No IDs']); exit; }

    $config = $pdo->query("SELECT * FROM email_smtp_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$config || !$config['smtp_host']) {
        echo json_encode(['success' => false, 'error' => 'SMTP not configured. Configure SMTP first.']); exit;
    }

    $stmt = $pdo->prepare("
        UPDATE competitor_backlink_prospects
        SET outreach_status = 'queued_to_send'
        WHERE id = ? AND (outreach_status IS NULL OR outreach_status = 'to_send')
    ");
    $queued = 0;
    foreach ($ids as $id) {
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) $queued++;
    }

    echo json_encode(['success' => true, 'queued' => $queued]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SEND NOW — immediately send a single email via PHP SMTP (calls ZAP 5 worker)
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'send_now' && $method === 'POST') {
    require_once '../includes/smtp_mailer.php';
    // Re-use zap5_email_sender logic inline
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $prospectId = (int) ($body['prospect_id'] ?? 0);
    if (!$prospectId) { echo json_encode(['success' => false, 'error' => 'Missing prospect_id']); exit; }

    $config = $pdo->query("SELECT * FROM email_smtp_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$config || !$config['smtp_host']) {
        echo json_encode(['success' => false, 'error' => 'SMTP not configured']); exit;
    }

    // Delegate to zap5_email_sender.php via internal include
    ob_start();
    $_POST['mode'] = 'send_one';
    $_POST['prospect_id'] = $prospectId;
    include __DIR__ . '/zap5_email_sender.php';
    $out = ob_get_clean();
    echo $out;
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SMTP CONFIG — SAVE (with rate limit fields)
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'smtp_config_full' && $method === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $maxPerHour = max(1, min(500, (int) ($body['max_per_hour'] ?? 10)));
    $maxPerDay  = max(1, min(2000, (int) ($body['max_per_day'] ?? 50)));

    // Ensure columns exist
    try { $pdo->exec("ALTER TABLE email_smtp_config ADD COLUMN max_per_hour INT NOT NULL DEFAULT 10"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE email_smtp_config ADD COLUMN max_per_day  INT NOT NULL DEFAULT 50"); } catch (Throwable $e) {}

    $existing = $pdo->query("SELECT id, smtp_pass FROM email_smtp_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pass     = trim((string) ($body['smtp_pass'] ?? ''));
    $actualPass = ($pass === '••••••••' && $existing) ? $existing['smtp_pass'] : $pass;

    if ($existing) {
        $pdo->prepare("
            UPDATE email_smtp_config
            SET smtp_host=?,smtp_port=?,smtp_user=?,smtp_pass=?,from_name=?,from_email=?,
                email_subject=?,email_template=?,max_per_hour=?,max_per_day=?
            WHERE id=?
        ")->execute([
            trim($body['smtp_host'] ?? ''),
            max(1, min(65535, (int) ($body['smtp_port'] ?? 587))),
            trim($body['smtp_user'] ?? ''),
            $actualPass,
            trim($body['from_name'] ?? ''),
            trim($body['from_email'] ?? ''),
            trim($body['email_subject'] ?? ''),
            trim($body['email_template'] ?? ''),
            $maxPerHour, $maxPerDay,
            $existing['id'],
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO email_smtp_config
                (smtp_host,smtp_port,smtp_user,smtp_pass,from_name,from_email,email_subject,email_template,max_per_hour,max_per_day)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            trim($body['smtp_host'] ?? ''),
            max(1, min(65535, (int) ($body['smtp_port'] ?? 587))),
            trim($body['smtp_user'] ?? ''),
            $actualPass,
            trim($body['from_name'] ?? ''),
            trim($body['from_email'] ?? ''),
            trim($body['email_subject'] ?? ''),
            trim($body['email_template'] ?? ''),
            $maxPerHour, $maxPerDay,
        ]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SEND STATUS — current rate-limit counters
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'send_status') {
    try {
        $sentHour = (int) $pdo->query("
            SELECT COUNT(*) FROM backlink_email_sends
            WHERE status='sent' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR
        ")->fetchColumn();
    } catch (Throwable $e) { $sentHour = 0; }

    $berlin = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM backlink_email_sends
            WHERE status='sent' AND DATE(CONVERT_TZ(sent_at,'+00:00','+02:00')) = ?
        ");
        $stmt->execute([$berlin->format('Y-m-d')]);
        $sentDay = (int) $stmt->fetchColumn();
    } catch (Throwable $e) { $sentDay = 0; }

    $cfg = $pdo->query("SELECT max_per_hour, max_per_day FROM email_smtp_config LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode([
        'success'        => true,
        'sent_last_hour' => $sentHour,
        'sent_today'     => $sentDay,
        'max_per_hour'   => (int) ($cfg['max_per_hour'] ?? 10),
        'max_per_day'    => (int) ($cfg['max_per_day']  ?? 50),
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown mode: ' . htmlspecialchars($mode)]);
