<?php
/**
 * SimpleSMTP — minimal pure-PHP SMTP mailer (no external dependencies).
 *
 * Supports:
 *  - TLS (port 587 STARTTLS) and SSL (port 465)
 *  - AUTH LOGIN
 *  - Plain-text and HTML bodies
 *
 * Usage:
 *   $mailer = new SimpleSMTP('smtp.example.com', 587, 'user@example.com', 'password');
 *   $ok = $mailer->send('from@example.com', 'From Name', 'to@example.com', 'Subject', $html, $plain);
 *   if (!$ok) error_log($mailer->lastError);
 */
class SimpleSMTP {

    public string $lastError = '';

    public function __construct(
        private readonly string $host,
        private readonly int    $port     = 587,
        private readonly string $user     = '',
        private readonly string $password = '',
        private readonly int    $timeout  = 15,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    public function send(
        string  $fromEmail,
        string  $fromName,
        string  $toEmail,
        string  $subject,
        string  $htmlBody,
        ?string $plainBody = null,
    ): bool {
        $sock = $this->connect();
        if (!$sock) return false;

        try {
            $this->cmd($sock, null); // read greeting

            // EHLO
            $this->cmd($sock, "EHLO " . ($this->getLocalHost()) . "\r\n");

            // STARTTLS if not already SSL
            if ($this->port !== 465) {
                $res = $this->cmd($sock, "STARTTLS\r\n");
                if (!str_starts_with($res, '220')) {
                    $this->lastError = "STARTTLS failed: $res";
                    fclose($sock);
                    return false;
                }
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = 'STARTTLS crypto negotiation failed';
                    fclose($sock);
                    return false;
                }
                // Re-EHLO after TLS
                $this->cmd($sock, "EHLO " . $this->getLocalHost() . "\r\n");
            }

            // AUTH LOGIN
            if ($this->user) {
                $this->cmd($sock, "AUTH LOGIN\r\n");
                $this->cmd($sock, base64_encode($this->user)     . "\r\n");
                $res = $this->cmd($sock, base64_encode($this->password) . "\r\n");
                if (!str_starts_with($res, '235')) {
                    $this->lastError = "AUTH failed: $res";
                    fclose($sock);
                    return false;
                }
            }

            // Envelope
            $this->cmd($sock, "MAIL FROM:<{$fromEmail}>\r\n");
            $res = $this->cmd($sock, "RCPT TO:<{$toEmail}>\r\n");
            if (!str_starts_with($res, '250') && !str_starts_with($res, '251')) {
                $this->lastError = "RCPT rejected: $res";
                fclose($sock);
                return false;
            }

            // DATA
            $this->cmd($sock, "DATA\r\n");
            $message = $this->buildMessage($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $plainBody);
            $res = $this->cmd($sock, $message . "\r\n.\r\n");
            if (!str_starts_with($res, '250')) {
                $this->lastError = "Message rejected: $res";
                fclose($sock);
                return false;
            }

            $this->cmd($sock, "QUIT\r\n");
            fclose($sock);
            return true;

        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            @fclose($sock);
            return false;
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return resource|false */
    private function connect() {
        $proto = ($this->port === 465) ? 'ssl' : 'tcp';
        $addr  = "{$proto}://{$this->host}:{$this->port}";
        $errno = 0; $errstr = '';
        $ctx   = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);
        $sock = @stream_socket_client($addr, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            $this->lastError = "Could not connect to {$addr}: {$errstr} ({$errno})";
            return false;
        }
        stream_set_timeout($sock, $this->timeout);
        return $sock;
    }

    /** @param resource $sock */
    private function cmd($sock, ?string $data): string {
        if ($data !== null) {
            fwrite($sock, $data);
        }
        $buf = '';
        while ($line = fgets($sock, 512)) {
            $buf .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break; // end of multi-line
        }
        return trim($buf);
    }

    private function getLocalHost(): string {
        return gethostname() ?: 'localhost';
    }

    private function buildMessage(
        string  $fromEmail,
        string  $fromName,
        string  $toEmail,
        string  $subject,
        string  $htmlBody,
        ?string $plainBody,
    ): string {
        $boundary = '=_Part_' . md5(uniqid('', true));
        $plain    = $plainBody ?? strip_tags($htmlBody);
        $fromFmt  = $fromName ? '"' . $fromName . '" <' . $fromEmail . '>' : $fromEmail;
        $date     = date('r');
        $msgId    = '<' . uniqid('', true) . '@' . (parse_url('http://' . ($this->host), PHP_URL_HOST) ?: 'mail') . '>';

        return implode("\r\n", [
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            "From: {$fromFmt}",
            "To: {$toEmail}",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: {$date}",
            "Message-ID: {$msgId}",
            "",
            "--{$boundary}",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
            "",
            chunk_split(base64_encode($plain)),
            "--{$boundary}",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
            "",
            chunk_split(base64_encode($htmlBody)),
            "--{$boundary}--",
        ]);
    }
}
