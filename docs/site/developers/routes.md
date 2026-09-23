---
title: Routes
description: Add your own pages to ShopClass with osc_add_route — clean URLs, parameters, admin routes and controller routes.
sidebar:
  order: 4
---

A plugin often needs a page of its own. Without a route, the only way to reach
one is the raw dispatcher URL:

```
https://example.com/index.php?page=custom&file=your_plugin/page.php
```

which is ugly and exposes your file layout. Routes turn that into:

```
https://example.com/your-plugin-page
```

## Registering a route

```php
osc_add_route($id, $regexp, $url, $file);
```

| Parameter | Meaning |
|---|---|
| `$id` | Short name you will use to build the URL later. |
| `$regexp` | Regular expression the incoming path must match. |
| `$url` | Pattern used to *build* the pretty URL, with `{parameters}`. |
| `$file` | The PHP file to load when it matches. |

Register routes early — on `init` or at the top of your plugin's `index.php` —
so they exist before the request is dispatched.

## Building the URL

Never hard-code the path. Ask for it, so a change to the pattern does not break
every link:

```php
osc_route_url($id, $args = array());        // public site
osc_route_admin_url($id, $args = array());  // admin panel
```

## Linking to a core page

Core's own pages — login, contact, the account screens, the credit wallet — are in
a shared table, and `osc_core_url()` builds their URLs from it. The same table
compiles the rewrite rules, so you get the friendly URL when the site has friendly
URLs on and the query-string form when it does not, without testing for it:

```php
echo osc_core_url('user_login');                          // login page
echo osc_core_url('item_edit', array('id' => 42, 'secret' => $secret));
echo osc_core_url('user_pub_profile', array('username' => 'jo'));
```

Most core pages also have a named helper — `osc_user_login_url()`,
`osc_contact_url()`, `osc_billing_wallet_url()` — and those are thin wrappers over
this. Prefer the named helper where one exists; reach for `osc_core_url()` for a
page that has none. The route names are the keys of
`mindstellar\routing\CoreRoutes::all()`.

An unknown name returns an empty string rather than a broken link.

### Permalink structures

Listings, static pages and categories do not have a fixed path — an admin writes
their shape on **Settings → Permalinks**, with placeholders:

| Structure | Placeholders |
|---|---|
| Listing | `{ITEM_ID}` (required), `{ITEM_TITLE}`, `{ITEM_CITY}`, `{CATEGORIES}` |
| Page | `{PAGE_ID}`, `{PAGE_SLUG}` |
| Category | `{CATEGORY_ID}`, `{CATEGORY_NAME}`, `{CATEGORIES}` |

These live in the same table, and `CoreRoutes::expand()` fills one in:

```php
echo \mindstellar\routing\CoreRoutes::expand('page', array(
    'PAGE_ID'   => 3,
    'PAGE_SLUG' => 'about-us',
));
// → about-us-p3
```

It returns the path only — no site address and no language prefix, because the
caller decides both. Use `osc_item_url()`, `osc_static_page_url()` and
`osc_search_url()` for a finished link; reach for `expand()` when you are
building something else out of the same structure, such as a sitemap.

Where two placeholders could answer the same thing, the one that appears first in
the structure wins.

## A worked example

```php
// Register — in your plugin's index.php
osc_add_route(
    'dynamic-route',                                  // id
    'dynamic-route/([0-9]+)/(.+)',                    // regexp
    'dynamic-route/{my-numeric-param}/{my-own-param}', // url pattern
    osc_plugin_folder(__FILE__) . 'mydynamicroute.php' // file
);

// Link to it — anywhere in a theme or plugin
echo osc_route_url('dynamic-route', array(
    'my-numeric-param' => '12345',
    'my-own-param'     => 'my-own-value',
));
// → https://example.com/dynamic-route/12345/my-own-value
```

Inside `mydynamicroute.php`, read the captured groups with `Params::getParam()`.

## Rules worth remembering

- Parameters in `$url` go between braces: `{parameter}`.
- Parameter names must match **exactly**, case included, between `osc_add_route`
  and `osc_route_url`.
- Any file in a folder called `admin` is opened in the admin panel and returns
  404 on the public site.

:::danger[Make your patterns unique]
Regular expressions collide easily, and a greedy pattern can swallow core URLs —
listings, categories, user pages. Prefix your routes with something specific to
your plugin, and test that listings still resolve after you add one.
:::

## Controller routes

For an endpoint that *acts and redirects* rather than rendering a page — a form
target, a webhook receiver, a "mark as sold" link — use a route hook instead:

```php
osc_add_route_hook($id, $regexp, $url);
```

Unlike a file-backed route, this runs its handler without first emitting the
theme's `custom.php` chrome, so you can redirect or return a response without
half a page already sent. Link to it with `osc_route_url($id)` exactly as
before.
