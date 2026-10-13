<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Provider;

/**
 * Built-in provider presets. A preset fixes the host and the security settings, so the merchant only enters the
 * account details. "Custom SMTP server" leaves everything open.
 */
class PresetRegistry
{
    public const CUSTOM = 'custom';
    public const GOOGLE = 'google';
    public const GOOGLE_OAUTH = 'google_oauth';
    public const MICROSOFT = 'microsoft';
    public const MICROSOFT_OAUTH = 'microsoft_oauth';
    public const SES = 'ses';
    public const SENDGRID = 'sendgrid';
    public const MAILGUN = 'mailgun';
    public const BREVO = 'brevo';
    public const POSTMARK = 'postmark';
    public const MAILJET = 'mailjet';
    public const ZOHO = 'zoho';
    public const CLOUDFLARE = 'cloudflare';

    /** @var array<string, Preset>|null */
    private ?array $presets = null;

    /**
     * @return array<string, Preset>
     */
    public function getAll(): array
    {
        if ($this->presets === null) {
            $this->presets = [];
            foreach ($this->build() as $preset) {
                $this->presets[$preset->code] = $preset;
            }
        }
        return $this->presets;
    }

    public function get(string $code): Preset
    {
        $all = $this->getAll();
        return $all[$code] ?? $all[self::CUSTOM];
    }

