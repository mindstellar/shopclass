---
title: Themes & widgets
description: Install, switch and customise ShopClass themes, and place widgets into a theme's sections — including what to check before switching.
sidebar:
  order: 7
---

**Appearance** in the admin panel is where you install and switch themes, and
where you place widgets into the sections a theme offers.

## The bundled theme

New installs get **Storefront** — a real, maintained theme, not a placeholder.
It has light and dark modes, three WCAG-AA colour palettes and its own settings
screen, and it is the theme the [live demo](https://demo.mindstellar.com) runs.

Start by configuring it rather than replacing it. Most sites need a logo, a
palette and their own hero copy, which is a settings change, not a theme change.

## Installing another theme

**Appearance → Manage themes** browses the
[theme registry](/docs/developers/market/) — the same catalog `oc-cli.php` reads
— and installs with one click. **Available themes** lists what is installed;
**Current theme** marks the active one.

A theme zip can also be uploaded directly, for something you built or bought
outside the catalog.

From a shell:

```bash
php oc-cli.php theme:list
php oc-cli.php theme:activate --theme=storefront
php oc-cli.php market:install storefront --type=theme
```

`theme:activate` is the way back when a theme breaks the site badly enough that
you cannot reach the admin panel.

## Before you switch

Themes are not interchangeable. Check three things:

- **Widget sections differ between themes.** A theme declares its own sections,
  so widgets placed for one theme may have nowhere to go in another. They are
  not deleted — they simply stop rendering until you place them again.
- **Compatibility.** A theme declares the ShopClass versions it supports; the
  admin will not offer one that does not match.
- **Try it on a copy.** Especially for a site with traffic.

Deleting a theme removes its files. Switch away from it first.

## Widgets

A widget is a block of content placed into a section of the page. Theme
templates declare which sections exist — a sidebar, a footer column, a strip
above the listing grid.

**Appearance → Manage widgets** shows every section with what is in it. **Add widget**
picks a type, then **Add to which section?** places it. Drag to reorder within a
section; the order is the order visitors see.

### Built-in widget types

| Type | What it is |
|---|---|
| **Rich text** | A block of formatted text. Blank lines become paragraphs. |
| **Image** | An image from your media library, optionally linking somewhere. |
| **Custom Code (HTML / JavaScript)** | Raw markup and script. |

Plugins register further types, which appear in the same picker.

:::danger[Custom Code runs on your visitors' browsers]
Anything you paste there executes on every page the widget appears on. Paste
only code you understand, from a source you trust. A "free analytics snippet"
from a forum post is the classic way a classifieds site starts serving malware
to its own users.
:::

## Page builder

The same widget system composes whole pages. A static page can use the **Page
builder (blocks)** template instead of the text editor, and is then assembled
from widget blocks.

See [pages](/docs/use/pages/).

## Customising a theme

You can edit a theme's files directly, and it will work — until the theme
updates and overwrites your changes.

Two durable approaches:

- **A child theme.** Declare `Parent Theme` in the child's header block and
  override only the templates you change. The parent keeps updating underneath.
- **A plugin.** Styles, scripts and behaviour can be added from a plugin with
  [the enqueue functions](/docs/developers/scripts-and-styles/), leaving the
  theme untouched entirely.

For building a theme from scratch, see the
[package specification](/docs/developers/package-spec/).
