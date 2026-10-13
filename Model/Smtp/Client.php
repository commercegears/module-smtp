<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Smtp;

/**
 * Small ESMTP client (RFC 5321) with STARTTLS, implicit TLS and AUTH PLAIN, LOGIN, CRAM-MD5 and XOAUTH2.
 *
 * It works on the raw MIME message, so it behaves the same on Magento 2.4.6/2.4.7 (Laminas Mail) and 2.4.8+
 * (Symfony Mailer). Every step is written to a transcript with secrets masked, which the admin can read when
 * something goes wrong.
 */
class Client
{
    private const MAX_TRANSCRIPT_LINES = 300;

    /** @var array<string, string> capability keyword => parameters */
    private array $capabilities = [];
    /** @var string[] */
    private array $transcript = [];
    private bool $connected = false;
    private bool $encrypted = false;
    private string $authMechanism = '';
    private int $messageCount = 0;
    /** True once the message data of the current send() went to the server (a retry could deliver it twice). */
    private bool $dataSent = false;
    /** @var array<string, string> recipient => server reply, for recipients the server refused permanently */
    private array $rejected = [];

    public function __construct(
        private readonly ConnectionSettings $settings,
        private readonly Socket $socket
    ) {
    }

    /**
     * Opens the connection, says hello, switches to TLS and signs in.
     *
     * @throws SmtpException
     */
    public function connect(): void
    {
        $settings = $this->settings;
        if ($settings->host === '') {
            throw new SmtpException('No SMTP host is set', SmtpException::STAGE_CONNECT);
        }
        if ($settings->port < 1 || $settings->port > 65535) {
            throw new SmtpException('The port must be between 1 and 65535', SmtpException::STAGE_CONNECT);
        }
        if (ConnectionSettings::isNonMailPort($settings->port)) {
            throw new SmtpException(
                sprintf('Port %d belongs to another kind of service, not to an SMTP server', $settings->port),
                SmtpException::STAGE_CONNECT
            );
        }
        $implicitTls = $settings->encryption === ConnectionSettings::ENCRYPTION_SSL;
        $remote = ($implicitTls ? 'ssl://' : 'tcp://') . $settings->host . ':' . $settings->port;
        $this->log('-- connecting to ' . $remote);
        $this->socket->open($remote, (float)$settings->timeout, $this->sslOptions());
        $this->connected = true;
        $this->encrypted = $implicitTls;

        $this->expect([220], SmtpException::STAGE_GREETING);
        $this->hello();

        if ($settings->encryption === ConnectionSettings::ENCRYPTION_STARTTLS) {
            if (!isset($this->capabilities['STARTTLS'])) {
                throw new SmtpException(
                    'The server does not offer STARTTLS on this port',
                    SmtpException::STAGE_TLS
                );
            }
            $this->command('STARTTLS');
            $this->expect([220], SmtpException::STAGE_TLS);
            $this->socket->enableCrypto();
            $this->encrypted = true;
            $this->log('-- TLS started');
            $this->hello();
        }

        $this->authenticate();
    }

    /**
     * Sends one message. Returns the server's final reply (often contains the queue ID).
     *
     * @param string[] $recipients
     * @throws SmtpException
     */
    public function send(string $from, array $recipients, string $data): string
    {
        if (!$this->connected) {
            $this->connect();
        }
        if ($recipients === []) {
            throw new SmtpException('The email has no recipients', SmtpException::STAGE_MESSAGE);
        }
        $data = $this->prepareData($data);
        $size = strlen($data);
        $params = '';
        if (isset($this->capabilities['SIZE'])) {
            $limit = (int)$this->capabilities['SIZE'];
            if ($limit > 0 && $size > $limit) {
                throw new SmtpException(
                    sprintf('The email is %s but the server accepts at most %s', self::formatBytes($size), self::formatBytes($limit)),
                    SmtpException::STAGE_MESSAGE
                );
            }
            $params .= ' SIZE=' . $size;
        }
        if (isset($this->capabilities['8BITMIME']) && preg_match('/[\x80-\xFF]/', $data) === 1) {
            $params .= ' BODY=8BITMIME';
        }
        $allAddresses = implode('', array_merge([$from], $recipients));
        if (preg_match('/[\x80-\xFF]/', $allAddresses) === 1) {
            if (!isset($this->capabilities['SMTPUTF8'])) {
                throw new SmtpException(
                    'An address contains non-ASCII characters and the server does not support SMTPUTF8',
                    SmtpException::STAGE_MESSAGE
                );
            }
            $params .= ' SMTPUTF8';
        }

        $this->dataSent = false;
        $this->rejected = [];
        $this->command('MAIL FROM:<' . self::cleanAddress($from) . '>' . $params);
        $this->expect([250], SmtpException::STAGE_MAIL_FROM);
        $accepted = 0;
        $lastRejection = null;
        foreach ($recipients as $recipient) {
            $this->command('RCPT TO:<' . self::cleanAddress($recipient) . '>');
            try {
                $this->expect([250, 251, 252], SmtpException::STAGE_RCPT);
                $accepted++;
            } catch (SmtpException $e) {
                // A permanently refused address (5xx, for example a typo) must not stop the email for the
                // other recipients, such as the store's own Bcc copy. Temporary refusals fail the whole email,
                // so a retry never sends it twice to the recipients that were accepted.
                if ($e->getReplyCode() < 500 || $e->getReplyCode() > 599) {
                    throw $e;
                }
                $this->rejected[$recipient] = self::firstLine($e->getReplyText());
                $lastRejection = $e;
            }
        }
        if ($accepted === 0 && $lastRejection !== null) {
            $this->rejected = [];
            throw $lastRejection;
        }
        $this->command('DATA');
        $this->expect([354], SmtpException::STAGE_DATA);
        $this->dataSent = true;
        $this->socket->write($data . ".\r\n");
        $this->log('C: [message, ' . self::formatBytes($size) . ']');
        $this->log('C: .');
        [, $text] = $this->expect([250], SmtpException::STAGE_DATA);
        $this->messageCount++;
        return $text;
    }

