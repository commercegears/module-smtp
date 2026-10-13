<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Smtp;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\OAuth\TokenManager;
use CommerceGears\Smtp\Model\Scope;

/**
 * Sends prepared messages for a scope. Keeps connections open for the rest of the PHP process (cron runs and mass
 * resends send many emails), one per server and account, and reconnects when an idle connection was dropped.
 */
class Sender
{
    private const MAX_MESSAGES_PER_CONNECTION = 100;
    private const MAX_OPEN_CONNECTIONS = 3;

    /** @var array<string, Client> */
    private array $pool = [];

    public function __construct(
        private readonly Config $config,
        private readonly TokenManager $tokenManager,
        private readonly ClientFactory $clientFactory,
        private readonly SocketFactory $socketFactory
    ) {
    }

    /**
     * Connection settings for the scope, with a fresh OAuth access token where needed.
     *
     * @throws SmtpException
     */
    public function getSettings(Scope $scope, bool $forceTokenRefresh = false): ConnectionSettings
    {
        $settings = $this->config->getConnectionSettings($scope);
        if ($settings->authMethod === ConnectionSettings::AUTH_XOAUTH2) {
            $oauth = $this->config->getOAuthSettings($scope);
            if ($oauth === null) {
                throw new SmtpException('OAuth is not configured', SmtpException::STAGE_TOKEN);
            }
            $settings = $settings->withPassword($this->tokenManager->getAccessToken($scope, $oauth, $forceTokenRefresh));
        }
        return $settings;
    }

    /**
     * @param string[] $recipients
     * @return array{reply: string, server: string, rejected: array<string, string>}
     * @throws SmtpException
     */
    public function send(Scope $scope, string $from, array $recipients, string $data): array
    {
        $settings = $this->getSettings($scope);
        try {
            return $this->sendWith($settings, $from, $recipients, $data);
        } catch (SmtpException $e) {
            if ($e->getStage() === SmtpException::STAGE_AUTH && $settings->authMethod === ConnectionSettings::AUTH_XOAUTH2) {
                // The cached token may have been revoked early: get a new one once.
                $settings = $this->getSettings($scope, true);
                return $this->sendWith($settings, $from, $recipients, $data);
            }
            throw $e;
        }
    }

    /**
     * Opens a new connection without sending anything. Returns the client so the caller can read the transcript.
     */
    public function createClient(ConnectionSettings $settings): Client
    {
        return $this->clientFactory->create(['settings' => $settings, 'socket' => $this->socketFactory->create()]);
    }

    public function closeAll(): void
    {
        foreach ($this->pool as $client) {
            $client->quit();
        }
        $this->pool = [];
    }

    public function __destruct()
    {
        $this->closeAll();
    }

    /**
     * @param string[] $recipients
     * @return array{reply: string, server: string, rejected: array<string, string>}
     * @throws SmtpException
     */
    private function sendWith(ConnectionSettings $settings, string $from, array $recipients, string $data): array
    {
        $key = $settings->getConnectionKey();
        $client = $this->pool[$key] ?? null;
        $reused = false;
        if ($client !== null) {
            if ($client->getMessageCount() >= self::MAX_MESSAGES_PER_CONNECTION || !$client->isAlive()) {
                $client->quit();
                unset($this->pool[$key]);
                $client = null;
            } else {
                $reused = true;
            }
        }
        if ($client === null) {
            $client = $this->createClient($settings);
            try {
                $client->connect();
            } catch (SmtpException $e) {
                $client->close();
                throw $this->withTranscript($e, $client);
            }
            $this->remember($key, $client);
        }

        try {
            $reply = $client->send($from, $recipients, $data);
        } catch (SmtpException $e) {
            if ($reused && $e->getStage() === SmtpException::STAGE_IO && !$client->wasDataSent()) {
                // The server dropped the idle connection between our check and the send: one new attempt.
                // Never after the message data went out: the server may have accepted it already.
                $client->close();
                unset($this->pool[$key]);
                return $this->sendWith($settings, $from, $recipients, $data);
            }
            // Leave the connection in a clean state for the next message, or drop it.
            if ($client->isConnected()) {
                try {
                    $client->reset();
                } catch (SmtpException) {
                    $client->close();
                    unset($this->pool[$key]);
                }
            } else {
                unset($this->pool[$key]);
            }
            throw $this->withTranscript($e, $client);
        }
        return ['reply' => $reply, 'server' => $settings->describe(), 'rejected' => $client->getRejectedRecipients()];
    }

    private function remember(string $key, Client $client): void
    {
        if (count($this->pool) >= self::MAX_OPEN_CONNECTIONS) {
            $oldestKey = array_key_first($this->pool);
            if ($oldestKey !== null) {
                $this->pool[$oldestKey]->quit();
                unset($this->pool[$oldestKey]);
            }
        }
        $this->pool[$key] = $client;
    }

    private function withTranscript(SmtpException $e, Client $client): SmtpException
    {
        return new TransportFailure($e, $client->getTranscript());
    }
}
