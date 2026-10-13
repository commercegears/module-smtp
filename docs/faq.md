# SMTP for Magento 2: FAQ

**Magento already has an SMTP option. Why do I need this?**

Magento 2.4.6 and later can send through an SMTP server with a username and password. It has no OAuth (which Google
and Microsoft 365 now expect), no test button, no log, no resend and no retries, and when sending fails it only says
"Unable to send mail". This module adds all of that, plus provider presets, test addresses for staging and a DNS
check, and it works the same on Magento 2.4.6 to 2.4.9.

**Which providers can I use?**

Presets exist for Gmail and Google Workspace, Microsoft 365 and Outlook.com, Amazon SES, SendGrid, Mailgun, Brevo,
Postmark, Mailjet, Zoho Mail and Cloudflare Email Service. Any other service or your own mail server works with
"Custom SMTP server" (STARTTLS, SSL/TLS or unencrypted; PLAIN, LOGIN or CRAM-MD5 sign-in).

**Microsoft is switching off basic authentication. Does this module help?**

Yes. Choose "Microsoft 365 (OAuth 2.0)". You can sign in once with the mailbox, or use app-only sign-in (client
credentials) where no person has to sign in and nothing has to be reconnected later. The user guide lists the steps in
Microsoft Entra ID and Exchange.

**Google says my password is wrong.**

Google no longer accepts the normal account password for SMTP. Use an app password (needs 2-Step Verification) with
the "app password" preset, or the OAuth 2.0 preset. If you use OAuth with a Google Cloud app in "Testing" status,
Google ends the sign-in after 7 days: set the app to Internal or In production.

**Can each store view use a different email account?**

Yes. All server, sender and delivery settings work per website and store view. Emails that you send from the admin,
such as an order confirmation, use the order's store view.

**What happens when the SMTP server is down?**

With "Retry Failed Emails" on (the default), the email is kept and sent again after 5, 15, 60 and 240 minutes by cron.
It is marked "Failed" in the log only after the last attempt, and an admin notice tells you about it.

**Does sending slow down checkout?**

Normally an email is sent in about a second. If your server is slow, choose "In the background" under Queue and
Retries: emails are then queued and sent by cron within a minute.

**How do I stop a staging site from emailing customers?**

Enter your live domain under "Live Store Domain (Staging Safety Lock)" on the live store. Any copy of the database
running on another domain then sends all emails to your test addresses, or only logs them when there are none. You can
also set Delivery to "Send everything to the test addresses" or "Do not send, only log" on a staging site directly.

**Can I see and resend an email a customer says they never received?**

Open CommerceGears > SMTP > Email Log, search for the customer's address and open the email. You see whether and when
it was sent, the server's reply and the content. "Send Again" sends it to the same recipients, or enter another
address if the customer gave a wrong one.

**Is the email content stored? What about privacy?**

With "Keep Email Content" on (the default), the full email is kept compressed in the database so you can view and
resend it. Emails can contain personal data and links such as password resets, so the log is cleaned after 30 days by
default. You can shorten that, or switch "Keep Email Content" off; queued and retried emails are always kept until they
are sent.

**My emails go to spam. Can the module help?**

Use "Check DNS Records" in Test and Diagnostics. It checks SPF, DKIM, DMARC and MX of your sender domain and tells you
in plain words what is missing, for example that your SPF record does not include your provider.

**Do I need to change anything in my theme? Does it work with Hyvä?**

No. The module only works in the background and in the admin. Luma, Hyvä and Hyvä Checkout are not affected.

**Can I use it together with another SMTP module?**

No. Switch the other SMTP module off, because both would try to take over the mail transport.
