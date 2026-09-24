---
title: Admin editors
description: Build an add/edit screen in the ShopClass admin from the same components the listing and page editors use — the two-column shell, the status panel, the pickers, the photo grid and the sticky save bar.
sidebar:
  order: 7
---

A settings page is a list of preferences. Core will
[declare and render the whole thing](/docs/developers/settings-pages/) for you. An
**editor** is different: one record, a main column of content, a rail of panels beside it
showing what state the record is in, and one Save at the foot.

Core's listing and page editors are built from a set of helpers, and those helpers are
public. Your plugin's own add/edit screen can use them too. It then looks like the rest
of the admin on every install, and keeps looking that way when the admin theme changes.

Everything here lives in `oc-includes/osclass/helpers/hAdminUi.php`. Each helper is
wrapped in `function_exists()`, takes one options array, and escapes every value it is
handed. Only a key ending in `_html` is passed through raw.

## The shell

Three calls bracket the screen:

```php
osc_admin_editor_open(array(
    'page'   => 'plugins',
    'action' => 'acme_offer_save',
    'fields' => array('id' => $offer['pk_i_id']),
    'id'     => 'acmeOfferForm',
    'errors' => $errors,
));

// … the main column …

osc_admin_editor_rail();

// … the rail's panels …

osc_admin_editor_close(array(
    array('label' => __('Save offer', 'acme'), 'type' => 'submit', 'variant' => 'primary'),
    array('label' => __('Back', 'acme'), 'url' => $listUrl, 'variant' => 'dim'),
));
```

`osc_admin_editor_open()` emits the `<form>`, its hidden route, the CSRF token, the error
summary and the main column. It takes everything
[`osc_admin_form_open()`](/docs/developers/settings-pages/) takes — `action`, `page`, `url`,
`method`, `fields`, `name`, `id`, `class`, `upload`, `csrf`. It always opens the form
unwrapped, because an editor stacks its labels above its controls. Plus:

| Key | What it does |
|---|---|
| `main_id`, `main_class` | Put on the main column, for a script that has to find it |
| `errors` | `name => message`. Non-empty draws the `#error_list` summary at the head of the form |
| `error_labels` | `name => label`, for the summary's links |
| `error_ids` | `name => control id`, where the id is not the name |

`osc_admin_editor_rail()` closes the main column and opens the rail. Options: `id`, `class`.
**Skip this call and you get one full-width column** — right for a screen with nothing to
say about state.

`osc_admin_editor_close($actions, $opts)` closes the form with the sticky save bar. It
counts what has changed since the page loaded. A second argument of
`array('dirty' => false)` makes it a plain action row. `null` in place of `$actions` emits
no row at all, for a screen whose buttons live elsewhere. Each action takes `label`, `url`
or `type`, `variant` (`primary`, `secondary`, `dim`, `danger`, `outline-danger`), `icon`,
`class`, `title` and `attrs`.

The grid is CSS grid, not Bootstrap columns. From 992px up, the rail is 20rem wide and
sticky. Below that there is one column, and **the rail comes first** — so a moderator on a
phone reaches the record's state without scrolling past its body.

## The status panel

The rail's first panel: what state the record is in, the facts behind it, and the actions
that change that state.

```php
osc_admin_publish_panel(array(
    'title'  => __('Status', 'acme'),
    'status' => array(array('active', __('Live', 'acme')), array('premium', __('Featured', 'acme'))),
    'rows'   => array(
        array('label' => __('Created', 'acme'), 'value' => $created),
        array('label' => __('Expires', 'acme'), 'value' => $expiresHtml, 'html' => true),
    ),
    'actions' => array(
        array('label' => __('Pause', 'acme'), 'url' => $url('pause')),
    ),
    'danger' => array(
        array('label' => __('Block', 'acme'), 'url' => $url('block'), 'variant' => 'outline-danger'),
    ),
));
```

