---
title: Account pages
description: ShopClass renders every account and sign-in page itself when your theme does not, using a published set of class names you can restyle without shipping a line of PHP.
sidebar:
  order: 19
---

Thirteen views make up the account section — the dashboard, the seller's
listings, alerts, the profile form, the three settings pages, sign in, register,
the two password-reset steps, a member's public page, and the slot a plugin's
account page renders into.

A theme that ships all thirteen never sees any of this. A theme that ships none
of them used to produce **blank pages**: core asked for a file, found nothing,
and printed an empty document.

Core now has a fallback page for every one of them.

## What decides who renders

Per view, first hit wins:

1. **your theme ships the view** — your file renders, unchanged. Nothing below
   this runs.
2. **your parent theme ships it** — the parent's file renders, with the parent's
   asset URLs, exactly as it always has.
3. **you have [chrome](/developers/theme-chrome/)** — your header and footer, with
   core's page between them.
4. **otherwise** — core's own standalone page.

So adding a view to your theme takes the page back, at any time, with no
migration. Deleting one hands it to core. There is no registration step and
nothing to declare.

Step 3 is the interesting one: it is the same header, the same footer, the same
typography and the same widgets as the rest of your site, wrapped around markup
core owns. The whole of the theming job is CSS.

## The class vocabulary

Core's markup carries the classes below. **These names are a permanent contract**
— the same promise as the `osc_*` helpers and the admin's class names. They can
be restyled freely; they will not be renamed or removed.

Every rule core ships is scoped `.oe-page .name`, so match that specificity when
you override — a bare `.oe-list-item {}` loses to core's `.oe-page .oe-list-item {}`.

### Structure

| Class | Wraps | You may assume |
|---|---|---|
| `.oe-page` | everything core renders | the outermost element; present on every fallback page |
| `.oe-doc` | the page's column inside `.oe-page` | core bounds and pads it; neutralise both if your own spine already does |
| `.oe-h1` | the page heading | exactly one per page |
| `.oe-account` | an account page: content column then nav | two children, content **first** in source order |
| `.oe-account-main` | the content column of `.oe-account` | the page's own markup, nothing else |
| `.oe-account-nav` | the account section nav | a `<nav>` holding an `<h2>` and one `<ul>`; the current entry carries `aria-current="page"` |
| `.oe-form-page` | a page that is one form — sign in, register, reset | no nav beside it |

### Records

| Class | Wraps | You may assume |
|---|---|---|
| `.oe-list` | a list of records — listings, alerts | a `<ul>`; no bullets, no padding |
| `.oe-list-item` | one record | an `<li>`; holds a thumb, an `.oe-list-body` and an `.oe-price` |
| `.oe-list-body` | the middle column of a record | holds the `<h3>` title and `.oe-meta` |
| `.oe-meta` | a record's secondary line | date, status, category, row actions; wraps freely |
| `.oe-thumb` | a record's image | fixed 6/5 ratio; also on the placeholder |
| `.oe-thumb-empty` | the no-image placeholder | carries `.oe-thumb` too |
| `.oe-price` | a listing's price | one already-formatted string, currency included |
| `.oe-badge` | a status flag | always paired with a word, never colour alone; modifiers `paid` `pending` `failed` `cancelled` `refunded` |
| `.oe-pager` | the paging strip | core's paginator markup inside |
| `.oe-empty` | an empty state | replaces the list, never sits beside it |
| `.oe-panel` | a bordered block grouping related content | also used by the credits pages |
| `.oe-muted` | secondary prose | a paragraph, not a control |

### Forms

| Class | Wraps | You may assume |
|---|---|---|
| `.oe-field` | a label and its control | one control, or a country/region pair |
| `.oe-label` | the field's `<label>` | `for` always matches a real control id |
| `.oe-input` | a control core renders itself | absent on controls `UserForm` renders — see below |
| `.oe-hint` | help text under a field | bound with `aria-describedby` |
| `.oe-check` | a checkbox and its label on one line | the `<label>` wraps the control |
| `.oe-actions` | a form's buttons | the submit is first |
| `.oe-btn` | a button or a link acting as one | add `.oe-secondary` for the quiet one, `.oe-btn-danger` for the destructive one |
| `#error_list` | the register form's client-side errors | core's own validator fills it; **empty until it has one**, so style `:not(:empty)` |

