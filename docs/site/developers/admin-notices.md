---
title: Admin notices
description: "Show a site problem or plugin warning in the admin bell with osc_add_admin_notice(), keep it as a standing check with osc_add_admin_check(), and clear it with osc_clear_admin_notice()."
sidebar:
  order: 35
---

The bell in the admin top bar lists what needs the owner's attention. A plugin adds a
notice with one call, and the bell, the sidebar dot and the Notices screen do the rest.

## The shortest complete example

```php
if (function_exists('osc_add_admin_notice')) {
    osc_add_admin_check('acme:no_key', 'warning', fn () => __('No API key is set.', 'acme'), static fn (): bool => osc_get_preference('api_key', 'acme') === '', array(
        'link'       => osc_admin_render_plugin_url('acme/admin/settings.php'),
        'link_label' => fn () => __('Acme settings', 'acme'),
        'menu'       => 'plugins',
        'source'     => 'Acme',
    ));
}
```

The notice shows while `when` returns true and goes away by itself when it returns false.

## Functions

All are in `oc-includes/osclass/helpers/hAdminNotices.php`. They need Shopclass 7.1, so
guard the call with `function_exists()`.

| Function | Does |
|---|---|
| `osc_add_admin_notice(string $type, string\|callable $text, array $options = []): void` | Adds a one-off notice. |
| `osc_add_admin_check(string $id, string $type, string\|callable $text, callable $when, array $options = []): void` | Adds a standing check. |
| `osc_clear_admin_notice(string $id): void` | Removes a notice for every admin. |
| `osc_admin_notices(): AdminNoticeView` | The notices of the signed-in admin: `active`, `hidden`, `count()`, `actionCount()`. |

`$type` is `error` (needs action) or `warning` and `info` (heads-up). A bad type or id is
logged and skipped. The call never throws on a page.

## Options

| Option | Meaning |
|---|---|
| `id` | Lowercase letters, digits and `_ . : -`, up to 101 characters, starting with a letter or digit. Prefix it with your plugin slug, as in `acme:no_key`. Hide and clear work on the id. |
| `detail` | The rest of the message. The panel shows only `$text`; the Notices screen shows both. |
| `link` | Empty, `?page=…`, or an http(s) address under the site URL. Anything else is dropped. |
| `link_label` | The link's words. |
| `menu`, `submenu` | Where the sidebar dot goes, as admin menu ids such as `plugins` or `tools` and `tools_backup`. Default `dash`. |
| `source` | The area name on the Notices screen. |
| `moderator` | `true` to show it to moderators too. Default `false`. |
| `ttl` | Seconds a one-off notice lives. Default 30 days. |
| `when` | On `osc_add_admin_notice()`: the same as calling `osc_add_admin_check()`. |

Text and detail are plain: tags are stripped and each is cut at 500 characters.

## Standing checks and one-off notices

`osc_add_admin_check()` adds a **standing check**. Nothing is stored. Core calls `$when` on each
admin page and shows the notice while it returns true. It is ignored outside the admin.

Register a check on every admin request: when the plugin loads or on `init_admin`, not on a
page-only hook. A check missing from a request counts as stopped, and every admin's hide of it ends.

`$when` runs on every admin page, so keep it cheap: read a preference or a value you already
hold. Do not query the database for lists, scan files or call another server. A `$when` that
throws counts as not firing.

`osc_add_admin_notice()` adds a **one-off**. It is stored until `ttl` ends, until
`osc_clear_admin_notice()` removes it, or until the same `id` replaces it. Raising it again
unchanged is harmless: it keeps its time. Give it an `id` when its text changes. Use it for an event
such as a failed export, and clear it when the cause is fixed. Core keeps at most 100 one-offs
and drops the oldest.

Hiding a notice lasts 7 days, per admin. When a notice is cleared or its check stops firing, every
admin's hide ends too, so a later return shows at once.

## Text that needs a translation

Plugins load before translations. A `__()` call at file load returns English. Pass a callable
for `text`, `detail` or `link_label` and core runs it when the notice is drawn, in the admin's
language:

```php
'detail' => fn () => __('Add a key under Settings.', 'acme'),
```

## Keeping the old banner

Older sites have no notice API. Keep the banner for them and use the API when it exists:

```php
if (function_exists('osc_add_admin_notice')) {
    osc_add_admin_notice('warning', fn () => __('No API key is set.', 'acme'), array(…));
} else {
    osc_add_hook('admin_page_header', 'acme_old_banner');
}
```

`admin_page_header` is unchanged, so banners that already use it keep printing. With the API,
print the full warning only on your own settings page.

## Payment methods in test mode

A gateway does not call `osc_add_admin_notice()`. Register the method on the
`admin_payment_test_modes` filter (name => settings URL), or on the `PaymentTestModes` event. See
[Payment gateways](/docs/developers/payment-gateways/). Core makes one notice for each method,
with its own hide.

A gateway that is on but has no keys does the same on the `admin_payment_keys_missing` filter, or on the
`PaymentKeysMissing` event, so the admin learns why checkout offers no payment method.

## Related

- [Key-value store](/docs/developers/kv-store/): stored notices live in the core group `admin_notice`.
- [Admin toolbar](/docs/developers/admin-toolbar/): shortcuts in the same top bar.
- [Notices for site owners](/docs/use/notices/).
