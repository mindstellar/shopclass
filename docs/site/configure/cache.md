---
title: Object caching
description: "Speed up ShopClass by keeping repeated database results in memcached, Redis, Valkey or APCu: setup, how long entries last, environment variables, and how it differs from a page cache."
sidebar:
  order: 4
---

Many pages ask the database the same questions again and again: the category
tree, the site settings, location lookups. An **object cache** keeps those
answers in memory for a short time, so the next page can reuse them. On a busy
site, it cuts a page from a few dozen database queries to a handful.

By default, ShopClass keeps these answers for **one page load only**. That is
safe on any server, but it does not speed anything up. Turning on a real cache
takes two lines.

:::note[This is not a page cache]
The object cache keeps small pieces of work inside PHP. A page cache stores whole
finished pages in front of PHP. You can use both. See
[page caching](/docs/configure/page-cache/) to turn that on, and the
[caching contract](/docs/developers/caching/) for what ShopClass tells a proxy.
:::

## Before you start

memcached and APCu need a PHP extension (an add-on module for PHP). Install the
one you want, then check that PHP can see it:

```bash
php -m | grep -E 'memcached|apcu|redis'
```

If the extension is missing, the setting does nothing. Redis and Valkey need no
extension: ShopClass uses `phpredis` when it is installed, and its own client
otherwise.

## memcached (recommended)

**memcached** is a small cache server. Use it if you have more than one web
server. It is also fine with one.

```php
// config.php
define('OSC_CACHE', 'memcached');
```

This connects to `127.0.0.1:11211`. For another host, or several servers:

```php
define('OSC_CACHE', 'memcached');
$_cache_config = array(
    array('default_host' => '10.0.0.5', 'default_port' => 11211, 'default_weight' => 1),
    array('default_host' => '10.0.0.6', 'default_port' => 11211, 'default_weight' => 1),
);
```

## Redis or Valkey

**Redis** and **Valkey** are cache servers like memcached. Use them if your host
offers one, or if you run other things on Redis already. KeyDB and Dragonfly work
too.

```php
define('OSC_CACHE', 'redis');
```

This connects to `127.0.0.1:6379`. For another server, a password or a database
number:

```php
define('OSC_CACHE', 'redis');
$_cache_config = array(
    array('default_host' => '10.0.0.5', 'default_port' => 6379, 'password' => 'secret', 'database' => 1),
);
```

A host starting with `/` is a Unix socket, and `tls://host` connects over TLS. Add
`'username'` for a server with users (ACL). Several sites can share one server:
each keeps its own keys, and emptying the cache empties only that site's.

With Redis or Valkey, a background job also starts the moment it is queued, when the
job listener runs (`oc-cli.php jobs:work --listen`; the Docker image runs it). See
[Background jobs](/docs/developers/jobs/).

The REST API's rate limits count in the object cache too: with memcached or Redis every web server shares one count; with `apcu` each server counts its own; with no `OSC_CACHE` they count in the database.

## APCu (one server only)

**APCu** keeps the cache inside PHP itself. It is simpler and faster, but each
web server has its own copy. Use it on a single server. Do not use it once you
add a second web server.

```php
define('OSC_CACHE', 'apcu');
```

## How long entries last

An entry lasts 60 seconds by default. If your categories and settings rarely
change, keep entries longer:

```php
define('OSC_CACHE_TTL', 300);
```

The number is in seconds. The longer it is, the longer a change in the admin
panel can take to show on the site.

## Setting it with environment variables

On containers, editing `config.php` for each environment is awkward. Use
environment variables instead:

| Variable | What it sets |
|---|---|
| `OSC_CACHE` | The cache type: `memcached`, `redis` or `apcu` |
| `OSC_CACHE_HOST` | The cache server's host, for memcached or redis |
| `OSC_CACHE_PORT` | The cache server's port. Default `11211`, or `6379` for redis |
| `OSC_CACHE_PASSWORD` | Redis only: the server's password |
| `OSC_CACHE_USERNAME` | Redis only: the user name, for a server with users |
| `OSC_CACHE_DB` | Redis only: the database number. Default `0` |

A `define()` in `config.php`, or a `$_cache_config` array, always wins over
these variables.

## Emptying the cache

After a bulk import, a direct database edit, or any change made outside
ShopClass, empty the cache:

```bash
php oc-cli.php cache:flush
```

## The memcache driver is gone

The `memcache` driver is gone. `define('OSC_CACHE', 'memcache')` now uses the
`memcached` driver with the same servers, so install the `memcached` extension.
Without it, the site falls back to the one-request cache.

## Writing your own driver

A plugin can add a cache: a class named `Object_Cache_<name>`, picked with
`OSC_CACHE` set to `<name>`. It must be loaded before the first cache call.

- Extend `mindstellar\base\Cache` and write `is_supported()`, `fetch()`, `add()`,
  `set()`, `delete()`, `flush()`, `_get_cache()` and `statsTitle()`. The base keeps
  the in-request copy, the hit and miss counts and the site key prefix.
- Or implement `mindstellar\cache\CacheDriver` yourself. Drivers written for the
  old `iObject_Cache` interface keep working.

An unknown name, or a driver whose `is_supported()` says no, falls back to the
one-request cache with a PHP notice.

## Troubleshooting

**Changes in the admin panel take a while to show.**
The cache still holds the old answer until the entry ends. Lower
`OSC_CACHE_TTL`, or empty the cache after admin work.

**The site got slower after turning it on.**
ShopClass probably cannot reach the cache server. Every lookup then waits for the
connection to time out first. Check the host and port, and check that the cache
server is running. ShopClass stops asking a server after its first failure in a page
load, and the reason is in the PHP error log.

**Two web servers show different versions of the site.**
You are using APCu, which keeps one cache per server. Move to memcached or Redis.