| Key | What it does |
|---|---|
| `title` | Panel heading, "Status" by default |
| `title_actions` | Action specs in the panel's header |
| `class` | Extra classes on the panel |
| `status` | List of `array($state, $word)` — or `array('state' => …, 'word' => …)` |
| `rows` | `osc_admin_definition()` rows: `label`, `value`, `html`, `mono` |
| `body_html` | Markup between the rows and the actions |
| `actions` | Routine actions, as secondary buttons |
| `danger` | Destructive actions, below a rule and apart from the rest |

`$state` is a lower-case key that picks the pill's tint and glyph. Core defines `active`,
`premium`, `inactive`, `expired`, `blocked`, `spam`, `new`, `read`, `archived`, `disabled`
and `uninstalled`. **An unmapped state still renders**, in the neutral tint, so your own
word degrades instead of vanishing.

The panel enforces two rules for you:

- No Save lives inside it. A form has one primary, and it is the save bar.
- A destructive action uses `outline-danger`, which paints from the theme's own danger
  token. Bootstrap's `.btn-outline-danger` reads a colour nothing re-points per theme, and
  lands at 2.86:1 on a dark card.

## Fields

`osc_admin_field()` is the one field renderer, shared with declared settings pages. Three of
its keys matter most on an editor.

**`error`** puts the message under the control it belongs to, marks the control invalid, and
wires `aria-describedby`. On a translated field, it takes a map of locale code to message.

**`translate`** expands one field into one control per locale, under a tab strip.
**`translate_name`** says how one locale's posted name is spelled, with `%s` standing for
the locale code. Without it, the name is the field's name with the code appended — which is
what existing callers already get, so nothing changed under them.

`locales` carries the list, as `code => name`. It is required: the field never queries
anything to draw itself, so `translate` with no `locales` renders one plain control.

The strip opens on the admin's own language and marks that tab with a dot. If the field
does not offer that language, it opens on the first one.

```php
$locales = array();
foreach (osc_get_locales() as $locale) {
    $locales[$locale['pk_c_code']] = $locale['s_name'];
}

osc_admin_field(array(
    'type'           => 'text',
    'name'           => 'title',
    'translate'      => true,
    'translate_name' => 'title[%s]',        // posts title[en_US], title[hi_IN], …
    'locales'        => $locales,
    'value'          => array('en_US' => 'A title', 'hi_IN' => ''),
    'label'          => __('Title', 'acme'),
    'layout'         => 'stacked',
    'required'       => true,
    'error'          => array('en_US' => __('Give the offer a title.', 'acme')),
));
```

A tab whose panel holds an error is marked, so the error is findable without opening every
locale in turn.

**`'type' => 'richtext'`** renders a textarea. One shared script turns it into the admin's
rich-text editor after load, already following the light/dark toggle. Write no
`tinymce.init` of your own, and select no editors by a name pattern.

```php
osc_admin_field(array(
    'type'   => 'richtext',
    'name'   => 'terms',
    'label'  => __('Terms', 'acme'),
    'layout' => 'stacked',
    'value'  => $offer['s_terms'],
    'preset' => 'basic',     // basic | full
    'height' => 320,
    'media'  => false,       // true adds the media library picker
));
```

Declared settings pages accept `richtext` too, so a plugin page gets it inside core's own
save path.

## The pickers

Each renders a composite control and posts the same plain names a hand-written form would.

**`osc_admin_category_picker()`** — a control showing the chosen path, and a searchable list
of the whole tree behind it. Keys: `name` (default `catId`), `id`, `value`, `label`,
`required`, `help`, `error`, `categories` (rows carrying `pk_i_id`, `fk_i_parent_id` and
`s_name`; the enabled tree by default). Picking a category fires `change` on the hidden
field, so a script of yours can listen for it.

