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

The settings are in **Settings → Spam and bots → Sign-in protection**:

| Setting | Default |
|---|---|
| Count failures from the last | 15 minutes |
| Failures per IP address | 20 |
| Failures per account | 10 |
| Keep records for | 7 days |

The per-account limit is the one that matters against a targeted attack; the
per-IP limit catches broad scanning.

**Failed sign-ins right now**, under **Tools → System info → Security**, lists every
address and account with recent failures and says which are blocked. **Unblock** lets one of them try again at
once; **Unblock everyone** clears them all.

:::danger[Behind a proxy, throttling needs the real client IP]
If your site sits behind Cloudflare, a tunnel or any reverse proxy and the real
client IP is not being passed through, **every visitor looks like the proxy**.
The per-IP limit then throttles your entire audience as one person, and abuse
reports all key to the same address. Set the real-IP header before you rely on
either. See the [caching contract](/docs/developers/caching/).
:::

## Two-step sign-in for admins

Each admin can add a second step to their sign-in: a 6-digit code from an
authenticator app, such as Google Authenticator, Microsoft Authenticator or
Aegis. Open your account menu, top right, and choose **Edit profile**. Under
**Two-step sign-in**, choose **Set up**, scan the QR code and type the code the
app shows.

You then get 8 backup codes, shown once. Keep them somewhere safe. Each one
works once, in place of an app code, if you lose your phone. **New backup
codes** replaces them, and **Turn off** removes the second step. Both ask for a
current code.

After 10 wrong codes in 15 minutes, the code step waits until the 15 minutes
are up. Someone who knows the password can keep an admin waiting this way. After
5 wrong codes in 15 minutes, or more than 10 in a day, the admin gets an e-mail
saying so, at most once an hour: change the password when it comes. Changing the site's signing key
(`OSC_CSRF_SECRET`) makes every backup code stop working; the app codes still
work, and **New backup codes** issues fresh ones.

If an admin loses both their phone and their backup codes, a full administrator
can turn it off on that admin's edit screen, or from the command line:

```bash
php oc-cli.php user:2fa-off --user=<username>
```

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

## Messages

The contact form, contact the seller, contact a user and share a listing all
send e-mail.

**Confirmed senders only.** Mail to a member leaves only from an address the
sender has proved is theirs. A signed-in member's own address counts. Anyone
else gets a link at the address they typed; the message is sent when they click
it, and waits at most 24 hours. After one click, that browser sends straight
away for 30 days. One message per address can wait at a time. A file can be
attached only once the address is confirmed. The site's own contact form works
the same way.

**Mail from your contact form** ends with *Report the sender* too. It bans the
address from the whole site for good: sign-in, posting and messages. Only a
signed-in admin can use it; anyone else is asked to sign in first.

**Settings → Spam and bots → Messages:**

- **Links allowed in a message.** Default 1, counted in the message. A message
  with more is refused and the sender is told why. 0 allows none. Names may not
  hold links or markup, and a phone number may hold only digits, spaces,
  `+ ( ) - .` and an extension.
- **Report link.** Mail a member receives ends with *Report the sender*. The
  member confirms on a page, and the sender's address cannot send messages for
  the set number of days (default 30). Sign-in and posting still work. Each link
  works once. The rule shows under **Users → Ban rules** as *Messages only, until
  …*, where you can delete it early.

Each form also has an hourly limit per IP address. The ban list applies to all
of them, and to e-mail fields on your own forms.

## Rate limits and registration rules

The settings that do the most, and are easiest to forget:

- **Listings → Settings → An user has to wait _n_ seconds between each listing
  added.** A bulk poster is stopped by a delay long before they are stopped by a
  CAPTCHA.
- **Listings → Settings → Only logged in users can post listings.** The single
  biggest reduction in spam volume, at the cost of some genuine posts.
- **Listings → Settings → Users have to validate their listings.** Holding new
  listings until they are validated stops spam reaching visitors at all. Set the
  threshold so a user stops needing to validate after a few approved listings,
  and the cost falls to almost nothing once your regulars are established.
- **Users → Settings → Users need to validate their account.** The same idea,
  for registration.
- **Users → Ban rules.** Block a returning abuser's address, domain or IP
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
