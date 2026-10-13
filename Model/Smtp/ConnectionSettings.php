<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Smtp;

/**
 * Everything the SMTP client needs for one connection. The password is in clear text in memory only.
 */
class ConnectionSettings
{
    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_STARTTLS = 'tls';
    public const ENCRYPTION_SSL = 'ssl';

    public const AUTH_AUTO = 'auto';
    public const AUTH_NONE = 'none';
    public const AUTH_PLAIN = 'plain';
    public const AUTH_LOGIN = 'login';
    public const AUTH_CRAM_MD5 = 'cram-md5';
    public const AUTH_XOAUTH2 = 'xoauth2';

    /**
     * Well-known ports of services that are never SMTP servers (SSH, web, mailbox access, databases, caches,
     * search, queues). Refused, so the connection check cannot be used to probe them.
     */
    private const NON_MAIL_PORTS = [
        22, 23, 53, 80, 110, 111, 135, 139, 143, 443, 445, 993, 995, 1433, 1521, 2049, 2375, 2376, 3306, 3389,
        5432, 5672, 5984, 6379, 6380, 8080, 8443, 9000, 9200, 9300, 11211, 15672, 27017,
    ];

    public function __construct(
        public readonly string $provider,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $authMethod,
        public readonly string $username,
        #[\SensitiveParameter] public readonly string $password,
        public readonly int $timeout = 20,
        public readonly bool $verifyTls = true,
        public readonly string $heloName = 'localhost'
    ) {
    }

    /**
     * Same connection settings with another secret (used to put a fresh OAuth access token in).
     */
    public function withPassword(#[\SensitiveParameter] string $password): self
    {
        return new self(
            $this->provider,
            $this->host,
            $this->port,
            $this->encryption,
            $this->authMethod,
            $this->username,
            $password,
            $this->timeout,
            $this->verifyTls,
            $this->heloName
        );
    }

    /**
     * Key for reusing an open connection: same server, same account, same security.
     */
    public function getConnectionKey(): string
    {
        return hash('sha256', implode("\0", [
            $this->host, (string)$this->port, $this->encryption, $this->authMethod, $this->username,
            $this->password, $this->verifyTls ? '1' : '0', $this->heloName,
        ]));
    }

    public static function isNonMailPort(int $port): bool
    {
        return in_array($port, self::NON_MAIL_PORTS, true);
    }

    public function describe(): string
    {
        return $this->host . ':' . $this->port;
    }
}
