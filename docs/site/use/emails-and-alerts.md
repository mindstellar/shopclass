---
title: E-mail templates & alerts
description: Edit the e-mails ShopClass sends — activation, password reset, contact and alerts — and understand which ones depend on cron.
sidebar:
  order: 13
---

ShopClass sends e-mail at almost every meaningful moment: account activation,
listing validation, password resets, contact-form messages, comment
notifications and saved-search alerts. All of them are editable.

**Settings → Email templates.**

## Editing a template

Click a template to open it. Two fields, per language:

- **Subject** — the e-mail's subject line.
- **Message** — the e-mail's body.

Core and installed plugins each register their own templates, so a plugin that
sends mail adds its own to this list.

### Language tabs

Each field has a tab per active language. The tab for your own admin language
opens first and is marked with a dot, so you always land where you can read what
you write.

### Placeholders

The **Placeholders** panel on the right lists the values ShopClass fills in when
it sends the e-mail — the user's name, the listing title, the confirmation link.
It is split in two:

- **In this email** — placeholders only this template uses.
- **In every email** — placeholders available on every template.

Click a placeholder to insert it at the cursor, in whichever field (Subject or
Message) you were last typing in.

**Keep the placeholders.** A template missing its link placeholder sends an
activation e-mail nobody can act on, and the send still looks successful.

### Sending a test

The **Send a test** panel sends the subject and message exactly as they are on
screen — placeholders are not filled in. The address is prefilled with your own
admin e-mail; change it, then click **Send test email**.

Use it after editing, especially on the activation and password-reset templates:
those two are the ones that lock people out when they are wrong.

### Internal name

The **Template** panel shows the template's **internal name**, read-only. This
is how core finds the template, so it cannot be changed.

## The templates that matter most

| Template | Why it matters |
|---|---|
| **Account activation** | A user who never gets this cannot register. |
| **Listing validation** | A seller who never gets this has an invisible listing. |
| **Password reset** | The only self-service route back in. |
| **Contact publisher** | The message that makes the marketplace work at all. |
| **Alerts** | Saved-search notifications — the thing that brings users back. |

## Notifications to you

Several settings decide when the admin gets mail:

- **Listings → Settings → notify admin when a new listing is added**
- **Users → Settings → when a new user is registered**
- **Settings → Comments → Notifications** — when a comment is posted, and when
  one is held for moderation

All of these are useful in week one and unbearable at volume. Turn them off when
the site is busy and moderate from the admin lists instead.

## Alerts and cron

Saved-search alerts are **not** sent when a listing is published. They are sent
by **cron**, on the schedule each user picked for their saved search — hourly,
daily or weekly.

That means the single most common report — "my users never get alerts" — is
almost always a missing crontab entry, not a mail problem. Check it first:

```bash
php oc-cli.php doctor          # reports cron freshness
```

See [setting up cron](/docs/configure/cron/).

Alerts themselves are managed at **Users → Alerts**, where you can see,
enable, disable and delete users' saved searches.

## When e-mail does not arrive

Work through it in this order:

1. **Is it a cron e-mail?** Alerts and anything scheduled need cron running.
2. **Is SMTP configured?** PHP's default `mail()` usually lands in spam — see
   [mail server](/docs/configure/mail-server/).
3. **Is the domain authenticated?** SPF, DKIM and DMARC for the sending address.
4. **Is the template intact?** A broken template can produce mail that arrives
   but is useless.
5. **Check the spam folder** before concluding nothing was sent.
