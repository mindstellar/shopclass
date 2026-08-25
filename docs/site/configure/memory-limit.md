---
title: Increase the PHP memory limit
description: Fix "Allowed memory size exhausted" in ShopClass with OSC_MEMORY_LIMIT, php.ini, or your host's control panel.
sidebar:
  order: 6
---

If a page dies with:

```
Fatal error: Allowed memory size of 33554432 bytes exhausted
```

PHP hit its memory ceiling. It usually shows up while resizing a large uploaded
photo, importing location data, or running a plugin that processes many rows at
once.

## The quick fix

Add this to `config.php`, above the closing lines:

```php
define('OSC_MEMORY_LIMIT', '256M');
```

ShopClass raises PHP's limit to that value at start-up. The default is `32M`.

Two things worth knowing:

- It only applies to ShopClass, so nothing else on the server is affected.
- It only ever raises the limit. If PHP is already allowed more than the value
  you set, the setting is ignored rather than lowering it — so setting `128M` on
  a host that already gives you `256M` does nothing.

## When that does not work

`OSC_MEMORY_LIMIT` calls `ini_set()`, and some hosts forbid it. Then you have to
raise the limit where PHP itself is configured.

**`php.ini`** — if you can edit it:

```ini
memory_limit = 256M
```

**`.htaccess`** — on Apache with `mod_php`:

```apacheconf
php_value memory_limit 256M
```

This causes a `500` error on hosts using PHP-FPM or CGI. If the site breaks
immediately after adding it, remove the line.

**Your control panel** — cPanel, Plesk and most managed hosts expose a "Select
PHP version" or "PHP settings" screen with `memory_limit` on it. This is the
route that always works, because it edits the pool configuration directly.

**Ask your host.** On restrictive shared hosting this is the only route, and
support will usually raise it on request.

## Checking the current value

```bash
php oc-cli.php doctor
```

The report includes the effective memory limit alongside the other environment
checks. From the admin panel, **Tools → System info** shows the same.

## How much is enough

| Site | Reasonable limit |
|---|---|
| Small, few photos | `64M` |
| Normal classifieds site | `128M` – `256M` |
| Large imports, big images | `512M` |

Raising it to `512M` on a small site does not make anything faster — it only
raises the ceiling before a runaway process is stopped. If you need far more
than `256M` for ordinary page views, something is wrong: a plugin loading every
row into memory, or images being resized at their full original resolution.
