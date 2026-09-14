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
| `OSC_REAL_IP_HEADER` / `OSC_REAL_IP_TRUSTED` | The header carrying the real client IP behind a proxy, e.g. `X-Real-IP` or `CF-Connecting-IP`, and the CIDRs to trust it from — see [putting it behind TLS](#putting-it-behind-tls) |
| `OSC_CACHE` / `OSC_CACHE_HOST` / `OSC_CACHE_PORT` | [Object cache](/docs/configure/cache/) |
| `OSC_MICROCACHE` | Set to `1` to cache public pages in nginx — see [page caching](/docs/configure/page-cache/). The image carries the purge module, so the nginx Cache plugin works with nothing further to configure |
| `OSC_RATE_LIMIT` / `OSC_RATE_LIMIT_BURST` | Requests per second per client IP, e.g. `10r/s`. Unset is off |

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

## Putting it behind TLS

The image speaks plain HTTP on port 80 and is built to sit behind something that
terminates TLS. Keep it that way: a certificate is renewing state, and this
container is meant to be replaceable at any moment. Terminate on the host with
nginx, and let certbot own the certificate.

Start by taking the container off the public interface, so the only way in is
through the proxy:

```yaml
services:
  app:
    ports:
      - "127.0.0.1:8080:80"   # loopback only
```

Then a server block on the host. This is the plain-HTTP form — certbot rewrites
it in the next step:

```nginx
server {
    listen 80;
    server_name example.com;

    # Must be at least as large as the app's own limit, or uploads fail at the
    # proxy with a 413 before ShopClass ever sees them.
    client_max_body_size 108M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Then get the certificate:

```bash
certbot --nginx -d example.com
```

That obtains it, rewrites the block to listen on 443, and adds the redirect from
port 80.

### How renewal is handled

**Nothing about renewal touches the container.** The certificate lives on the
host, the host's nginx is what serves it, and certbot's nginx installer reloads
that nginx after a successful renewal. There is no mounted certificate, no
deploy hook to write, and no container restart in the loop — which is the main
reason to terminate here rather than inside the image.

Installing certbot from your distribution's package (or snap) also installs the
renewal job, so this is already running:

```bash
systemctl list-timers | grep certbot     # twice-daily check
certbot renew --dry-run                  # prove the whole path works
```

`certbot renew` is a no-op until a certificate is within 30 days of expiry, so
running it often is free and expected. Two things keep it working:

- **Leave port 80 open on the host.** The HTTP-01 challenge arrives there. The
  redirect certbot adds is fine — Let's Encrypt follows it — but a firewall that
  drops :80 entirely will fail every renewal, silently, until the certificate
  expires.
- **Do not hand-edit the `managed by Certbot` lines** in the server block. That
  is how certbot finds what to update.

Run the dry run once after setup. If it passes, renewal is genuinely unattended.

### What the app needs to be told

TLS is invisible to ShopClass unless these are set:

| Setting | Value |
|---|---|
| `WEB_PATH` | `https://example.com/` — the app builds every URL and cookie path from this |
| `OSC_REAL_IP_HEADER` | `X-Real-IP`, matching the `proxy_set_header` above |
| `OSC_REAL_IP_TRUSTED` | `172.16.0.0/12` — see below |

`X-Forwarded-Proto` is what makes the app treat the request as secure: it sets
`HTTPS=on` for PHP, so `osc_is_ssl()` is true and the login cookie is issued with
the `Secure` flag. Without it a visitor on HTTPS gets cookies that are not marked
secure, and the app generates `http://` links.

`OSC_REAL_IP_TRUSTED` is the one people get wrong. When the host proxies into a
published port, the container does not see `127.0.0.1` — it sees the Docker
bridge gateway, an address like `172.19.0.1`. Trusting loopback there restores
nothing, and every visitor arrives as the gateway, which collapses login
throttling and abuse-report keying onto a single identity. `172.16.0.0/12`
covers Docker's default pools; narrow it to your own gateway with
`docker network inspect`.

### Other terminators

An ALB, a Kubernetes ingress, Cloudflare or a managed platform all work the same
way — the contract is the three settings above plus a proxy that sends
`X-Forwarded-Proto`. Only the certificate's owner changes. See
[security](/docs/deploy/security/) and the
[caching contract](/docs/developers/caching/).

### With page caching on

Nothing changes. The container's nginx still sees `http` as its own scheme and
keys its cache on that, which is correct — and it is what the nginx Cache
plugin's purge endpoint follows, so that stays `http://127.0.0.1/purge`. The
plugin's host list is the **public** hostname, because that is the `Host`
visitors send and therefore what the cache is keyed on. See
[page caching](/docs/configure/page-cache/).

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