**`osc_admin_location_picker()`** — the country select, region and city inputs with their
hidden ids, and the rest of the address behind a disclosure. Keys: `value` and `errors`
keyed by `countryId`, `region`, `regionId`, `city`, `cityId`, `cityArea`, `zip`, `address`;
`names` to post any of them under another name; `countries`; `detail` (`disclosure` by
default, or `inline` or `none`); `label_*` per label. Every control keeps the id core's
location autocomplete already binds to, so the suggestions work with no script of your own.

**`osc_admin_user_picker()`** — the card when a registered user matches, and a search that
fills the named fields when one is picked. Keys: `user` (an array with `name`, `email` and
`url`; `null` for no match), `id`, `label`, `placeholder`, `help`, `source` (the
autocomplete endpoint), `fields` (what a pick fills, as key => the posted name).

## The photo grid

`osc_admin_photo_grid()` draws a record's photos as tiles: the ones it has, the ones staged
ahead of the save, a drop target that uploads, and the count against the ceiling.

```php
osc_admin_photo_grid(array(
    'name'       => 'photos',
    'label'      => __('Photos', 'acme'),
    'resources'  => $resources,
    'max'        => osc_max_images_per_item(),
    'upload_url' => $uploadUrl,
    'delete_url' => $deleteUrl,
    'secret'     => $secret,
    'cover'      => true,
));
```

Keys: `name` (default `photos`), `id`, `label`, `resources` (rows carrying `pk_i_id`,
`fk_i_item_id`, `s_name`, `s_path`, `s_extension`), `staged` (file names in
`uploads/temp/`), `max` (0 for no ceiling), `max_size` in bytes, `extensions`, `upload_url`,
`delete_url`, `temp_url`, `secret`, `cover`.

The file input keeps its posted name, so a browser with no JavaScript still uploads on
submit. **The first tile is the cover.** `cover => true` offers the control that moves a
tile to the front. It works only while every tile is still staged, because the save
attaches photos in the order it is handed them and stores no order afterwards. On a record
that already has photos, the first one uploaded stays the cover.

## The disclosure

A `<details>` element for the settings a screen has to offer that nobody changes twice a
year. It works with no JavaScript, the browser's in-page search finds inside it, and it
opens itself when a field inside it carries an error.

```php
osc_admin_disclosure_open(__('Advanced', 'acme'), array('summary_hint' => __('Internal name', 'acme')));
osc_admin_field(array('type' => 'text', 'name' => 'slug', 'label' => __('Slug', 'acme'), 'layout' => 'stacked'));
osc_admin_disclosure_close();
```

Options: `open` (true to start expanded), `id`, `class`, `summary_hint` (a muted line beside
the title naming what is inside).

## A panel of your own

The status panel is one shape of rail panel. For any other shape,
`osc_admin_panel_open($title, $opts)` and `osc_admin_panel_close()` bracket a `.widget-box`
you fill yourself: fields, a definition list via `osc_admin_definition($rows)`, a pill via
`osc_admin_status($state, $word)`, whatever the record needs.

## Errors

`osc_admin_editor_open()`'s `errors` map does two jobs at once. It draws the summary at the
head of the form, and it is where the per-field messages come from. Pass the same map to
your fields' `error` keys, and a rejected save reads the same message in both places.

```php
$errors = array('title' => __('Give the offer a title.', 'acme'));

osc_admin_editor_open(array(
    'page'         => 'plugins',
    'action'       => 'acme_offer_save',
    'errors'       => $errors,
    'error_labels' => array('title' => __('Title', 'acme')),
));
```

A screen that draws its own `<form>` can call `osc_admin_error_summary($errors, $opts)`
directly.

Re-render with what was typed, not with what is stored. A rejected save that comes back
empty is the screen throwing away the person's work.

## A worked example

One file, an add/edit screen for a plugin's own record type, with a rail.

