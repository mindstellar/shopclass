---
title: Head and body
description: Hand the document head to ShopClass with osc_head(), and let core name the page for you with osc_body_class() and osc_language_attributes().
sidebar:
  order: 21
---

The `<head>` used to belong entirely to the theme. Core could not put a title, a
description or a canonical on a page, not even on a page core rendered itself,
unless the theme happened to print one. Every theme therefore reimplemented the
same block, and a theme that got it slightly wrong got it wrong on every page.

## `osc_head()`

Call it as the first thing inside `<head>`:

```php
<head>
<?php osc_head(); ?>
<meta name="theme-color" content="#1b2c5e">
</head>
```

It prints, in order:

| Part | What |
|---|---|
| `charset` | `<meta charset="utf-8">` |
| `viewport` | `<meta name="viewport" content="width=device-width, initial-scale=1">` |
| `title` | `<title>` from `meta_title()`, escaped |
| `description` | `<meta name="description">`, omitted when there is none |
| `keywords` | `<meta name="keywords">`, omitted when there is none |
| `canonical` | `<link rel="canonical">`, omitted when there is none |
| `pagination` | `<link rel="prev">` / `<link rel="next">` on a search results page, omitted elsewhere |
| `feed` | `<link rel="alternate" type="application/rss+xml">`: the current search's feed on a results page, the site's elsewhere |
| `api` | `<meta name="shopclass-api">`: the REST API address and this page's [page token](/docs/developers/api/authentication/#same-site-session-theme-javascript) |

and then runs the `header` hook, which is where enqueued styles and scripts, the
robots meta, and everything plugins add come from.

**Do not also call `osc_run_hook('header')`.** `osc_head()` runs it, and a second
call prints every enqueued asset twice.

### Keeping a part for yourself

Every part is opt-out. Declare only what you want to keep:

```php
osc_add_theme_support('head', array('feed' => false));
```

Anything not named stays on, so `osc_add_theme_support('head')` on its own means
"core owns all of it". The declaration is also how core knows your head is
core-managed; `osc_head()` itself works whether or not you declared anything.

A theme that does not call `osc_head()` still gets the `api` tag from the `header` hook, unless it
declares `'api' => false`.

### A theme with no comments

A theme that never shows listing comments says so:

```php
osc_add_theme_support('comments', false);
```

**Settings → Comments** then tells the owner the active theme shows no comments.

## `osc_body_class()`

```php
<body <?php osc_body_class(); ?>>
```

Prints the whole attribute, so a page with no classes prints nothing rather than
an empty `class=""`. Core names the page for you:

- the page itself: `home`, `search`, `search-category`, `item`, `item-post`,
  `item-edit`, `item-contact`, `contact`, `custom`, `login`, `register`,
  `recover`, `forgot-password`, `user-public-profile`, `error-404`
- an account view: its own class *and* a shared `user`, so one selector reaches
  the whole signed-in area: `user-dashboard`, `user-profile`, `user-items`,
  `user-alerts`, `user-change-email`, `user-change-password`,
  `user-change-username`
- a static page: `page` and `page-{internal name}`
- the visitor: `logged-in` or `logged-out`
- the context: `lang-{locale}`, `theme-{theme}`, and `rtl` on a right-to-left
  locale

Pass your own alongside them, as a string or a list:

```php
<body <?php osc_body_class('has-sidebar'); ?>>
```

`osc_body_class_list()` returns the same classes as an array. Both pass through
the `body_class` filter, which receives the computed list and whatever the caller
passed. That is where a plugin adds one.

Every class is lowercased and reduced to `[a-z0-9_-]`; a static page's internal
name is typed by an admin and lands in an attribute, so it is not trusted.

## `osc_language_attributes()`

```php
<html <?php osc_language_attributes(); ?>>
```

Prints `lang="en-US" dir="ltr"`, with `dir="rtl"` on a right-to-left locale.
Filterable through `language_attributes`. Pass `false` to get the string back
instead of printing it.

## None of this is required

A theme that calls none of these behaves exactly as it did. They exist so a theme
stops reimplementing them, and so core can describe a page it renders itself.

## The place field

```php
<?php osc_place_field(); ?>
```

Prints the Location field: one box that suggests cities from `GET /api/v1/cities`
and sets the place when one is picked. Core owns the markup and the script; the
theme only places and styles it. On an edit page pass the listing:
`osc_place_field(['item' => osc_item()])`. Other keys: `id`, `label`, `required`,
`class`, `hint_class`, `error_class`, `req_class`, `saved`, `account` and `error`.
`required` defaults to the Required location setting.

The form posts `city` (the text), `cityId`, `regionId` and `countryId`. An old
listing with a typed place also posts `region` and `country` as text. Read them
as before; nothing changes for the listing input.

Style these classes: `oe-place` (and `is-picked`), `oe-place__box`,
`oe-place__input`, `oe-place__pin`, `oe-place__clear`, `oe-place__spin`,
`oe-place__hint`, `oe-place__note` (and `oe-place__note--picked`),
`oe-place__end`, `oe-place__saved`, `oe-field-error`, and in the list
`osc-ac-list`, `osc-ac-option`, `is-active`, `oe-place__name`,
`oe-place__region`, `oe-place__cc`, `oe-place__tick`, `oe-place__status` (with
`--more` and `--error`) and the **Try again** button `oe-place__retry`. The list sits inside
the box at the input's width.

When **Only places from the list** refuses a save, the error is one entry on
`/city` with the text "Pick your location from the list.". The helper prints it
itself; a theme that prints its own errors reads it with `osc_form_error('/city')`.
With the option off, typed text is accepted and the field only suggests.
