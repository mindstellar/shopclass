---
title: Account pages
description: ShopClass renders every account and sign-in page itself when your theme does not, using a published set of class names you can restyle without shipping a line of PHP.
sidebar:
  order: 22
---

Sixteen views make up the account section: the dashboard, the seller's
listings, alerts, the inbox, a conversation, the profile form, the three settings pages, sign in, register,
the two password-reset steps, a member's public page, the slot a plugin's
account page renders into, and API access.

A theme that ships all sixteen never sees any of this. A theme that ships none
of them used to produce **blank pages**: core asked for a file, found nothing,
and printed an empty document.

Core now has a fallback page for every one of them.

## What decides who renders

Per view, first hit wins:

1. **your theme ships the view**: your file renders, unchanged. Nothing below
   this runs.
2. **your parent theme ships it**: the parent's file renders, with the parent's
   asset URLs, exactly as it always has.
3. **you have [chrome](/docs/developers/theme-chrome/)**: your header and footer, with
   core's page between them.
4. **otherwise**: core's own standalone page.

So adding a view to your theme takes the page back, at any time, with no
migration. Deleting one hands it to core. There is no registration step and
nothing to declare.

Step 3 is the interesting one: it is the same header, the same footer, the same
typography and the same widgets as the rest of your site, wrapped around markup
core owns. The whole theming job is CSS.

## The class vocabulary

Core's markup carries the classes below. **These names are a permanent contract**,
the same promise as the `osc_*` helpers and the admin's class names. You can
restyle them freely; they will not be renamed or removed.

Every rule core ships is scoped `.oe-page .name`, so match that specificity when
you override. A bare `.oe-list-item {}` loses to core's `.oe-page .oe-list-item {}`.

### Structure

| Class | Wraps | You may assume |
|---|---|---|
| `.oe-page` | everything core renders | the outermost element; present on every fallback page |
| `.oe-doc` | the page's column inside `.oe-page` | core bounds and pads it; neutralise both if your own spine already does |
| `.oe-h1` | the page heading | exactly one per page |
| `.oe-account` | an account page: content column then nav | two children, content **first** in source order |
| `.oe-account-main` | the content column of `.oe-account` | the page's own markup, nothing else |
| `.oe-account-nav` | the account section nav | a `<nav>` holding an `<h2>` and one `<ul>`; the current entry carries `aria-current="page"` |
| `.oe-form-page` | a page that is one form: sign in, register, reset | no nav beside it |
| `.oe-contact` | the contact page | holds `.oe-contact-main` and, when filled, `.oe-contact-aside` |

### Records

