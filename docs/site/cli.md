---
title: Command-line interface
description: The oc-cli.php reference for ShopClass — cron, database migrations, backups, admin recovery, plugin and theme management, health checks.
sidebar:
  order: 4
---

ShopClass ships a small command-line tool for the jobs that do not belong in a
browser: scheduled tasks, database migrations, recovering a locked-out admin, and
installing packages on a server you deploy to from a script.

```bash
cd /path/to/your/site
php oc-cli.php <command> [options]
php oc-cli.php help          # list every command
```

:::note[It cannot be reached over HTTP]
`oc-cli.php` only runs from the command line. If you request it through a web
browser, it answers `403` (forbidden) instead of running. So every command
below needs a shell (a command-line session) on the server — which is also why
these commands can do things the admin panel will not.
:::

A site configured from the environment with no `config.php` needs its address for
the command line: set `WEB_PATH`, or `OSC_CLI_URL` to give the address to the
command line only. Use the exact address visitors use, or the command line clears
the wrong cache.

Every command ends with an **exit code**: `0` means it worked, anything else
means it failed. Schedulers and monitoring tools can read this directly, with
no wrapper script needed.

## Scheduled tasks

| Command | What it does |
|---|---|
| `cron [--type=hourly\|daily\|weekly\|all]` | Run due scheduled tasks: e-mail alerts, expiring premium listings, cleanup, sitemap warm. Defaults to all three tiers. |

A typical crontab entry — see [setting up cron](/docs/configure/cron/) for the
full setup:

```cron
*/5 * * * * php /path/to/site/oc-cli.php cron >/dev/null 2>&1
```

## Installation and upgrades

| Command | What it does |
|---|---|
| `install --unattended` | Install with no browser — settings come from environment variables or flags. |
| `db:upgrade` | Run pending migrations. Also in the admin under **Tools → System info → Database**. |
| `db:doctor` | Report where this database differs from what ShopClass expects. Changes nothing. Exits `1` when it finds anything. |
| `db:repair [--dry-run]` | Add missing tables, columns, indexes and foreign keys, and correct column types and defaults. Exits `1` when a statement fails. `--dry-run` prints the `db:doctor` report and changes nothing. Also in the admin under **Tools → System info → Database**. |
| `package:reconcile` | Install or refresh bundled plugins and themes onto a persistent `oc-content`. Outside a container image, it does nothing. |
| `version` | Print the installed version. |

## Recovering access

The way back in when you cannot sign in:

```bash
php oc-cli.php user:reset-password --user=admin
php oc-cli.php user:create-admin --user=jane --email=jane@example.com
```

| Command | What it does |
|---|---|
| `user:create-admin --user= --email= [--password=] [--name=]` | Create an admin account. Omit `--password` and a strong one is generated and printed. |
| `user:reset-password --user=\|--email= [--password=]` | Reset an admin's password. |
| `user:2fa-off --user=` | Turn off an admin's two-step sign-in. |

## Plugins and themes

| Command | What it does |
|---|---|
| `plugin:list` | List plugins with status, version and folder. |
| `plugin:activate --plugin=<folder>` | Enable an installed plugin. Accepts the folder name or `folder/index.php`. |
| `plugin:deactivate --plugin=<folder>` | Disable an active plugin — the fix when one fatals on load. |
| `theme:list` | List installed public themes, marking the active one. |
| `theme:activate --theme=<name>` | Set the active public theme. |

## The market

Browse and install from the [plugin and theme registries](/docs/developers/market/)
without opening the admin panel:

| Command | What it does |
|---|---|
| `market:refresh [--type=plugin\|theme]` | Refresh the cached catalog from the registry. |
| `market:search <query> [--type=…]` | Search the catalog. |
| `market:info <slug> [--type=…]` | Show catalog details for a package. |
| `market:install <slug> [--type=…]` | Install a package from the catalog. |
| `market:update <slug>\|--all [--type=…]` | Update installed packages. |

## Location data

| Command | What it does |
|---|---|
| `location:status` | Show installed location data against the published catalog. |
| `location:update --country=IN\|--all [--dry-run]` | Install or update country locations. `--all` means *every country already installed here*, not all 250 in the catalog. |

