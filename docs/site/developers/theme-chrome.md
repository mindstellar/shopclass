---
title: Theme chrome
description: Declare where your theme's header and footer live so ShopClass can render its own pages inside your layout instead of falling back to a standalone one.
sidebar:
  order: 16
---

Some pages belong to core rather than to your theme — the account-delete
confirmation, the credits wallet, the buy and orders screens. Core will use your
theme's view if you ship one. If you do not, it needs somewhere to put the page.

**Theme chrome** is the pair of views that opens and closes a page on your site:
the one printing `<!doctype html>` through the site header, and the one closing
`</body>`. Tell core where they are and those core-owned pages render inside your
layout, with your header, your footer, your typography.

## You probably do not need to do anything

Core finds chrome on its own, first hit wins:

1. what your theme declared (below)
2. `header.php` + `footer.php` in the theme root
3. `common/header.php` + `common/footer.php`

Both halves must exist. A header with no footer is not chrome — core would leave
the page unclosed, so it falls through to its own standalone page instead.

If your theme uses either conventional pair, it already works. Declare only when
your layout does not match one.

## Declaring

In your theme's `functions.php`:

```php
osc_add_theme_support('chrome', array(
    'header' => 'parts/site-header.php',
    'footer' => 'parts/site-footer.php',
));
```

Both paths are **relative to your theme directory**. An absolute path, or one
containing `..`, is refused and core falls back to the probes.

## Rendering chrome yourself

```php
osc_get_header();   // true when the theme has chrome, false otherwise
osc_get_footer();
```

Both return `false` rather than printing anything when there is no chrome, so a
caller can fall through to something else:

```php
if (!osc_get_header()) {
    // no chrome on this theme — render a self-contained page instead
}
```

`osc_theme_has_chrome()` answers the same question without rendering.

## What core puts between them

Core prints its page markup wrapped in `.oe-page` and `.oe-doc`, and injects one
small stylesheet through the `header` hook — the same hook your `<head>` already
runs for enqueued scripts and styles. Every selector in it is `.oe-*` prefixed,
so it cannot reach your own markup on the same page.

Text colour and typography are deliberately **not** set on that path: the page
inherits yours, so it reads as part of your theme rather than a panel dropped
into it.

## Theme supports in general

`chrome` is one feature. The registry is generic:

```php
osc_add_theme_support(string $feature, mixed $args = true): void
mixed osc_theme_supports(string $feature);   // the args, or false
osc_remove_theme_support(string $feature): void;
```

Call `osc_add_theme_support()` from `functions.php`, which core loads before it
renders anything. A feature nobody declared reads as `false`, and core does what
it did before — declaring is always optional.