| Class | Wraps | You may assume |
|---|---|---|
| `.oe-list` | a list of records: listings, alerts | a `<ul>`; no bullets, no padding |
| `.oe-list-item` | one record | an `<li>`; holds a thumb, an `.oe-list-body` and an `.oe-price` |
| `.oe-list-body` | the middle column of a record | holds the `<h3>` title and `.oe-meta` |
| `.oe-meta` | a record's secondary line | date, status, category, row actions; wraps freely |
| `.oe-dashboard-intro` | the dashboard's "Signed in as" line | a `<p>` |
| `.oe-dashboard-actions` | the dashboard's two buttons under it | also carries `.oe-actions` |
| `.oe-dashboard-title` | the dashboard's "Your latest listings" heading ("Your recent orders" for a shopper in shop mode) | an `<h2>` |
| `.oe-dashboard-more` | the dashboard's "See all" link line | also carries `.oe-muted` |
| `.oe-bill` | a shopper's order table on the dashboard, the same table as the orders page | holds one `.oe-scroll` and its `<table>` |
| `.oe-row-actions` | a record's action links | also carries `.oe-meta`; `.oe-danger-link` marks the destructive one |
| `.oe-row-promote` | the paid-upgrades line under a listing | also carries `.oe-row-actions` |
| `.oe-link-btn` | a button that looks like a link | inside an `.oe-inline-form` |
| `.oe-inline-form` | a one-button form in a line of links | `display: inline` |
| `.oe-tabs` | a status filter over a list | a `<nav>` of links; the current one carries `aria-current="page"` |
| `.oe-thumb` | a record's image | fixed 6/5 ratio; also on the placeholder |
| `.oe-thumb-empty` | the no-image placeholder | carries `.oe-thumb` too |
| `.oe-price` | a listing's price | one already-formatted string, currency included |
| `.oe-badge` | a status flag | always paired with a word, never colour alone; modifiers `paid` `pending` `failed` `cancelled` `refunded` |
| `.oe-threads` | the inbox's list of conversations | a `<ul>`; no bullets, no padding |
| `.oe-thread` | one conversation in the inbox | an `<li>` that also carries `.oe-panel`; holds `.oe-thread-title` and an `.oe-meta` line |
| `.oe-thread-title` | a conversation's listing title | a heading; a link to the thread in the inbox |
| `.oe-thread-last` | the date of a conversation's last message | a `<span>` in the `.oe-meta` line |
| `.oe-unread` | the unread count on a conversation | also carries `.oe-badge`; always paired with a word |
| `.oe-messages` | the messages of one conversation, oldest first | an `<ol>` carrying the `data-osc-messages` attributes the thread script reads |
| `.oe-message` | one message | an `<li>`; `.is-mine` for the viewer's own (right), `.is-held` for one waiting for review |
| `.oe-message-body` | a message's text | plain text, line breaks kept with `white-space: pre-wrap` |
| `.oe-message-meta` | who wrote a message and when | a `<p>` holding the name, a `<time>` and, for a held message, a badge |
| `.oe-compose` | the reply form under a conversation | a `<form>` with one `textarea name="body"` and a send button |
| `.oe-push` | the "Notify me on this device" switch on the messages pages, shown only while web push is on | a `<form hidden>` that `push.js` reveals once the browser supports push; holds one button and a note line |
| `.oe-msg-link` | the header link to the inbox from `osc_messaging_link()` | an `<a>` outside `.oe-page`, so core ships no style for it; holds the theme's icon, an `.oe-msg-label` and an `.oe-unread` badge |
| `.oe-msg-label` | the word "Messages" in `.oe-msg-link` | hide it visually for an icon-only link; the link keeps its `aria-label` |
| `.oe-thread-actions` | the Block, Unblock and Report links under a conversation | holds the shared row actions and a `<details>` for the report form |
| `.oe-pager` | the paging strip | core's paginator markup inside |
| `.oe-empty` | an empty state | replaces the list, never sits beside it |
| `.oe-panel` | a bordered block grouping related content | also used by the credits pages |
| `.oe-muted` | secondary prose | a paragraph, not a control |
| `.oe-address` | one saved address on the addresses page | also carries `.oe-panel`; holds an `<h2>` (with an `.oe-badge` on the default), `.oe-address-lines` and the row actions |
| `.oe-address-lines` | a saved address on one line | a `<p>` |

### Forms

| Class | Wraps | You may assume |
|---|---|---|
| `.oe-field` | a label and its control | one control, or a country/region pair |
| `.oe-label` | the field's `<label>` | `for` always matches a real control id |
| `.oe-input` | a control core renders itself | absent on controls `UserForm` renders (see below) |
| `.oe-hint` | help text under a field | bound with `aria-describedby` |
| `.oe-field-error` | why a field was refused | also carries `.oe-hint`; the control has `aria-invalid` |
| `.oe-profile-head` | the top of a public profile | holds the `.oe-avatar`, an `.oe-meta` line, and the owner's Edit button |
| `.oe-dialog-close` | a dialog's close button | carries `data-osc-dialog-close` |
| `.oe-contact-open` | the profile head's Message button | a link to `#oe-contact-dialog` |
| `.oe-form-error` | why a contact form's send failed, inside the form | `role="alert"`; the same text is also a flash message |
| `.oe-dialog` | a `<dialog>` core opens as a modal | holds `.oe-dialog-head` and `.oe-dialog-body`; shows in place without JavaScript |
| `.oe-group` | a titled group of form fields | a `<fieldset>` with a `<legend>` |
| `.oe-grid` | fields laid out two to a row where there is room | inside an `.oe-group` |
| `.oe-avatar-field` | the profile picture and its upload control | holds the `.oe-avatar` and the file input |
| `.oe-avatar-empty` | the placeholder picture when the member has none | also carries `.oe-avatar` |
| `.oe-avatar` | the account holder's current picture on the profile page | a square image; core sizes and rounds it |
| `.oe-danger` | the destructive block at the foot of a page | separated by a rule; holds a heading, a line of copy and one danger button |
| `.oe-check` | a checkbox and its label on one line | the `<label>` wraps the control |
| `.oe-actions` | a form's buttons | the submit is first |
| `.oe-btn` | a button or a link acting as one | add `.oe-secondary` for the quiet one, `.oe-btn-danger` for the destructive one |
| `#error_list` | the register form's client-side errors | core's own validator fills it; **empty until it has one**, so style `:not(:empty)` |

