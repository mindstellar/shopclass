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

Register it while your plugin loads. Core builds the menu entry on
`admin_menu_init`, which runs after plugins have loaded, so anything declared
during load is in place by then.

That is the whole page. It appears under **Plugins**, renders with the admin's
own field markup, refuses a POST without a valid CSRF token, hides and
un-requires *Radius* while *Offer delivery* is off, saves to `t_preference`
under the section `acme.delivery`, and redirects with a flash message.

Read the values back anywhere:

```php
if (osc_settings_value('acme.delivery', 'enabled')) {
    $radius = (int) osc_settings_value('acme.delivery', 'radius_km');
}
```

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
`select`, `radio`, `checkbox`, `hidden`, and `custom` for markup core does not
own.

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

## The hand-rolled path

Writing your own view, controller action and save block still works and is not
going away — the `osc_*` helpers it uses are a public API.

It is no longer the recommended way to build a settings screen. Everything it
gets you, a declaration gets you with the CSRF check, the capability check, the
escaping, the `depends` handling and the redirect written once in core instead
of once per plugin. New screens should be declared; existing ones are worth
moving when you next touch them.
