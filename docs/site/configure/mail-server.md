---
title: Mail server
description: Set up SMTP in ShopClass so sign-up, alert and contact e-mails reach the inbox — the settings, provider examples and what to do when mail goes missing.
sidebar:
  order: 2
---

ShopClass sends e-mail for account activation, password resets, listing alerts
and the contact form.

Out of the box, it hands these messages to PHP's `mail()`. On most shared hosting,
that sends them from a local server that nobody has verified, and they land in
spam.

**SMTP** is the standard way to send mail through a real mail server. Setting it
up is the best thing you can do to get your mail delivered.

The settings are at **Admin → Settings → Mail server**.

## The settings

| Field | What to enter |
|---|---|
| **Hostname** | Your provider's SMTP server, e.g. `smtp.example.com`. |
| **Server port** | `587` for most providers. `465` if your provider says so. |
| **Username** | The mailbox or API user. Usually the full e-mail address. |
| **Password** | Its password, an app password, or an API key. |
| **Encryption** | **TLS** for port 587, **SSL** for port 465. Choose **None** only for a mail server on your own trusted network. |
| **SMTP authentication enabled** | Tick it. |

:::caution[Tick SMTP authentication enabled]
ShopClass sends through your SMTP server only when this box is ticked, or when
**Use POP before SMTP** is ticked. If neither is ticked, it uses PHP's `mail()`.
:::

:::caution[Use port 587 with TLS unless your provider says otherwise]
Most hosting providers and home networks block port 25 for outgoing mail. If
mail never leaves, check this first.
:::

## Sending through a mail provider

A sending service such as Postmark, Mailgun, Amazon SES, Brevo or SendGrid will
improve delivery more than any setting in ShopClass. Each one gives you SMTP
details that go into the fields above.

Whichever you pick, finish their domain check. They give you three DNS records
(entries you add at the company that runs your domain name). Add all three:

- **SPF** says which servers may send mail for your domain.
- **DKIM** signs each message, so receivers can check it really came from you.
- **DMARC** tells receivers what to do when the first two checks fail.

Without all three, receivers cannot tell your mail from a fake, however you send
it.

## Sending through Gmail or Google Workspace

This works, and it is fine for a small site. But Google limits how much you can
send each day. It also flags a classifieds site's alert mail as suspicious
sooner than a sending service would.

| Field | Value |
|---|---|
| Hostname | `smtp.gmail.com` |
| Server port | `587` |
| Username | Your full address, e.g. `you@gmail.com` |
| Password | An [app password](https://support.google.com/accounts/answer/185833), not your account password |
| Encryption | **TLS** |
| SMTP authentication enabled | Ticked |

An app password needs 2-Step Verification turned on for the account. Gmail has
not accepted your normal account password for SMTP for years.

## Testing

Save your settings, then press **Send a test email** on the same screen. It
sends a short message to your site's contact address.

Then try a real message: register a test account, or use the contact form. If
nothing arrives, look in the spam folder before you decide the send failed.

## Troubleshooting

**No e-mail arrives at all.**
Check the hostname, port and encryption for typos. Check that
**SMTP authentication enabled** is ticked. Then ask your host whether it blocks
outgoing mail. Many hosts block port 25, and some block 587 until you ask. Port
465 is often the one left open.

**Mail arrives, but always in spam.**
Receivers cannot verify your domain. Add the SPF, DKIM and DMARC records for the
address in **Mail from**. Use an address on a domain you own, not a free mailbox.

**It worked, then stopped.**
Either the provider stopped your sending, or a burst of alert mail hit a sending
limit. Look on the provider's dashboard for bounces, complaints or limits. You
will not see this in ShopClass.

**Some people get it, others never do.**
That is your domain's reputation, not your settings. Move to a sending service
that has already built a good reputation for its servers.
