---
title: Command-line interface
description: The oc-cli.php reference for ShopClass — cron, database migrations, admin recovery, plugin and theme management, health checks.
sidebar:
  order: 4
---

ShopClass ships a small command-line tool for the jobs that do not belong in a
browser: scheduled tasks, migrations, recovering a locked-out admin, and
installing packages on a server you deploy to from a script.

```bash
cd /path/to/your/site
php oc-cli.php <command> [options]
php oc-cli.php help          # list every command
```

:::note[It cannot be reached over HTTP]
`oc-cli.php` refuses any non-CLI SAPI and answers `403` if it is ever requested
through the web server. The commands below are only reachable from a shell on
the server, which is why they can do things the admin panel will not.
:::

Every command sets a proper exit code — `0` on success, non-zero on failure — so
they slot into schedulers and monitoring without wrapper scripts.

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
| `install --unattended` | Headless install from environment variables or flags — no browser. |
| `db:upgrade [--skip-db] [--skip-reconcile]` | Run pending migrations, repairing a drifted schema first. `--skip-db` continues past false-positive query errors. |
| `package:reconcile` | Install or refresh bundled plugins and themes onto a persistent `oc-content` — a no-op outside a container image. |
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

## Maintenance and health

| Command | What it does |
|---|---|
| `doctor` | Check PHP version, extensions, database, writability, cron freshness and cache. Exits non-zero if any check fails. |
| `cache:flush` | Flush the object cache. |
| `sitemap:warm` | Pre-generate the XML sitemap into the cache. |

`doctor` is the first thing to run when a site is misbehaving and you do not yet
know why:

```bash
php oc-cli.php doctor
```

## Legacy invocation

The older cron entry point still works for existing crontabs:

```bash
php index.php -p cron -t hourly
```

New setups should use `oc-cli.php cron` — it covers more than alerts and returns
a meaningful exit code.
