---
title: Customiser
description: Let site owners reorder, hide and edit your theme's sections and look with a live preview, by declaring one theme support.
sidebar:
  order: 20
---

**Appearance → Customise** is built into core. It appears for any theme that
declares the `customiser` theme support. Sections are ordinary
[widgets](/docs/use/themes-and-widgets/) at a widget location; look values are
ordinary [settings page](/docs/developers/settings-pages/) fields. The theme adds
no table and no admin code.

## Declare it

```php
osc_add_theme_support('customiser', array(
    'locations' => array(
        'home' => array(
            'label'   => 'Home page',
            'types'   => array('mytheme.hero', 'mytheme.products', 'core.rich_text'),
            'preview' => osc_base_url(),
        ),
    ),
    'settings'  => array('page' => 'mytheme', 'fields' => array('brand', 'scheme', 'font', 'logo')),
    'swatches'  => array('brand' => array('raspberry' => '#c81f5e', 'ocean' => '#0f6fbf')),
    'live'      => array('brand' => 'data-brand', 'scheme' => 'data-theme', 'font' => 'data-font'),
));
```

Core checks the declaration with `osc_customiser_spec()` and drops anything
invalid:

- `locations` must be widget locations the theme declares, `types` registered
  widget types, and `preview` a URL on this site.
- `settings.fields` must be fields of the named settings page. Secret, custom,
  category-mode, rich text and translated fields are not allowed; core drops them.
- `swatches` are printed as a background colour only when they match
  `#rrggbb`. A `select` field with swatches is drawn as colour circles.
- `live` names fields the preview applies without a reload, as attributes on
  `<html>`.

If nothing usable is left, `osc_customiser_spec()` returns `null` and the
screen does not appear.

## Where a type fits

A widget type can name the sections it fits with the `locations` key of its
spec. Without it the type fits every section.

| Key | Value |
|---|---|
| `locations` | A non-empty list of section slugs, each `[a-z0-9_-]{1,40}`. Anything else throws. |
| `builder` | `true` for a layout block. It fits a Page builder page, and a customiser section whose `types` lists it, never Appearance → Widgets. |
| `icon` | A Bootstrap Icons name shown beside the block in the page editor. |

Core's layout blocks (`core.media_text`, `core.columns`, `core.heading`, `core.button`) print
`osc-media-text`, `osc-columns`, `osc-heading`, `osc-button-row` and `osc-button` markup. Core's
`page-blocks.css` (style id `osc-page-blocks`) lays them out on Page builder pages; a theme styles
the look on the same classes. A theme that shows one in a home section enqueues `osc-page-blocks`
there itself.

Block fields take the types `text`, `textarea`, `richtext`, `select`, `choice` (a segmented switch),
`checkbox`, `number`, `image`, `link` (a web, `mailto:` or site address) and `note` (help text only).
A field may add `half` to share a row, `section` (with an optional `section_note`) to open a group,
`help`, `placeholder`, `icons` (option value => Bootstrap Icons name, for a `choice`) and `show_if`
(`array('count' => '3')`: shown only while that field holds that value).

```php
osc_register_widget('aisle.hero', array(
    'label'     => 'Hero banner',
    'render'    => $render,
    'locations' => array('home'),
));
```

The Widgets screen, the add and move actions and the customiser refuse the type
in any other section and do not offer it there. Widgets already placed keep
rendering. Legacy HTML widgets are never limited.

## Hidden sections

A section can be hidden without being deleted. Core stores this as the reserved
config key `_enabled`. `osc_render_widget()` prints nothing when it is `false`; a
missing key or `true` renders. Your render callback never sees a hidden row.
Editing a widget keeps the key.

## Draft and preview

Changes are saved as a draft in the key-value store, one per administrator and
theme, for seven days. **Publish** writes each drafted location in one
transaction, saves the look values, clears the page cache and runs
`customiser_published( $theme )`.

The preview is the location's `preview` URL plus `?osc_preview=1`. It is honoured
only for a signed-in administrator. Otherwise the parameter does nothing. With
no draft for the active theme, the preview shows the published page. In a preview:

- `osc_show_widgets( $location )` renders the drafted rows. An unsaved row has
  id `0`.
- `osc_customiser_preview()` is `true`.
- `osc_customiser_draft_value( $page, $name )` returns the drafted look value, or
  `null` when it is not drafted. Read it before the saved setting.
- The response is not cacheable and sends `X-Robots-Tag: noindex`, and
  `osc_head()` prints `<meta name="osc-customiser" content="ADMIN ORIGIN">`.
- `osc_customiser_preview_rows( $location )` gives the drafted rows if you render
  a location yourself.

Never print a drafted value into CSS or a URL without checking it. Put owner
choices on `<html>` as attributes and check them against a list in PHP.

## Marking up sections for the preview

Print these only while `osc_customiser_preview()` is true:

