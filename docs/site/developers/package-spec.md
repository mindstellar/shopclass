---
title: Package specification
description: The contract a ShopClass plugin or theme must satisfy — header fields, compatibility metadata, versioning and artwork.
sidebar:
  order: 2
---

Every ShopClass plugin and theme declares itself in a comment block at the top
of its `index.php`. Core parses that block to show the package in the admin
list; the registry parses the same block to publish it. One declaration, two
readers, no way for them to disagree.

This page is the practical summary. The normative version — with the parser's
exact behaviour, the manifest schema and the PR validation rules — is
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
Tested up to: 6.1
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-digital-goods/issues
*/
```

:::danger[Keep the block at the very top, and nowhere else]
Core matches each field with a case-insensitive substring search over the whole
file — not a parse of the comment. **The first occurrence anywhere in
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
| `Tested up to` | recommended | Highest core version you have verified. |
| `Requires PHP` | recommended | Minimum PHP version. |
| `Author URI` | optional | |
| `Plugin update URI` | optional | Legacy self-hosted updates — see [auto-update](/docs/developers/auto-update/). |

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
| `Parent Theme` | optional | Slug of the theme this one extends. |
| `Widgets` | optional | Comma-separated widget location ids. |
| `Author URI` | optional | |
| `Theme update URI` | optional | Legacy self-hosted updates. |

## Versioning

Use `MAJOR.MINOR.PATCH`. Core compares versions to decide whether an update is
available, so a version that does not sort meaningfully will not update
correctly on people's sites.

`Requires Shopclass` and `Tested up to` decide whether an install offers your
package at all. Set `Requires Shopclass` to the oldest core version you actually
support — not the newest one you happen to run.

## Artwork

| Asset | Plugin | Theme | Spec |
|---|---|---|---|
| Icon | `assets/icon.svg` or `assets/icon.png` | — | Square. SVG preferred; PNG at 256×256. |
| Screenshot | `assets/screenshot-1.png`, … | `screenshot.png` **at the package root** | 4:3, minimum 1200×900. |

The theme screenshot sits at the package root because that is where a decade of
themes already put it, and where core looks.

**Artwork is optional.** Core renders a built-in placeholder for any package
without it — a neutral, theme-aware tile tinted from a hash of the slug — so a
grid of unillustrated packages still reads as distinct tiles rather than broken
images. Do not ship a blank or a "no image" graphic of your own; the fallback is
better than one.

Screenshots must show the package's real interface. No marketing copy, no
logos-on-gradients, no before/after collages.

## Getting listed

Publishing to every install goes through the registries — see
[the market](/docs/developers/market/).
