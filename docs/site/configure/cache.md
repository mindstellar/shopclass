---
title: Object caching
description: Configure the ShopClass object cache with memcached or APCu — drivers, TTLs, environment variables, and how it differs from a page cache.
sidebar:
  order: 4
---

ShopClass has an object cache: a short-lived store for the results of repeated
database work — category trees, preferences, location lookups — shared across
requests. On a busy site it is the difference between a handful of queries per
page and a few dozen.

By default the driver is an in-memory array that lives for **one request only**
and does not persist. That is safe everywhere and helps nothing. Configuring a
real backend is a two-line change.

:::note[This is not a page cache]
The object cache stores fragments of work inside PHP. Caching whole responses at
a reverse proxy or CDN is a separate, complementary layer — see
[page caching](/docs/configure/page-cache/) for how to turn it on, and the
[caching contract](/docs/developers/caching/) for what core promises a proxy.
:::

## Before you start

Install the matching PHP extension and confirm PHP can see it:

```bash
php -m | grep -E 'memcached|apcu'
```

The setting does nothing if the extension is missing.

## memcached — recommended

Right for anything with more than one web server, and fine with one.

```php
// config.php
define('OSC_CACHE', 'memcached');
```

That connects to `127.0.0.1:11211`. For a different host, or several servers:

```php
define('OSC_CACHE', 'memcached');
$_cache_config = array(
    array('default_host' => '10.0.0.5', 'default_port' => 11211, 'default_weight' => 1),
    array('default_host' => '10.0.0.6', 'default_port' => 11211, 'default_weight' => 1),
);
```

## APCu — single server

Simpler, faster, and confined to one PHP process pool. Right for a single VPS,
wrong the moment you add a second web server.

```php
define('OSC_CACHE', 'apcu');
```

## Entry lifetime

Cached entries live 60 seconds by default. Raise it on a site whose categories
and preferences rarely change:

```php
define('OSC_CACHE_TTL', 300);
```

Longer TTLs mean an admin change can take that long to appear on the front end.

## Configuring by environment variable

Handy for containers, where editing `config.php` per environment is awkward:

| Variable | Purpose |
|---|---|
| `OSC_CACHE` | Driver name — `memcached`, `apcu`, `memcache` |
| `OSC_CACHE_HOST` | Server host, for memcached/memcache |
| `OSC_CACHE_PORT` | Server port, default `11211` |

An explicit `define()` in `config.php` — or a `$_cache_config` array — always
wins over the environment.

## Flushing it

After a bulk import, a direct database edit, or anything that changed data
behind the application's back:

```bash
php oc-cli.php cache:flush
```

## Legacy drivers

`define('OSC_CACHE', 'memcache')` still works and drives the old, unmaintained
`memcache` extension. It is deprecated — use `memcached`.

## Troubleshooting

**Changes in the admin panel take a while to show.**
That is `OSC_CACHE_TTL` doing its job. Lower it, or flush after admin work.

**The site got slower after enabling it.**
The cache server is probably unreachable, so every lookup pays a connection
timeout before falling through. Confirm the host and port, and that the daemon
is running.

**Two web servers disagree about what the site looks like.**
You are on APCu, which is per-server. Move to memcached.
