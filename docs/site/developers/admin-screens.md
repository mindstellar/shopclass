---
title: Admin screens
description: "Lay out a ShopClass admin screen from the shared page, body, section, panel, table and form helpers, so it spaces, stacks and themes like every core screen."
sidebar:
  order: 34
---

Every core admin screen is built the same way, from the same few helpers. A screen of your
own can use them too. It then spaces itself, stacks on a phone and follows light and dark
mode like the rest of the admin, and keeps doing so when the admin theme changes.

The helpers live in `oc-includes/osclass/helpers/hAdminUi.php` and
`oc-admin/themes/modern/parts/ui.php`. Most take one options array. The table below lists
the ones that take positional arguments first. Each escapes every value it is handed. Only a
key ending in `_html` is passed through raw.

## The shape of a screen

Page, then body, then sections, then the content of each section.

```php
osc_admin_page(array(
    'section' => __('Acme'),
    'heading' => __('Offers'),
));
osc_current_admin_theme_path('parts/header.php');

osc_admin_body_open(array('id' => 'acme-offers'));

osc_admin_section(__('Open offers'));
osc_admin_table(
    array('name' => __('Offer'), 'price' => array('label' => __('Price'), 'numeric' => true)),
    array(array('name' => 'Spring sale', 'price' => '20')),
    array('empty' => array('title' => __('No offers yet')))
);
osc_admin_section_close();

osc_admin_section(__('Settings'));
osc_admin_form_open(array(/* page, action */));
// fields
osc_admin_form_close();
osc_admin_section_close();

osc_admin_body_close();
```

- **`osc_admin_page()`** declares the screen once at the top: its `heading` (the `<h1>`),
  `buttons`, `help` and the rest. Every screen declares a heading.
- **`osc_admin_body_open()` / `osc_admin_body_close()`** hold all the blocks of the screen
  as direct children. The body spaces them with a gap.
- **`osc_admin_section()` / `osc_admin_section_close()`** are a titled block. `'level' =>
  'subsection'` makes a smaller one, `'actions'` puts buttons at the end of the title row.
- Inside a section: `osc_admin_table()` or `osc_admin_data_table()` for rows,
  `osc_admin_form_open()` with `osc_admin_field()` for inputs, `osc_admin_action_section()`
  for a row of buttons.

## Body layouts

`osc_admin_body_open()` takes `'layout'`:

| Layout | Use it for |
|---|---|
| `stack` (default) | One column of blocks. |
| `split` | A record and a rail beside it. End the main column with `osc_admin_body_rail()`. On a phone the rail stacks under the record. |
| `grid` | Tiles, as on the dashboard and Statistics. |

`'id'`, `'class'` and `'attrs'` go on the body. Pass `'attrs'` raw: it is escaped for you.

## Which helper for which job

| Job | Helper | Signature |
|---|---|---|
| A card with a title | `osc_admin_panel_open()` / `osc_admin_panel_close()`. `'stack' => true` spaces its content by gap, `'count'` adds a count badge to the title. | `($title, $opts)` |
| A titled block | `osc_admin_section()` / `osc_admin_section_close()` | `($title, $opts)` |
| A table | `osc_admin_table()`. Options: `'caption'`, `'empty'`; per-cell `'colspan'` and `'th'`. | `($columns, $rows, $opts)` |
| A table with a bulk column, filters and paging | `osc_admin_data_table()` | `($aData, $opts)` |
| A row of filters or buttons above a table | `osc_admin_toolbar_open()` / `osc_admin_toolbar_close()` | `($opts)` |
| Tabs | `osc_admin_tab_nav()` | `($links, $class, $opts)` |
| A state next to a record | `osc_admin_status()` | `($state, $word, $title)` |
| A warning or a note | `osc_admin_callout()` | `($tone, $text, $opts)` |
| Nothing to show | `osc_admin_empty()` or a table's `'empty'` option | `($opts)` |
| Ask before deleting | `osc_admin_confirm_dialog()` | `($opts)` |

Do not write your own tabs, status pills, callouts, empty states or confirm dialogs. The
rules tests in core fail when a template does.

## Choosing between similar helpers

Some helpers look alike. Pick by what the content is.

| Job | Use | Not |
|---|---|---|
| A block of screen content with a heading | `osc_admin_section()` | |
| A card: a border and a title bar, such as a side box or a dashboard tile | `osc_admin_panel_open()` | `osc_admin_section()` |
| A heading and intro line between fields in one form | `osc_admin_form_section()`. It prints no wrapper and has no close. | `osc_admin_section()` |
| Any screen body | `osc_admin_body_open()` with `'layout'` `stack`, `split` or `grid` | |
| Editing one record, with a sticky rail for status and Save | `osc_admin_editor_open()` | `osc_admin_body_open()` with `split` |
| Paging under a list with DataTables-shaped `$aData` | `osc_admin_pagination()` | |
| Paging when you only have total, per-page size and page number | `osc_admin_pager()` | |
| One label and one control | `osc_admin_field()` | `osc_admin_form_row_open()` |
| A row holding your own markup | `osc_admin_form_row_open()` / `osc_admin_form_row_close()` | |
| One label over several controls | `osc_admin_field_row()` | |

No core screen calls `osc_admin_field_row()`, `osc_admin_secret()` or `osc_admin_page_titled()`. They stay for themes and plugins. `osc_admin_secret()` is `osc_admin_field()` with `'type' => 'secret'`.

## No icons, no margins

- **Icons** go on buttons, status marks, callouts and dialogs. Not on headings, intro or
  lead lines, empty states or dashboard tiles.
- **Spacing** comes from the gap of the body, section or stacked panel. Do not add `mt-*`,
  `mb-*`, `<hr>` or `<br>` between blocks. A block that needs space has the wrong parent.
- **A long form** ends in one right-aligned Save bar, sticky at the foot of the window.

## Plugin pages

A plugin page that does not open a body stays in plain block flow, as before: nothing about
it changes. Core's old classes (`.widget-box`, `.form-row`, `.render-title`, `.osc-panel`
and the rest) keep their look and their margins for those pages. Open a body to opt in to
gap spacing. Inside a body, those margins are zeroed so the gap is the only spacing.

See also [Admin editors](/docs/developers/admin-editors/) for add/edit screens and
[Settings pages](/docs/developers/settings-pages/) for screens core renders for you.
