---
title: Package specification
description: "The contract a ShopClass plugin or theme must satisfy: header fields, compatibility metadata, versioning and artwork."
sidebar:
  order: 2
---

Every ShopClass plugin and theme declares itself in a comment block at the top
of its `index.php`. Core parses that block to show the package in the admin
list; the registry parses the same block to publish it. One declaration, two
readers, no way for them to disagree.

This page is the practical summary. The normative version (with the parser's
exact behaviour, the manifest schema and the PR validation rules) is
[`docs/PACKAGE-SPEC.md`](https://github.com/mindstellar/shopclass/blob/master/docs/PACKAGE-SPEC.md)
in the repository.

## The header block

A plain PHP comment, immediately after `<?php`:

```php
<?php
/*
Plugin Name: Digital Goods
Plugin URI: https://github.com/mindstellar/shopclass-plugin-digital-goods
Description: Attach downloadable files to a listing and deliver them to buyers.
Version: 2.0.0
Author: Mindstellar
Author URI: https://github.com/mindstellar
Short Name: digital-goods
Requires Shopclass: 6.0.0
Tested up to: 6.4
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-digital-goods/issues
*/
```

:::danger[Keep the block at the very top, and nowhere else]
Core matches each field with a case-insensitive substring search over the whole
file, not a parse of the comment. **The first occurrence anywhere in
`index.php` wins**, and a longer field name contains a shorter one: a line
reading `API Version: 2` placed above `Version: 1.4.0` makes your package report
version `2`. Values also end at the end of the line; they cannot wrap.
:::

### Plugin fields

| Field | Required | Notes |
|---|---|---|
| `Plugin Name` | yes | Human-readable. Falls back to the filename. |
| `Description` | yes | One line. Long prose belongs in `README.md`. |
| `Version` | yes | See [versioning](#versioning). |
| `Author` | yes | |
| `Short Name` | recommended | Should equal your slug. Defaults to `Plugin Name`. |
| `Plugin URI` | recommended | Homepage. |
| `Support URI` | recommended | Rendered as the support icon in the admin list. |
| `Requires Shopclass` | recommended | Minimum core version. |
| `Tested up to` | optional | Highest core version you have verified. Shown for information only; core never warns or blocks on it. |
| `Requires PHP` | recommended | Minimum PHP version. |
| `Modes` | optional | Site types it works for: `classifieds`, `shop`. Absent means `classifieds`. |
| `Requires Plugins` | optional | Plugins it needs, such as `stripe >=1.2.0, digital-goods`. |
| `Author URI` | optional | |
| `Plugin update URI` | optional | Legacy self-hosted updates: see [auto-update](/docs/developers/auto-update/). |

### Theme fields

Same idea, different names, because theme parsing is a separate function:

| Field | Required | Notes |
|---|---|---|
| `Theme Name` | yes | |
| `Description` | yes | |
| `Version` | yes | |
| `Author` | yes | |
| `Theme URI` | recommended | |
| `Requires Shopclass` | recommended | |
| `Tested up to` | recommended | |
| `Parent Theme` | optional | Slug of the theme this one extends. See [Child themes](/docs/developers/child-themes/). |
| `Widgets` | optional | Comma-separated widget location ids. |
| `Modes` | optional | Site types it works for: `classifieds`, `shop`. Absent means `classifieds`. |
| `Requires Plugins` | optional | Plugins the theme needs. Activating the theme offers to install and activate them. |
| `Author URI` | optional | |
| `Theme update URI` | optional | Legacy self-hosted updates. |

### Modes and required plugins

The site owner picks a site type in **Settings → General**: classifieds or shop. A theme
or plugin that does not declare that type gets a warning there.

A package in the catalog can say the same in `shopclass.json`, which wins over the header:

```json
{
  "modes": ["shop"],
  "plugins": {
    "required": { "stripe": ">=1.2.0" },
    "recommended": { "digital-goods": "*" }
  }
}
```

A theme's required plugins are installed and activated from one screen before the theme goes
live. Recommended ones can be skipped. A plugin cannot be turned on while one of its required
plugins is missing, off, or the wrong version. A plain list such as `["stripe"]` accepts any
version. A bad slug is ignored, and bad JSON reads as if the file had no `modes` or `plugins`.

A shop theme with its own order page names it in `order_url`, with `{id}` where the order
number goes. Order e-mails then link to it. It is a path on the site, or a full `https://`
address for a front end on another site:

```json
{
  "order_url": "orders/{id}"
}
```

## Versioning

Use `MAJOR.MINOR.PATCH`. Core compares versions to decide whether an update is
available, so a version that does not sort meaningfully will not update
correctly on people's sites.

`Requires Shopclass` and `Requires PHP` decide whether an install offers your
package at all. Set `Requires Shopclass` to the oldest core version you actually
support, not the newest one you happen to run.

### The compatibility badge

Every package card in the admin carries one short verdict about *that* install.
These are the four, and what earns each:

The checks run in this order, and the first one that matches wins:

| Badge | When | What to do |
|---|---|---|
| **Needs 6.5 or newer** | `Requires Shopclass` is above this install | nothing: the site must upgrade first |
| **Needs PHP 8.2** | Core is fine, but `Requires PHP` is above this server | nothing: the server must upgrade first |
| **Works with 6.4** | It runs here | nothing: this is the goal |
| **No version declared** | You declared neither `Requires Shopclass` nor `Requires PHP` | declare at least `Requires Shopclass` |

An older `Tested up to` does not change the badge, so you do not re-release a
package only because core moved on.

The catalog uses the same first two checks when it picks which of your releases
an install is offered: the newest one whose `Requires Shopclass` and
`Requires PHP` that site satisfies. So an old release stays reachable for old sites.

## Artwork

| Asset | Plugin | Theme | Spec |
|---|---|---|---|
| Icon | `assets/icon.svg` or `assets/icon.png` | None | Square. SVG preferred; PNG at 256×256. |
| Screenshot | `assets/screenshot-1.png`, … | `screenshot.png` **at the package root** | 4:3, minimum 1200×900. |

The theme screenshot sits at the package root because that is where a decade of
themes already put it, and where core looks.

**Artwork is optional.** Core renders a built-in placeholder for any package
without it (a neutral, theme-aware tile tinted from a hash of the slug), so a
grid of unillustrated packages still reads as distinct tiles rather than broken
images. Do not ship a blank or a "no image" graphic of your own; the fallback is
better than one.

Screenshots must show the package's real interface. No marketing copy, no
logos-on-gradients, no before/after collages.

## Getting listed

Publishing to every install goes through the registries: see
[the market](/docs/developers/market/).
