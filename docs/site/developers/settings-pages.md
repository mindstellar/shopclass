---
title: Settings pages
description: Declare an admin settings page in ShopClass and core renders it, validates it, checks CSRF and capability, saves it and reports the result — no controller of your own.
sidebar:
  order: 6
---

A settings page used to mean writing the whole thing: a view, a `<form>`, a
controller action, a CSRF check, a capability check, a `Params::getParam()` per
field, validation, `osc_set_preference()` per field, a flash message and a
redirect. Every plugin wrote its own, and every one of them was a place to get
the CSRF check or the escaping wrong.

Declare the page instead. Core owns all of it.

```php
osc_register_settings_page('acme.delivery', array(
    'title'  => __('Delivery', 'acme'),
    'menu'   => 'plugins',
    'fields' => array(
        array('type' => 'checkbox', 'name' => 'enabled', 'label' => __('Offer delivery', 'acme')),
        array(
            'type'     => 'number',
            'name'     => 'radius_km',
            'label'    => __('Radius (km)', 'acme'),
            'default'  => 10,
            'depends'  => 'enabled',
            'required' => true,
        ),
    ),
));
```

Register it while your plugin loads. A theme registers from `functions.php` on
the `init` hook, once translations are ready; its menu entry is added then.

That is the whole page. It appears under **Plugins**, renders with the admin's
own field markup, refuses a POST without a valid CSRF token, hides and
un-requires *Radius* while *Offer delivery* is off, saves to `t_preference`
under the section `acme.delivery`, and redirects with a flash message.

