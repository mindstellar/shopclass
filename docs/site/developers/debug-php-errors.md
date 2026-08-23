---
title: Debug PHP errors
description: Turn on error reporting and logging in ShopClass with OSC_DEBUG and OSC_DEBUG_LOG — including how to debug a white screen.
sidebar:
  order: 11
---

By default ShopClass keeps PHP quiet: notices and strict warnings are suppressed
so a visitor never sees them. That is right for production and unhelpful the
moment something breaks.

Two constants in `config.php` change it.

## Show errors on screen

```php
define('OSC_DEBUG', true);
```

Error reporting rises to `E_ALL | E_STRICT` and `display_errors` is turned on,
so PHP prints what went wrong instead of a blank page.

With `OSC_DEBUG` off — the default — the level is
`E_ALL ^ E_NOTICE ^ E_USER_NOTICE`.

:::danger[Never leave this on in production]
Displayed errors leak file paths, database structure and sometimes credentials
to anyone who can trigger them. Turn it on, read the error, turn it off.
:::

## Log errors to a file

Better on a live site, because visitors see nothing:

```php
define('OSC_DEBUG', true);
define('OSC_DEBUG_LOG', true);
```

Errors are written to `oc-content/debug.log`.

If the file does not appear, the web server cannot create it. Create it yourself
and make it writable by the web-server user:

```bash
touch oc-content/debug.log
chmod 664 oc-content/debug.log
```

Then watch it while you reproduce the problem:

```bash
tail -f oc-content/debug.log
```

:::caution[`oc-content/` is web-accessible]
Anyone who guesses the URL can read your log. Delete it when you are finished,
or deny access to `*.log` in your server config.
:::

## Debugging a white screen

A blank page means PHP died before it could print anything — usually a fatal
error with display off.

1. Set `OSC_DEBUG` and `OSC_DEBUG_LOG` as above.
2. Reload the page and read `oc-content/debug.log`.
3. If the log is still empty, PHP failed before ShopClass loaded — a syntax
   error in `config.php`, or an out-of-memory kill. Check your **server's** PHP
   error log; your host's control panel will point at it.

Common causes, in the order they are worth checking:

| Symptom | Usual cause |
|---|---|
| Blank page right after an update | A leftover file from an older version, or a plugin using something removed. |
| "Allowed memory size exhausted" | [Raise the memory limit](/docs/configure/memory-limit/). |
| Blank page on one page only | A plugin hooked to that page. Disable them one at a time. |
| Blank page everywhere, admin included | `config.php`, the database connection, or a fatal in a plugin loaded on every request. |

To take plugins out of the picture from a shell:

```bash
php oc-cli.php plugin:list
php oc-cli.php plugin:deactivate --plugin=<folder>
```

And to check the environment as a whole:

```bash
php oc-cli.php doctor
```

## Related

- [Debug SQL queries](/docs/developers/debug-sql-queries/) — when the problem is
  the database rather than the code.
