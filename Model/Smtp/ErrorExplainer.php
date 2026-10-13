<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Smtp;

use CommerceGears\Smtp\Model\Provider\PresetRegistry;

/**
 * Turns SMTP and network errors into a plain sentence plus "what to do", based on the step that failed, the
 * server's reply and the provider. The technical text is kept so nothing is hidden.
 */
class ErrorExplainer
{
    /**
     * @return array{message: string, hint: string, technical: string, temporary: bool}
     */
    public function explain(\Throwable $error, ?ConnectionSettings $settings = null): array
    {
        $technical = $error->getMessage();
        $stage = $error instanceof SmtpException ? $error->getStage() : '';
        $code = $error instanceof SmtpException ? $error->getReplyCode() : 0;
        $reply = $error instanceof SmtpException ? $error->getReplyText() : '';
        $temporary = $error instanceof SmtpException && $error->isTemporary();
        $haystack = strtolower($technical . ' ' . $reply);
        $provider = $settings?->provider ?? '';
        $where = $settings !== null ? $settings->describe() : '';
        $port = $settings?->port ?? 0;
        $encryption = $settings?->encryption ?? '';

        [$message, $hint] = $this->match($stage, $code, $haystack, $provider, $where, $port, $encryption);
        if ($message === '') {
            $message = $technical;
        }
        return [
            'message' => $message,
            'hint' => $hint,
            'technical' => $technical . ($reply !== '' && !str_contains($technical, $reply) ? ' | ' . $reply : ''),
            'temporary' => $temporary,
        ];
    }