See [installing locations](/docs/configure/locations/).

## Backups

The same backups as **Tools → Backup and restore**, run in the shell with no time
limit. Use them for a large site, for a backup file too big to upload, and on a
site with [web restore turned off](/docs/use/backups-and-maintenance/#turning-web-restore-off).

| Command | What it does |
|---|---|
| `backup:create [--what=database\|files\|everything] [--to=server\|s3\|<folder>]` | Make a backup. Defaults to everything, on the server. Prints the file name, size and where it is. |
| `backup:list [--to=server\|s3\|<folder>]` | List backups: date, what, where, size, name. |
| `backup:restore <name\|file> [--from=server\|s3] [--only=database\|files] [--yes]` | Restore a backup by name, or a `.zip` or `.sql` file by path. |
| `backup:delete <name> [--from=server\|s3] [--yes]` | Delete a backup. |

```bash
php oc-cli.php backup:create --what=database
php oc-cli.php backup:create --to=/var/backups/shop
php oc-cli.php backup:create --to=s3
php oc-cli.php backup:list --to=s3
php oc-cli.php backup:restore 2026-09-29-140213-everything-k7f3q9abcdefghij.zip
php oc-cli.php backup:restore /home/me/old-site.sql --yes
```

`--to=<folder>` saves outside the site. The folder must exist and be writable, and
a folder inside the site is refused, since anyone could download from it.
`--to=s3` needs [S3 offload](/docs/use/media-and-storage/#offloading-to-s3-compatible-storage)
and `WEB_PATH` set in `config.php` or the environment.

A restore runs the same checks as the admin: it refuses a backup from a newer
Shopclass or with another table prefix, saves a safety copy first, shows the
maintenance page while it runs, and puts the safety copy back if loading fails. It
waits for a database update that is running. It asks you to type `restore` first;
`--yes` skips the question, and without a terminal to ask, `--yes` is required.
Shell access is the permission here, so no admin password is asked.

## Maintenance and health

| Command | What it does |
|---|---|
| `doctor` | Check PHP version, extensions, database, strict SQL mode readiness, writability, cron freshness and cache. Exits non-zero if any check fails. |
| `cache:flush` | Flush the object cache. |
| `sitemap:warm` | Pre-generate the XML sitemap into the cache. |
| `jobs:work [--max-seconds=]` | Drain the background job queue and nothing else. Safe to run every minute. |
| `jobs:status` | Show pending, running and gave-up jobs, and the oldest pending one, per type; name anything that gave up. `--type=` narrows it to one type. |

Slow work — moving photos to remote storage, emptying a large category, whatever a
plugin queues — is done in the background rather than during a page load. Every
`cron` run also works through that queue. `jobs:work` does only this work, so it can
run every minute and pick new work up quickly:

```cron
* * * * * php /path/to/site/oc-cli.php jobs:work --max-seconds=50 >/dev/null 2>&1
```

It exits non-zero only when the queue holds jobs the worker gave up on — a backlog
still draining is the normal case and exits `0`. An empty queue costs one query, so
the entry is harmless to leave in place on a site that queues nothing.

`storage:work` still works, as a deprecated alias for `jobs:work`. New crontabs
should use `jobs:work` directly.

`doctor` is the first thing to run when a site is misbehaving and you do not yet
know why:

```bash
php oc-cli.php doctor
```

## Commands added by plugins

A plugin can add its own commands with the `cli_commands` filter. `help` lists them
under **Added by plugins**. A plugin cannot replace a core command.

```php
osc_add_filter('cli_commands', function (array $commands) {
    $commands['acme:sync'] = array(
        'summary'  => 'Sync listings from Acme (--dry-run)',
        'callback' => function (array $args): int {
            // $args holds the parsed options: --dry-run becomes 'dry-run' => true.
            return 0; // the exit code
        },
    );

    return $commands;
});
```

## Legacy invocation

The older cron entry point still works for existing crontabs:

```bash
php index.php -p cron -t hourly
```

New setups should use `oc-cli.php cron` — it covers more than alerts and returns
a meaningful exit code.
