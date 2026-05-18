<?php
/**
 * ZAP 5 — Email Sender Worker  (pure PHP SMTP, no Python required)
 *
 * Sends outreach emails to prospects in `competitor_backlink_prospects` with
 * `outreach_status = 'queued_to_send'`.
 *
 * Rate limiting:
 *   - max_per_hour  (default 10): max emails sent in any rolling 60-minute window
 *   - max_per_day   (default 50): max emails sent in the current calendar day (Europe/Berlin)
 *
 * Template variables available in subject and body:
 *   {{domain}}           referring domain name
 *   {{competitor}}       competitor domain
 *   {{keyword}}          matched keyword
 *   {{backlink_url}}     the referring page URL
 *   {{from_name}}        sender name from SMTP config
 *
 * Can be triggered:
 *   - Via dashboard "Send Batch" button (POST mode=send_batch)
 *   - Via cron: php /path/to/api/zap5_email_sender.php
 *   - Single send: POST {"mode":"send_one","prospect_id":123}
 *
 * Returns JSON.
 */
require_once '../config.php';
require_once '../includes/smtp_mailer.php';

set_time_limit(120);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$pdo  = db();
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$mode = (string) ($body['mode'] ?? ($_GET['mode'] ?? 'send_batch'));

// ── Schema ────────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS backlink_email_sends (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        prospect_id  BIGINT UNSIGNED NULL,
        to_email     VARCHAR(255)    NOT NULL,
        domain       VARCHAR(255)    NULL,
        subject      TEXT            NULL,
        sent_at      DATETIME        NULL,
        status       VARCHAR(32)     NOT NULL DEFAULT 'sent',
        error        TEXT            NULL,
        created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sent_at (sent_at),
        KEY idx_domain  (domain(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add rate limit columns to email_smtp_config if missing
try {
    $pdo->exec("ALTER TABLE email_smtp_config ADD COLUMN max_per_hour INT NOT NULL DEFAULT 10");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE email_smtp_config ADD COLUMN max_per_day  INT NOT NULL DEFAULT 50");
} catch (Throwable $e) {}

// ── Load SMTP config ──────────────────────────────────────────────────────────
$config = $pdo->query("SELECT * FROM email_smtp_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$config || !$config['smtp_host']) {
    echo json_encode(['success' => false, 'error' => 'SMTP not configured. Go to E-Mail Outreach → Settings.']);
    exit;
}

$maxPerHour = max(1, (int) ($config['max_per_hour'] ?? 10));
$maxPerDay  = max(1, (int) ($config['max_per_day']  ?? 50));

// ── Rate-limit check ──────────────────────────────────────────────────────────

function zap5_count_sent_last_hour(PDO $pdo): int {
    return (int) $pdo->query("
        SELECT COUNT(*) FROM backlink_email_sends
        WHERE status = 'sent' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR
    ")->fetchColumn();
}

function zap5_count_sent_today(PDO $pdo): int {
    $berlin = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $todayStr = $berlin->format('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM backlink_email_sends
        WHERE status = 'sent'
          AND DATE(CONVERT_TZ(sent_at, '+00:00', '+02:00')) = ?
    ");
    $stmt->execute([$todayStr]);
    return (int) $stmt->fetchColumn();
}

// ── Template expansion ────────────────────────────────────────────────────────

function zap5_expand_template(string $tpl, array $vars): string {
    foreach ($vars as $key => $value) {
        $tpl = str_replace('{{' . $key . '}}', (string) $value, $tpl);
    }
    return $tpl;
}

// ── Send one email ────────────────────────────────────────────────────────────

function zap5_send_one(PDO $pdo, array $config, array $prospect): array {
    $mailer = new SimpleSMTP(
        $config['smtp_host'],
        (int) $config['smtp_port'],
        $config['smtp_user'],
        $config['smtp_pass'],
    );

    $vars = [
        'domain'       => $prospect['referring_domain'] ?? ($prospect['competitor_domain'] ?? ''),
        'competitor'   => $prospect['competitor_domain']  ?? '',
        'keyword'      => $prospect['keyword']            ?? '',
        'backlink_url' => $prospect['referring_page_url'] ?? '',
        'from_name'    => $config['from_name']            ?? '',
    ];

    $subject  = zap5_expand_template($config['email_subject'] ?? '', $vars);
    $htmlBody = zap5_expand_template($config['email_template'] ?? '', $vars);
    $toEmail  = (string) ($prospect['email'] ?? '');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => "Invalid email: $toEmail"];
    }

    $ok = $mailer->send(
        $config['from_email'],
        $config['from_name'],
        $toEmail,
        $subject,
        $htmlBody,
    );

    // Log the attempt
    $pdo->prepare("
        INSERT INTO backlink_email_sends
            (prospect_id, to_email, domain, subject, sent_at, status, error)
        VALUES (?,?,?,?,NOW(),?,?)
    ")->execute([
        $prospect['id'],
        $toEmail,
        $vars['domain'],
        $subject,
        $ok ? 'sent' : 'failed',
        $ok ? null : $mailer->lastError,
    ]);

    if ($ok) {
        // Update prospect status
        $pdo->prepare("
            UPDATE competitor_backlink_prospects
            SET outreach_status = 'sent', updated_at = NOW()
            WHERE id = ?
        ")->execute([$prospect['id']]);
    }

    return ['success' => $ok, 'email' => $toEmail, 'error' => $ok ? null : $mailer->lastError];
}

// ── mode=status ───────────────────────────────────────────────────────────────
if ($mode === 'status') {
    $sentHour = zap5_count_sent_last_hour($pdo);
    $sentDay  = zap5_count_sent_today($pdo);
    $queued   = (int) $pdo->query("
        SELECT COUNT(*) FROM competitor_backlink_prospects
        WHERE outreach_status = 'queued_to_send' AND email IS NOT NULL AND email != ''
    ")->fetchColumn();
    $toSend   = (int) $pdo->query("
        SELECT COUNT(*) FROM competitor_backlink_prospects
        WHERE (outreach_status IS NULL OR outreach_status = 'to_send')
          AND email IS NOT NULL AND email != '' AND email_status = 'found'
    ")->fetchColumn();
    echo json_encode([
        'success'       => true,
        'sent_last_hour'=> $sentHour,
        'sent_today'    => $sentDay,
        'max_per_hour'  => $maxPerHour,
        'max_per_day'   => $maxPerDay,
        'remaining_hour'=> max(0, $maxPerHour - $sentHour),
        'remaining_day' => max(0, $maxPerDay  - $sentDay),
        'queued_to_send'=> $queued,
        'to_send_total' => $toSend,
    ]);
    exit;
}

// ── mode=send_one ─────────────────────────────────────────────────────────────
if ($mode === 'send_one') {
    $prospectId = (int) ($body['prospect_id'] ?? 0);
    if (!$prospectId) {
        echo json_encode(['success' => false, 'error' => 'Missing prospect_id']);
        exit;
    }

    $sentHour = zap5_count_sent_last_hour($pdo);
    $sentDay  = zap5_count_sent_today($pdo);
    if ($sentHour >= $maxPerHour) {
        echo json_encode(['success' => false, 'error' => "Hourly limit reached ({$sentHour}/{$maxPerHour})"]);
        exit;
    }
    if ($sentDay >= $maxPerDay) {
        echo json_encode(['success' => false, 'error' => "Daily limit reached ({$sentDay}/{$maxPerDay})"]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM competitor_backlink_prospects WHERE id = ? LIMIT 1");
    $stmt->execute([$prospectId]);
    $prospect = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prospect) {
        echo json_encode(['success' => false, 'error' => "Prospect #$prospectId not found"]);
        exit;
    }

    $result = zap5_send_one($pdo, $config, $prospect);
    echo json_encode($result);
    exit;
}

// ── mode=send_batch ───────────────────────────────────────────────────────────
$batchSize = max(1, min(50, (int) ($body['batch'] ?? ($_GET['batch'] ?? 5))));

$sentHour = zap5_count_sent_last_hour($pdo);
$sentDay  = zap5_count_sent_today($pdo);

$canSendHour = max(0, $maxPerHour - $sentHour);
$canSendDay  = max(0, $maxPerDay  - $sentDay);
$canSend     = min($batchSize, $canSendHour, $canSendDay);

if ($canSend <= 0) {
    echo json_encode([
        'success'   => false,
        'error'     => "Rate limit reached: {$sentHour}/{$maxPerHour} this hour, {$sentDay}/{$maxPerDay} today.",
        'sent_hour' => $sentHour,
        'sent_day'  => $sentDay,
    ]);
    exit;
}

// Load queued prospects
$stmt = $pdo->prepare("
    SELECT * FROM competitor_backlink_prospects
    WHERE outreach_status = 'queued_to_send'
      AND email IS NOT NULL AND email != ''
    ORDER BY domain_rank_0_100 DESC, search_volume DESC
    LIMIT ?
");
$stmt->execute([$canSend]);
$prospects = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($prospects)) {
    echo json_encode([
        'success'   => true,
        'sent'      => 0,
        'message'   => 'No prospects queued. Use "Queue for Send" in the Outreach dashboard.',
        'can_send'  => $canSend,
    ]);
    exit;
}

$results  = [];
$sent     = 0;
$failed   = 0;

foreach ($prospects as $prospect) {
    // Re-check rate limits on each iteration
    if (zap5_count_sent_last_hour($pdo) >= $maxPerHour) break;
    if (zap5_count_sent_today($pdo)     >= $maxPerDay)  break;

    $result  = zap5_send_one($pdo, $config, $prospect);
    $results[] = $result;
    if ($result['success']) $sent++;
    else $failed++;

    // Small delay between sends to avoid rate limits on receiving server
    usleep(500_000); // 0.5s
}

echo json_encode([
    'success'      => true,
    'sent'         => $sent,
    'failed'       => $failed,
    'results'      => $results,
    'sent_hour_total' => zap5_count_sent_last_hour($pdo),
    'sent_day_total'  => zap5_count_sent_today($pdo),
    'generated_at' => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
