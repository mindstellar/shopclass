---
title: Themes & widgets
description: "Install, switch and customise ShopClass themes, and place widgets into a theme's sections, including what to check before switching."
sidebar:
  order: 7
---

**Appearance** in the admin panel is where you install and switch themes, and
where you place widgets into the sections a theme offers.

## The bundled theme

New installs get **Storefront**: a real, maintained theme, not a placeholder.
It has light and dark modes, three colour palettes that meet WCAG-AA (the
standard for readable colour contrast) and its own settings screen, and it is
the theme the [live demo](https://demo.mindstellar.com) runs.

Start by configuring it rather than replacing it. Most sites need a logo, a
palette and their own hero copy, which is a settings change, not a theme change.

## Installing another theme

**Appearance → Manage themes** has three tabs:

| Tab | What it shows |
|---|---|
| **Themes** | What is installed. **Current theme** is the live one; **Other themes** follow. |
| **Browse** | The [theme registry](/docs/developers/market/): the same catalog `oc-cli.php` reads. One click installs. |
| **Updates** | Installed themes with a newer version, with a count in the tab. |

Each theme is a card carrying its version, its author, a short description and a
state badge (*Live*, *Installed*), with the buttons that apply to it:
**Activate**, **Preview**, **Delete**.

**Plugins → Manage plugins** works the same way, with **Installed**, **Browse**
and **Updates** tabs and the same card layout.

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

## Plugins a theme needs

A theme can ask for plugins, such as a payment plugin for a shop theme. When you activate it and
one is missing, **Plugins for this theme** lists them with what each does. **Install and activate
all** installs them one at a time, and the theme goes live once every required plugin is on.
Recommended plugins can be skipped.

If you later turn off or remove a required plugin, **Appearance** shows a notice with a **Fix it**
link back to the same screen. Nothing installs until you click.

## Before you switch

Themes are not interchangeable. Check three things:

- **Widget sections differ between themes.** A theme declares its own sections,
  so widgets placed for one theme may have nowhere to go in another. They are
  not deleted; they stop rendering until you place them again.
- **Compatibility.** A theme declares the ShopClass and PHP versions it supports,
  and the card says plainly whether it runs here (*Needs 6.5 or newer*, *Needs
  PHP 8.2*) instead of leaving you to compare numbers.
- **Try it on a copy.** Especially for a site with traffic.

Deleting a theme removes its files. Switch away from it first.

## Widgets

A widget is a block of content placed into a section of the page. Theme
templates declare which sections exist: a sidebar, a footer column, a strip
above the listing grid.

**Appearance → Manage widgets** shows every section with what is in it. **Add widget**
picks a type, then **Add to which section?** places it. Drag to reorder within a
section; the order is the order visitors see.

### Built-in widget types

| Type | What it is |
|---|---|
| **Rich text** | A block of formatted text. Blank lines become paragraphs. |
| **Image** | An image from your media library, optionally linking somewhere. |
| **Menu** | One of your [menus](#menus) as a list of links, with an optional heading. |
| **Custom Code (HTML / JavaScript)** | Raw markup and script. |

Plugins register further types, which appear in the same picker.

Each typed widget has an eye button. It hides the widget from the site without
deleting it, and a second click shows it again. A hidden widget keeps its place
and settings, and its name is struck through in the list. Moderators can hide
any widget except a Custom Code one.

Only full admins can add a Custom Code widget; moderators cannot.

:::danger[Custom Code runs on your visitors' browsers]
Anything you paste there executes on every page the widget appears on. Paste
only code you understand, from a source you trust. A "free analytics snippet"
from a forum post is the classic way a classifieds site starts serving malware
to its own users.
:::

## Menus

**Appearance → Menus** sets the links your theme shows in its header and footer.
Admins only; moderators cannot edit menus.

- **Main menu and Footer menu.** Until you save them, the main menu lists your top
  categories and the footer lists the pages you show as links. The editor starts
  from that list. Once saved, visitors see your list instead.
- **Add items.** Add a category, a page or a link. A link needs a label and an
  address starting with `http://`, `https://` or `/`. Category and page items use
  their own name unless you give them a label.
- **Arrange.** Drag the handle, or focus it and use the arrow keys: up and down
  move the item, right puts it under the item above, left moves it out. Items nest
  up to three levels. A category you switch off, or a page you delete, drops out
  of the menu with the items under it.
- **Labels in each language.** With more than one language, the pencil opens a
  label field for each.
- **Save menu** stores the whole menu. **Use the automatic menu** throws your main
  or footer list away and goes back to the automatic one.
- **Other menus.** **New menu** makes another one, up to 20, with a name and an id
  such as `top-bar`. Show it with a **Menu** widget, or ask your theme's developer
  to use its id. **Delete menu** removes it.

How much of a menu shows depends on the theme. If your theme names where it shows a menu, the editor says so and warns when items are nested deeper than the theme shows.

## Customise your theme

If your theme supports it, **Appearance → Customise** edits the home page with a
live preview beside the controls. A theme that does not support it has no
Customise entry; use **Manage widgets** instead.

- **Sections.** The home page is a list of sections. Reorder them, hide one with
  the eye, remove it, or add another from the types the theme allows. Click a
  section to edit its text and images.
- **Look.** The theme's brand colour, fonts, logo and light or dark default are
  set in the same screen. Colour and font changes show at once; the rest reload
  the preview.
- **Draft and publish.** Every change is saved as your private draft, kept for
  seven days. Visitors see nothing until you press **Publish**. **Discard**
  throws the draft away and returns to the published page. If the sections
  change elsewhere after your draft began, for example on the Widgets screen,
  Publish asks before it replaces those changes.
- **Hidden sections.** A hidden section stays in the list, struck through, and
  can be shown again. It is the same hide switch as on **Manage widgets**.
- **The preview is for you only.** It works only for a signed-in administrator,
  is never cached and is hidden from search engines. Moderators cannot
  customise; they keep **Manage widgets**.

## Page builder

The same widget system composes whole pages. A static page can use the **Page
builder (blocks)** template instead of the text editor, and is then assembled
from widget blocks.

See [pages](/docs/use/pages/).

## Customising a theme

You can edit a theme's files directly, and it will work, until the theme
updates and overwrites your changes.

Two durable approaches:

- **A child theme.** Declare `Parent Theme` in the child's header block and
  override only the templates you change. The parent keeps updating underneath.
  See [Child themes](/docs/developers/child-themes/) for what is inherited and
  the two rules that stop a child breaking its parent.
- **A plugin.** Styles, scripts and behaviour can be added from a plugin with
  [the functions that register them](/docs/developers/scripts-and-styles/),
  leaving the theme untouched entirely.

For building a theme from scratch, see the
[package specification](/docs/developers/package-spec/).
