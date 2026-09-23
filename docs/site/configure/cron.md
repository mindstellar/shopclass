---
title: Set up cron
description: Make ShopClass run its scheduled jobs — alerts, listing expiry and clean-up — with one crontab line, or the built-in fallback.
sidebar:
  order: 1
---

Some jobs must run on a timer, not when someone opens a page:

- sending e-mail alerts
- ending premium listings when they expire
- removing spam and accounts that were never activated
- rebuilding the XML sitemap

A **cron job** is a timer on your server that runs a command on a schedule.
ShopClass uses one for all of these jobs.

**If cron does not run, none of these jobs happen.** This is the most common
reason alerts never send and expired listings still show.

## The recommended setup

1. Add this line to your server's **crontab** (its list of cron jobs):

   ```cron
   */5 * * * * php /path/to/site/oc-cli.php cron >/dev/null 2>&1
   ```

   It runs every five minutes. Each time, ShopClass checks the hourly, daily and
   weekly jobs and runs only the ones that are due. So the short interval costs
   nothing, and alerts go out on time.

2. Turn the fallback **off**, so jobs do not run twice:

   **Admin → Settings → General** → untick **Automatic cron process**.

### Adding the line

Connect to your server over SSH and open your crontab:

```bash
crontab -e
```

Paste the line and save. Then check that it is there:

```bash
crontab -l
```

Use the **CLI** PHP (the command-line program), not the one your web server
uses. If plain `php` does not work, ask your host for its full path. It is often
`/usr/local/bin/php` or `/opt/alt/php82/usr/bin/php`.

### One line per schedule

Older sites often have a line for each schedule. That still works:

```cron
0 * * * *  php /path/to/site/oc-cli.php cron --type=hourly
0 3 * * *  php /path/to/site/oc-cli.php cron --type=daily
0 4 * * 0  php /path/to/site/oc-cli.php cron --type=weekly
```

:::tip
Not sure what the five fields at the start of a line mean?
[crontab.guru](https://crontab.guru/) explains any schedule in plain English.
:::

## When you have no SSH access

Many shared hosts have a cron screen in the control panel instead (cPanel:
**Advanced → Cron Jobs**; Plesk: **Scheduled Tasks**). Give it the same command.

Some panels can only open a web address, not run a command. Then use this:

```bash
wget -qO /dev/null https://example.com/index.php?page=cron
```

Set it to run hourly. It is less reliable than the command: it runs as a web
request, so the web server stops it if it takes too long. It is still much better
than nothing.

## The built-in fallback

If you cannot schedule anything, ShopClass can use visits to your site as its
timer:

**Admin → Settings → General** → tick **Automatic cron process**.

When someone opens a page, ShopClass runs the jobs that are due, at most once
every five minutes. Visitors do not wait for them. On PHP-FPM (the usual way a
server runs PHP), the page is sent first and the jobs run after it.

On other setups, ShopClass asks for its own `?page=cron` address instead.
**This fails when your site sits behind a proxy** (a service such as a CDN in
front of your server). The request goes to the proxy and never reaches your
server. The jobs do not run, and nothing tells you. If that is your setup, use a
real cron job.

This fallback has one more limit: when nobody visits, nothing runs.

Use it to get started. Then move to a real cron job.

:::caution[Never use both]
If **Automatic cron process** is on *and* a cron job runs, jobs can run twice.
Pick one.
:::

## Checking that it works

```bash
php oc-cli.php doctor
```

`doctor` reports **cron freshness**: how long ago the scheduled jobs last
finished. If that number keeps growing, your cron job is not running the command
you think it is.

A cron job runs silently. To see the output, run the command once yourself:

```bash
php /path/to/site/oc-cli.php cron --type=hourly
```

## What runs when

| Schedule | Jobs |
|---|---|
| Hourly | E-mail alerts, expiring premium listings |
| Daily | Cleanup of expired, spam, blocked and unactivated content; alerts |
| Weekly | Longer-running maintenance |

Plugins add their own jobs through the `cron_hourly`, `cron_daily` and
`cron_weekly` hooks.

## Background jobs want their own line

Some slow work runs in the background: moving uploaded images to remote storage,
emptying a large category, or work a plugin adds. Every cron run also works
through this queue. To pick new work up within a minute, add a second line that
does only this work:

```cron
* * * * * php /path/to/site/oc-cli.php jobs:work --max-seconds=50 >/dev/null 2>&1
```

It is safe to run every minute. With no work waiting, it costs one database
query, so you can add it before you need it. See the
[CLI reference](/docs/cli/) and [Background jobs](/docs/developers/jobs/).