### Notices

Core's flash messages keep the class names they have always had:
`flashmessage` and `flashmessage-{ok,error,warning,info}`. Style those; there is
no second name for the same thing.

They carry `role="status"`, or `role="alert"` on an error, so the message
announces itself with no JavaScript. Each message includes a dismiss link
(`.flashmessage a.ico-close`), but core does not wire it up. Clicking it does
nothing until your theme's own script binds click and keyboard handling to it,
the way bender and storefront do. Core renders no dismiss behaviour of its own
because a message is one-shot anyway: it is dropped from the session as it is
printed, so it never returns on the next page.

If your header already calls `osc_show_flash_message()`, core's own call is a
no-op: whichever runs first prints the message, and there is no double render.

### Controls `UserForm` renders

`UserForm::name_text()` and its siblings emit a bare `<input>` with no class of
core's own, because their **names** are core's contract and a theme must not
hand-roll them. Reach them through the wrapper:

```css
.oe-page .oe-field :is(input, select, textarea) { /* yours */ }
```

Core's own defaults for those controls are declared inside `:where()`, which
gives them **zero specificity**. So any rule you write wins, including a bare
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

`osc_gui_account_view(string $themeView): bool` runs the resolution above for
one view name and returns `false` when core has no page for it:

```php
if (!osc_gui_account_view('user-login.php')) {
    // core owns no fallback for this one
}
```

Controllers call it; a plugin serving its own account route can too.

## Sending a visitor back after sign-in

`osc_user_login_url($return)` and `osc_register_account_url($return)` take a page on this site,
as a URL or a path, and add it as `return`. After signing in or registering the visitor lands
there. Only a path under the site's base URL is kept; another host, `//host`, a scheme or a
backslash is dropped. A theme with its own sign-in or register form fires `user_login_form` or
`user_register_form` inside the form, which prints the hidden `return` field.

```php
<a href="<?php echo osc_esc_html(osc_user_login_url(osc_route_url('my-checkout'))); ?>">Sign in</a>
```

With e-mail validation on, a new account lands on the home page after it is validated.

## What core's pages do not do

- **Almost no JavaScript.** The profile form calls
  `UserForm::location_javascript()` so the region list follows the country
  without a reload. A listing list prints one line that asks before a link
  marked `data-osc-confirm` is followed. Both pages work without them.
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

In shop mode core drops the seller entries from a shopper's menu: `opt_items`,
`opt_publicprofile`, `opt_alerts`, `opt_billing_wallet`, `opt_billing_buy` and
`opt_api_access`, and adds `opt_billing_orders` (Your orders) when billing is on. It does
this in one `user_menu_filter` callback at priority 20, so plugin entries stay. In every
mode an entry whose `class` is already in the list is dropped, so two sources of one entry
show once. `osc_is_shopper()` is true for a signed-in user with no admin session in shop
mode; use it to guard hard-coded links in your own account chrome. A seller page a shopper
opens redirects to the dashboard.

## Adding to a page

Every account page fires two actions inside its content column. The argument is
the page: `user-dashboard`, `user-items`, `user-alerts`, `user-profile`,
`user-signin`, `user-custom`, `user-api_access`, `user-messages`, `user-conversation`, `user-delete_account`, and on the credits pages
`billing-wallet`, `billing-buy`, `billing-orders` or `billing-publish`.

```php
osc_add_hook('account_page_before', function ($page) {
    if ($page === 'user-items') {
        echo '<p class="oe-muted">Listings renew for free for 30 days.</p>';
    }
});
```

`account_page_after` is the same, at the foot of the column.

## The profile form

