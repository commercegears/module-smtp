<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Smtp;

/**
 * Thin wrapper around a PHP stream socket, so the SMTP dialogue can be unit tested with a scripted fake.
 * PHP warnings (refused connections, certificate errors) are turned into exception messages.
 */
class Socket
{
    /** @var resource|null */
    private $stream = null;

    /**
     * @param array<string, mixed> $sslOptions
     * @throws SmtpException
     */
    public function open(string $remote, float $timeout, array $sslOptions): void
    {
        $context = stream_context_create(['ssl' => $sslOptions]);
        $errno = 0;
        $errstr = '';
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = self::cleanWarning($message);
            return true;
        });
        try {
            $stream = stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($stream)) {
            // With TLS the useful text (for example "certificate verify failed") is in the PHP warnings, and
            // $errstr only says "Unknown error".
            $tlsWarnings = array_filter($warnings, static fn (string $w): bool => stripos($w, 'SSL') !== false || stripos($w, 'certificate') !== false || stripos($w, 'crypto') !== false);
            $reason = trim($errstr);
            if ($tlsWarnings !== []) {
                throw new SmtpException(implode(' ', array_unique($tlsWarnings)), SmtpException::STAGE_TLS);
            }
            if ($reason === '' || stripos($reason, 'unknown error') !== false) {
                $reason = implode(' ', array_unique(array_filter($warnings)));
            }
            throw new SmtpException(
                $reason !== '' ? $reason : 'Could not open the connection',
                SmtpException::STAGE_CONNECT
            );
        }
        stream_set_timeout($stream, (int)ceil($timeout));
        $this->stream = $stream;
    }

    /**
     * @throws SmtpException
     */
    public function enableCrypto(): void
    {
        $this->assertOpen();
        $warning = '';
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            $result = stream_socket_enable_crypto($this->stream, true, $method);
        } finally {
            restore_error_handler();
        }
        if ($result !== true) {
            $reason = self::cleanWarning($warning);
            throw new SmtpException(
                $reason !== '' ? $reason : 'The TLS handshake failed',
                SmtpException::STAGE_TLS
            );
        }
    }

    /**
     * @throws SmtpException
     */
    public function write(string $data): void
    {
        $this->assertOpen();
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $chunk = @fwrite($this->stream, substr($data, $written, 65536));
            if ($chunk === false || $chunk === 0) {
                $this->checkTimeout();
                throw new SmtpException('The server closed the connection while data was being sent', SmtpException::STAGE_IO);
            }
            $written += $chunk;
        }
    }

    /**
     * One line from the server without the line ending, or null when the connection was closed.
     *
     * @throws SmtpException
     */
    public function readLine(): ?string
    {
        $this->assertOpen();
        $line = @fgets($this->stream);
        if ($line === false) {
            $this->checkTimeout();
            return null;
        }
        return rtrim($line, "\r\n");
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream) && !feof($this->stream);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    /**
     * @throws SmtpException
     */
    private function checkTimeout(): void
    {
        if (is_resource($this->stream)) {
            $meta = stream_get_meta_data($this->stream);
            if (!empty($meta['timed_out'])) {
                throw new SmtpException('The server did not answer in time', SmtpException::STAGE_IO);
            }
        }
    }

    /**
     * @throws SmtpException
     */
    private function assertOpen(): void
    {
        if (!is_resource($this->stream)) {
            throw new SmtpException('The connection is not open', SmtpException::STAGE_IO);
        }
    }

    private static function cleanWarning(string $warning): string
    {
        $warning = preg_replace('/^stream_socket_(client|enable_crypto)\(\):\s*/', '', $warning) ?? $warning;
        return trim($warning);
    }
}
