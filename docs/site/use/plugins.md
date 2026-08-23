---
title: Plugins
description: Find, install, update and remove ShopClass plugins from the admin panel or the command line.
sidebar:
  order: 8
---

Plugins add what your particular site needs and core deliberately does not carry
— payment gateways, map providers, storage backends, import tools.

**Plugins** in the admin panel.

## Installing

**Plugins → Add plugin** browses the
[plugin registry](/docs/developers/market/): a public, static catalog of
packages submitted by pull request and validated by CI. Search it, read what a
package does, and install in one step.

A plugin zip can also be **uploaded** directly, for something private or bought
elsewhere.

From a shell:

```bash
php oc-cli.php market:search maps
php oc-cli.php market:info better-s3
php oc-cli.php market:install better-s3
```

## Installed, enabled, uninstalled

Three states, and the difference matters:

| State | Meaning |
|---|---|
| **Installed but disabled** | Files present, code not running, settings and data kept. |
| **Enabled** | Running. |
| **Uninstalled** | Files removed. Most plugins drop their tables and settings — usually irreversibly. |

To stop a plugin temporarily, **disable** it. Uninstall only when you are done
with it for good.

```bash
php oc-cli.php plugin:list
php oc-cli.php plugin:deactivate --plugin=better-s3
php oc-cli.php plugin:activate --plugin=better-s3
```

`plugin:deactivate` is the fix when a plugin fatals on load and takes the admin
panel down with it — see
[debugging PHP errors](/docs/developers/debug-php-errors/).

## Updating

Updates appear in the plugins list when the catalog offers a newer version.

```bash
php oc-cli.php market:update --all
php oc-cli.php market:update better-s3
```

Update plugins **after** updating core, not before: a plugin release usually
targets the newest core, while the reverse is not guaranteed.

## Configuring

A configurable plugin adds its own screen, reachable from its row in the
plugins list. Where that screen lives in the menu is the plugin's choice — some
add a top-level section, most add an entry under **Settings** or **Tools**.

Some plugins also carry **per-category** configuration, set from the category
rather than from the plugin.

## Choosing well

The catalog will happily install anything listed. Before you add one:

- **Check what it last supported.** A package declares the ShopClass versions it
  was tested against.
- **Prefer one plugin over three.** Every plugin is code running on every
  request, and a conflict between two is much harder to diagnose than a missing
  feature.
- **Check its support link.** Every listed package has one. An unanswered issue
  tracker tells you what you are buying into.
- **Test on a copy** before installing on a site with traffic.

## When a plugin breaks the site

1. Disable it from a shell: `php oc-cli.php plugin:deactivate --plugin=<folder>`.
2. Confirm the site recovers.
3. Turn on [error logging](/docs/developers/debug-php-errors/) and reproduce.
4. Report it on the plugin's own issue tracker, with the detail in
   [how to write a bug report](/docs/developers/bug-reports/) — including your
   ShopClass and PHP versions.

## Locking installs down

On a container deployment you may not want packages installed from the admin at
all, since a container's filesystem is replaced on every redeploy. Set
`OSC_DISABLE_PACKAGE_INSTALLS=1` to turn off installing and updating from both
the market UI and `oc-cli.php market:*`. See [Docker](/docs/deploy/docker/).
