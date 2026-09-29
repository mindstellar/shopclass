---
title: Backups & maintenance
description: Back up and restore a ShopClass site, put it in maintenance mode, clean up dead content and read the activity log.
sidebar:
  order: 14
---

Everything here lives under **Tools** in the admin panel.

## Backups

**Tools → Backup and restore** makes a backup of:

- **Database** — listings, users, categories, settings.
- **Files** — `oc-content/`: photos, plugins, themes, languages. Core files are
  not included; the release zip is their backup.
- **Everything** — both.

Download it to your computer, or save it on the server in
`oc-content/downloads/backups/`. That folder is closed to the web by an
`.htaccess` file; on nginx add the rule in
[security](/docs/deploy/security/#the-backups-folder). The last 5 server backups are
kept. Every backup is one `.zip` with a `manifest.json` that records the
Shopclass version and what it holds.

The backup runs in the background, so a large site does not time out. It keeps
going while the page is open, and with [cron](/docs/configure/cron/) set up it
also carries on after you close it. One backup runs at a time, and it can be
cancelled.

A linked folder that points outside `oc-content` (a theme symlinked from elsewhere)
is skipped and named on the page. With photos offloaded to S3, the photos stay
in the bucket and are not copied; turn on versioning in the bucket.

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
| **Avatars of deleted users** | Profile pictures left behind by deleted accounts |

Tick which groups to clean, and set **Older than** (in days) for each one. For
Reported listings the age counts from the listing's last change, so an owner who
fixes a reported listing gets more time.

Run it on demand with **Run cleanup now**, or save the settings and let the
**daily cron** do it. Either way it runs in the background, a batch at a time, until
nothing matches, so a large backlog never times out a page. **Items removed per
batch** sets the batch size. The background work runs on [cron](/docs/configure/cron/).
**Recent cleanups** lists what each finished run removed, and **Tools → Background
jobs** shows what is still waiting.

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

## Restore

**Tools → Backup and restore** puts a backup back: **Restore…** on a saved
backup, or **Restore from a file** for a `.zip` or an old `.sql` backup, up to
the server's upload limit.

Before anything changes, the page refuses a backup made by a newer Shopclass, or
an old `.sql` file whose tables use another prefix. The confirm lets you put back
only the database or only the files of an "Everything" backup.

While it runs, visitors see the maintenance page. A safety copy of the database
is saved first, and if loading the backup fails the safety copy is put back.
Database updates run after an older backup. Files are written over; files added
since the backup are left in place. While a restore runs, cron and
`oc-cli.php jobs:work` carry it on and run nothing else.

Only restore backups you made. A restore runs the SQL and puts back the files in
it, and it brings back the admin passwords and keys it holds.

The confirm asks for your admin password, and for a code from your app or a
backup code when [two-step sign-in](/docs/use/spam-and-abuse/#two-step-sign-in-for-admins) is on. Wrong
tries count toward the same limit as failed sign-ins.

### Turning web restore off

To allow restores only from a shell, add this to `config.php`, or set the
environment variable `OSC_DISABLE_WEB_RESTORE=1`:

```php
define('OSC_DISABLE_WEB_RESTORE', true);
```

The page then hides the restore buttons and says restore is turned off. Backups
still work. To restore by hand, load the `.sql` file with `mysql` and unzip the
files over `oc-content/`.

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

**Tools → System info** opens on **Overview**: one box that says whether
anything needs doing, one line per problem with a button to fix it, then the key
facts — versions, web server, last backup, cron, photo storage. It asks for a
backup when none was saved on the server in the last 30 days.

**Server** lists PHP, its limits and extensions, the uploads and backups
folders, cron, the object cache and the site's paths — the details every bug
report should include — with a short guide to changing them.

The same ground, from a shell, with pass/fail verdicts and a non-zero exit code
when something is wrong:

```bash
php oc-cli.php doctor
```

Run it after any change to the server, and put it in your monitoring.

## Database

The **Database** tab of **Tools → System info** holds the database checks:

- **Status** — one box saying whether anything needs doing, then the
  Shopclass and database versions, the server, the table count and size, and
  the table prefix.
- **Database update** — shown only when updates are waiting. **Run database
  update** runs them, the same as `php oc-cli.php db:upgrade`.
- **Check and repair** — where the database differs from what ShopClass
  expects, grouped by what to do about it. **Repair** appears only when there is
  something it can fix: a missing table, column or index, or a column with the
  wrong type. Take a backup first. An extra column or index is left alone; a
  nullability difference or an index with the wrong columns needs a person to
  look at it.

From a shell: `php oc-cli.php db:doctor` reports, `php oc-cli.php db:repair`
repairs.

## A maintenance routine

**Weekly** — check reported listings and the moderation queue; skim new users
for spam registrations.

**Monthly** — run `doctor`; apply core, plugin and theme updates on a staging
copy, then live; check the cleanup ran.

**Quarterly** — restore a backup into staging and confirm it works; review
[location data](/docs/configure/locations/) for updates; re-read your category
tree against what people actually search for.
