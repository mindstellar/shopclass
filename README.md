<p align="center">
  <a href="https://github.com/mindstellar/shopclass-brand">
    <img src="https://raw.githubusercontent.com/mindstellar/shopclass-brand/main/brand/shopclass-logo.svg" alt="Shopclass" width="360">
  </a>
</p>

<p align="center">
  <strong>Open-source, self-hosted classifieds — by Mindstellar.</strong><br>
  Build and run your own ads marketplace: real estate, jobs, vehicles, anything.
</p>

<p align="center">
  <a href="https://github.com/mindstellar/shopclass/blob/master/LICENSE"><img src="https://img.shields.io/badge/License-GPLv3-blue.svg" alt="License: GPL v3"></a>
  <a href="https://github.com/mindstellar/shopclass/actions/workflows/test.yml"><img src="https://github.com/mindstellar/shopclass/actions/workflows/test.yml/badge.svg?branch=develop" alt="Tests"></a>
  <a href="https://github.com/mindstellar/shopclass/releases/latest"><img src="https://img.shields.io/github/v/release/mindstellar/shopclass?include_prereleases&label=release" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4" alt="PHP 8.0+">
  <a href="https://github.com/mindstellar/shopclass/stargazers"><img src="https://img.shields.io/github/stars/mindstellar/shopclass" alt="Stars"></a>
</p>

<div align="center">

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/mindstellar/shopclass)

</div>

---

## What is Shopclass?

Shopclass is a PHP application that lets you launch a full classifieds website on
your own hosting — listings with photos, categories and locations, user accounts,
comments, search and filtering, multi-language support, and an admin panel to run
it all. It ships as a zip you install on ordinary shared or VPS hosting; there is
no build step or bundler to run on the server.

Shopclass is the modernised, community-maintained successor to **Osclass**. It
keeps Osclass's plugin and theme APIs (the `osc_*` helpers, hook names, and asset
paths) so existing extensions keep working, while replacing the legacy frontend:
a Bootstrap 5 admin theme, jQuery removed from the core, PHP 8 throughout, and a
first-class maintenance/cleanup toolset built in.

