---
title: Updating ShopClass
description: How to update a ShopClass install — the built-in one-click updater, the manual route, and the database migration step that finishes the job.
sidebar:
  order: 2
---

ShopClass updates itself. When a new release lands, a notice appears in the
admin panel and the built-in updater fetches and applies the package for you.
The manual route below exists for hosts that block outbound HTTP, and for
anyone who prefers to see every file move.

:::caution[Back up first — every time]
Take a copy of your **database** and of **`oc-content/`** before you start.
Everything you have configured lives in one of the two, and an update is much
easier to undo when you can put them back.
:::

## The one-click update

1. Open **Admin → Tools → Update**.
2. If a release is available, the page offers it with its changelog.
3. Press update and wait — the updater downloads the package, replaces core
   files, and runs any pending database migrations.

That is the whole procedure on a healthy install.

## Updating by hand

Use this when the updater cannot reach GitHub, or when you deploy from your own
pipeline.

### 1. Download the release

Get the latest package from the
[**Releases**](https://github.com/mindstellar/shopclass/releases) page and unpack
it locally.

### 2. Replace the core files

Upload the new files over the old ones, replacing:

- `oc-admin/` and everything under it
- `oc-includes/` and everything under it
- the root-level PHP files — `index.php`, `item.php`, `contact.php`,
  `ajax.php`, `oc-load.php`, `oc-cli.php` and their siblings

:::danger[Two things you must not overwrite]
- **`config.php`** — it holds your database credentials. The release does not
  contain one; make sure your upload tool does not delete it.
- **`oc-content/`** — copy only the *contents* of the release's `oc-content`
  into yours. Replacing the whole directory destroys your uploads, your
  installed plugins and any theme you have customised.
:::

### 3. Run the database migration

Core files alone are not an update — the schema has to catch up. Either open the
admin panel, which offers the migration as a button, or run it from a shell:

```bash
php oc-cli.php db:upgrade
```

`db:upgrade` reconciles a drifted schema before applying pending migrations, so
it is also the repair tool when an interrupted update leaves a site half-way.

### 4. Check the site

Load the front page and the admin panel. If you disabled friendly URLs before
updating, turn them back on now.

## When an update goes wrong

**The site shows a blank page.**
Turn on [PHP error display](/docs/developers/debug-php-errors/) temporarily and
read what it says. A blank page after an update is almost always a leftover file
from an older version.

**The admin panel loads unstyled.**
You deployed from a branch rather than a release package. Branches do not carry
the compiled admin CSS and JavaScript. Re-deploy from the release zip.

**A plugin fatals on load.**
Disable it from a shell and update it afterwards:

```bash
php oc-cli.php plugin:deactivate --plugin=<folder>
```

**You are locked out of the admin panel.**

```bash
php oc-cli.php user:reset-password --user=admin
```