```php
<?php
/**
 * Acme Offers — the add/edit screen. Rendered by the plugin's own controller action.
 *
 * @var array $offer   The record being edited, or an empty array when adding
 * @var array $errors  name => message, empty on a first draw
 */

$new     = empty($offer['pk_i_id']);
$listUrl = osc_admin_base_url(true) . '?page=plugins&action=renderplugin&file=acme-offers/list.php';
$locales = array();
foreach (osc_get_locales() as $locale) {
    $locales[$locale['pk_c_code']] = $locale['s_name'];
}

$action  = static function ($what) use ($offer) {
    return osc_admin_base_url(true) . '?page=plugins&action=renderplugin'
        . '&file=acme-offers/state.php&id=' . (int)$offer['pk_i_id'] . '&to=' . $what;
};

osc_admin_page_head(
    $new ? __('Add offer', 'acme') : __('Edit offer', 'acme'),
    array(array('label' => __('Back to offers', 'acme'), 'url' => $listUrl, 'variant' => 'dim'))
);

osc_admin_editor_open(array(
    'page'         => 'plugins',
    'action'       => 'renderplugin',
    'fields'       => array(
        'file' => 'acme-offers/save.php',
        'id'   => $new ? '' : $offer['pk_i_id'],
    ),
    'id'           => 'acmeOfferForm',
    'errors'       => $errors,
    'error_labels' => array(
        'title' => __('Title', 'acme'),
        'catId' => __('Category', 'acme'),
    ),
));

osc_admin_field(array(
    'type'           => 'text',
    'name'           => 'title',
    'translate'      => true,
    'translate_name' => 'title[%s]',
    'locales'        => $locales,
    'value'          => $offer['title'] ?? array(),
    'label'          => __('Title', 'acme'),
    'layout'         => 'stacked',
    'required'       => true,
    'class'          => 'osc-editor-title',
    'error'          => $errors['title'] ?? null,
));

echo '<div class="osc-editor-cols">';

osc_admin_category_picker(array(
    'name'     => 'catId',
    'value'    => $offer['fk_i_category_id'] ?? '',
    'label'    => __('Category', 'acme'),
    'required' => true,
    'error'    => $errors['catId'] ?? null,
));

osc_admin_field(array(
    'type'   => 'number',
    'name'   => 'price',
    'label'  => __('Price', 'acme'),
    'layout' => 'stacked',
    'value'  => $offer['i_price'] ?? '',
    'attrs'  => array('min' => '0', 'step' => '1'),
));

echo '</div>';

osc_admin_field(array(
    'type'   => 'richtext',
    'name'   => 'terms',
    'label'  => __('Terms', 'acme'),
    'layout' => 'stacked',
    'value'  => $offer['s_terms'] ?? '',
    'preset' => 'basic',
    'height' => 320,
));

osc_admin_editor_rail();

osc_admin_publish_panel(array(
    'title'  => __('Status', 'acme'),
    'status' => $new
        ? array(array('new', __('Not saved yet', 'acme')))
        : array(array($offer['b_active'] ? 'active' : 'inactive',
                      $offer['b_active'] ? __('Live', 'acme') : __('Paused', 'acme'))),
    'rows'   => $new ? array() : array(
        array('label' => __('Created', 'acme'), 'value' => osc_format_date($offer['dt_pub_date'])),
    ),
    'actions' => $new ? array() : array(
        array('label' => $offer['b_active'] ? __('Pause', 'acme') : __('Resume', 'acme'),
              'url'   => $action($offer['b_active'] ? 'paused' : 'live')),
    ),
    'danger'  => $new ? array() : array(
        array('label'   => __('Delete offer', 'acme'),
              'url'     => $action('deleted'),
              'variant' => 'outline-danger'),
    ),
));

osc_admin_panel_open(__('Placement', 'acme'));

osc_admin_location_picker(array(
    'value'  => $offer['location'] ?? array(),
    'detail' => 'disclosure',
));

osc_admin_disclosure_open(__('Advanced', 'acme'), array('summary_hint' => __('Internal name', 'acme')));
osc_admin_field(array(
    'type'   => 'text',
    'name'   => 'internal_name',
    'label'  => __('Internal name', 'acme'),
    'layout' => 'stacked',
    'value'  => $offer['s_internal_name'] ?? '',
    'help'   => __('Only ever shown in the admin.', 'acme'),
));
osc_admin_disclosure_close();

osc_admin_panel_close();

osc_admin_editor_close(array(
    array('label' => $new ? __('Add offer', 'acme') : __('Save offer', 'acme'),
          'type'  => 'submit', 'variant' => 'primary'),
    array('label' => __('Back to offers', 'acme'), 'url' => $listUrl, 'variant' => 'dim'),
));
```

