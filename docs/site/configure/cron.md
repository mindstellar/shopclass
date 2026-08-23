---
title: Set up cron
description: Configure scheduled tasks in ShopClass — a system crontab, the built-in fallback, and what breaks when neither is running.
sidebar:
  order: 1
---

Some of what a classifieds site does cannot happen inside a page request. E-mail
alerts have to go out, premium listings have to expire, spam and unactivated
accounts have to be cleaned up, and the XML sitemap has to be regenerated.
ShopClass runs all of that on a schedule.

**Without a working cron, none of it happens.** That is the single most common
cause of "my alerts never send" and "expired listings are still showing".

## The recommended setup

Add one crontab entry on the server and let ShopClass decide what is due:

```cron
*/5 * * * * php /path/to/site/oc-cli.php cron >/dev/null 2>&1
```

That is the whole configuration. The command checks the hourly, daily and weekly
tiers each time it runs and executes only what is actually due, so running it
every five minutes costs nothing and keeps alerts prompt.

Then turn the fallback **off**, so work is not attempted twice:

**Admin → Settings → Cron** → uncheck **Auto-cron**.

### Adding the crontab entry

Over SSH:

```bash
crontab -e
```

Add the line, save, and confirm it registered:

```bash
crontab -l
```

You need the **CLI** PHP binary, not the web server's module. If plain `php` is
not on the path, ask your host for the full path — it is often something like
`/usr/local/bin/php` or `/opt/alt/php82/usr/bin/php`.

### If you prefer separate tiers

The older, explicit form works too, and is what long-running installs already
have:

```cron
0 * * * *  php /path/to/site/oc-cli.php cron --type=hourly
0 3 * * *  php /path/to/site/oc-cli.php cron --type=daily
0 4 * * 0  php /path/to/site/oc-cli.php cron --type=weekly
```

:::tip
Not sure what a crontab expression means? [crontab.guru](https://crontab.guru/)
explains any schedule in plain English.
:::

## When you have no shell access

Many shared hosts do not offer SSH but do offer a cron wizard in the control
panel (cPanel: **Advanced → Cron Jobs**; Plesk: **Scheduled Tasks**). Point it at
the same command.

If the panel only allows fetching a URL rather than running a command, use the
web entry point instead:

```bash
wget -qO /dev/null https://example.com/index.php?page=cron
```

Set it to run hourly. This is weaker than the CLI — it runs inside a web request
and inherits the web server's timeout — but it is far better than nothing.

## The built-in fallback

If you cannot schedule anything at all, ShopClass can piggyback on visitor
traffic instead:

**Admin → Settings → Cron** → check **Auto-cron**.

Due tasks are then triggered by ordinary page views. It works, with two real
costs: nothing runs while the site has no visitors, and one unlucky visitor pays
the cost of the job in their page load.

Use it to get started, then move to a real crontab.

:::caution[Never enable both]
If Auto-cron is checked *and* a system crontab is running, jobs can fire twice.
Pick one.
:::

## Checking that it works

```bash
php oc-cli.php doctor
```

Among its checks, `doctor` reports **cron freshness** — how long since the
scheduled tasks last completed. If that number keeps growing, your crontab is
not running the command you think it is.

Run it manually once to see the output rather than the silence a crontab gives
you:

```bash
php /path/to/site/oc-cli.php cron --type=hourly
```

## What runs when

| Tier | Work |
|---|---|
| Hourly | E-mail alerts, expiring premium listings |
| Daily | Cleanup of expired, spam, blocked and unactivated content; alerts |
| Weekly | Longer-running maintenance |

Plugins add their own work to these tiers through the `cron_hourly`,
`cron_daily` and `cron_weekly` hooks.
