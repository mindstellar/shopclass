---
title: Mail server
description: Configure SMTP in ShopClass so registration, alert and contact e-mails actually arrive — settings, provider examples and deliverability troubleshooting.
sidebar:
  order: 2
---

ShopClass sends e-mail for account activation, password resets, listing alerts
and the contact form. Out of the box it hands those messages to PHP's `mail()`,
which on most shared hosting means they are sent from an unauthenticated local
server and land in spam.

Configuring real SMTP is the single biggest deliverability improvement you can
make.

Settings live at **Admin → Settings → Mail server**.

## The settings

**Server type** chooses a provider slot. Custom, Gmail, Brevo, SMTP2GO and
Amazon SES each keep their own host, port, username and password. Saving one
does not erase the others — switch the menu to recall a saved provider, then
**Save** on the one that should send. The live `mailserver_*` preferences are
still what `osc_sendMail()` reads; the extra slots are only so you can park a
second provider without retyping it.

Choosing a type fills a typical host and port when that slot is still empty.
Amazon SES uses `email-smtp.us-east-1.amazonaws.com` as a placeholder — change
the region to match your SES account. If outbound port 587 is blocked, Brevo
and SMTP2GO also accept **2525** with `tls`.

| Field | What to enter |
|---|---|
| **Hostname** | Your provider's SMTP host, e.g. `smtp.example.com`. |
| **Server port** | `587` for STARTTLS (the modern default), `465` for implicit TLS. |
| **Username** | The mailbox or API user. Usually the full e-mail address. |
| **Password** | Its password, or an app-specific password / API key. |
| **Encryption** | `tls` for port 587, `ssl` for port 465. Leave blank only on a trusted local relay. |
| **SMTP authentication** | Check it whenever you filled in a username and password. |

:::caution[Use port 587 with `tls` unless told otherwise]
Port 25 is blocked outbound by most hosting providers and by most residential
networks. If mail silently never leaves, this is the first thing to check.
:::

## Sending through a transactional provider

A dedicated sending service — Postmark, Mailgun, Amazon SES, Brevo, SendGrid and
others — will do more for your delivery rate than any setting in ShopClass. They
all expose plain SMTP credentials that drop into the fields above.

Whichever you use, complete their domain verification and publish the DNS
records they give you:

- **SPF** — authorises the provider to send as your domain.
- **DKIM** — signs your messages so receivers can verify them.
- **DMARC** — tells receivers what to do when the first two fail.

Without those three, your mail is unauthenticated no matter how it is sent.

## Sending through Gmail or Google Workspace

Possible, and fine for a small site, but understand the limits: Google caps
daily volume and will mark a classifieds site's alert traffic as suspicious
sooner than a transactional provider would. Choosing **Gmail Server** fills
`smtp.gmail.com`, port **465**, encryption **ssl** (implicit TLS). Port 587
with `tls` also works if you switch to Custom and enter it by hand.

| Field | Value |
|---|---|
| Hostname | `smtp.gmail.com` |
| Server port | `465` (or `587` with `tls`) |
| Username | Your full address, e.g. `you@gmail.com` |
| Password | An [app password](https://support.google.com/accounts/answer/185833) — not your account password |
| Encryption | `ssl` on 465, `tls` on 587 |
| SMTP authentication | Checked |

An app password requires 2-Step Verification on the account. Plain account
passwords have not worked for SMTP for years.

## Testing

**Send a test email** on the Mail Settings screen sends a message to the
contact address. Prefer that over guessing. If nothing arrives, check the spam
folder before assuming the send failed. A real registration or the contact form
is the next check.

## Troubleshooting

**No e-mail arrives at all.**
Re-read the hostname, port and encryption for typos. Then confirm your host does
not block outbound SMTP; many block port 25 and some block 587 unless you ask.
Port 465 is often the one left open.

**Mail arrives, but always in spam.**
Your domain is not authenticated. Publish SPF, DKIM and DMARC records for the
address in the **From** field, and make sure that address is on a domain you
control — not a free mailbox.

**It worked, then stopped.**
Either the provider suspended sending — check their dashboard for a bounce or
complaint threshold you crossed — or a burst of alert e-mail hit a rate limit.
Both are visible on the provider's side, not in ShopClass.

**Some recipients get it, others never do.**
That pattern is reputation, not configuration. Move to a transactional provider
with a warmed sending domain.
