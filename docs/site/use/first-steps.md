---
title: First steps after installing
description: The settings to get right on day one of a new ShopClass site — site details, categories, locations, cron, mail and spam defences.
sidebar:
  order: 1
---

A fresh install works, but it is empty and its defaults are conservative. Do
the steps below in order.

Everything below lives in the admin panel at `https://example.com/oc-admin/`.

## 1. Site details

**Settings → General.**

Set the site title, contact e-mail and default language. The title appears in
page titles, in e-mail and in the browser tab. Get it right before search
engines see the site.

## 2. Locations

**Listings → Locations**, or `php oc-cli.php location:update --country=IN`.

Visitors filter listings by place. Without location data, they cannot.
Install the countries you serve — see
[installing location data](/docs/configure/locations/).

## 3. Categories

**Categories.**

The default category tree is a starting point, not an answer. Disable what
you will not use and rename the rest to the words your visitors use. Do this
now: every listing belongs to one category, so the tree is hard to change once
listings exist.

See [categories](/docs/use/categories/).

## 4. Cron

Add one crontab entry:

```cron
*/5 * * * * php /path/to/site/oc-cli.php cron >/dev/null 2>&1
```

Without it, e-mail alerts never send and expired listings never expire. This
is the step people skip most, and the bug they report most. See
[setting up cron](/docs/configure/cron/).

## 5. Mail

**Settings → Mail server.**

Account activation, password resets and alerts all need outgoing mail. PHP's
default `mail()` usually lands in spam on shared hosting, so set up real SMTP
before you invite anyone.  See [mail server](/docs/configure/mail-server/).

## 6. Spam defences

**Settings → Spam and bots.**

Turn on a CAPTCHA (Turnstile or reCAPTCHA) before the site is public. Bots
find an open publish form within days of a site going live. See
[spam and abuse](/docs/use/spam-and-abuse/).

## 7. Listing rules

**Listings → Settings.**

Decide these before people start posting. Changing them later is visible to
everyone:

- Whether new listings from users are held for admin approval
  (**Hold new listings for admin moderation**), and whether users must
  validate their own listings before they go live
  (**Users have to validate their listings**), with a threshold after which a
  regular user stops needing to.
- Whether publishing needs an account.
- How many photos a listing may carry.
- How long a user must wait between posts.
- How much notice a seller gets before a listing expires
  (**Warn about expiration**).

Listing **expiry itself is set per category** — *Expiration (days)* on the
category, with an option to apply it to all subcategories. See
[categories](/docs/use/categories/).

## 8. Permalinks

**Settings → Permalinks.**

Turn on friendly URLs and confirm a listing page loads. Do this on day one:
changing URL structure after search engines have indexed your pages means
redirects and lost rankings.

See [permalinks and SEO](/docs/use/permalinks-and-seo/).

## 9. A theme

**Appearance.**

The bundled Storefront theme is a real theme, not a placeholder. Set your
logo and colours before doing anything more ambitious. See
[themes and widgets](/docs/use/themes-and-widgets/).

## 10. Check your work

```bash
php oc-cli.php doctor
```

`doctor` checks the PHP version and extensions, the database, directory
writability, whether cron has actually run recently, and the cache. It exits
non-zero if anything fails.

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
