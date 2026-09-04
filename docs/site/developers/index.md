---
title: Developer documentation
description: Build plugins and themes for ShopClass — the extension API carried over from Osclass, the package contract, and the registry that publishes your work.
sidebar:
  order: 1
---

ShopClass is extended the way Osclass was: **hooks** to run your code at the
right moment, **helpers** to read and write the application's data, and
**routes** to add pages of your own. That API was kept deliberately through the
modernisation — the `osc_*` helper functions, hook names, admin CSS class names
and `oc-includes/assets/` paths are treated as a public API and were not
renamed.

If you wrote for Osclass, you already know most of this.

## What changed in the 6.x line

Worth knowing before you port something:

- **PHP 8 throughout.** The floor is 8.0. Code that relied on PHP 7 leniency —
  implicit type juggling, dynamic properties, `create_function` — will fatal.
- **jQuery is not loaded for you.** The admin panel is Bootstrap 5 and registers
  `bootstrap5`, `popper` and `sortablejs`; the front end loads nothing by
  default. A plugin that assumed `$` was present must now register and enqueue
  its own copy.
- **A real CLI.** `oc-cli.php` covers cron, migrations, packages and health
  checks — see the [CLI reference](/docs/cli/).
- **A package registry.** Plugins and themes are published through
  [the market](/docs/developers/market/) instead of ad-hoc update URLs.
- **`oc-includes/assets/chart-js/` is gone** as of 6.2.0. It was added in 2021
  and never used by anything in core. A plugin loading that path directly must
  bundle its own copy.
- **Delete cascades run in transactions**, and every record type now has
  `before_delete_*` / `after_delete_*` hooks. A `before_` hook runs before the
  transaction opens and an `after_` hook only once it has committed, so your own
  database work is never rolled back with a failed delete.

## Where to start

| If you want to… | Read |
|---|---|
| Publish a plugin or theme | [Package specification](/docs/developers/package-spec/) |
| Get it listed for every install | [The market](/docs/developers/market/) |
| Add a page of your own | [Routes](/docs/developers/routes/) |
| Add admin screens | [Administrator menus](/docs/developers/admin-menus/) |
| Add toolbar shortcuts | [Admin toolbar](/docs/developers/admin-toolbar/) |
| Load CSS and JavaScript | [Scripts and styles](/docs/developers/scripts-and-styles/) |
| Host core's own pages in your theme | [Theme chrome](/docs/developers/theme-chrome/) |
| Understand the schema | [Database model](/docs/developers/database/) |
| Debug something | [PHP errors](/docs/developers/debug-php-errors/) · [SQL queries](/docs/developers/debug-sql-queries/) |
| Contribute to core | [Contributing](/docs/developers/contributing/) |

## A local development stack

The runtime needs no build tools, but the admin theme's CSS and JavaScript are
compiled from source, so working on core needs Node.

```bash
git clone git@github.com:mindstellar/shopclass.git
cd shopclass
npm install
npm run build        # vendor assets + SCSS → CSS + JS
npm run watch        # rebuild on change
```

A full stack — PHP-FPM, MariaDB, Nginx, Memcached, Mailhog and phpMyAdmin —
ships in `docker-compose.dev.yml`:

```bash
npm run dev:build     # first run — builds the PHP-FPM image
npm run dev           # start
npm run dev:logs      # follow the logs
```

The site comes up on `http://localhost:8000`, with Mailhog on `:8025` and
phpMyAdmin on `:8081`. Public themes live in their own repositories, so install
one into the running stack:

```bash
docker compose exec php-fpm php oc-cli.php market:install storefront --type=theme
```

The [repository README](https://github.com/mindstellar/shopclass) has the full
local-development section, including how to mount a theme or plugin you have
checked out locally.

## Reading the code

These pages are task-shaped: how to add a route, how to register a menu, what a
package must declare. For tracing the code itself — what a class does, where a
call ends up — [**Ask DeepWiki**](https://deepwiki.com/mindstellar/shopclass)
indexes the repository and answers questions about it conversationally.

It is generated from the source, so it knows the codebase and nothing about your
hosting. Use it to find your way around; use these pages for how things are
meant to be done.
