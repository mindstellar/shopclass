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

## Declaring extra views

A static page's internal name becomes a URL segment, so core keeps a list of
names a page may not take — otherwise a page slugged `contact` would shadow the
contact route. That list is core's own view vocabulary, and a theme adds to it:

```php
osc_add_theme_support('views', array(
    'user-wishlist',
    'template-promo',
));
```

Names may be written with or without `.php`. A declaration only ever **adds** —
core's own names stay reserved whatever you declare, and a theme that declares
nothing behaves exactly as before.

`osc_theme_provides('user-wishlist.php')` answers whether the active theme can
render a view: it ships the file, or it named the view here. Use it when the view
is not a file — one a plugin renders, or one built at runtime.

## Declaring widget zones

A theme has always listed its widget zones on the `Widgets:` line of
`index.php`. That line gives core a slug and nothing else, so the admin screen
labels the zone `footer` and cannot say what it is or where it renders.

Declare them instead:

```php
osc_add_theme_support('widget_locations', array(
    'header' => array(
        'label'       => __('Masthead', 'mytheme'),
        'description' => __('Below the navigation.', 'mytheme'),
    ),
    'footer' => array(
        'label'       => __('Colophon', 'mytheme'),
        'description' => __('Above the copyright line.', 'mytheme'),
    ),
));
```

The declared order is the order the admin shows them in. `description` is
optional; a zone with no `label` falls back to its slug. A bare list —
`array('header', 'footer')` — and a `slug => label` map are both accepted.

Declare from the `init` hook rather than the top of `functions.php` if your
labels are translated: core requires `functions.php` before the translation
layer is initialised.

**A theme that declares nothing keeps its `Widgets:` line**, with each slug
standing in as its own label — exactly what it does today.

`osc_widget_locations()` returns the resolved map, and passes through the
`widget_locations` filter, which is how a plugin contributes a zone of its own.
Anything in that map is placeable: the admin builds its drop zones from it and
refuses a move into a zone that is not in it.

Rendering a zone is unchanged:

```php
osc_show_widgets('footer');
```

It prints nothing when the zone is empty — which is most zones on most sites, so
buffer it if your wrapper would otherwise render as an empty box.
