# CommerceGears SMTP for Magento 2 (commercegears/module-smtp)

Sends every Magento email (orders, invoices, shipments, accounts, password resets, contact form, newsletters and
third-party module emails) through the SMTP server you choose, with OAuth 2.0 for Google and Microsoft 365, an email
log with resend, retries of failed emails, a staging safety switch and plain-language diagnostics.

- User guide: [docs/user-guide.md](docs/user-guide.md) · FAQ: [docs/faq.md](docs/faq.md) · Changelog: [CHANGELOG.md](CHANGELOG.md)

## Features

- **Provider presets**: Gmail / Google Workspace (app password or OAuth 2.0), Microsoft 365 (password or OAuth 2.0),
  Amazon SES (region), SendGrid, Mailgun (US/EU), Brevo, Postmark, Mailjet, Zoho Mail (data centre), Cloudflare Email
  Service, or any custom SMTP server (None / STARTTLS / SSL/TLS; Automatic, PLAIN, LOGIN, CRAM-MD5; certificate check).
- **OAuth 2.0 (XOAUTH2)**: Google and Microsoft 365 delegated sign-in with PKCE and a Connect Account button;
  Microsoft 365 app-only (client credentials) without a browser sign-in. Refresh tokens are stored encrypted, access
  tokens cached for their real lifetime, rotated refresh tokens saved again.
- **Per store view**: every connection, sender and delivery setting has default, website and store view scope. Emails
  sent from the admin (for example an order email) use the order's store view, not the admin scope.
- **Send Test Email / Check Connection** on the settings page with a readable explanation of any failure and the
  SMTP conversation (passwords and tokens hidden).
- **Readable errors**: app password needed, SMTP AUTH switched off for the Microsoft 365 mailbox, SendAsDenied,
  port and encryption mismatch, blocked port, certificate name, unverified sender (SES), too large, DMARC rejection.
- **Email log**: grid with keyword search and filters (status, email type, store view, dates), last 24 hours summary,
  detail page with safe HTML preview (remote images blocked until you load them for that email), text version,
  headers, attachment list, delivery notes and SMTP transcript. Content needs its own permission.
- **Resend** one email now (same recipients or another address, with a new Message-ID) or many at once (queued);
  refused while the module is off for the email's store view or the store view was deleted.
- **Retries**: failed emails are kept and retried by cron after 5, 15, 60 and 240 minutes (configurable attempts).
  An address the server refuses permanently (for example a typo) is left out and noted; the other recipients, such as
  the store's own Bcc copy, still get the email.
- **Privacy**: email content is kept only as long as needed (own optional retention, deleted when "Keep Email
  Content" is switched off); the connection check refuses ports of other services and never shows their answers.
- **Background sending** (optional): emails are queued and sent by cron, so checkout never waits for the SMTP server.
- **Delivery and staging**: send everything to test addresses (with an optional subject prefix), or send nothing and
  only log; "Always Deliver To" addresses or domains; **staging safety lock**: on any domain other than the live one,
  emails are redirected or held automatically; **Never Send To** blocklist with wildcards.
- **Sender override**: send all emails from one address (needed by Gmail, Microsoft 365, verified senders) with the
  store address as Reply-To. Magento's own Return-Path setting is honoured.
- **DNS check** of the sender domain: SPF (missing, duplicate, provider include missing, `+all`, lookups), DKIM
  (provider selectors or your own), DMARC policy, MX.
- **Admin notice** when emails failed in the last 24 hours or an OAuth sign-in stopped working.
- Magento's "Disable Email Communications" switch is respected; held-back emails are logged as "Not sent".

## Configuration

*Stores > Configuration > CommerceGears > SMTP* (also *CommerceGears > SMTP > Settings*). Email log:
*CommerceGears > SMTP > Email Log*.

ACL: `CommerceGears_Smtp::smtp` > `::log` (view the log list and email details) > `::log_content` (preview, text,
headers, attachments) and `::log_manage` (delete; resend also needs `::log_content`); `::config` (settings, test
buttons, OAuth connect).

## CLI

```
bin/magento commercegears:smtp:test --to=you@example.com [--store=1|--website=1] [--transcript]
bin/magento commercegears:smtp:test --check-only [--store=1]
bin/magento commercegears:smtp:queue [--limit=200]     # send queued emails and due retries now
bin/magento commercegears:smtp:clean [--days=30]       # delete old log entries and content no longer kept
bin/magento commercegears:smtp:dns [domain] [--selector=s1] [--store=1]
```

Cron jobs: `commercegears_smtp_queue` (every minute: queue and retries, with a lock) and
`commercegears_smtp_log_cleanup` (daily).

## How it works

A plugin on `Magento\Framework\Mail\TransportInterface::sendMessage()` takes over when the module is on for the
email's store view. The module has its own small SMTP client that works on the raw MIME message, so Magento 2.4.6 and
2.4.7 (Laminas Mail) and 2.4.8+ (Symfony Mailer) behave the same; Bcc recipients and attachments are kept. When the
module is off, Magento's own transport sends the email unchanged. If the module itself hits an unexpected problem
(not an SMTP error), Magento's own transport sends the email, unless test addresses, log only, the staging lock or the
blocklist apply: then the email is not sent and Magento gets the error. A second plugin on the TransportBuilder records the
template identifier and store view of each email. Delivery rules are applied at send time, so queued, retried and
resent emails follow the settings that are in force then.

Tables: `commercegears_smtp_log`, `commercegears_smtp_log_content` (compressed raw message, kept apart from the grid),
`commercegears_smtp_oauth_token` (encrypted refresh tokens per scope).

## Compatibility

Magento Open Source and Adobe Commerce 2.4.6 to 2.4.9, PHP 8.2 to 8.5. Admin only: nothing runs on storefront pages,
so Luma, Hyvä and Hyvä Checkout are all unaffected. Requires `commercegears/module-core` ^2.0.

## Limitations

- SMTP only: provider HTTP APIs, Google Workspace service accounts, bounce webhooks and open tracking are not included.
- Another SMTP module that also replaces the mail transport must be switched off.
- The test buttons use the saved settings (save first).
- Background sending and retries need Magento cron.