For a complete plugin built this way, see the bundled Test Payments plugin in
[Payment gateways](/docs/developers/payment-gateways/#declaring-its-settings-page).

Read the values back anywhere:

```php
if (osc_settings_value('acme.delivery', 'enabled')) {
    $radius = (int) osc_settings_value('acme.delivery', 'radius_km');
}
```

`osc_settings_value()` needs the page registered in that request, since defaults
come from the declaration. A page registered only in the admin reads its values
on the front end with `osc_get_preference($name, $pageId)` instead.

## The builder

The array form above and the builder describe the same page — use whichever
reads better. The builder is worth it once a page has more than a handful of
fields, because each field's options sit on the field rather than in a nested
array:

```php
use mindstellar\admin\ui\FormSpec;

(new FormSpec('acme.delivery'))
    ->title(__('Delivery', 'acme'))
    ->menu('plugins')
    ->checkbox('enabled', __('Offer delivery', 'acme'))
    ->number('radius_km', __('Radius (km)', 'acme'))
        ->default(10)
        ->dependsOn('enabled')
        ->required()
    ->register();
```

`menu` is one of `settings`, `plugins`, `appearance`, `tools`, `items`,
`users`, `pages`, `stats`. Pass `''` for a page with no menu entry, reached
from a link you put somewhere else — `osc_settings_page_url('acme.delivery')`
gives you its URL.

## Field types

`text`, `email`, `url`, `tel`, `number`, `color`, `secret`, `textarea`,
`select`, `radio`, `checkbox`, `hidden`, `image` (see [Images](#images)), and
`custom` for markup core does not own.

Keys every field takes:

| Key | What it does |
|---|---|
| `default` | Value used until something is saved |
| `required` | Rejected as empty on save |
| `sanitize` | `callable(mixed $value): mixed`, run before validation |
| `validate` | `callable(mixed $value, array $field): ?string` — the error, or null |
| `depends` | Another field on this page. While that field is off, this one is hidden, is not required, and its posted value is **discarded** |
| `depends_value` | With `depends`: a string or list of strings. The field is on while the master's value is one of them. Only for a select or radio master, and each must be one of its option keys |
| `translate` | `text`/`textarea` only: one control per enabled locale |
| `purify` | `false` to store markup as submitted (see below) |
| `column` | The key to store under, when it is not the field's own name |
| `persist` | `false` to store nowhere, or a callable returning what the key takes |
| `write_only` | The control never shows what is stored |

`depends` is decided again on the server. The browser hides the row as a
convenience; the save discards the value regardless of what was posted, so a
hand-crafted request cannot set a field the form never showed.

To follow one value of a select or radio, pass it as the second argument:

```php
->select('wm_type', __('Watermark', 'acme'), array('none' => __('None', 'acme'), 'text' => __('Text', 'acme')))
->text('wm_text', __('Watermark text', 'acme'))->dependsOn('wm_type', 'text')
```

## Text is stripped of tags

`text`, `textarea`, `tel`, `color` and `hidden` have every tag removed on save,
which is what a hand-written screen reading the same field through
`Params::getParam()` has always stored. Declare `'purify' => false` for a field
that holds markup or code on purpose.

This governs what is **stripped**, not what is **escaped** — print a stored
value through `osc_esc_html()` or `osc_esc_js()` as you always would.

A `secret` must say whether it is one the admin can read back (an API key) or
one they must never see again (a password). The type does not say which, so
`write_only` is required on it and registration fails without it.

## Images

An `image` field uploads a picture, such as a logo:

```php
(new FormSpec('folio'))
    ->title(__('Folio', 'folio'))
    ->menu('appearance')
    ->image('logo', __('Logo', 'folio'))
    ->register();
```

Read its URL back anywhere, front end included:

```php
$logo = osc_settings_image_url('folio', 'logo');              // '' when none is stored
$thumb = osc_settings_image_url('folio', 'logo', 'thumbnail'); // or 'preview'
```

The page shows the current image, a file picker and a **Remove image** box, and
posts multipart on its own. The file goes through the same image pipeline as
listing photos: it must be a real image, it is scaled down to fit (never padded
or enlarged), and it is offloaded when remote storage is on. What is stored is the image's resource id.

- A new upload replaces the stored image, and the old one is deleted once the
  new id is saved. Saving without a file keeps the stored image.
- A file that is not an image, or is too large, refuses the save with a field
  error, and the stored image stays. The limit is the site's maximum upload size,
  or `max_kb` on the field: `->image('logo', $label)->set('max_kb', 512)`.
- `required` is met by an image already stored.
- While a `depends` master is off, the posted file and the remove box are
  ignored and the stored image is kept.
- Uploads and deletes run only for a signed-in admin allowed on the page.
- An image field cannot take `default`, `sanitize`, `validate`, `persist` or
  `write_only`, cannot be another field's `depends` master, and needs a
  preference page, not a table store.

`osc_settings_image_url()` works even when the page is not registered in the
current request. It then reads the preference `logo` in the section `folio`, so
a page that sets its own `section` or `column` must be registered to be read.

## Storing in a table instead

A page can write one row of a table rather than one preference per field:

```php
(new FormSpec('acme.route'))
    ->title(__('Route', 'acme'))
    ->store('acme_route', 'pk_i_id')   // unprefixed; core applies DB_TABLE_PREFIX
    ->text('s_label', __('Label', 'acme'))
    ->register();
```

The row is addressed by an integer key **supplied by your controller**, never
taken from the request — no key inserts a row, and a key that is not a positive
integer is refused. That is why the generic controller does not serve a
table-backed page: it needs a controller of yours that supplies a row id it has
already checked this admin may edit.

## Reacting to a save

Four hooks, in the order they run:

| Hook | Kind | When |
|---|---|---|
| `admin_form_before_save` | filter | After validation, before the write. Return the values to store |
| `admin_form_after_save` | action | After a successful write, with the row id |
| `settings_page_saved` | action | After that, for listeners that only care that it saved |
| `admin_form_save_failed` | action | Instead of the above, when the save was refused |

Every one of them is handed the values with `secret` fields **removed**, so a
listener on someone else's page cannot read a password out of a payload it did
not ask for.

For an effect that belongs to one page rather than to anyone listening, declare
it on the page instead. It runs once after a successful save, never after a
refused one, and last — after `admin_form_after_save`, so it sees whatever a
listener made of the values:

```php
->onAfterSave(static function (array $values, $id) {
    Acme\Cache::flush();
})
```

This one is your own page's code rather than an arbitrary listener, so it is
handed the values as stored, `secret` fields included.

## What the admin sees

The action row counts what has changed since the page loaded: quiet on a form
nobody has touched, and "3 unsaved changes" on one somebody has. It follows the
page as you scroll, so Save stays reachable on a long screen.

A save that changes nothing reports exactly that rather than claiming success —
the store writes only the values that actually differ.

## The hand-rolled path (deprecated)

:::caution[Deprecated since 6.3.0]
Hand-writing a settings screen — your own `<form>`, controller action, CSRF check
and save block — is deprecated. Declare the page instead. The old way keeps
working and will not be removed: the `osc_*` helpers and admin class names it
uses stay a public API.
:::

One example already in core: Listings → Locations is hand-rolled, and offers
two hooks so a plugin can extend it without owning the page:

| Hook | Kind | When |
|---|---|---|
| `admin_locations_row_actions` | filter | Building a row's actions cell. Receives `$actions` (array keyed by name, starting with `edit`), `$level` (`country`, `region` or `city`) and `$row` (that row's data). Return the array with your entry added; each value is raw, already-escaped HTML |
| `admin_locations_drawer_fields` | action | Rendering the add/edit drawer, after the built-in fields. Receives `$level` and `$record` (`null` when adding, the row's data when editing) |

A declaration gives you the CSRF check, the capability check, the escaping, the
`depends` handling and the redirect, written once in core. Move an existing
screen when you next touch it; the Test Payments plugin in
[Payment gateways](/docs/developers/payment-gateways/) shows the result.

### When you cannot declare the page

Some screens are not a settings form at all — a list with its own actions, a
dialog, a panel inside another page. Do not hand-write the markup for those
either. Core exposes the same field renderers the declared path uses, so your
screen looks like the rest of the admin and keeps doing so when the admin theme
changes.

```php
osc_admin_form_open(array('page' => 'plugins', 'action' => 'my_save'));

osc_admin_form_section(__('Delivery'));

osc_admin_text(array(
    'name'  => 'sender_name',
    'label' => __('Sender name'),
    'value' => $current,
    'help'  => __('Shown on every outgoing message.'),
));

osc_admin_checkbox(array(
    'name'    => 'notify',
    'label'   => __('Email me on each order'),
    'checked' => $notify,
));

osc_admin_form_close(array(
    array('label' => __('Save'), 'type' => 'submit', 'variant' => 'primary'),
));
```

`osc_admin_form_open()` writes the CSRF token for you. A GET form never gets one.

| Function | What it does |
|---|---|
| `osc_admin_field($spec)` | One labelled field. Everything below is sugar over it, so this is the only name you must depend on. |
| `osc_admin_text()` · `osc_admin_number()` · `osc_admin_select()` · `osc_admin_textarea()` · `osc_admin_checkbox()` · `osc_admin_radio_group()` · `osc_admin_secret()` | One field of that type. |
| `osc_admin_tree_picker()` | The category / location picker. |
| `osc_admin_form_open()` · `osc_admin_form_close()` | The form element, the hidden route, the CSRF token, and the submit row. |
| `osc_admin_form_section($title)` | A titled group of fields. |
| `osc_admin_form_row_open($label)` · `osc_admin_form_row_close()` | One labelled row holding several controls. |
| `osc_admin_page_head($title, $actions)` | The screen title with its action buttons. |
| `osc_admin_action_section()` | An intro, a status block, and buttons — no form. |

`osc_admin_field()` takes `type`, `name`, `label`, `value`, `help`, `options`,
`prefix`, `suffix`, `width`, `required`, `disabled`, `id` and `attrs`. Pass
`'row' => false` for the control on its own, and `'type' => 'custom'` with a
`render` callable to put your own markup inside a normal row.
