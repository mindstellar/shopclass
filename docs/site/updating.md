---
title: Updating ShopClass
description: How to update a ShopClass install — the built-in one-click updater, the manual route, and the database migration step that finishes the job.
sidebar:
  order: 2
---

ShopClass updates itself. When a new release comes out, a notice appears in
the admin panel, and the built-in updater downloads and applies it for you.
Use the manual route below if your host blocks outgoing web requests, or if
you would rather move every file yourself.

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

## Upgrading to 6.4.0 specifically

Saved search alerts used to store SQL, and it ran as written. The upgrade rewrites
each alert as the plain search values it stands for.

- **Back up `t_alerts` if you might need the old alerts.** The stored SQL is thrown
  away as each alert is converted.
- **An alert holding anything Shopclass did not write** — usually a plugin's own
  filter — is paused, not deleted. **Users → Alerts** lists them under a notice.
  The user has to save the search again.
- **A large site finishes in the background.** The upgrade converts for about ten
  seconds, then queues the rest for the next cron runs. Alerts not converted yet
  send no email until they are. To finish at once:

  ```bash
  php oc-cli.php jobs:work
  ```

## Upgrading to 6.3.0 specifically

Nothing is required. One setting is worth knowing about.

### Strict SQL modes

MySQL and MariaDB can refuse a value that does not fit its column. Shopclass used
to switch that off, so a name too long for its field was quietly cut short instead
of rejected.

**A new install now leaves the strict modes on.** The installer writes this into
`config.php`:

```php
define('OSC_DB_STRICT_MODE', true);
```

**An upgraded site does not get that line**, and keeps the old, forgiving
behaviour. That is deliberate: a plugin that has been silently truncating a value
for years would start failing mid-request.

Before you opt in, run `php oc-cli.php doctor`. Its **Strict SQL mode** line lists any
column holding a zero date (`0000-00-00`), which strict mode refuses the next time that
row is saved. Fix those first.

To opt your site in, add the line to `config.php` yourself. In a container with no
`config.php`, set the environment variable instead:

```
OSC_DB_STRICT_MODE=1
```

Remove it to go back — nothing is stored in the database either way.

:::caution[Try it on a copy first]
Core is tested under strict modes. Third-party plugins write through the same
connection and are not. A plugin storing an over-length or out-of-range value gets
an error where it used to get a silently altered row.
:::

## Upgrading to 6.2.0 specifically

6.2.0 rebuilds foreign keys on **twenty-four tables** so the database removes
dependent rows along with their parent. Three consequences:

- **Back up the database first.** This is the one release where that instruction
  is not boilerplate.
- **It takes time proportional to your row count.** Tests on a quarter of a
  million listings and three quarters of a million custom-field values show the
  whole rebuild takes about six seconds. A much larger site, or slow shared
  hosting, should expect longer.
- **A timeout page does not mean it failed.** The upgrade is still running and
  will finish. With shell access you can sidestep the browser entirely:

  ```bash
  php oc-cli.php db:upgrade
  ```

An interrupted upgrade is **safe to resume** — each step is recorded as it
completes and every step can be re-run, so starting it again finishes it.

Before each key is rebuilt, any row still pointing at a parent that no longer
exists is removed. A healthy database has none; if yours does, they were rows
nothing could reach. The backup is what lets you look at them afterwards.

### Google Analytics is gone from core

The **Tracking ID** field has been removed from **Settings → General** and no
measurement snippet is rendered on public pages. If you were using it, paste
your own snippet into a **Custom Code (HTML / JavaScript)** widget under
**Appearance → Manage widgets**, or install a plugin that provides one.

Your saved measurement ID is left in the database untouched, so a theme printing
its own snippet keeps working.

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
