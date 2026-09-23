---
title: Increase the PHP memory limit
description: Fix "Allowed memory size exhausted" in ShopClass with OSC_MEMORY_LIMIT, php.ini, or your host's control panel.
sidebar:
  order: 7
---

If a page stops with this error:

```
Fatal error: Allowed memory size of 33554432 bytes exhausted
```

PHP ran out of the memory it is allowed to use. This usually happens while
ShopClass resizes a large photo, imports location data, or runs a plugin that
works through many rows at once.

## The quick fix

Add this line to `config.php`, above the last lines of the file:

```php
define('OSC_MEMORY_LIMIT', '256M');
```

ShopClass raises PHP's limit to this value when it starts. The default is `32M`.

Two things to know:

- It changes the limit for ShopClass only. Nothing else on the server changes.
- It only ever raises the limit. If PHP already allows more, ShopClass leaves it
  alone. So `128M` does nothing on a host that already gives you `256M`.

## When that does not work

`OSC_MEMORY_LIMIT` uses PHP's `ini_set()`, and some hosts block it. Then you must
raise the limit where PHP itself is set up. Try these in order.

**`php.ini`**, if you can edit it:

```ini
memory_limit = 256M
```

**`.htaccess`**, on Apache with `mod_php`:

```apacheconf
php_value memory_limit 256M
```

On hosts that run PHP-FPM or CGI, this line causes a `500` error. If the site
breaks as soon as you add it, remove the line.

**Your control panel.** cPanel, Plesk and most managed hosts have a
"Select PHP version" or "PHP settings" screen with `memory_limit` on it. This
always works, because it changes PHP's own settings.

**Ask your host.** On strict shared hosting, this is the only way. Support will
usually raise it if you ask.

## Checking the current value

In the admin panel, **Tools → System info** shows the memory limit in use.

## How much is enough

| Site | Good limit |
|---|---|
| Small, few photos | `64M` |
| Normal classifieds site | `128M` – `256M` |
| Large imports, big images | `512M` |

A higher limit does not make the site faster. It only lets a runaway process use
more memory before PHP stops it. If ordinary pages need far more than `256M`,
something is wrong. Look for a plugin that loads every row into memory, or for
images being resized at their full original size.