Core's profile form has the account type (`b_company`), the neighbourhood
(`cityArea`) and an About field for each language. Other languages sit in a
`<details>` under the current one. The form is grouped in `<fieldset class="oe-group">`s: Photo, Your details,
Contact, Location and About you. The Location group carries `data-location-cascade`,
so a theme script can find the address fields.
`user_avatar_form($user)` fires after the picture field and its Remove box. A
"Your data" panel links to `osc_user_export_url()`.

## The public profile contact form

The public profile has a contact form, unless the visitor owns the profile, or
only registered users may write and the visitor is signed out; then the head shows
a "Sign in to message" link. It posts `page=user&action=contact_post` with
`yourName`, `yourEmail`, `phoneNumber` and `message`. After a failed send,
`osc_gui_kept()` gives back what was typed.

The form sits in `<dialog id="oe-contact-dialog" class="oe-dialog">`, with
`.oe-dialog-head` (the `<h2>` and a close button marked `data-osc-dialog-close`)
and `.oe-dialog-body`. The `.oe-contact-open` button in the profile head opens it
through `data-osc-dialog-open="oe-contact-dialog"`. The dialog opens by itself after
a failed send, with the reason in `.oe-form-error` at the top of the form. Without
JavaScript it shows in place and the button jumps to it.

`user_contact_form($user)` fires inside the form, before the button, and
`user_contact_form_after($user)` after it. Sends are limited to 15 an hour per visitor. The
`action_throttle_limit` filter changes it for `'user_contact'`.

## Changing the listing rows

Listing lists pass a context: `dashboard`, `user_items`, `public_profile` or
`alert`. Three filters change a row; each gets the list, the item and the context.

| Filter | Entries |
|---|---|
| `listing_row_badges` | `['label' => …, 'class' => …]`; the class is an `.oe-badge` modifier. Core's keys: `status`, `premium`, `highlight`, `urgent`. A listing waiting for payment gets the `status` "Waiting for payment" on `user_items` |
| `listing_row_meta` | `['text' => …]`, plus `'url'` for a link or `'datetime'` for a `<time>`. Core's keys: `category`, `date`, `views` |
| `listing_row_actions` | `['label' => …, 'url' => …, 'class' => …, 'confirm' => …]`. Add `'method' => 'post'` and `'fields' => [name => value]` for a button in a form with the CSRF token. `'group' => 'promote'` puts it on the paid-upgrades line. Core's keys: `edit`, `delete`, and on `user_items` only `upgrade_<feature>` for each upgrade the owner can buy, or `publish` for a listing waiting for payment |

The My listings tabs are All, Published, Awaiting validation and Expired, plus Waiting for payment (`itemType=unpaid`) when pay-at-post is on or the seller has one. Core escapes every value. Add a Renew link on expired listings:

```php
osc_add_filter('listing_row_actions', function ($actions, $item, $context) {
    if ($context === 'user_items' && osc_item_is_expired()) {
        $actions['renew'] = array(
            'label'  => __('Renew', 'my-theme'),
            'url'    => my_renew_url(),
            'method' => 'post',
            'fields' => array('id' => $item['pk_i_id']),
        );
    }

    return $actions;
});
```

Each saved alert has its own actions through `alert_row_actions($actions, $alert)`,
in the same entry shape. Core's key is `unsubscribe`. To show an alert yourself,
`osc_alert_criteria()` gives its search as `['label', 'value']` parts and
`osc_alert_summary()` gives it as one line.

`osc_item_upgrade_offers($item)` gives the upgrades as `['feature', 'label', 'credits']`
data, for a list you draw yourself. `osc_account_upgrade_offers()` gives the account
upgrades (extra slots, photos, runtime, no wait) as `['feature', 'label', 'detail', 'credits']`;
core's Credits page lists them with a POST button to `osc_account_upgrade_url($feature)`. A theme
with its own `user-billing-wallet.php` should show them too. A plugin adds its own through the
`account_upgrade_offers` filter, which gets the list and the user id (or null) and must return
the list; only a registered account-scoped feature can be bought this way. Leave out an offer the
seller already holds when buying it again would only stack it. `osc_gui_listing_list($context, $owned)` prints
core's list for the current `items` loop, filters included.

To draw the whole list yourself, return a string from `listing_list_html`. It
gets `null`, the item rows and the context; anything but a string keeps core's list.

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
Your file is markup only: no `<html>`, no header, no footer.

## The API access page

