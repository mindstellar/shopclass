---
title: Backups & maintenance
description: Back up a ShopClass site, put it in maintenance mode, clean up dead content, read the activity log and import data.
sidebar:
  order: 14
---

Everything here lives under **Tools** in the admin panel.

## Backups

**Tools → Backup data** exports two things, and a complete backup needs both:

1. **A SQL dump** of the database — listings, users, categories, settings,
   everything. Downloaded directly, or written to a directory on the server.
2. **A zip of the install**, which carries `oc-content/` — uploads, installed
   plugins and themes.

A database dump without the uploads restores a site whose every photo is
missing.

On a large site the zip is the part that fails first: it is built in one request,
so a big uploads directory can exhaust the memory limit or the execution time.
When that happens, use the command line below instead — it has neither limit.

### From the command line

More reliable on a large site, because there is no web-server timeout to hit:

```bash
mysqldump -u USER -p DATABASE | gzip > backup-$(date +%F).sql.gz
tar -czf uploads-$(date +%F).tar.gz oc-content/
```

### Rules that make a backup real

- **Store it somewhere else.** A backup on the same server is not a backup; it
  is a second copy of the thing that will fail.
- **Restore one.** An untested backup is a guess. Restore into a staging copy
  once, and you will find the problem before you need it.
- **Automate it.** A backup you take by hand is a backup you take until you get
  busy.
- **Take one before every update**, migration, cleanup and bulk change. Every
  destructive action in this documentation says so for a reason.

## Maintenance mode

**Tools → Maintenance mode** takes the front end offline while leaving the admin
panel reachable. The screen shows the current state — *maintenance mode is: ON /
OFF* — with a single button to toggle it.

Turn it on before a major update, a large migration or a schema change. It means
visitors see a maintenance page rather than half-broken pages, and nobody
publishes a listing into a database you are in the middle of moving.

:::caution[Do not forget it is on]
A site left in maintenance mode is indistinguishable from a dead one, to
visitors and to search engines alike.
:::

## Cleanup

**Tools → Cleanup** removes dead content in bulk:

| Group | What it removes |
|---|---|
| **Expired listings** | Past their expiration date |
| **Blocked listings** | Disabled or blocked |
| **Spam listings** | Flagged as spam by visitors |
| **Unactivated listings** | Never activated from the confirmation e-mail |
| **Unactivated accounts** | Never activated from the confirmation e-mail |

Run it on demand, or save the settings and let the **daily cron** do it.

On an established site this is what keeps the database fast — dead rows cost you
on every search. Back up before the first run, and think about expired listings
specifically: deleting them 404s pages that may still rank.

## The activity log

**Tools → Activity log** records admin actions with their details and originating IP,
searchable by *details, action or IP*.

This is what answers "who disabled that category" and "when did this setting
change" on a site with more than one admin. It can be filtered, and cleared
entirely.

Behind a reverse proxy, the logged IP is only meaningful if the real client IP
is being passed through — see the
[caching contract](/docs/developers/caching/).

## Import

**Tools → Import data** takes SQL directly — the route for location data,
bulk-loading listings, or anything prepared outside the admin.

It substitutes the `/*TABLE_PREFIX*/` placeholder for your actual prefix, which
is why data prepared for it should keep the placeholder rather than a
hard-coded `oc_`.

Back up first. An import runs whatever SQL you give it.

## Cache

**Tools → Cache** clears the object cache after a bulk import or a direct
database edit — anything that changed data behind the application's back.

```bash
php oc-cli.php cache:flush
```

See [object caching](/docs/configure/cache/).

## System info and health

**Tools → System info** reports the PHP version, memory limit, upload limits,
extensions, database version and disk space — the details every bug report
should include.

The same ground, from a shell, with pass/fail verdicts and a non-zero exit code
when something is wrong:

```bash
php oc-cli.php doctor
```

Run it after any change to the server, and put it in your monitoring.

## A maintenance routine

**Weekly** — check reported listings and the moderation queue; skim new users
for spam registrations.

**Monthly** — run `doctor`; apply core, plugin and theme updates on a staging
copy, then live; check the cleanup ran.

**Quarterly** — restore a backup into staging and confirm it works; review
[location data](/docs/configure/locations/) for updates; re-read your category
tree against what people actually search for.
