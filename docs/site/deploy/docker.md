---
title: Docker & the production image
description: Run ShopClass from the published container image — environment configuration, persistent volumes, and how core and packages update differently.
sidebar:
  order: 1
---

ShopClass publishes a self-contained image — Nginx, PHP-FPM and Supervisor in
one container, with the Storefront theme baked in — that provisions itself on
first boot.

```bash
docker pull ghcr.io/mindstellar/shopclass:latest
```

Tags are published per release, with `:latest` tracking the newest **stable**
release. The published image runs **PHP 8.5**.

## Bringing it up

`docker-compose.prod.yml` in the repository brings up the image with a database:

```bash
docker compose -f docker-compose.prod.yml up -d
```

It comes up **already installed** at `http://localhost:8080`, admin at
`/oc-admin/`. There is no installer to click through — the container runs the
headless install itself from the environment.

## Configuration

Everything is set from environment variables:

| Variable | Purpose |
|---|---|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | Database connection |
| `WEB_PATH` | The site's public base URL |
| `OSC_ADMIN_USER` / `OSC_ADMIN_EMAIL` / `OSC_ADMIN_PASSWORD` | The first admin account. Leave the password unset and a strong one is generated and printed to the logs |
| `OSC_SITE_TITLE` | Site title at provisioning time |
| `OSC_IGNORE_CONFIG_FILE` | Set to `1` so the image configures itself from the environment rather than a `config.php` |
| `OSC_DISABLE_PACKAGE_INSTALLS` | Set to `1` to turn off installing and updating plugins and themes from the admin market and `oc-cli.php market:*` |
| `OSC_REAL_IP_HEADER` | The header carrying the real client IP behind a proxy, e.g. `CF-Connecting-IP` |
| `OSC_CACHE` / `OSC_CACHE_HOST` / `OSC_CACHE_PORT` | [Object cache](/docs/configure/cache/) |

For a real deployment: point `DB_HOST` at a managed database, set `WEB_PATH` to
the public URL, set a strong admin password, and
[offload uploads to S3](/docs/use/media-and-storage/) so more than one instance
can run.

## Volumes

Four paths must survive a redeploy:

| Path | Holds |
|---|---|
| `oc-content/uploads` | Listing photos |
| `oc-content/downloads` | Files served by download-type plugins |
| `oc-content/plugins` | Plugins installed through the market |
| `oc-content/themes` | Themes installed through the market |

The last two matter more than they look. Without them, a package installed from
the admin lives in the container's writable layer and is discarded on the next
redeploy.

:::caution[Upgrading from an image released before the plugin/theme volumes existed]
Copy `oc-content/plugins` and `oc-content/themes` out of the running container
**before** you redeploy. Those directories used to live in the writable layer;
the new volumes are seeded from the image, so packages from the old container
are not carried across and cannot be recovered once it is gone. Reinstall them
afterwards. Zip installs are unaffected.
:::

## Core and packages update differently

This is the part that surprises people.

**Core ships baked into the image.** A core update is a redeploy with a newer
image tag. The container migrates its own schema on start, and the in-app core
updater is switched off (`OSC_DISABLE_SELF_UPDATE=1`) — otherwise it would write
over itself, only to lose the write on the next redeploy.

**Plugins and themes live in volumes.** A package installed or updated through
the admin market, or through `oc-cli.php market:install` / `market:update`,
survives a redeploy.

On every start the entrypoint reconciles the volume against the packages baked
into the new image — installing any that are missing, refreshing any the image
ships a newer version of — **without ever touching a package installed through
the market**. You can run that yourself:

```bash
docker compose exec app php oc-cli.php package:reconcile
```

## Running commands

Every [CLI command](/docs/cli/) works inside the container:

```bash
docker compose exec app php oc-cli.php doctor
docker compose exec app php oc-cli.php cron
docker compose exec app php oc-cli.php user:reset-password --user=admin
```

## Cron in a container

The container does not schedule anything for you. Run cron from the host, from
a sidecar, or from your orchestrator:

```cron
*/5 * * * * docker compose -f /path/to/docker-compose.prod.yml exec -T app php oc-cli.php cron
```

On Kubernetes, a `CronJob` running the same command is the equivalent. Without
it, alerts never send and listings never expire — see
[setting up cron](/docs/configure/cron/).

## Behind a proxy

Terminate TLS at your proxy and forward to the container. Set
`OSC_REAL_IP_HEADER` to the header your proxy sets, or every visitor arrives as
the proxy's address — which collapses login throttling and abuse-report keying
onto one identity. See [security](/docs/deploy/security/) and the
[caching contract](/docs/developers/caching/).

## Running more than one instance

Three things have to be true before a second instance is safe:

1. **Uploads are offloaded to S3** — otherwise each instance has its own photos.
2. **The object cache is memcached, not APCu** — APCu is per-process, so two
   instances disagree.
3. **Cron runs once**, not once per instance.

## Local development

For working on ShopClass itself there is a separate development stack — PHP-FPM,
MariaDB, Nginx, Memcached, Mailhog and phpMyAdmin — in `docker-compose.dev.yml`.
See the [developer documentation](/docs/developers/).