`user-api_access.php` lists the apps signed in to the account through the
[REST API](/docs/developers/api/authentication/) and the personal keys the member made. The
member can end any of them, and make a key when the site allows it.

| | |
|---|---|
| Fallback file | `oc-includes/osclass/gui/account/user-api_access-content.php` |
| URL | `osc_user_api_access_url()` |
| Nav entry | "API access", class `opt_api_access`. Shown only when the API is on and the member has a sign-in or key, or the site lets members make keys. |
| Redirects | To the dashboard when the API is switched off. |

A theme that ships its own `user-api_access.php` gets these view variables:

| Variable | Holds |
|---|---|
| `api_sessions` | A list of sign-ins and keys, newest first. Each has `id`, `type` (`token` or `key`), `label`, `prefix` (keys only), `scopes`, `created_at`, `last_used_at`, `last_ip` and `expires_at`. Times are Unix times or `null`. |
| `api_user_keys` | `true` when members may make keys. |
| `api_key_scopes` | Scope name to description, for the make-a-key form. Empty when keys are off. |
| `api_new_key` | The key just made, as text. Empty on every other request. It is shown once. |

Every form posts `page=user`, `action=api_access_post` and the CSRF token from
`osc_csrf_token_form()`. The `do` field is `end` (with `session` set to the session `id`)
or `create` (with `name`, `expires` as `YYYY-MM-DD`, `scopes[]` and `password`).

The page reuses `.oe-panel`, `.oe-badge`, `.oe-group`, `.oe-field`, `.oe-check` and
`.oe-link-btn`. The two page hooks fire as on the other account pages.

## The addresses page

`user-addresses.php` is the member's address book: saved delivery addresses, the default first,
with Edit, Make default and Delete on each, and a form to add one. The default address is the
account's own address. At most 10 are kept.

| | |
|---|---|
| Fallback file | `oc-includes/osclass/gui/account/user-addresses-content.php` |
| URL | `osc_user_addresses_url()` |
| Nav entry | "Addresses", class `opt_addresses`. Shown in shop mode only (`osc_address_book_enabled()`); elsewhere the page is a 404. |

A theme that ships its own `user-addresses.php` gets these view variables:

| Variable | Holds |
|---|---|
| `addresses` | The book, default first. Each has `id`, `label`, `name`, `phone`, `line1`, `city`, `state`, `postcode`, `country` (a two-letter code) and `default`. |
| `address_form` | The address being edited (from `?edit=<id>`), or the fields just posted back after a refusal. Empty for a new one. |
| `address_errors` | Field name to message, after a refusal. |

Every form posts `page=user`, `action=addresses_post` and the CSRF token. The `do` field is
`save` (with `id` empty for a new address, the fields above and `default=1` to make it the
default), `default` or `delete` (each with `id`). `osc_address_text($address)` prints an address
on one line.

### At checkout

`osc_address_picker()` prints the saved addresses as radio cards (`.osc-address-picker`,
`.osc-address-card`, `.osc-address-new`, `.osc-address-default`), the default chosen, and "Use a
new address". It prints nothing for a buyer with no saved address. Wrap your address fields in an
element with `data-address-form`: the picker fills fields named `name`, `phone`, `line1`, `city`,
`state`, `postcode` and `country` from the chosen address, hides the box while a saved one is
chosen, and fires `osc:address` with `{id, address}` on every change. `osc_address_save_field()`
prints the ticked "Save this address" box (`.osc-address-save`) for a typed address. Send the
chosen id as `address.id` to `POST account/orders`, or the typed fields with `address.save`.

## The messages pages

`user-messages.php` is the inbox and `user-conversation.php` one conversation between a buyer and
a seller. Both need the on-site messaging switch (Users > Settings); with it off, the pages
redirect to the dashboard.

| | |
|---|---|
| Fallback files | `oc-includes/osclass/gui/account/user-messages-content.php`, `user-conversation-content.php` |
| URLs | `osc_messaging_inbox_url()`, `osc_messaging_thread_url($id)` |
| Nav entry | "Messages", class `opt_messages`, with the unread count while there is one |

`user-messages.php` gets these view variables:

| Variable | Holds |
|---|---|
| `conversations` | Up to 20 conversations, newest first. Each is the stored row plus `title`, `other_name`, `side`, `unread` and `url`. |
| `older` | The link to the next page, or `null`. |