The save side is yours. Read the posted names with `Params::getParamInt()` /
`getParamString()`, check `osc_csrf_check()`, and build `$errors` for a re-render.
`Params::getParam()` returns raw request data — it is not sanitisation.

## Hooking core's editors

A plugin that renders into the listing or page editor — on `item_form`, `item_edit`,
`page_meta` — draws below the core fields, inside the same main column. Nothing about
those hooks or their arguments changed. Draw your fields with `osc_admin_field()` and they
match what is above them. Hand-written markup keeps working as it always did.

One thing that will catch your eye: inside the plugin-field panel, a value's right edge
sits in from core's fields above it. That is deliberate and long-standing. Leave it alone —
it is not your markup misbehaving.

## Class names you can target

These are published and additive. Restyle them freely — they will not be renamed.

`osc-editor`, `osc-editor-main`, `osc-editor-side`, `osc-editor-cols`, `osc-editor-actions`,
`osc-editor-title`, `osc-field`, `osc-field-required`, `osc-publish`, `osc-publish-status`,
`osc-publish-actions`, `osc-publish-danger`, `osc-publish-expiry`, `osc-catpick`,
`osc-catpick-value`, `osc-catpick-path`, `osc-photo-grid`, `osc-photo`, `osc-photo-cover`,
`osc-photo-remove`, `osc-photo-add`, `osc-photo-count`, `osc-disclosure`,
`osc-disclosure-hint`, `osc-disclosure-body`, `osc-user-card`, `field-error`, and the
`data-osc-tab-error` and `data-osc-tab-mine` attributes on a locale tab.

On a list screen of your own, add `osc-table-stack` to the element around a table. On a phone
each row then becomes a card, and each cell is labelled from its `data-col-name` attribute.

The rich-text field is the exception: it mounts a third-party editor, whose own markup is
not a contract. Style it through `.osc-field` around it, not the editor's internals.

An admin theme can replace any of these helpers by defining the function in its own
`functions.php`, which loads before core's. That is the same `function_exists()` contract
every `osc_admin_*` helper has.

## What these replace

:::caution[Deprecated since 6.4.0]
Three form methods are the old way to draw these parts of an admin editor. **None of them
is removed and none will be**: third-party themes and plugins still call them, and the front
end still uses some of them. Use the replacements in new admin code.
:::

| Deprecated | Use instead |
|---|---|
| `mindstellar\form\admin\Item::printMultiLangTitleDesc()` and `PageForm::printMultiLangTitleDesc()` | `osc_admin_field()` with `translate` and `translate_name`, plus `'type' => 'richtext'` for the body |
| `ItemForm::category_multiple_selects()` *(admin use)* | `osc_admin_category_picker()`. The cascading selects stay for front-end themes |
| `ItemForm::photos_javascript()` | `osc_admin_photo_grid()`, which brings its own script. `ItemForm::photos()` stays for themes |

## Related

- [Settings pages](/docs/developers/settings-pages/) — when the screen is a list of
  preferences rather than one record
- [Administrator menus](/docs/developers/admin-menus/) — getting your screen into the menu
- [Scripts and styles](/docs/developers/scripts-and-styles/) — loading a script of your own
  alongside these