    /**
     * Resets the transaction so the next message can use the same connection.
     *
     * @throws SmtpException
     */
    public function reset(): void
    {
        $this->command('RSET');
        $this->expect([250], SmtpException::STAGE_IO);
    }

    /**
     * Sends NOOP; used to check that an idle connection still works.
     */
    public function isAlive(): bool
    {
        if (!$this->connected || !$this->socket->isOpen()) {
            return false;
        }
        try {
            $this->reset();
            return true;
        } catch (SmtpException) {
            $this->close();
            return false;
        }
    }

    public function quit(): void
    {
        if ($this->connected) {
            try {
                $this->command('QUIT');
                $this->readReply();
            } catch (SmtpException) {
                // The server may drop the connection right away; nothing to do.
            }
        }
        $this->close();
    }

    public function close(): void
    {
        $this->socket->close();
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function getAuthMechanism(): string
    {
        return $this->authMechanism;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    /**
     * Whether the message data of the last send() reached the server, even if no final reply came back.
     */
    public function wasDataSent(): bool
    {
        return $this->dataSent;
    }

    /**
     * Recipients of the last send() that the server refused permanently while accepting the others.
     *
     * @return array<string, string> address => server reply
     */
    public function getRejectedRecipients(): array
    {
        return $this->rejected;
    }

    /**
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function getTranscript(): string
    {
        return implode("\n", $this->transcript);
    }

    /**
     * @throws SmtpException
     */
    private function hello(): void
    {
        $this->command('EHLO ' . $this->settings->heloName);
        [$code, $text] = $this->readReply();
        if ($code !== 250) {
            // Very old servers only know HELO; they have no extensions.
            $this->command('HELO ' . $this->settings->heloName);
            [$code, $text] = $this->expect([250], SmtpException::STAGE_EHLO);
            $this->capabilities = [];
            return;
        }
        $this->capabilities = [];
        $lines = explode("\n", $text);
        array_shift($lines);
        foreach ($lines as $line) {
            $parts = preg_split('/[\s=]+/', trim($line), 2) ?: [];
            $keyword = strtoupper($parts[0] ?? '');
            if ($keyword !== '') {
                $this->capabilities[$keyword] = trim($parts[1] ?? '');
            }
        }
    }

    /**
     * @throws SmtpException
     */
    private function authenticate(): void
    {
        $settings = $this->settings;
        $method = $settings->authMethod;
        if ($method === ConnectionSettings::AUTH_NONE) {
            return;
        }
        if ($method === ConnectionSettings::AUTH_AUTO && $settings->username === '' && $settings->password === '') {
            return;
        }
        $offered = array_map('strtoupper', preg_split('/\s+/', $this->capabilities['AUTH'] ?? '') ?: []);
        $offered = array_values(array_filter($offered));
        if ($method === ConnectionSettings::AUTH_AUTO) {
            $method = '';
            foreach ([ConnectionSettings::AUTH_PLAIN, ConnectionSettings::AUTH_LOGIN, ConnectionSettings::AUTH_CRAM_MD5] as $candidate) {
                if (in_array(strtoupper($candidate), $offered, true)) {
                    $method = $candidate;
                    break;
                }
            }
            if ($method === '') {
                throw new SmtpException(
                    $offered === []
                        ? 'The server does not offer sign-in on this connection'
                        : 'The server offers no supported sign-in method (it offers: ' . implode(', ', $offered) . ')',
                    SmtpException::STAGE_AUTH
                );
            }
        }
        $this->authMechanism = strtoupper($method);
        switch ($method) {
            case ConnectionSettings::AUTH_PLAIN:
                $this->command(
                    'AUTH PLAIN ' . base64_encode("\0" . $settings->username . "\0" . $settings->password),
                    'AUTH PLAIN [credentials hidden]'
                );
                $this->expect([235], SmtpException::STAGE_AUTH);
                break;
            case ConnectionSettings::AUTH_LOGIN:
                $this->command('AUTH LOGIN');
                $this->expect([334], SmtpException::STAGE_AUTH);
                $this->command(base64_encode($settings->username), '[username hidden]');
                $this->expect([334], SmtpException::STAGE_AUTH);
                $this->command(base64_encode($settings->password), '[password hidden]');
                $this->expect([235], SmtpException::STAGE_AUTH);
                break;
            case ConnectionSettings::AUTH_CRAM_MD5:
                $this->command('AUTH CRAM-MD5');
                [, $challenge] = $this->expect([334], SmtpException::STAGE_AUTH);
                $decoded = (string)base64_decode(trim($challenge), true);
                $response = $settings->username . ' ' . hash_hmac('md5', $decoded, $settings->password);
                $this->command(base64_encode($response), '[credentials hidden]');
                $this->expect([235], SmtpException::STAGE_AUTH);
                break;
            case ConnectionSettings::AUTH_XOAUTH2:
                $token = 'user=' . $settings->username . "\x01auth=Bearer " . $settings->password . "\x01\x01";
                $this->command('AUTH XOAUTH2 ' . base64_encode($token), 'AUTH XOAUTH2 [token hidden]');
                [$code, $text] = $this->readReply();
                if ($code === 334) {
                    // The server sends a base64 JSON error and waits for an empty line before the final 535.
                    $detail = (string)base64_decode(trim($text), true);
                    if ($detail !== '') {
                        $this->log('-- server error detail: ' . self::truncate($detail, 500));
                    }
                    $this->command('');
                    [$code, $text] = $this->readReply();
                    if ($detail !== '') {
                        $text .= ' ' . $detail;
                    }
                }
                if ($code !== 235) {
                    throw new SmtpException(
                        'Server replied: ' . $code . ' ' . self::firstLine($text),
                        SmtpException::STAGE_AUTH,
                        $code,
                        $text
                    );
                }
                break;
            default:
                throw new SmtpException('Unknown sign-in method: ' . $method, SmtpException::STAGE_AUTH);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sslOptions(): array
    {
        $verify = $this->settings->verifyTls;
        return [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'peer_name' => $this->settings->host,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
    }

    private function command(string $line, ?string $logLine = null): void
    {
        $this->log('C: ' . ($logLine ?? $line));
        $this->socket->write($line . "\r\n");
    }

    /**
     * @param int[] $codes
     * @return array{0:int, 1:string}
     * @throws SmtpException
     */
    private function expect(array $codes, string $stage): array
    {
        [$code, $text] = $this->readReply();
        if (!in_array($code, $codes, true)) {
            throw new SmtpException(
                'Server replied: ' . $code . ' ' . self::firstLine($text),
                $stage,
                $code,
                $text
            );
        }
        return [$code, $text];
    }

    /**
     * Reads a (multi-line) reply.
     *
     * @return array{0:int, 1:string}
     * @throws SmtpException
     */
    private function readReply(): array
    {
        $lines = [];
        $code = 0;
        for ($i = 0; $i < 1000; $i++) {
            $line = $this->socket->readLine();
            if ($line === null) {
                $this->connected = false;
                throw new SmtpException('The server closed the connection', SmtpException::STAGE_IO);
            }
            if (strlen($line) < 3 || !ctype_digit(substr($line, 0, 3))
                || (strlen($line) > 3 && $line[3] !== ' ' && $line[3] !== '-')) {
                // Not an SMTP reply (for example a database or SSH service on that port): its content is not
                // shown, so the connection check cannot be used to read other services on the network.
                $this->log('S: [an answer that is not SMTP, hidden]');
                throw new SmtpException('The server did not answer like an SMTP server', SmtpException::STAGE_GREETING);
            }
            $line = $this->maskSecrets($line);
            $this->log('S: ' . self::truncate($line, 500));
            $code = (int)substr($line, 0, 3);
            $lines[] = (string)substr($line, 4);
            if (($line[3] ?? ' ') !== '-') {
                break;
            }
        }
        return [$code, implode("\n", $lines)];
    }

    /**
     * Normalises line endings, applies dot-stuffing and makes sure the data ends with CRLF.
     */
    private function prepareData(string $data): string
    {
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data) ?? $data;
        $data = preg_replace('/(^|\r\n)\./', '$1..', $data) ?? $data;
        if (!str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }
        return $data;
    }

    /**
     * Hides the password or token if a server repeats it in a reply.
     */
    private function maskSecrets(string $line): string
    {
        $secret = $this->settings->password;
        if (strlen($secret) < 6) {
            return $line;
        }
        return str_replace([$secret, base64_encode($secret)], '[hidden]', $line);
    }

    private function log(string $line): void
    {
        if (count($this->transcript) >= self::MAX_TRANSCRIPT_LINES) {
            if (count($this->transcript) === self::MAX_TRANSCRIPT_LINES) {
                $this->transcript[] = '-- transcript truncated';
            }
            return;
        }
        $this->transcript[] = $line;
    }

    private static function cleanAddress(string $address): string
    {
        return str_replace(["\r", "\n", '<', '>', ' '], '', trim($address));
    }

    private static function firstLine(string $text): string
    {
        return self::truncate(strtok($text, "\n") ?: $text, 300);
    }

    private static function truncate(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
