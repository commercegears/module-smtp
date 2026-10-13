<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Smtp;

use CommerceGears\Smtp\Model\Smtp\Socket;

/**
 * Scripted SMTP server: answers each client line with the next queued reply.
 */
class FakeSocket extends Socket
{
    /** @var string[] lines the server sends */
    private array $replies = [];
    /** @var string[] what the client wrote */
    public array $written = [];
    public string $remote = '';
    public bool $crypto = false;
    public bool $open = false;

    /**
     * @param string[] $lines
     */
    public function queue(array $lines): self
    {
        foreach ($lines as $line) {
            $this->replies[] = $line;
        }
        return $this;
    }

    public function open(string $remote, float $timeout, array $sslOptions): void
    {
        $this->remote = $remote;
        $this->open = true;
    }

    public function enableCrypto(): void
    {
        $this->crypto = true;
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function readLine(): ?string
    {
        return array_shift($this->replies);
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }
}