```php
<section data-sec="<?php echo osc_esc_html($rowKey); ?>">
    <h2 data-field="title"><?php echo osc_esc_html($config['title']); ?></h2>
</section>
```

`data-sec` is the row id, or the draft key of an unsaved row. `data-field` is
the config field name. Also print the allowed values of every `live` attribute
on `<html>`, for example `data-osc-allow-data-brand="raspberry ocean"`.

## Messages

The admin screen and the preview talk with `postMessage`. Both sides check
`event.origin === location.origin`. Every message has `type: 'osc-customiser'`.

Core ships no preview-side script, only the admin side. The theme must write the
preview side: a script it enqueues only while `osc_customiser_preview()` is true.
That script must:

- listen for `message` events of type `osc-customiser` from its own origin;
- apply `set`, `text` and `select` as in the table below;
- send `{ ready: true }` to `window.parent` once loaded;
- on a click inside a `[data-sec]` element, stop the click and send
  `{ picked: <its data-sec> }`, so links do not navigate while editing;
- send `{ missed: true }` when a `text` message finds no matching node.

Aisle's `js/aisle-preview.js` is a working example.

Admin to preview:

| Message | Effect |
|---|---|
| `{ set: { attr: 'data-brand', value: 'ocean' } }` | Sets the attribute, only if the value is in the matching `data-osc-allow-*` list. A value of `''` or `'system'` removes the attribute instead. |
| `{ text: { section, field, value } }` | Sets `textContent` of `[data-sec=section] [data-field=field]`. |
| `{ select: section, name }` | Outlines the section. The optional `name` is the label shown on the outline; send the section's display name. |

Preview to admin:

| Message | When |
|---|---|
| `{ ready: true }` | The page has loaded. |
| `{ picked: section }` | The owner clicked a section. |
| `{ missed: true }` | A `text` message had no node to change. The admin reloads the preview after saving. |

Any other change (order, hide, add, remove, images) saves the draft and
reloads the preview.

## Page mode

Core builds a customiser for one Page builder page on any theme, with no theme support needed.
The screen is `?page=pages&action=blocks&id=N`, and access follows the Pages screen. It has one
location, `page.N`, which takes every registered type that fits it (`builder` and content types), and
no look fields.

- The draft is kept per page and admin under the key `page.N`, apart from the theme's draft, for
  seven days.
- **Publish** writes only `page.N` and clears the `widget` page cache. It does not run
  `customiser_published`, which stays theme-scoped.
- The draft can hold `page_text`, locale => HTML, posted as `page_text[<locale>]`. Only a site
  language is taken, as a string of at most 65,535 bytes, stored unpurified as the page form
  stores it. **Publish** writes those texts to `t_pages_description` (adding a missing locale row)
  in the same transaction as the blocks, then runs `edit_page` and purges the `page` cache. A
  live text change after the draft began makes the draft stale. In a page preview,
  `osc_static_page_text()` returns the drafted text.
- Pictures in rich text keep only the classes in `Sanitize::IMAGE_WRAP` (`osc-img-left`,
  `osc-img-right`, `osc-img-center`); the editor's wrap buttons write the class and its inline
  style. A theme that turns off core styles styles these classes itself.
- The preview is the page with `?osc_preview=1&osc_blocks=1`. `osc_customiser_preview_rows( 'page.N' )` returns
  the page draft, whatever the active theme.
- Rows of a `super_admin` type are locked for a moderator: they can be moved but not edited or
  removed, and the server ignores posted changes to them. Untyped legacy rows cannot be edited by
  any admin and their content is never rewritten; only a moderator cannot remove them.

Only when both `osc_preview=1` and `osc_blocks=1` are set, `osc_render_widget()` adds `data-osc-block="<ref>"` and `data-osc-block-name` to the
first element of each page block. A theme-mode preview has no `osc_blocks`, so it gets neither. `<ref>` is the row id, or the draft key of an unsaved row. A page
that renders blocks in that preview also loads core's `osclass/customiser-preview.js` and `.css`, so a theme writes
no preview script for pages. Print `data-osc-block` yourself only if you render a page location
without `osc_render_widget()`.

The frame script uses the messages below, with `data-osc-block` where Aisle uses `data-sec`:

| Direction | Message |
|---|---|
| Admin to preview | `{ select: ref, name }` outlines the block and scrolls it into view. `text` is answered with `{ missed: true }`. |
| Preview to admin | `{ ready: true }` on load, `{ picked: ref }` on a click. |

Clicks and form submits inside the preview are stopped. A theme may restyle the outline with
`--osc-preview-outline` and `--osc-preview-outline-text`.

## Helpers

`osc_customiser_spec()`, `osc_customiser_preview()`,
`osc_customiser_draft_value()`, `osc_customiser_preview_rows()`,
`osc_customiser_preview_url()`, `osc_customiser_preview_frame_assets()`. See [helpers](/docs/developers/helpers/).

## Hook

`customiser_published` ( `$theme` ) runs after a publish. Use it to rebuild
anything derived from the sections or look.