**Coming from Osclass?** [What happened to Osclass, and how to upgrade](https://mindstellar.com/osclass/)
walks through the history, what carries over, and the upgrade path from a 3.x or 5.x install.

## Features

- 🗂️ Listings with photos, categories, and hierarchical locations
- 🔍 Search, filtering, and SEO-friendly URLs
- 👥 User registration, accounts, and moderation
- 🎨 Themeable frontend + a modern, accessible (WCAG-checked) admin panel
- 🧩 Plugin & theme system — compatible with the Osclass extension API
- 🌎 Multi-language / i18n support
- 🔒 CSRF protection, CAPTCHA, and hardened sessions
- 🧹 Built-in Tools → Cleanup for expired, spam, blocked, and unactivated content
- ♻️ One-click self-updater that pulls release packages

## Requirements

- PHP **8.0+** with `mysqli`, `gd`, `curl`, `mbstring`, `openssl`, `zip`, `json`,
  `ctype`, `fileinfo`, and `posix`
- MySQL 5.7+ / MariaDB 10.2+
- Any web server (Apache or nginx)

## Install

> Deploy from a **release zip**, never from a branch — `master`/`develop` may
> contain untested code, and releases carry the compiled CSS/JS the branches
> don't rebuild for you.

1. Download the latest package from the [**Releases**](https://github.com/mindstellar/shopclass/releases) page and unpack it into your web root (e.g. `public_html`).
2. Open your site in a browser — `https://example.com/` — and the installer starts automatically (or go straight to `oc-includes/osclass/install.php`).
3. Follow the four steps below.
4. Sign in at `https://example.com/oc-admin/` with the admin password shown on the final screen.

### The installer, step by step

**1 · Check server** — the installer confirms your PHP version, extensions and folder permissions up front, so nothing fails halfway through.

<img src="docs/images/install/1-check-server.png" width="640" alt="Installer step 1 — server requirements check">

**2 · Connect database** — enter the details from your hosting panel and press **Test connection** to confirm they work *before* anything is written. A database on a non-default port can be entered as `host:port`.

<img src="docs/images/install/2-connect-database.png" width="640" alt="Installer step 2 — connect the database with a test-connection check">

**3 · Your site** — pick an admin username (leave the password blank and a strong one is generated for you), your site title, contact e-mail and country.

<img src="docs/images/install/3-your-site.png" width="640" alt="Installer step 3 — admin account and site details">

**4 · Done** — copy your admin password (it's also e-mailed to you) and open the admin panel.

<img src="docs/images/install/4-done.png" width="640" alt="Installer step 4 — finished, with admin credentials">

The installer runs once; if the site is already set up it shows a short notice instead of re-running.

## Command-line interface

Shopclass ships a small CLI for maintenance tasks, run from the install root with
the PHP binary. It refuses to run over HTTP, so the commands are only reachable
from a shell on the server.

```bash
php oc-cli.php <command> [options]
php oc-cli.php help          # list every command
```

| Command | What it does |
|---|---|
| `cron [--type=hourly\|daily\|weekly\|all]` | Run due scheduled tasks (alerts, cleanup, sitemap warm). Default runs all three. |
| `db:upgrade [--skip-db]` | Reconcile the schema and run pending migrations after an update. `--skip-db` continues past false-positive query errors. |
| `package:reconcile` | Install/refresh bundled plugins & themes onto a persistent `oc-content` — a no-op outside a container image. |
| `cache:flush` | Flush the object cache. |
| `sitemap:warm` | Pre-generate the XML sitemap into the cache. |
| `user:create-admin --user= --email= [--password=] [--name=]` | Create an admin account. A password is generated and printed when `--password` is omitted. |
| `user:reset-password --user=\|--email= [--password=]` | Reset an admin's password — the way back in when you're locked out. |
| `plugin:list` | List plugins with their enabled/disabled status, version, and folder. |
| `plugin:activate --plugin=<folder>` | Enable an installed plugin (accepts the folder name or `folder/index.php`). |
| `plugin:deactivate --plugin=<folder>` | Disable an active plugin. |
| `theme:list` | List installed public themes and mark the active one. |
| `theme:activate --theme=<name>` | Set the active public theme. |
| `market:refresh [--type=plugin\|theme]` | Refresh the cached plugin/theme catalog from the registry. |
| `market:search <query> [--type=plugin\|theme]` | Search the catalog. |
| `market:info <slug> [--type=plugin\|theme]` | Show catalog details for a package. |
| `market:install <slug> [--type=plugin\|theme]` | Install a package from the catalog. |
| `market:update <slug>\|--all [--type=plugin\|theme]` | Update installed packages from the catalog. |
| `doctor` | Report on PHP version, extensions, database, writability, cron freshness, and cache. Exits non-zero if any check fails. |
| `version` | Print the installed version. |

Every command sets a proper exit code (`0` success, non-zero on failure), so they
slot into schedulers and monitoring. A typical crontab entry:

```cron
*/5 * * * * php /path/to/site/oc-cli.php cron >/dev/null 2>&1
```

> The older `php index.php -p cron -t hourly` invocation still works for existing
> crontabs, but new setups should use `oc-cli.php`.

## Local development

The runtime needs no build tools, but the admin theme's CSS/JS are compiled from
source. You only need Node to work on them.

```bash
git clone --recursive git@github.com:mindstellar/shopclass.git
cd shopclass
npm install
npm run build        # vendor assets + SCSS → CSS + JS
npm run watch        # rebuild CSS on change while developing
```

Compiled output (`oc-admin/themes/modern/css/main.css`, `oc-includes/assets/…`)
is **committed** — releases are cut with `git archive`, so whatever is committed
is exactly what users receive. Rebuild and commit the output with any SCSS/JS
change.

### Run it with Docker

A full local stack — PHP-FPM, MariaDB, Nginx, Memcached, Mailhog and phpMyAdmin —
ships in `docker-compose.dev.yml`, alongside `docker-compose.prod.yml` for the
production image:

```bash
npm run dev:build     # first run — builds the PHP-FPM image
npm run dev           # start
npm run dev:down      # stop
npm run dev:logs      # follow the logs
```

The first of those writes a `.env` from `.env.example` if you have none. It sets
`COMPOSE_FILE`, so plain `docker compose up -d` works too and brings up exactly the same
stack. `.env` is also where you change the database credentials and, on Linux, the
`PUID`/`PGID` the container writes files as.

Public themes live in their own repositories and `oc-content/themes` is gitignored, so a
fresh checkout starts without one. Install the default theme into the running stack:

```bash
docker compose exec php-fpm php oc-cli.php market:install storefront --type=theme
```

To work on a theme or plugin you have checked out locally, put the mounts in
`docker-compose.local.yml` and append it to `COMPOSE_FILE` — `.env.example` shows the
shape. Keep them out of the committed file: where the path is missing Docker creates an
empty directory at the mount point rather than failing, and an empty theme directory
looks broken rather than absent.

Then open **http://localhost:8000** and run the installer with these database
details (leave the admin password blank on step 3 for a generated one):

| Field | Value |
|---|---|
| Host | `mariadb` |
| Database | `shopclass` |
| User | `shopclass` |
| Password | `shopclass` |

Outgoing e-mail — including the installer's welcome message — is caught by
**Mailhog**, so you can read it in a browser instead of it silently failing.

| Service | Address |
|---|---|
| Site | http://localhost:8000 |
| Mailhog inbox | http://localhost:8025 |
| phpMyAdmin | http://localhost:8081 (root / `root`) |
| MariaDB (from host) | `127.0.0.1:3307` |

Inside the compose network the services resolve as `php-fpm:9000`,
`mariadb:3306`, `memcached:11211`, and `mailhog:1025`. Override the database name and
credentials with the `SHOPCLASS_DATABASE_NAME` / `SHOPCLASS_DATABASE_USER` /
`SHOPCLASS_DATABASE_PASSWORD` variables and the root password with
`MYSQL_ROOT_PASSWORD` (export them, or put them in a `.env` file next to the
compose file).

### Run the production image

For a deployment rather than development there is a self-contained image — Nginx,
PHP-FPM and Supervisor in one container, with the Storefront theme baked in — that
provisions itself on first boot. Bring it up with a database:

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

Or skip the build and pull the published image (tagged per release, with `:latest`
tracking the newest stable):

```bash
docker pull ghcr.io/mindstellar/shopclass:latest
```

It comes up **already installed** at **http://localhost:8080** (admin at
`/oc-admin/`). Everything is configured from the environment:

| Variable | Purpose |
|---|---|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | Database connection |
| `WEB_PATH` | Public base URL of the site |
| `OSC_ADMIN_USER` / `OSC_ADMIN_EMAIL` / `OSC_ADMIN_PASSWORD` | First admin account — leave the password unset to have one generated and printed to the logs |
| `OSC_DISABLE_PACKAGE_INSTALLS` | Set to `1` to turn off installing/updating plugins and themes from the admin market and `oc-cli.php market:*` — unset (the default) leaves them on |

For a real deployment, point `DB_HOST` at a managed database, set a strong admin
password, set `WEB_PATH` to your public URL, and offload uploads to S3 so more than
one instance can run.

**Core vs. packages update differently.** Core ships baked into the image, so core
updates come from deploying a newer image tag; the container migrates its own
schema on start, and the in-app core updater is off (`OSC_DISABLE_SELF_UPDATE=1`) so
it can't write over itself only to lose the write on the next redeploy. Plugins and
themes are different: `docker-compose.prod.yml` mounts `oc-content/plugins` and
`oc-content/themes` as named volumes alongside `uploads`/`downloads`, so a package
installed or updated from the admin market (or `oc-cli.php market:install` /
`market:update`) survives a redeploy. On every start, the entrypoint reconciles that
volume against the bundled packages baked into the new image — installing any that
are missing and refreshing any the image ships a newer version of — without ever
touching a package installed through the market.

> **Upgrading from an image released before those two volumes existed:** copy
> `oc-content/plugins` and `oc-content/themes` out of the running container before you
> redeploy. Those directories used to live in the container's writable layer, so anything
> installed there was already discarded on each redeploy; the new volumes are seeded from
> the image, which means packages from the old container are not carried across and cannot
> be recovered once it is gone. Reinstall them after upgrading. Zip installs are unaffected.

## Brand

Logos, the mark, the favicon set, and the palette live in the
[**shopclass-brand**](https://github.com/mindstellar/shopclass-brand) repository.

| Role | Color | Hex |
|---|---|---|
| Deep Navy | dark / headings | `#0F2742` |
| Teal | brand / identity | `#12A6A0` |
| Slate Gray | neutral | `#435466` |
| Warm Off-White | surface | `#F7F5F1` |
| Coral | accent | `#FF6B4A` |

Brand assets are licensed **CC BY-ND 4.0**: use them to refer to Shopclass, but
please don't modify the marks or imply endorsement.

## Documentation

- [Changelog](CHANGELOG.md) — what changed in each release; also the source for the admin upgrade screen.
- [Security policy](SECURITY.md) — supported versions and how to report a vulnerability.

**Guides**

- [Caching contract](docs/CACHING.md) — how Shopclass drives a reverse-proxy/CDN cache: the cookie allowlist, the `Cache-Control` it emits, and the reference nginx micro-cache config.
- [Page builder](docs/PAGE-BUILDER.md) — the page-template registry and the widget-based page composition model.
- [Custom fields](docs/CUSTOM-FIELDS.md) — field inheritance down the category tree, reusable groups, conditional logic, and the field-type registry.
- [Market](docs/MARKET.md) — the GitHub-native plugin & theme ecosystem: the [`shopclass-plugins`](https://github.com/mindstellar/shopclass-plugins) / [`shopclass-themes`](https://github.com/mindstellar/shopclass-themes) registries, the static catalog they publish, and how core browses, installs, and updates from it.
- [Package spec](docs/PACKAGE-SPEC.md) — the contract a plugin or theme must satisfy to be listed in the market: header fields, compatibility, versioning, artwork, and security requirements.

Installation, local development, and the production image are covered in the sections above.

## Contributing

Contributions are welcome — bug fixes, features, translations, docs.

1. Open an issue describing the change before you start.
2. Branch from **`develop`** (never target `master`).
3. Make your change; if it touches the admin theme, run `npm run build` and commit the compiled output.
4. Open a pull request against `develop`.

Because Shopclass runs on installs with third-party themes and plugins, treat the
`osc_*` helpers, hook names, admin CSS class names, and `oc-includes/assets/`
paths as a public API — restyle freely, but don't rename or remove them.

## Support

Questions, help, and discussion happen on
[**GitHub Discussions**](https://github.com/mindstellar/shopclass/discussions).
For reproducible bugs, open an [issue](https://github.com/mindstellar/shopclass/issues).

## License

Shopclass is distributed under the **GNU General Public License v3.0 or later**
([LICENSE](LICENSE)). It derives from Osclass, whose original code is licensed
under the **Apache License 2.0** ([LICENSE-APACHE](LICENSE-APACHE)); those
notices are retained in [NOTICE](NOTICE) as that license requires.

## Links

- 🏠 [Website](https://mindstellar.com) · [Live demo](https://demo.mindstellar.com)
- 🧭 [Coming from Osclass](https://mindstellar.com/osclass/)
- 📦 [Releases](https://github.com/mindstellar/shopclass/releases)
- 🐛 [Issues](https://github.com/mindstellar/shopclass/issues)
- 💬 [Discussions](https://github.com/mindstellar/shopclass/discussions)
- 🎨 [Brand kit](https://github.com/mindstellar/shopclass-brand)