`user-conversation.php` gets:

| Variable | Holds |
|---|---|
| `conversation` | The stored row. `s_state` is `open`, `held`, `reported` or `closed`. |
| `messages` | Messages oldest first. Each has `pk_i_id`, `fk_i_sender_id`, `s_body` (plain text, escape it), `s_state` and `dt_created`. The viewer's own held messages are in the list; the other side's are not. |
| `names` | User id to display name. |
| `title`, `item_url` | The listing's title and link; `item_url` is `null` once the listing is gone. |
| `can_send` | `''` when the viewer may write, else `blocked`, `closed` or `not_party`. |
| `my_block`, `their_block` | Who blocked the conversation. |

The reply form posts `page=messages`, `action=send_post`, `id` and `body`. Block and unblock post
`block_post` and `unblock_post` with `id`; a report posts `report_post` with `id` and a `reason`
of `spam`, `scam`, `offensive` or `other`. All carry the CSRF token. Core's script
`oc-includes/assets/osclass/messages.js` sends the reply without a page load and adds it to the
`.oe-messages` list; a theme that wants the same loads it and keeps the `data-osc-messages`
attributes. It exposes `window.oscMessages.appendMessage(row)`.

Both messages pages print the partial `oc-includes/osclass/gui/account/parts/push-optin.php`: a
`<form class="oe-push" data-osc-push hidden>` that prints nothing while `osc_push_enabled()` is
false. Core's `oc-includes/assets/osclass/push.js` reads its attributes, reveals it when the
browser supports push, and toggles the subscription. A theme styles it and does not copy it.
`push.js` reads these:

| Attribute | Holds |
|---|---|
| `data-key` | The public key, from `osc_push_public_key()` |
| `data-worker-url` | The service worker script (`push_worker`) |
| `data-subscribe-url`, `data-unsubscribe-url`, `data-status-url` | The `push_subscribe`, `push_unsubscribe` and `push_status` AJAX actions |
| `data-inbox-url` | Where a click on the notification goes |
| `data-on-label`, `data-off-label`, `data-on-note`, `data-blocked-note`, `data-error-note` | The texts, already translated |

The form holds one `<button type="submit">` and a `[data-osc-push-note]` line, and carries the CSRF
token. The worker is `oc-includes/assets/osclass/push-worker.js`, served by PHP so it can be
switched off.

The pages reuse `.oe-panel`, `.oe-badge`, `.oe-field`, `.oe-check`, `.oe-actions` and `.oe-link-btn`.
The two page hooks fire as on the other account pages.

### Messaging in a theme

Core owns the messaging markup and scripts; a theme places them and styles the `.oe-*` classes,
light and dark, phone and desktop. A theme never writes its own messaging form, link, badge or
script.

- **The pages.** Ship no `user-messages.php` or `user-conversation.php`. Core's partials render
  inside your [chrome](/docs/developers/theme-chrome/).
- **The header link.** `osc_messaging_link($args)` returns the link to the inbox, or `''` while
  messaging is off, for a guest and for a shopper. `$args['icon']` is your icon's HTML, printed as
  given; `$args['class']` adds classes. The markup is
  `a.oe-msg-link[data-osc-messages-link] > span.oe-msg-label + span.oe-badge.oe-unread[data-osc-unread]`.
  The badge has `hidden` at 0, so keep `[hidden]` hidden if you set `display` on it.
- **The live count.** The first `osc_messaging_link()` on a page loads `messages.js`, which sets
  every `[data-osc-unread]` badge and the link's `aria-label` after each check, on every page.
  Do not add a script for it.
- **The contact form.** Call `osc_item_contact_form()`. When the listing uses messaging it shows
  only the message, the captcha and the button, with a "You are writing as" line above. Otherwise
  it is the e-mail form.

Messaging needs core 7.1. To keep a theme working on 7.0, check `function_exists()` once, in one
helper in `functions.php`, and call that helper from your views:

```php
function mytheme_messaging_link(array $args = array()): string
{
    return function_exists('osc_messaging_link') ? osc_messaging_link($args) : '';
}
```

Pass the contact form's option as
`function_exists('osc_item_uses_messaging') && osc_item_uses_messaging()`. A 7.0 core ignores
the key and prints the e-mail form.

