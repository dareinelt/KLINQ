<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Mail;

/**
 * Minimaler IMAP4rev1-Client auf Basis von PHP-Streams (kein ext-imap nötig):
 * LOGIN, SELECT, UID SEARCH UNSEEN, UID FETCH BODY.PEEK[], UID STORE, UID COPY/MOVE, EXPUNGE.
 * Unterstützt SSL (imaps), STARTTLS und Klartext (nur für Tests).
 */
final class ImapMailboxClient implements MailboxClientInterface
{
    private const FAILED_FLAG = '$HelpdeskFailed';

    /** @var resource|null */
    private $socket = null;
    private int $tag = 0;
    private bool $selected = false;
    private ?bool $supportsMove = null;

    /** @param array<string,mixed> $options host, port, encryption (ssl|starttls|none), username, password, mailbox, processed_mailbox, timeout, verify_peer */
    public function __construct(private readonly array $options) {}

    public function describe(): string
    {
        return sprintf('imap://%s@%s:%d/%s', (string) ($this->options['username'] ?? ''), (string) ($this->options['host'] ?? ''), (int) ($this->options['port'] ?? 993), $this->mailbox());
    }

    public function fetchUnprocessed(int $limit): array
    {
        $this->connect();
        $response = $this->command('UID SEARCH UNSEEN UNKEYWORD ' . self::FAILED_FLAG);
        $uids = [];
        foreach ($response as $line) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $line, $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $uid) {
                    if ($uid !== '' && ctype_digit($uid)) {
                        $uids[] = $uid;
                    }
                }
            }
        }
        $uids = array_slice($uids, 0, max(1, $limit));
        $messages = [];
        foreach ($uids as $uid) {
            $raw = $this->fetchRaw($uid);
            if ($raw !== null) {
                $messages[$uid] = $raw;
            }
        }

        return $messages;
    }

    public function markProcessed(string $uid): void
    {
        $this->connect();
        $this->command(sprintf('UID STORE %s +FLAGS.SILENT (\Seen)', $uid));
        $target = trim((string) ($this->options['processed_mailbox'] ?? ''));
        if ($target === '' || $target === $this->mailbox()) {
            return;
        }
        $quoted = $this->quote($target);
        if ($this->supportsMove()) {
            $this->command(sprintf('UID MOVE %s %s', $uid, $quoted), true);

            return;
        }
        $copied = $this->command(sprintf('UID COPY %s %s', $uid, $quoted), true);
        if ($this->isOk($copied)) {
            $this->command(sprintf('UID STORE %s +FLAGS.SILENT (\Deleted)', $uid));
            $this->command('EXPUNGE');
        }
    }

    public function markFailed(string $uid): void
    {
        $this->connect();
        $this->command(sprintf('UID STORE %s +FLAGS.SILENT (\Seen %s)', $uid, self::FAILED_FLAG), true);
    }

    public function close(): void
    {
        if ($this->socket === null) {
            return;
        }
        try {
            $this->command('LOGOUT', true);
        } catch (\Throwable) {
            // Verbindung wird ohnehin geschlossen
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->selected = false;
    }

    // ------------------------------------------------------------------ Protokoll

    private function connect(): void
    {
        if ($this->socket !== null && $this->selected) {
            return;
        }
        $host = (string) ($this->options['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('IMAP: kein Host konfiguriert (HELPDESK_IMAP_HOST).');
        }
        $encryption = strtolower((string) ($this->options['encryption'] ?? 'ssl'));
        $port = (int) ($this->options['port'] ?? ($encryption === 'ssl' ? 993 : 143));
        $timeout = max(5, (int) ($this->options['timeout'] ?? 30));
        $verify = (bool) ($this->options['verify_peer'] ?? true);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException(sprintf('IMAP: Verbindung zu %s fehlgeschlagen (%d %s).', $target, $errno, $errstr));
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new \RuntimeException('IMAP: unerwartete Begrüßung: ' . trim($greeting));
        }
        if ($encryption === 'starttls') {
            $this->command('STARTTLS');
            $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                throw new \RuntimeException('IMAP: STARTTLS-Aushandlung fehlgeschlagen.');
            }
        }
        if (!str_starts_with($greeting, '* PREAUTH')) {
            $this->command(sprintf('LOGIN %s %s', $this->quote((string) ($this->options['username'] ?? '')), $this->quote((string) ($this->options['password'] ?? ''))), false, true);
        }
        $this->command('SELECT ' . $this->quote($this->mailbox()));
        $this->selected = true;
    }

    private function fetchRaw(string $uid): ?string
    {
        $this->write(sprintf('%s UID FETCH %s (BODY.PEEK[])', $tag = $this->nextTag(), $uid));
        $raw = null;
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $line)) {
                    throw new \RuntimeException('IMAP: FETCH fehlgeschlagen: ' . trim($line));
                }
                break;
            }
            if (preg_match('/\{(\d+)\}\s*$/', $line, $m) === 1) {
                $size = (int) $m[1];
                $raw = $this->readBytes($size);
                // Rest der FETCH-Antwort (schließende Klammer) einlesen
                $this->readLine();
            }
        }

        return $raw;
    }

    /**
     * Sendet ein Kommando und liest bis zur getaggten Antwort.
     * @return array<int,string> Alle Antwortzeilen (inkl. getaggter Abschlusszeile)
     */
    private function command(string $command, bool $tolerateFailure = false, bool $sensitive = false): array
    {
        $tag = $this->nextTag();
        $this->write($tag . ' ' . $command);
        $lines = [];
        while (true) {
            $line = $this->readLine();
            $lines[] = rtrim($line, "\r\n");
            if (str_starts_with($line, $tag . ' ')) {
                break;
            }
        }
        $last = end($lines) ?: '';
        if (!$tolerateFailure && !preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $last)) {
            $shown = $sensitive ? preg_replace('/^(\S+)\s.*$/', '$1 [***]', $command) : $command;
            throw new \RuntimeException(sprintf('IMAP: „%s“ fehlgeschlagen: %s', $shown, trim($last)));
        }

        return $lines;
    }

    /** @param array<int,string> $lines */
    private function isOk(array $lines): bool
    {
        $last = end($lines) ?: '';

        return preg_match('/^\S+ OK/i', $last) === 1;
    }

    private function supportsMove(): bool
    {
        if ($this->supportsMove === null) {
            $this->supportsMove = false;
            foreach ($this->command('CAPABILITY', true) as $line) {
                if (stripos($line, ' MOVE') !== false) {
                    $this->supportsMove = true;
                }
            }
        }

        return $this->supportsMove;
    }

    private function write(string $line): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('IMAP: keine Verbindung.');
        }
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new \RuntimeException('IMAP: Senden fehlgeschlagen.');
        }
    }

    private function readLine(): string
    {
        if ($this->socket === null) {
            throw new \RuntimeException('IMAP: keine Verbindung.');
        }
        $line = fgets($this->socket);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            throw new \RuntimeException($meta['timed_out'] ? 'IMAP: Zeitüberschreitung beim Lesen.' : 'IMAP: Verbindung vom Server geschlossen.');
        }

        return $line;
    }

    private function readBytes(int $size): string
    {
        if ($this->socket === null) {
            throw new \RuntimeException('IMAP: keine Verbindung.');
        }
        $data = '';
        while (strlen($data) < $size) {
            $chunk = fread($this->socket, min(65536, $size - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('IMAP: Nachricht unvollständig empfangen.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function nextTag(): string
    {
        return sprintf('A%04d', ++$this->tag);
    }

    private function quote(string $value): string
    {
        return '"' . addcslashes($value, "\\\"\r\n") . '"';
    }

    private function mailbox(): string
    {
        $mailbox = trim((string) ($this->options['mailbox'] ?? 'INBOX'));

        return $mailbox !== '' ? $mailbox : 'INBOX';
    }
}