    /**
     * @return Preset[]
     */
    private function build(): array
    {
        $google = ['include:_spf.google.com'];
        $microsoft = ['include:spf.protection.outlook.com'];
        return [
            new Preset(
                self::CUSTOM,
                'Custom SMTP server',
                '',
                587,
                help: 'Any SMTP server, for example your hosting provider\'s mail server or a relay on your own network. '
                    . 'Ask your provider for the host name, port, security type and sign-in details.'
            ),
            new Preset(
                self::GOOGLE,
                'Gmail or Google Workspace (app password)',
                'smtp.gmail.com',
                587,
                spfIncludes: $google,
                dkimSelectors: ['google'],
                help: 'Username: the full Google address. Password: a 16-character app password, created at '
                    . 'myaccount.google.com/apppasswords (needs 2-Step Verification). Your normal Google password '
                    . 'does not work. Google may rewrite the From address to the signed-in account unless the store '
                    . 'address is added as a "Send mail as" alias.'
            ),
            new Preset(
                self::GOOGLE_OAUTH,
                'Gmail or Google Workspace (OAuth 2.0)',
                'smtp.gmail.com',
                587,
                Preset::AUTH_OAUTH_GOOGLE,
                spfIncludes: $google,
                dkimSelectors: ['google'],
                help: 'Sign in with Google instead of storing a password. Create an OAuth client of type "Web '
                    . 'application" in Google Cloud Console (APIs & Services > Credentials), add the redirect URI shown '
                    . 'below, enter the client ID and secret, save, then click Connect Account. Set the consent screen '
                    . 'to Internal (Workspace) or In production: while it is in Testing, Google ends the sign-in after '
                    . '7 days.'
            ),
            new Preset(
                self::MICROSOFT,
                'Microsoft 365 or Outlook.com (password)',
                'smtp.office365.com',
                587,
                spfIncludes: $microsoft,
                dkimSelectors: ['selector1', 'selector2'],
                help: 'Username: the full mailbox address. Microsoft is switching off password sign-in for SMTP '
                    . '(basic authentication) in Exchange Online, and it only works where "Authenticated SMTP" is on '
                    . 'for the mailbox. Use "Microsoft 365 (OAuth 2.0)" for a lasting setup.'
            ),
            new Preset(
                self::MICROSOFT_OAUTH,
                'Microsoft 365 (OAuth 2.0)',
                'smtp.office365.com',
                587,
                Preset::AUTH_OAUTH_MICROSOFT,
                spfIncludes: $microsoft,
                dkimSelectors: ['selector1', 'selector2'],
                help: 'Register an app in Microsoft Entra ID (App registrations), add a client secret and enter the '
                    . 'client ID, secret and tenant ID. "Sign in with the mailbox": add the redirect URI shown below as '
                    . 'a Web redirect URI and the delegated permission SMTP.Send, save, then click Connect Account. '
                    . '"App-only": no sign-in, the app needs the SMTP.SendAsApp permission and access to the mailbox '
                    . '(see the user guide). "Authenticated SMTP" must be on for the mailbox.'
            ),
            new Preset(
                self::SES,
                'Amazon SES',
                'email-smtp.{region}.amazonaws.com',
                587,
                regions: [
                    'us-east-1' => 'US East (N. Virginia)',
                    'us-east-2' => 'US East (Ohio)',
                    'us-west-1' => 'US West (N. California)',
                    'us-west-2' => 'US West (Oregon)',
                    'ca-central-1' => 'Canada (Central)',
                    'eu-west-1' => 'Europe (Ireland)',
                    'eu-west-2' => 'Europe (London)',
                    'eu-west-3' => 'Europe (Paris)',
                    'eu-central-1' => 'Europe (Frankfurt)',
                    'eu-central-2' => 'Europe (Zurich)',
                    'eu-south-1' => 'Europe (Milan)',
                    'eu-north-1' => 'Europe (Stockholm)',
                    'ap-south-1' => 'Asia Pacific (Mumbai)',
                    'ap-northeast-1' => 'Asia Pacific (Tokyo)',
                    'ap-northeast-2' => 'Asia Pacific (Seoul)',
                    'ap-northeast-3' => 'Asia Pacific (Osaka)',
                    'ap-southeast-1' => 'Asia Pacific (Singapore)',
                    'ap-southeast-2' => 'Asia Pacific (Sydney)',
                    'ap-southeast-3' => 'Asia Pacific (Jakarta)',
                    'sa-east-1' => 'South America (Sao Paulo)',
                    'me-south-1' => 'Middle East (Bahrain)',
                    'af-south-1' => 'Africa (Cape Town)',
                    'il-central-1' => 'Israel (Tel Aviv)',
                ],
                defaultRegion: 'eu-west-1',
                spfIncludes: ['include:amazonses.com'],
                help: 'Username and password: the SMTP credentials from the SES console (SMTP settings > Create SMTP '
                    . 'credentials), not your AWS access keys. The sender address or domain must be verified in the '
                    . 'same region. New accounts are in the sandbox and can only send to verified addresses. For SPF on '
                    . 'your own domain, set a custom MAIL FROM domain in SES.'
            ),
            new Preset(
                self::SENDGRID,
                'SendGrid',
                'smtp.sendgrid.net',
                587,
                fixedUsername: 'apikey',
                spfIncludes: ['include:sendgrid.net'],
                dkimSelectors: ['s1', 's2'],
                help: 'Password: a SendGrid API key with "Mail Send" permission. The username is always "apikey" and '
                    . 'is filled in for you. The sender address or domain must be verified in SendGrid.'
            ),
            new Preset(
                self::MAILGUN,
                'Mailgun',
                '{region}',
                587,
                regions: ['smtp.mailgun.org' => 'US', 'smtp.eu.mailgun.org' => 'EU'],
                defaultRegion: 'smtp.mailgun.org',
                spfIncludes: ['include:mailgun.org'],
                dkimSelectors: ['pdk1', 'pdk2', 'smtp', 'mx', 'k1'],
                help: 'Username and password: the SMTP credentials of your sending domain (Sending > Domain settings > '
                    . 'SMTP credentials), for example postmaster@mg.example.com. Pick the region your domain was '
                    . 'created in.'
            ),
            new Preset(
                self::BREVO,
                'Brevo',
                'smtp-relay.brevo.com',
                587,
                spfIncludes: ['include:spf.brevo.com', 'include:spf.sendinblue.com'],
                dkimSelectors: ['brevo1', 'brevo2', 'mail'],
                help: 'Username: the SMTP login shown under SMTP & API > SMTP (it is not always your account e-mail). '
                    . 'Password: an SMTP key from the same page, not your Brevo password.'
            ),
            new Preset(
                self::POSTMARK,
                'Postmark',
                'smtp.postmarkapp.com',
                587,
                usernameIsPassword: true,
                spfIncludes: ['include:spf.mtasv.net'],
                help: 'Password: the Server API token of your Postmark server (Servers > API tokens). It is used as the '
                    . 'username too. The sender signature or domain must be confirmed in Postmark. Emails go to the '
                    . 'default transactional stream.'
            ),
            new Preset(
                self::MAILJET,
                'Mailjet',
                'in-v3.mailjet.com',
                587,
                spfIncludes: ['include:spf.mailjet.com'],
                dkimSelectors: ['mailjet'],
                help: 'Username: your Mailjet API key. Password: the matching secret key (Account settings > API keys). '
                    . 'The sender address or domain must be validated in Mailjet.'
            ),
            new Preset(
                self::ZOHO,
                'Zoho Mail',
                'smtp.zoho.{region}',
                587,
                regions: ['com' => 'zoho.com', 'eu' => 'zoho.eu', 'in' => 'zoho.in', 'com.au' => 'zoho.com.au'],
                defaultRegion: 'eu',
                spfIncludes: ['include:zoho.com', 'include:zoho.eu', 'include:zoho.in', 'include:zoho.com.au'],
                dkimSelectors: ['zmail'],
                help: 'Username: the full Zoho Mail address. Password: an application-specific password if two-factor '
                    . 'authentication is on. Pick the data centre your account is in (the domain you sign in at).'
            ),
            new Preset(
                self::CLOUDFLARE,
                'Cloudflare Email Service',
                'smtp.mx.cloudflare.net',
                465,
                fixedUsername: 'api_token',
                help: 'Password: a Cloudflare API token with the "Email Sending: Edit" permission. The username is '
                    . 'always "api_token" and is filled in for you. Cloudflare accepts SMTP on port 465 (SSL/TLS) only, '
                    . 'the sending domain must use Cloudflare DNS and be onboarded for Email Sending. SMTP is a beta '
                    . 'service at Cloudflare (since June 2026); SPF and DKIM records are added during onboarding.'
            ),
        ];
    }
}