### Notices

Core's flash messages keep the class names they have always had —
`flashmessage` and `flashmessage-{ok,error,warning,info}`. Style those; there is
no second name for the same thing.

They now carry `role="status"`, or `role="alert"` on an error, so the message
announces itself with no JavaScript. Core renders no dismiss control: the message
is dropped from the session as it is printed, so it never returns on the next
page.

If your header already calls `osc_show_flash_message()`, core's own call is a
no-op — whichever runs first prints the message, and there is no double render.

### Controls `UserForm` renders

`UserForm::name_text()` and its siblings emit a bare `<input>` with no class of
core's own, because their **names** are core's contract and a theme must not
hand-roll them. Reach them through the wrapper:

```css
.oe-page .oe-field :is(input, select, textarea) { /* yours */ }
```

Core's own defaults for those controls are declared inside `:where()`, which
gives them **zero specificity** — so any rule you write wins, including a bare
`input {}`. That is deliberate: inside your theme these should look like your
fields, not like core's.

## A worked example

The whole of what makes these pages look native to a theme:

```css
/* the account layout is the theme's existing two-column object */
.oe-page .oe-account { display: grid; grid-template-columns: 1fr 18rem; gap: 2rem 3.5rem; }
/* the nav is the theme's existing facet column */
.oe-page .oe-account-nav > h2 { text-transform: uppercase; letter-spacing: .07em; }
.oe-page .oe-account-nav [aria-current] { background: var(--tint); font-weight: 600; }
/* a listing row is the theme's existing record */
.oe-page .oe-list-item { display: grid; grid-template-columns: auto 1fr auto; }
/* core bounds its own column; the theme's spine already does */
.oe-page .oe-doc { max-inline-size: none; margin: 0; padding: 0; }
```

## Rendering one yourself

`osc_gui_account_view(string $view): bool` runs the resolution above for one view
name and returns `false` when core has no page for it:

```php
if (!osc_gui_account_view('user-login.php')) {
    // core owns no fallback for this one
}
```

Controllers call it; a plugin serving its own account route can too.

## What core's pages do not do

- **No JavaScript**, with one exception: the profile form calls
  `UserForm::location_javascript()` so the region list follows the country
  without a reload. The form works without it — choose a country, save, and the
  page comes back with that country's regions.
- **No assets.** One small stylesheet, printed inline once per request through
  the `header` hook. Nothing to enqueue, nothing to cache-bust.
- **No layout opinions you cannot undo.** Every rule is one class deep.

## Extending the account nav

The nav is built from the same `user_menu_filter` list `osc_private_user_menu()`
uses, and fires the `user_menu` hook, so a plugin that already adds an account
entry appears in it with no change. Core's own credits links arrive that way.

```php
osc_add_filter('user_menu_filter', function ($options) {
    $options[] = array(
        'name'  => __('My orders', 'my-plugin'),
        'url'   => osc_route_url('my-orders'),
        'class' => 'opt_my_orders',
    );

    return $options;
});
```

The `opt_logout` entry is always moved last, whatever the filter returns.

## Rendering your own page in the theme's chrome

The helper core uses for these pages is public, so a plugin can put its own page
inside the active theme without shipping a view for every theme in existence:

```php
osc_gui_view(
    'my-plugin-page.php',                  // the theme's own view, if it ships one
    PLUGINS_PATH . 'my-plugin/page.php',   // your markup, used when it does not
    array('heading' => __('My page'), 'title' => __('My page'))
);
```

Resolution is the same three steps core uses: the active theme's view if it has
one, otherwise your file inside the theme's chrome, otherwise core's own shell.
Your file is markup only — no `<html>`, no header, no footer.

## One page, three routes

`user-change_email.php`, `user-change_username.php` and `user-change_password.php` all
resolve to a single **Sign-in details** page. Each keeps its own form and its own POST
action, so nothing about the controllers changed; the route that was asked for gets
`autofocus` on its field, so an old link still lands where it used to.

A theme that ships any one of those three views still wins for that route, exactly as
before — the consolidation is core's fallback shape, not a rule imposed on themes.

Deleting an account stays on its own page. It is destructive and irreversible, and nothing
dangerous should sit a misclick away from changing an email address.
