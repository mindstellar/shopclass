---
title: Object caching
description: Speed up ShopClass by keeping repeated database results in memcached or APCu — setup, how long entries last, environment variables, and how it differs from a page cache.
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

The cache needs a PHP extension (an add-on module for PHP). Install the one you
want, then check that PHP can see it:

```bash
php -m | grep -E 'memcached|apcu'
```

If the extension is missing, the setting does nothing.

## memcached — recommended

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

## APCu — one server only

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
| `OSC_CACHE` | The cache type: `memcached`, `apcu` or `memcache` |
| `OSC_CACHE_HOST` | The cache server's host, for memcached or memcache |
| `OSC_CACHE_PORT` | The cache server's port. Default `11211` |

A `define()` in `config.php`, or a `$_cache_config` array, always wins over
these variables.

## Emptying the cache

After a bulk import, a direct database edit, or any change made outside
ShopClass, empty the cache:

```bash
php oc-cli.php cache:flush
```

## The old memcache driver

`define('OSC_CACHE', 'memcache')` still works. It uses the old `memcache`
extension, which nobody maintains any more. It is deprecated: use `memcached`.

## Troubleshooting

**Changes in the admin panel take a while to show.**
The cache still holds the old answer until the entry ends. Lower
`OSC_CACHE_TTL`, or empty the cache after admin work.

**The site got slower after turning it on.**
ShopClass probably cannot reach the cache server. Every lookup then waits for the
connection to time out first. Check the host and port, and check that memcached
is running.

**Two web servers show different versions of the site.**
You are using APCu, which keeps one cache per server. Move to memcached.
