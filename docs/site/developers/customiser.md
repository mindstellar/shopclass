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
  category-mode and rich text fields are not allowed.
- `swatches` are printed as a background colour only when they match
  `#rrggbb`. A `select` field with swatches is drawn as colour circles.
- `live` names fields the preview applies without a reload, as attributes on
  `<html>`.

If nothing usable is left, `osc_customiser_spec()` returns `null` and the
screen does not appear.

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
only for a signed-in administrator with a draft for the active theme. Otherwise
the parameter does nothing. In a preview:

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

Admin to preview:

| Message | Effect |
|---|---|
| `{ set: { attr: 'data-brand', value: 'ocean' } }` | Sets the attribute, only if the value is in the matching `data-osc-allow-*` list. |
| `{ text: { section, field, value } }` | Sets `textContent` of `[data-sec=section] [data-field=field]`. |
| `{ select: section }` | Outlines the section. |

Preview to admin:

| Message | When |
|---|---|
| `{ ready: true }` | The page has loaded. |
| `{ picked: section }` | The owner clicked a section. |

Any other change (order, hide, add, remove, images) saves the draft and
reloads the preview.

## Helpers

`osc_customiser_spec()`, `osc_customiser_preview()`,
`osc_customiser_draft_value()`, `osc_customiser_preview_rows()`,
`osc_customiser_preview_url()`. See [helpers](/docs/developers/helpers/).

## Hook

`customiser_published` ( `$theme` ) runs after a publish. Use it to rebuild
anything derived from the sections or look.
