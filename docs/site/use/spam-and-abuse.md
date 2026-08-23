---
title: Spam & abuse
description: Defend a ShopClass site against spam and bots — Turnstile or reCAPTCHA, the keyword blocklist, login throttling, Akismet and rate limits.
sidebar:
  order: 5
---

A classifieds site with an open publish form is found by bots within days of
going live. ShopClass ships several defences; none of them is on by default,
because each one costs a real visitor something.

**Settings → Spam and bots** holds most of them.

## CAPTCHA

Pick one provider and fill in its two keys. The admin validates them as you save
and tells you plainly — *This key is valid*, or *The key you entered is invalid.
Please double-check it* — so you find out immediately rather than when a visitor
cannot post.

| Provider | Keys |
|---|---|
| **Cloudflare Turnstile** | Turnstile site key and Turnstile secret key |
| **Google reCAPTCHA** | reCAPTCHA site key and reCAPTCHA secret key |

**Turnstile is the better default.** It is free without volume limits, usually
invisible to the visitor, and does not send your users' behaviour to an ad
company. reCAPTCHA is there because many sites already have keys for it.

Once a provider is configured, choose where the challenge appears — publishing a
listing, contacting a publisher, registering, and posting a comment. Turning it
on for **publishing** and **registration** stops most of what matters; turning
it on everywhere annoys real users for little extra gain.

:::caution[The site key is public, the secret key is not]
The site key is rendered into the page — that is normal. The secret key
authenticates your server to the provider and must never appear in a theme, a
repository or a support post.
:::

## Login throttling

Repeated failed logins are throttled per IP and per account, so a password
guesser is slowed to uselessness without locking out a real user who mistyped.

| Setting | Default |
|---|---|
| Window | 15 minutes |
| Maximum attempts per IP | 20 |
| Maximum attempts per account | 10 |
| Attempt log retention | 7 days |

The per-account limit is the one that matters against a targeted attack; the
per-IP limit catches broad scanning.

:::danger[Behind a proxy, throttling needs the real client IP]
If your site sits behind Cloudflare, a tunnel or any reverse proxy and the real
client IP is not being passed through, **every visitor looks like the proxy**.
The per-IP limit then throttles your entire audience as one person, and abuse
reports all key to the same address. Set the real-IP header before you rely on
either. See the [caching contract](/docs/developers/caching/).
:::

## The keyword blocklist

**Settings → Keyword blocklist** rejects listings containing words you choose.

Each keyword can be matched against:

- **Title only**
- **Description only**
- **Title and description**
- **Custom fields**

Keywords can be added one at a time or **imported** in bulk.

Use it for the specific spam your site actually attracts — the phrases in the
listings you keep deleting — not for a generic profanity list. Broad keywords
catch real listings: blocking "free" on a marketplace blocks "free delivery".

## Akismet

An Akismet API key enables comment and listing spam checking through the same
service WordPress uses. It is worth having on a site with open comments, and
redundant on a site where comments are closed or moderated.

## Rate limits and registration rules

The settings that do the most, and are easiest to forget:

- **Settings → Listings → wait *n* seconds between listings.** A bulk poster is
  stopped by a delay long before they are stopped by a CAPTCHA.
- **Settings → Listings → only logged in users can post.** The single biggest
  reduction in spam volume, at the cost of some genuine posts.
- **Settings → Listings → users need to validate their listing.** A confirmation
  e-mail per listing means a spammer needs a working mailbox per listing.
- **Settings → Users → users need to validate their account.** The same, for
  registration.
- **Users → Manage ban rules.** Block a returning abuser's address, domain or IP
  pattern.

## A defence worth having

In the order they cost your real visitors the least:

1. Turnstile on publishing and registration.
2. E-mail validation for listings and accounts.
3. A posting delay of 60 seconds or more.
4. A keyword blocklist built from the spam you actually receive.
5. Registration required to post, if the first four are not holding.

Then check **Listings → Reported listings** weekly and let
[Tools → Cleanup](/docs/use/backups-and-maintenance/) clear out what you mark.