## The pay step

When a seller posts over the listing limit and the site takes payment at the end of posting,
the listing is saved hidden and the seller lands on its pay step,
`osc_billing_publish_url($itemId)` (`page=billing&action=publish&itemId=N`). Only the owner can
open it; anyone else goes to their own listings.

| | |
|---|---|
| Theme view | `user-billing-publish.php` |
| Fallback file | `oc-includes/osclass/gui/billing/publish-content.php`, render target `billing/publish` |
| Redirects | To My listings with a flash when the listing is not the seller's. |

A theme that ships its own view gets these view variables:

| Variable | Holds |
|---|---|
| `item` | `id`, `title`, and `url` once the listing is live |
| `state` | `step`, `quote`, `order` and `failed`. `step` is `pay`, `waiting` (an order is pending), `failed` (paid, but not published yet), `published` or `closed`. `quote` holds `credits`, `balance` and `shortfall`. |
| `packages` | Enabled packages that cover the shortfall, smallest first |
| `gateways` | Payment methods, id to gateway |
| `order`, `checkoutHtml` | After a gateway that shows its instructions in the page. Print `checkoutHtml` as is. |

Forms post to `osc_base_url(true)` with `page=billing`, `action=publish`, `itemId`, the CSRF
token and `do`: `credits` (spend the balance and publish) or `order` (with `packageId` and
`gateway`). The price always comes from the server. Delete is the listing's own delete
(`page=item&action=item_delete&id=N`).

The page adds `.oe-bill-publish`, `.oe-bill-publish-card`, `.oe-bill-publish-status` and
`.oe-bill-publish-more` to the billing classes. Its package form is
`gui/billing/parts/package-picker.php`, the same one the Buy credits page uses.

An admin sees the same listing as Waiting for payment and can publish it free. A reminder
e-mail goes to the owner a day before the hold ends.

## One page, three routes

`user-change_email.php`, `user-change_username.php` and `user-change_password.php` all
resolve to a single **Sign-in details** page. Each keeps its own form and its own POST
action, so nothing about the controllers changed; the route that was asked for gets
`autofocus` on its field, so an old link still lands where it used to.

A theme that ships any one of those three views still wins for that route, exactly as
before: the consolidation is core's fallback shape, not a rule imposed on themes.

Deleting an account stays on its own page. It is destructive and irreversible, and nothing
dangerous should sit a misclick away from changing an email address.

## Pages outside the account section

`osc_gui_page_view()` does the same job for four pages that are not account pages:

| View | What it is |
|---|---|
| `custom.php` | the mount point a plugin's page renders into |
| `contact.php` | writing to whoever runs the site |
| `item-contact.php` | writing to a seller about one listing |
| `item-send-friend.php` | passing a listing on to someone else |

`contact-message.php` is the page the links in message mail open: *Send my message* for a
held message, and *Report the sender*. Core's fallback is `gui/contact-message-content.php`;
it reads `message_mode` (`confirm` or `report`), `message_done` and `message_token`.

The contact page, the seller contact page and the public profile draw one shared
form. After a failed send, each shows the reason in `.oe-form-error` and refills what
was typed. A plugin handling its own contact-style form can do the same with
`osc_keep_form($values, $error)` before it redirects back.

The contact page and the share form fire `contact_form` and then
`admin_contact_form` inside the form. The seller contact form fires
`item_contact_form` with the item id. A theme that puts the seller form in its own
layout, such as a dialog on the item page, calls `osc_item_contact_form()`: it prints
the e-mail form, or only the message field when the listing uses messaging. Pass
`prefix` for the field ids or `hint` for the text under the e-mail field. The contact page also fires `contact_form_top` before the
Subject field and `contact_form_after` below the form.

The contact page is `<div class="oe-contact">`, holding `.oe-contact-main` with the
form. Whatever `contact_page_aside` prints goes in an `<aside class="oe-contact-aside">`
beside it, for an address or links; the aside is left out when nothing prints.

Ship either view and yours wins, exactly as with the account pages. The
save-this-search field `osc_alert_form()` prints falls back the same way, so a
theme that calls it without shipping `alert-form.php` gets core's field rather
than nothing. Core's form carries `data-osc-alert-form`, so a theme script can find it
without depending on its class.
