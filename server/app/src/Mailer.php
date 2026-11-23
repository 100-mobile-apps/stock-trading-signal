<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Emails are queued in the database and sent by cron/send_mail.php, so API
 * requests and the scanner never wait on the mail server.
 */
final class Mailer
{
    /** @param array{html:string, text:string} $body */
    public static function queue(string $to, string $subject, array $body): int
    {
        return Db::insert('email_queue', [
            'to_email' => $to,
            'subject' => mb_substr($subject, 0, 255),
            'html' => $body['html'],
            'text_body' => $body['text'],
            'created_at' => Db::now(),
        ]);
    }

    /** Sends up to $limit pending emails. Returns [sent, failed]. */
    public static function processQueue(int $limit = 50): array
    {
        $rows = Db::all('SELECT * FROM email_queue WHERE sent_at IS NULL AND attempts < 5 ORDER BY id LIMIT ' . (int) $limit);
        $sent = $failed = 0;
        $smtp = null;
        foreach ($rows as $r) {
            try {
                if (Config::get('mail.driver', 'smtp') === 'mail') {
                    self::sendWithMail($r['to_email'], $r['subject'], $r['html'], $r['text_body']);
                } else {
                    $smtp ??= Smtp::connect();
                    $smtp->send($r['to_email'], $r['subject'], $r['html'], $r['text_body']);
                }
                Db::exec('UPDATE email_queue SET sent_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?', [Db::now(), $r['id']]);
                $sent++;
            } catch (\Throwable $e) {
                Db::exec('UPDATE email_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 250), $r['id']]);
                $failed++;
                $smtp = null; // reconnect on next email
            }
        }
        $smtp?->quit();
        return [$sent, $failed];
    }

    public static function mime(string $to, string $subject, string $html, string $text, string $from, string $fromName): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $host = parse_url((string) Config::get('base_url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeHeader($fromName) . " <$from>",
            "To: <$to>",
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$host>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    public static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function sendWithMail(string $to, string $subject, string $html, string $text): void
    {
        $from = (string) Config::get('mail.from');
        $mime = self::mime($to, $subject, $html, $text, $from, (string) Config::get('mail.from_name', 'Stock Trading Signals'));
        [$head, $body] = explode("\r\n\r\n", $mime, 2);
        // mail() adds To/Subject itself.
        $head = implode("\r\n", array_filter(explode("\r\n", $head), fn($l) => !preg_match('/^(To|Subject):/i', $l)));
        if (!mail($to, self::encodeHeader($subject), $body, $head, '-f' . $from)) {
            throw new \RuntimeException('mail() failed');
        }
    }
}

/** Tiny SMTP client (SSL/STARTTLS + AUTH LOGIN), enough for cPanel mailboxes. */
final class Smtp
{
    /** @var resource */
    private $s;

    private function __construct($socket)
    {
        $this->s = $socket;
    }

    public static function connect(): self
    {
        $host = (string) Config::get('mail.host');
        $port = (int) Config::get('mail.port', 465);
        $secure = (string) Config::get('mail.secure', 'ssl');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port";
        $sock = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new \RuntimeException("SMTP connect failed: $errstr ($errno)");
        stream_set_timeout($sock, 20);
        $c = new self($sock);
        $c->expect(220);
        $ehlo = gethostname() ?: 'localhost';
        $c->cmd("EHLO $ehlo", 250);
        if ($secure === 'tls') {
            $c->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed');
            }
            $c->cmd("EHLO $ehlo", 250);
        }
        $user = (string) Config::get('mail.user', '');
        if ($user !== '') {
            $c->cmd('AUTH LOGIN', 334);
            $c->cmd(base64_encode($user), 334);
            $c->cmd(base64_encode((string) Config::get('mail.pass', '')), 235);
        }
        return $c;
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        $from = (string) Config::get('mail.from');
        $this->cmd("MAIL FROM:<$from>", 250);
        $this->cmd("RCPT TO:<$to>", [250, 251]);
        $this->cmd('DATA', 354);
        $data = Mailer::mime($to, $subject, $html, $text, $from, (string) Config::get('mail.from_name', 'Stock Trading Signals'));
        $data = preg_replace('/^\./m', '..', $data); // dot-stuffing
        fwrite($this->s, $data . "\r\n.\r\n");
        $this->expect(250);
    }

    public function quit(): void
    {
        try {
            fwrite($this->s, "QUIT\r\n");
        } finally {
            fclose($this->s);
        }
    }

    private function cmd(string $line, int|array $expect): string
    {
        fwrite($this->s, $line . "\r\n");
        return $this->expect($expect);
    }

    private function expect(int|array $codes): string
    {
        $resp = '';
        while (($line = fgets($this->s, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, (array) $codes, true)) {
            throw new \RuntimeException('SMTP error: ' . trim($resp));
        }
        return $resp;
    }
}
