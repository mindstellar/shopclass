---
title: First steps after installing
description: The settings to get right on day one of a new ShopClass site — site details, categories, locations, cron, mail and spam defences.
sidebar:
  order: 1
---

A fresh install works, but it is empty and its defaults are conservative. This is
the order worth doing things in, and why each one matters.

Everything below lives in the admin panel at `https://example.com/oc-admin/`.

## 1. Site details

**Settings → General.**

Set the site title, contact e-mail and default language. The title appears in
page titles, in e-mail and in the browser tab, so it is worth getting right
before search engines see the site.

## 2. Locations

**Settings → Locations**, or `php oc-cli.php location:update --country=IN`.

A classifieds site without location data cannot filter by place, which is half
of what makes it useful. Install the countries you actually serve — see
[installing location data](/docs/configure/locations/).

## 3. Categories

**Categories.**

The default tree is a starting point, not an answer. Disable what you will not
use and rename the rest to the words your visitors use. Category structure is
hard to change once listings exist, because every listing belongs to one — so
spend time here now rather than later.

See [categories](/docs/use/categories/).

## 4. Cron

Add one crontab entry:

```cron
*/5 * * * * php /path/to/site/oc-cli.php cron >/dev/null 2>&1
```

Without it, e-mail alerts never send and expired listings never expire. This is
the most commonly skipped step and the most commonly reported bug. See
[setting up cron](/docs/configure/cron/).

## 5. Mail

**Settings → General → Mail server.**

Account activation, password resets and alerts all depend on outgoing mail, and
PHP's default `mail()` on shared hosting usually lands in spam. Configure real
SMTP before you invite anybody. See [mail server](/docs/configure/mail-server/).

## 6. Spam defences

**Settings → Spam and bots.**

Turn on a CAPTCHA — Turnstile or reCAPTCHA — before the site is public. A
classifieds site with an open publish form is found by bots within days of going
live. See [spam and abuse](/docs/use/spam-and-abuse/).

## 7. Listing rules

**Settings → Listings.**

Decide the rules before people start posting, because changing them later is
visible to everyone:

- whether listings need e-mail activation before they appear
- whether publishing requires an account
- how many photos a listing may carry
- how long a listing lives before it expires
- how long a user must wait between posts

## 8. Permalinks

**Settings → Permalinks.**

Turn on friendly URLs and confirm a listing page loads. Doing this on day one
matters: changing URL structure after you have indexed pages means redirects and
lost rankings. See [permalinks and SEO](/docs/use/permalinks-and-seo/).

## 9. A theme

**Appearance.**

The bundled Storefront theme is a real theme, not a placeholder. Set your logo
and colours before doing anything more ambitious. See
[themes and widgets](/docs/use/themes-and-widgets/).

## 10. Check your work

```bash
php oc-cli.php doctor
```

It checks the PHP version and extensions, the database, directory writability,
whether cron has actually run recently, and the cache — and exits non-zero if
anything fails.

Then post a listing yourself, from a logged-out browser, exactly as a visitor
would. It is the fastest way to find the thing you forgot.

## A first-week checklist

- [ ] Site title, contact e-mail and language set
- [ ] Location data installed for your countries
- [ ] Category tree pruned and renamed
- [ ] Cron running, verified with `doctor`
- [ ] SMTP configured, test e-mail received
- [ ] CAPTCHA enabled
- [ ] Listing expiry, photo limits and posting rules decided
- [ ] Friendly URLs on
- [ ] Logo and theme colours set
- [ ] A test listing posted end to end, logged out
- [ ] [A backup taken](/docs/use/backups-and-maintenance/)
