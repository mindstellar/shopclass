---
title: How to write a bug report
description: What to include in a ShopClass bug report so it can actually be reproduced and fixed.
sidebar:
  order: 15
---

One rule governs everything below:

:::tip[If we cannot reproduce it, we cannot fix it.]
:::

When a report arrives, someone reads it and tries to make the bug happen on
their own machine. If they can, they find the cause and fix it. If they cannot,
they have to come back and ask you for details — and the fix waits for the round
trip. Most reports that go unfixed are not ignored; they are unreproducible.

## What to include

1. **What were you trying to do?**
2. **What did you click or do last?**
3. **What happened, and what did you expect instead?** Quote the error message
   exactly — "it says an error" is not the error.
4. **What version of ShopClass?** `php oc-cli.php version`, or the admin
   dashboard.
5. **What PHP version and hosting?** Shared, VPS, Docker — and the PHP version.
   `php oc-cli.php doctor` reports both, along with your extensions and database
   version.
6. **What theme and plugins are active?** `php oc-cli.php plugin:list` and
   `php oc-cli.php theme:list`.
7. **Which browser**, if it is a front-end or admin panel problem.

**Screenshots for step 3 are worth a lot** — they show exactly what you saw
instead of your description of it.

## Where to report it

| What | Where |
|---|---|
| Core | [mindstellar/shopclass issues](https://github.com/mindstellar/shopclass/issues) |
| A plugin | That plugin's own repository — name and version in the report |
| A theme | That theme's own repository — name and version |
| Not sure it is a bug | [Discussions](https://github.com/mindstellar/shopclass/discussions) first |
| A security vulnerability | **Privately** — see the [security policy](https://github.com/mindstellar/shopclass/blob/master/SECURITY.md). Never a public issue. |

## Narrowing it down first

Ten minutes here saves days of back-and-forth:

- **Switch to the default theme.** If the bug disappears, it is the theme's.
- **Disable plugins one at a time** — `php oc-cli.php plugin:deactivate
  --plugin=<folder>`. If one makes it stop, name that plugin in the report.
- **Turn on error logging** and include what it says — see
  [debug PHP errors](/docs/developers/debug-php-errors/).
- **Search existing issues.** Your problem may already have a patch or a
  workaround waiting.

## An example worth copying

> **Publishing a listing with more than 4 photos fails silently**
>
> ShopClass 6.1.0, PHP 8.2, shared hosting (SiteGround), Storefront theme, no
> plugins active.
>
> 1. Go to /item/new, fill in the form, attach 5 JPEGs of ~3MB each
> 2. Press Publish
> 3. The page reloads on the form with no error message; the listing is not
>    created. With 4 photos it works.
>
> `oc-content/debug.log` shows:
> `PHP Warning: POST Content-Length of 16932819 bytes exceeds the limit`
>
> Expected: an error telling me the upload was too large, rather than a silent
> reload.

That report contains everything needed to reproduce it, and it names the
suspected cause without insisting on it. It would be fixed the same day.