    /**
     * One line for logs and exceptions: message plus hint.
     */
    public function summarise(\Throwable $error, ?ConnectionSettings $settings = null): string
    {
        $explained = $this->explain($error, $settings);
        return trim($explained['message'] . ' ' . $explained['hint']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function match(
        string $stage,
        int $code,
        string $text,
        string $provider,
        string $where,
        int $port,
        string $encryption
    ): array {
        $isGoogle = in_array($provider, [PresetRegistry::GOOGLE, PresetRegistry::GOOGLE_OAUTH], true)
            || str_contains($text, 'gsmtp') || str_contains($text, 'google');
        $isMicrosoft = in_array($provider, [PresetRegistry::MICROSOFT, PresetRegistry::MICROSOFT_OAUTH], true)
            || str_contains($text, 'outlook') || str_contains($text, 'office365');
        $isOAuth = in_array($provider, [PresetRegistry::GOOGLE_OAUTH, PresetRegistry::MICROSOFT_OAUTH], true);

        // Network and TLS problems first: they look the same for every provider.
        if (str_contains($text, 'certificate verify failed') || str_contains($text, 'peer certificate')
            || str_contains($text, 'did not match expected cn') || str_contains($text, 'self-signed')
            || str_contains($text, 'self signed')) {
            return [
                (string)__('The TLS certificate of %1 could not be verified.', $where),
                (string)__('Use the host name that is on the server\'s certificate (not an IP address or alias). For an internal relay with its own certificate, choose "Custom SMTP server" and turn off "Verify TLS Certificate".'),
            ];
        }
        if (str_contains($text, 'wrong version number') || str_contains($text, 'unknown protocol')
            || (str_contains($text, 'ssl') && str_contains($text, 'routines') && $port === 587)) {
            return [
                (string)__('The secure connection could not be set up: the port and the encryption type do not match.'),
                (string)__('Port 587 (and 25, 2525) use STARTTLS; port 465 uses SSL/TLS from the start.'),
            ];
        }
        if ($stage === SmtpException::STAGE_CONNECT) {
            if (str_contains($text, 'getaddrinfo') || str_contains($text, 'name or service not known')
                || str_contains($text, 'name resolution') || str_contains($text, 'no address associated')
                || str_contains($text, 'nodename nor servname')) {
                return [
                    (string)__('The SMTP host name could not be found.'),
                    (string)__('Check the spelling of the host (for example smtp.example.com, without https://).'),
                ];
            }
            if (str_contains($text, 'refused')) {
                return [
                    (string)__('%1 refused the connection.', $where),
                    (string)__('Nothing accepts SMTP connections on this port. Check the host and port with your provider.'),
                ];
            }
            if (str_contains($text, 'timed out') || str_contains($text, 'timeout')) {
                return [
                    (string)__('%1 did not answer.', $where),
                    (string)__('A firewall or your hosting provider probably blocks outgoing connections on port %1. Try port 587 or 2525, or ask your host to allow it.', $port),
                ];
            }
            if (str_contains($text, 'another kind of service')) {
                return [
                    (string)__('Port %1 is not an email port.', $port),
                    (string)__('Use the SMTP port of your provider: usually 587 (STARTTLS) or 465 (SSL/TLS), or 25 or 2525.'),
                ];
            }
            if (str_contains($text, 'no smtp host')) {
                return [(string)__('No SMTP host is set.'), (string)__('Choose a provider or enter the host of your SMTP server.')];
            }
            return [
                (string)__('Could not connect to %1.', $where),
                (string)__('Check the host, port and encryption type, and that this server may make outgoing connections.'),
            ];
        }
        if (str_contains($text, 'not answer like an smtp server')) {
            return [
                (string)__('%1 is not an SMTP server.', $where),
                (string)__('Check the host and port with your provider (usually 587 with STARTTLS or 465 with SSL/TLS).'),
            ];
        }
        if (($stage === SmtpException::STAGE_GREETING || $stage === SmtpException::STAGE_IO)
            && $port === 465 && $encryption !== ConnectionSettings::ENCRYPTION_SSL) {
            return [
                (string)__('The server on port 465 waited for an encrypted connection.'),
                (string)__('Choose SSL/TLS for port 465, or use port 587 with STARTTLS.'),
            ];
        }
        if ($stage === SmtpException::STAGE_TLS) {
            if (str_contains($text, 'does not offer starttls')) {
                return [
                    (string)__('The server does not offer STARTTLS on port %1.', $port),
                    (string)__('Use port 465 with SSL/TLS, or ask your provider which port supports encryption.'),
                ];
            }
            return [
                (string)__('The encrypted connection (TLS) could not be set up.'),
                (string)__('The server may only support old TLS versions (TLS 1.2 or newer is required), or the port and encryption type do not match.'),
            ];
        }
        if ($stage === SmtpException::STAGE_TOKEN) {
            return ['', (string)__('Open the SMTP settings and click Connect Account again, or check the OAuth client ID, secret and tenant.')];
        }

        if ($stage === SmtpException::STAGE_AUTH) {
            if (str_contains($text, 'does not offer sign-in')) {
                if ($encryption === ConnectionSettings::ENCRYPTION_NONE) {
                    return [
                        (string)__('The server does not allow sign-in on an unencrypted connection.'),
                        (string)__('Choose STARTTLS (port 587) or SSL/TLS (port 465).'),
                    ];
                }
                return [
                    (string)__('The server does not ask for a sign-in.'),
                    (string)__('Set "Sign-in Method" to None, or check that you use the right port (submission port 587, not 25).'),
                ];
            }
            if (str_contains($text, 'no supported sign-in method')) {
                return [(string)__('The server offers no sign-in method this module supports.'), (string)__('Choose another port or ask your provider for an SMTP endpoint with PLAIN or LOGIN.')];
            }
            if ($isMicrosoft && (str_contains($text, '5.7.139') || str_contains($text, 'smtpclientauthentication')
                || str_contains($text, 'basic authentication is disabled'))) {
                return [
                    (string)__('Microsoft 365 does not allow SMTP sign-in for this mailbox.'),
                    (string)__('Turn on "Authenticated SMTP" for the mailbox (Microsoft 365 admin centre > Users > Mail > Manage email apps). If basic authentication is switched off for your tenant, use "Microsoft 365 (OAuth 2.0)".'),
                ];
            }
            if ($isMicrosoft && $isOAuth) {
                return [
                    (string)__('Microsoft 365 rejected the OAuth sign-in.'),
                    (string)__('Check that "Authenticated SMTP" is on for the mailbox, that the username is the mailbox the token is for, and (app-only) that the app has SMTP.SendAsApp with access to this mailbox in Exchange.'),
                ];
            }
            if ($isMicrosoft) {
                return [
                    (string)__('Microsoft 365 rejected the username or password.'),
                    (string)__('Check the details and that "Authenticated SMTP" is on for the mailbox. Security defaults or MFA block password sign-in: use "Microsoft 365 (OAuth 2.0)" instead.'),
                ];
            }
            if ($isGoogle && $isOAuth) {
                return [
                    (string)__('Google rejected the OAuth sign-in.'),
                    (string)__('The username must be the Google account you connected. Click Connect Account again if you changed it.'),
                ];
            }
            if ($isGoogle) {
                return [
                    (string)__('Google rejected the username or password.'),
                    (string)__('Google needs an app password (myaccount.google.com/apppasswords, requires 2-Step Verification), not your normal password. Or use "Gmail or Google Workspace (OAuth 2.0)".'),
                ];
            }
            if ($provider === PresetRegistry::SES) {
                return [(string)__('Amazon SES rejected the SMTP credentials.'), (string)__('Use the SMTP user name and password created in the SES console for this region, not your AWS access keys.')];
            }
            if ($provider === PresetRegistry::SENDGRID) {
                return [(string)__('SendGrid rejected the API key.'), (string)__('Create an API key with "Mail Send" permission and paste it as the password.')];
            }
            if ($code === 535 || $code === 534 || $code === 530) {
                return [(string)__('The server rejected the username or password.'), (string)__('Check the sign-in details. Many providers need a separate SMTP password or API key.')];
            }
            if ($code === 504) {
                return [(string)__('The server does not support the chosen sign-in method.'), (string)__('Set "Sign-in Method" to Automatic.')];
            }
        }

        if (str_contains($text, 'sendasdenied') || str_contains($text, '5.2.252') || str_contains($text, '5.7.60')) {
            return [
                (string)__('Microsoft 365 does not allow this mailbox to send as the From address.'),
                (string)__('Use the mailbox address as the store sender, set "Send All Emails From" to it, or grant "Send As" for the store address in Exchange.'),
            ];
        }
        if (str_contains($text, 'not verified') || (str_contains($text, 'identity') && $provider === PresetRegistry::SES)) {
            return [
                (string)__('The address is not verified with the provider.'),
                (string)__('Verify the sender domain or address. Amazon SES accounts in the sandbox can also only send to verified recipients.'),
            ];
        }
        if ($code === 530 || str_contains($text, 'authentication required')) {
            return [(string)__('The server wants a sign-in before it accepts emails.'), (string)__('Enter the username and password.')];
        }
        if ($code === 552 || str_contains($text, 'size limit') || str_contains($text, 'too large')
            || str_contains($text, 'accepts at most')) {
            return [(string)__('The email is too large for the server.'), (string)__('Large attachments (for example invoice PDFs) can exceed the provider\'s limit.')];
        }
        if (str_contains($text, 'dmarc') || str_contains($text, '5.7.26') || str_contains($text, 'unauthenticated')
            || str_contains($text, '5.7.27') || str_contains($text, 'spf')) {
            return [
                (string)__('The email was rejected because the sender domain is not authenticated.'),
                (string)__('Check SPF, DKIM and DMARC for the sender domain with "Check DNS Records".'),
            ];
        }
        if ($code === 421 || $code === 450 || $code === 451 || $code === 452 || str_contains($text, 'rate limit') || str_contains($text, 'throttl')
            || str_contains($text, 'too many')) {
            return [
                (string)__('The server is busy or limits how fast you can send.'),
                (string)__('This is usually temporary. With retries on, the email is sent again later.'),
            ];
        }
        if ($stage === SmtpException::STAGE_MAIL_FROM) {
            return [
                (string)__('The server did not accept the sender address.'),
                (string)__('Your account may only send from addresses it owns. Use "Send All Emails From" or verify the address with your provider.'),
            ];
        }
        if ($stage === SmtpException::STAGE_RCPT) {
            if (str_contains($text, 'relay')) {
                return [(string)__('The server does not relay this email.'), (string)__('Sign-in is needed, or your account may not send to outside addresses.')];
            }
            if (str_contains($text, '5.1.1') || str_contains($text, 'unknown') || str_contains($text, 'does not exist')) {
                return [(string)__('A recipient address does not exist.'), (string)__('Check the spelling of the address.')];
            }
            return [(string)__('The server did not accept a recipient.'), ''];
        }
        if ($stage === SmtpException::STAGE_IO) {
            return [(string)__('The connection to %1 broke off.', $where), (string)__('Try again. If it keeps happening, raise the connection timeout.')];
        }
        if ($stage === SmtpException::STAGE_DATA) {
            return [(string)__('The server refused the email.'), ''];
        }
        return ['', ''];
    }
}
