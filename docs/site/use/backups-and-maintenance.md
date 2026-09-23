---
title: Backups & maintenance
description: Back up a ShopClass site, put it in maintenance mode, clean up dead content, read the activity log and import data.
sidebar:
  order: 14
---

Everything here lives under **Tools** in the admin panel.

## Backups

**Tools → Backup data** has a **Backup folder** field (where server-side
backups are written) and a **Backup Method** dropdown with three choices, and a
complete backup needs both kinds:

1. **Backup SQL (download file)** or **Backup SQL (store on server)** — a dump
   of the database: listings, users, categories, settings, everything.
2. **Backup files (store on server)** — a zip of the whole install folder: the
   application code plus `oc-content/` (uploads, installed plugins and
   themes). There is no direct-download option for this one; it is always
   written to the backup folder.

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

**Tools → Maintenance mode** puts the site into maintenance while you work. Signed-in
admins always keep full access. What everyone else sees is up to you.

The top of the screen shows the current state — *Maintenance mode is: ON / OFF* —
with one button to switch it.

### Two ways to run it

Under **Visitors** there is a checkbox, **Block the public site (HTTP 503)**. (A 503 is the "temporarily unavailable"
answer a server gives browsers and search engines.)

| Checkbox | What a visitor gets |
|---|---|
| **Ticked** (the default) | An HTTP 503 page carrying your message. Nobody can browse or post. |
| **Unticked** | The site as normal, with your message as a banner across the top. |

Tick it before a major update, a large migration or a schema change — nobody
publishes a listing into a database you are in the middle of moving.

Leave it unticked for work that does not risk the data: a theme change, a price
update, a slow import. Visitors keep shopping and know something is going on.

Your choice is remembered when you turn maintenance mode off again.

### The message

The **Message** box under the checkbox is shown on the banner and on the 503 page.
Plain text only, up to 500 characters — HTML is stripped. Leave it blank and
ShopClass writes a polite default using your site name.

:::caution[Do not forget it is on]
A site left blocked is indistinguishable from a dead one, to visitors and to
search engines alike. The banner mode carries no such risk.
:::

:::note[The cron keeps running]
Blocking the public site does not stop scheduled jobs. An upgrade in progress is
the one exception: it locks out everything except a signed-in admin.
:::

## Cleanup

**Tools → Cleanup** removes dead content in bulk:

| Group | What it removes |
|---|---|
| **Reported listings** | Flagged by visitors as spam |
| **Expired listings** | Past their expiration date |
| **Blocked listings** | Disabled or blocked |
| **Spam listings** | Marked as spam |
| **Unactivated listings** | Never activated from the confirmation e-mail |
| **Unactivated users** | Never activated from the confirmation e-mail |

Tick which groups to clean, and set **Older than** (in days) for each one
except Reported listings, which has no age limit. **Maximum items removed per
run** caps each run so it cannot time out on a large backlog — run it again to
work through the rest.

Run it on demand with **Run cleanup now**, or save the settings and let the
**daily cron** do it.

On an established site this is what keeps the database fast — dead rows cost you
on every search. Back up before the first run, and think about expired listings
specifically: deleting them turns pages that may still rank in search into a
404 (page not found) error.

The same screen also has a **Listing statistics** section: whether to count
listing views at all, whether to count crawler visits as views (off by
default), and how many days of daily view history to keep.

## The activity log

**Tools → Activity log** records admin and listing activity, with details and
originating IP, searchable by *details, action or IP*.

This is what answers "who disabled that category" and "when did this setting
change" on a site with more than one admin. It can be filtered, and cleared
entirely.

Behind a reverse proxy (a server such as a CDN or load balancer sitting in
front of yours), the logged IP is only meaningful if the real client IP is
being passed through — see the
[caching contract](/docs/developers/caching/).

## Import

**Tools → Import data** takes a `.sql` file — the route for location data,
bulk-loading listings, or anything prepared outside the admin.

It substitutes the `/*TABLE_PREFIX*/` placeholder for your actual table
prefix, which is why SQL prepared for it should keep the placeholder rather
than a hard-coded `oc_`.

Back up first. An import runs whatever SQL you give it.

## Cache

**Tools → Cache** shows which object-cache driver is running (in-request only
by default, or APCu, Memcached or Memcache if installed), whether it keeps
data between requests, and stats such as entries, hit rate and memory use when
the driver reports them.

The default driver holds nothing between requests, so there is nothing to
clear, and the **Clear cache** button is greyed out. To cache between requests,
install one of the extensions the screen lists and set
`define('OSC_CACHE', 'apcu');` (or `memcached`) in your config file.

Once a persistent driver is active, use **Clear cache** after a bulk import or
a direct database edit — anything that changed data behind the application's
back:

```bash
php oc-cli.php cache:flush
```

See [object caching](/docs/configure/cache/).

## System info and health

**Tools → System info** reports the PHP version, memory limit, upload limits,
extensions, database server details and free disk space — the details every
bug report should include.

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
