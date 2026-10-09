---
title: Hooks and filters
description: "How ShopClass hooks work: actions, filters, priority, the naming standard, and the full reference of every name core fires."
sidebar:
  order: 24
---

A hook is a named place in core where your code can run. An **action** lets you do
something at that moment. A **filter** lets you change a value on its way past.

```php
// Do something when an item is published.
osc_add_hook('item_post', 'my_plugin_notify');

// Change a value before core uses it.
osc_add_filter('item_title', 'my_plugin_shorten_title');
```

Core fires the hook; you register a callback for it.

## Actions

An action is fired for its side effects and returns nothing.

```php
osc_add_hook('after_item_post', function ($item) {
    error_log('New item: ' . $item['pk_i_id']);
});
```

Whatever your callback returns is discarded.

## Filters

A filter threads one value through every callback. **Your callback must return the
value**, changed or not. Return nothing and the value becomes `null` for everyone
after you.

```php
osc_add_filter('item_title', function ($title, $item) {
    return mb_strimwidth($title, 0, 60, '…');
});
```

The filtered value is always the first argument. Anything else is context.

## Priority

Lower runs first. The default is `5`.

```php
osc_add_hook('item_post', 'runs_early', 1);
osc_add_hook('item_post', 'runs_late', 50);
osc_add_hook('item_post', 'runs_before_core', -10);
```

Any whole number works, negative included.

::: warning Before 6.4.0
Priority was a fixed range of 0 to 10. A callback registered outside it was stored
and never ran, with no warning. If your plugin used a priority above 10, it starts
running on 6.4.0.
:::

Two callbacks at the same priority run in the order they were registered. Never write
a hook that only works because another plugin chose a particular priority.

## Actions and filters share one registry

`osc_add_hook()` and `osc_add_filter()` are the same function. There is no separate
filter table.

That means a name used for both is **one** list of callbacks, called with two
different argument shapes: the action's callbacks receive a filter's value as their
first argument, and the filter loses its value to a callback that returns nothing. No
name in core does this, and yours should not either.

## Removing a callback

```php
osc_remove_hook('item_post', 'my_plugin_notify');
osc_remove_filter('item_title', 'my_plugin_shorten_title');
```

The callback must match what you registered. A closure cannot be removed unless you
kept a reference to it.

## The naming standard

These rules apply to **new** hooks. Every name core already fires keeps its spelling
forever, because plugins on installs we cannot see depend on it. So some existing
names do not follow the rules below.

### Names

`{subject}_{event}`, lowercase, words joined by underscores.

- **Subject first.** `item_delete_before`, not `before_delete_item`.
- **Admin-only hooks start with `admin_`.** Public hooks carry no prefix.
- **Lifecycle words come last:** `_before` and `_after`.
- **A filter is named for what it returns**, not for the moment: `item_title`,
  `search_query`.
- **No names built at runtime.** `item_bulk_{$action}` cannot be documented, found by
  search, or pinned by a test. Fire one fixed name and pass the variable part as an
  argument.

### Arguments

- A filter's first argument is the value being filtered. Context follows.
- An action's arguments run most specific first: the entity, then its context.
- Pass ids and records, not rendered HTML, unless the hook exists to filter HTML.
- **Arguments are append-only.** Adding one to the end is safe. Reordering or removing
  one is not: a callback written for the old order keeps running and silently receives
  the wrong thing.

### Actions or filters

- An action returns nothing.
- A filter returns its value, always.
- One name is never both.

## Hooks that also run outside a page request

`search_conditions` fires on the search page and again whenever a saved alert runs: in the
alert cron, on the user's alerts page and on the admin user screen. Its third argument says
which: `'request'` or `'alert'`. During an alert, `Params::getParam()` returns the alert's saved
values and `Search::getInstance()` is the alert's search. A callback that sends headers, reads
the session or prints must return early when the context is `'alert'`.

To store your own filter with an alert, add it with the `alert_search_params` filter, then read
it back with `Params::getParam()` in your `search_conditions` callback.

`page_cache_purge` fires once at the end of a request that changed something every public page
shows: the theme, settings, a plugin, a category. It runs after the response has gone, and on the
command line too. Its one argument is the list of reasons, such as `array('theme', 'plugin')`.
Clear your cache there. Do not print, send headers or read the session. To ask for one yourself,
call `osc_purge_page_cache('my_reason')`. See [the caching contract](/docs/developers/caching/).

## Reference

Every name core fires, with where it is fired and what it passes.

<!-- generated:hooks -->

Core fires 555 names. Generated from the source; do not edit by hand.

### Admin (79)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `admin_alerts_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/AlertsDataTable.php` |
| `admin_base_url` | filter | `$path, $with_index` | `oc-includes/osclass/helpers/hDefines.php` |
| `admin_body_class` | filter | `array()` | `oc-admin/themes/modern/parts/header.php` |
| `admin_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php` |
| `admin_comments_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/CommentsDataTable.php` |
| `admin_contact_form` | action | none | `oc-includes/osclass/gui/contact-content.php` |
| `admin_content_footer` | action | none | `oc-admin/themes/modern/parts/footer.php` |
| `admin_date_format` | filter | `$format, $dateOnly` | `oc-includes/osclass/helpers/hUtils.php` |
| `admin_edit_completed` | action | `$id, $result['updated']` | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php` |
| `admin_favicons` | filter | `$favicons` | `oc-admin/themes/modern/functions.php` |
| `admin_footer` | action | none | `oc-admin/themes/modern/parts/footer.php` |
| `admin_forgot_form` | action | none | `oc-admin/gui/forgot_password.php` |
| `admin_forgot_password_form` | action | none | `oc-admin/gui/recover.php` |
| `admin_form_after_save` | action | `$pageId, $exposed, $savedId` | `oc-includes/osclass/helpers/hSettings.php` |
| `admin_form_before_save` | filter | `$exposed, $pageId` | `oc-includes/osclass/helpers/hSettings.php` |
| `admin_form_render_field` | filter | `$field, $pageId, $values` | `oc-includes/osclass/classes/admin/ui/SettingsForm.php` |
| `admin_form_save_failed` | action | `$pageId, $errors, array()` | `oc-includes/osclass/helpers/hSettings.php` |
| `admin_header` | action | none | `oc-admin/themes/modern/parts/header.php` |
| `admin_item_description` | filter | `$description, $itemRecord, $itemLocale` | `oc-admin/themes/modern/items/frm.php` |
| `admin_item_title` | filter | `$title, $itemRecord, $itemLocale` | `oc-admin/themes/modern/items/frm.php` |
| `admin_items_reported_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `admin_items_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `admin_keyword_block_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/KeywordBlocksDataTable.php` |
| `admin_locations_drawer_fields` | action | `$level, null` | `oc-admin/themes/modern/settings/locations/form.php` |
| `admin_locations_row_actions` | filter | `array('edit' => $edit), $level, $row` | `oc-admin/themes/modern/settings/locations/list.php` |
| `admin_login_footer` | action | none | `oc-admin/gui/main.php` |
| `admin_login_form` | action | none | `oc-admin/gui/login.php` |
| `admin_login_header` | action | none | `oc-admin/gui/main.php` |
| `admin_logs_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/LogsDataTable.php` |
| `admin_media_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/MediaDataTable.php` |
| `admin_menu_init` | action | none | `oc-includes/osclass/classes/AdminMenu.php` |
| `admin_page_description` | filter | `$description, $page, $pageLocale` | `oc-admin/themes/modern/pages/frm.php` |
| `admin_page_header` | action | none | `oc-admin/themes/modern/parts/header.php` |
| `admin_page_routes` | filter | `$core` | `oc-includes/osclass/classes/routing/PageDispatcher.php` |
| `admin_page_title` | filter | `$title, $page, $pageLocale` | `oc-admin/themes/modern/pages/frm.php` |
| `admin_pages_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/PagesDataTable.php` |
| `admin_post` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminPlugins.php` |
| `admin_profile_form` | action | `__get('admin')` | `oc-includes/osclass/classes/admin/form/AdminAccountForm.php` |
| `admin_rules_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php` |
| `admin_scripts_loaded` | action | none | `oc-includes/osclass/helpers/hTheme.php` |
| `admin_signout_all_after` | action | `$adminId` | `oc-includes/osclass/classes/auth/SignOut.php` |
| `admin_title` | filter | `osc_page_title() . ' - Shopclass'` | `oc-admin/themes/modern/parts/header.php` |
| `admin_user_profile_info` | filter | `$aInfo['s_info'], $aUser['pk_i_id'], $aInfo['fk_c_locale_code']` | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php` |
| `admin_users_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/UsersDataTable.php` |
| `init_admin` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `init_admin_admins` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php` |
| `init_admin_billing` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminBilling.php` |
| `init_admin_categories` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminCategories.php` |
| `init_admin_comments` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php` |
| `init_admin_emails` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminEmails.php` |
| `init_admin_fields` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminCFields.php` |
| `init_admin_insecure` | action | none | `oc-includes/osclass/classes/controller/base/AdminBaseModel.php` |
| `init_admin_items` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminItems.php` |
| `init_admin_languages` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminLanguages.php` |
| `init_admin_login` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `init_admin_main` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminMain.php` |
| `init_admin_media` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminMedia.php` |
| `init_admin_pages` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminPages.php` |
| `init_admin_plugins` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminPlugins.php` |
| `init_admin_settings` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminSettings.php` |
| `init_admin_settings_advanced` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsAdvanced.php` |
| `init_admin_settings_billing` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsBilling.php` |
| `init_admin_settings_comments` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsComments.php` |
| `init_admin_settings_currencies` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCurrencies.php` |
| `init_admin_settings_custom` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCustom.php` |
| `init_admin_settings_keyword_block` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsKeywordBlock.php` |
| `init_admin_settings_latest` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsLatestSearches.php` |
| `init_admin_settings_locations` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsLocations.php` |
| `init_admin_settings_mail` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsMailserver.php` |
| `init_admin_settings_main` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsMain.php` |
| `init_admin_settings_media` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsMedia.php` |
| `init_admin_settings_permalinks` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsPermalinks.php` |
| `init_admin_settings_sitemap` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsSitemap.php` |
| `init_admin_settings_spam` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsSpamnBots.php` |
| `init_admin_settings_storage` | action | none | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsStorage.php` |
| `init_admin_stats` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminStats.php` |
| `init_admin_tools` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminTools.php` |
| `init_admin_upgrade` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminUpgrade.php` |
| `init_admin_users` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php` |

### Category (6)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `add_category` | action | `$id` | `oc-includes/osclass/classes/category/CategoryService.php` |
| `after_delete_category` | action | `$pkInt` | `oc-includes/osclass/classes/model/Category.php` |
| `api_category` | filter | `$data, $context` | `oc-includes/osclass/classes/api/serializer/CategorySerializer.php` |
| `delete_category` | action | `$pkInt` | `oc-includes/osclass/classes/model/Category.php` |
| `edited_category` | action | `$id, $outcome` | `oc-includes/osclass/classes/category/CategoryService.php` |
| `edited_category_order` | action | `$error` | `oc-includes/osclass/classes/admin/ajax/CategoryAjax.php` |

### Comment (12)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `activate_comment` | action | `$id` | `oc-includes/osclass/classes/moderation/CommentModeration.php` |
| `add_comment` | action | `$id` | `oc-includes/osclass/classes/comment/CommentService.php` |
| `before_add_comment` | action | `$row` | `oc-includes/osclass/classes/comment/CommentService.php` |
| `comment_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php` |
| `comment_form` | action | none | `oc-includes/osclass/gui/item-comments-content.php` |
| `comments_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/CommentsDataTable.php` |
| `datatable_comment_class` | filter | `array(), $aRawRows[$key], $row` | `oc-admin/themes/modern/comments/index.php` |
| `deactivate_comment` | action | `$id` | `oc-includes/osclass/classes/moderation/CommentModeration.php` |
| `delete_comment` | action | `$commentId` | `oc-includes/osclass/classes/comment/CommentService.php` |
| `disable_comment` | action | `$id` | `oc-includes/osclass/classes/moderation/CommentModeration.php` |
| `edit_comment` | action | `$id` | `oc-includes/osclass/classes/moderation/CommentModeration.php` |
| `enable_comment` | action | `$id` | `oc-includes/osclass/classes/moderation/CommentModeration.php` |

### Email (93)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `email_admin_new_item_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php` |
| `email_admin_new_item_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_admin_new_item_description', $content['s_text'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php` |
| `email_admin_new_item_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php` |
| `email_admin_new_item_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_admin_new_item_title', $content['s_title'], $item) ), $words), $item` | `oc-includes/osclass/emails.php` |
| `email_admin_user_registration_title` | filter | `$content['s_title'], $user` | `oc-includes/osclass/emails.php` |
| `email_admin_user_registration_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_admin_user_registration_title', $content['s_title'], $user) ), $words), $user` | `oc-includes/osclass/emails.php` |
| `email_admin_user_regsitration_description` | filter | `$content['s_text'], $user` | `oc-includes/osclass/emails.php` |
| `email_admin_user_regsitration_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_admin_user_regsitration_description', $content['s_text'], $user) ), $words ), $user` | `oc-includes/osclass/emails.php` |
| `email_after_auto_upgrade_description` | filter | `$body, $result` | `oc-includes/osclass/emails.php` |
| `email_after_auto_upgrade_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_after_auto_upgrade_description', $body, $result) ), $words ), $result` | `oc-includes/osclass/emails.php` |
| `email_after_auto_upgrade_title` | filter | `$title, $result` | `oc-includes/osclass/emails.php` |
| `email_after_auto_upgrade_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_after_auto_upgrade_title', $title, $result) ), $words), $result` | `oc-includes/osclass/emails.php` |
| `email_alert_validation_description` | filter | `$page_description[$prefLocale]['s_text'], $alert, $email, $secret` | `oc-includes/osclass/emails.php` |
| `email_alert_validation_description_after` | filter | `osc_mailBeauty($_body, $words), $alert, $email, $secret` | `oc-includes/osclass/emails.php` |
| `email_alert_validation_title` | filter | `$page_description[$prefLocale]['s_title'], $alert, $email, $secret` | `oc-includes/osclass/emails.php` |
| `email_alert_validation_title_after` | filter | `osc_mailBeauty($_title, $words), $alert, $email, $secret` | `oc-includes/osclass/emails.php` |
| `email_comment_validated_description` | filter | `$content['s_text'], $aComment` | `oc-includes/osclass/emails.php` |
| `email_comment_validated_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_comment_validated_description', $content['s_text'], $aComment) ), $words), $aComment` | `oc-includes/osclass/emails.php` |
| `email_comment_validated_title` | filter | `$content['s_title'], $aComment` | `oc-includes/osclass/emails.php` |
| `email_comment_validated_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_comment_validated_title', $content['s_title'], $aComment) ), $words), $aComment` | `oc-includes/osclass/emails.php` |
| `email_description` | filter | `$v['s_title']` | `oc-includes/osclass/classes/controller/CWebPage.php` |
| `email_item_inquiry_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_item_inquiry_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_item_inquiry_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php` |
| `email_item_inquiry_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_item_inquiry_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_item_inquiry_title', $content['s_title'], $aItem) ), $titleWords), $aItem` | `oc-includes/osclass/emails.php` |
| `email_item_validation_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php` |
| `email_item_validation_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_item_validation_description', $content['s_text'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php` |
| `email_item_validation_non_register_user_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php` |
| `email_item_validation_non_register_user_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_item_validation_non_register_user_description', $content['s_text'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php` |
| `email_item_validation_non_register_user_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php` |
| `email_item_validation_non_register_user_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_item_validation_non_register_user_title', $content['s_title'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php` |
| `email_item_validation_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php` |
| `email_item_validation_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_item_validation_title', $content['s_title'], $item) ), $words), $item` | `oc-includes/osclass/emails.php` |
| `email_legend_words` | filter | `$array, @$email['s_internal_name']` | `oc-includes/osclass/classes/EmailVariables.php` |
| `email_new_admin_description` | filter | `$content['s_text'], $data` | `oc-includes/osclass/emails.php` |
| `email_new_admin_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_new_admin_description', $content['s_text'], $data) ), $words ), $data` | `oc-includes/osclass/emails.php` |
| `email_new_admin_title` | filter | `$content['s_title'], $data` | `oc-includes/osclass/emails.php` |
| `email_new_admin_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_new_admin_title', $content['s_title'], $data) ), $words), $data` | `oc-includes/osclass/emails.php` |
| `email_new_comment_admin_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_comment_admin_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_new_comment_admin_description', $content['s_text'], $aItem) ), $words), $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_comment_admin_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_comment_admin_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_new_comment_admin_title', $content['s_title'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_comment_user_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_comment_user_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_new_comment_user_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_comment_user_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_comment_user_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_new_comment_user_title', $content['s_title'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php` |
| `email_new_email_description` | filter | `$content['s_text'], $new_email, $validation_url` | `oc-includes/osclass/emails.php` |
| `email_new_email_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_new_email_description', $content['s_text'], $new_email, $validation_url) ), $words ), $new_email, $validation_url` | `oc-includes/osclass/emails.php` |
| `email_new_email_title` | filter | `$content['s_title'], $new_email, $validation_url` | `oc-includes/osclass/emails.php` |
| `email_new_email_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_new_email_title', $content['s_title'], $new_email, $validation_url) ), $words), $new_email, $validation_url` | `oc-includes/osclass/emails.php` |
| `email_new_item_non_register_user_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php` |
| `email_new_item_non_register_user_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_new_item_non_register_user_description', $content['s_text'], $item) ), $words), $item` | `oc-includes/osclass/emails.php` |
| `email_new_item_non_register_user_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php` |
| `email_new_item_non_register_user_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_new_item_non_register_user_title', $content['s_title'], $item) ), $words), $item` | `oc-includes/osclass/emails.php` |
| `email_send_friend_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_send_friend_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_send_friend_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php` |
| `email_send_friend_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_send_friend_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_send_friend_title', $content['s_title'], $aItem) ), $titleWords), $aItem` | `oc-includes/osclass/emails.php` |
| `email_title` | filter | `osc_apply_filter( 'email_alert_validation_title', $page_description[$prefLocale]['s_title'], $alert, $email, $secret )` | `oc-includes/osclass/emails.php` |
| `email_user_forgot_pass_word_title` | filter | `$content['s_title'], $user, $password_url` | `oc-includes/osclass/emails.php` |
| `email_user_forgot_pass_word_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_user_forgot_pass_word_title', $content['s_title'], $user, $password_url) ), $words ), $user, $password_url` | `oc-includes/osclass/emails.php` |
| `email_user_forgot_password_description` | filter | `$content['s_text'], $user, $password_url` | `oc-includes/osclass/emails.php` |
| `email_user_forgot_password_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter( 'email_user_forgot_password_description', $content['s_text'], $user, $password_url ) ), $words ), $user, $password_url` | `oc-includes/osclass/emails.php` |
| `email_user_registration_description` | filter | `$content['s_text'], $user` | `oc-includes/osclass/emails.php` |
| `email_user_registration_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_user_registration_description', $content['s_text'], $user) ), $words), $user` | `oc-includes/osclass/emails.php` |
| `email_user_registration_title` | filter | `$content['s_title'], $user` | `oc-includes/osclass/emails.php` |
| `email_user_registration_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_user_registration_title', $content['s_title'], $user) ), $words ), $user` | `oc-includes/osclass/emails.php` |
| `email_user_validation_description` | filter | `$content['s_text'], $user, $input` | `oc-includes/osclass/emails.php` |
| `email_user_validation_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_user_validation_description', $content['s_text'], $user, $input) ), $words ), $user, $input` | `oc-includes/osclass/emails.php` |
| `email_user_validation_title` | filter | `$content['s_title'], $user, $input` | `oc-includes/osclass/emails.php` |
| `email_user_validation_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_user_validation_title', $content['s_title'], $user, $input) ), $words), $user, $input` | `oc-includes/osclass/emails.php` |
| `email_warn_expiration_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_warn_expiration_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_warn_expiration_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php` |
| `email_warn_expiration_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php` |
| `email_warn_expiration_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_warn_expiration_title', $content['s_title'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php` |
| `hook_email_admin_new_item` | action | `$item` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `hook_email_admin_new_user` | action | `$user` | `oc-includes/osclass/classes/user/AccountService.php` |
| `hook_email_alert_validation` | action | `Alerts::getInstance()->findByPrimaryKey($alertID), $email, $secret` | `oc-includes/osclass/helpers/hSearch.php` |
| `hook_email_comment_validated` | action | `$comment` | `oc-includes/osclass/classes/moderation/CommentModeration.php` |
| `hook_email_contact_user` | action | `(int) $user['pk_i_id'], (string) $args['yourEmail'], (string) $args['yourName'], (string) $args['phoneNumber'], (string) $args['message']` | `oc-includes/osclass/classes/security/MessageHold.php` |
| `hook_email_item_inquiry` | action | `$args` | `oc-includes/osclass/classes/security/MessageHold.php` |
| `hook_email_item_validation` | action | `$item` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `hook_email_item_validation_non_register_user` | action | `$item` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `hook_email_new_admin` | action | `array( 's_name' => $result['values']['s_name'], 's_username' => $result['values']['s_username'], 's_password' => $result['values']['s_password'], 's_email' => $result['values']['s_email'], )` | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php` |
| `hook_email_new_comment_admin` | action | `$mail` | `oc-includes/osclass/classes/comment/CommentService.php` |
| `hook_email_new_comment_user` | action | `$mail` | `oc-includes/osclass/classes/comment/CommentService.php` |
| `hook_email_new_email` | action | `$newEmail, osc_change_user_email_confirm_url($userId, $code)` | `oc-includes/osclass/classes/user/AccountService.php` |
| `hook_email_new_item_non_register_user` | action | `$item` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `hook_email_send_friend` | action | `$args` | `oc-includes/osclass/classes/security/MessageHold.php` |
| `hook_email_user_forgot_password` | action | `$admin, $password_url` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `hook_email_user_registration` | action | `$user` | `oc-includes/osclass/classes/controller/CWebRegister.php` |
| `hook_email_user_validation` | action | `$user, $input` | `oc-includes/osclass/classes/user/AccountService.php` |
| `hook_email_warn_expiration` | action | `$item` | `oc-includes/osclass/cron.php` |

### Item (75)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `actions_manage_items` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `activate_item` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `after_delete_item` | action | `$itemId, $item` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `before_delete_item` | action | `$itemId` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `before_item_edit` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `deactivate_item` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `delete_item` | action | `$id` | `oc-includes/osclass/classes/model/Item.php` |
| `disable_item` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `edited_item` | action | `$edited` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `enable_item` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `filters_manage_item_search` | action | none | `oc-admin/themes/modern/items/index.php` |
| `init_item` | action | none | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `invalidate_item_cache` | action | `$itemId` | `oc-includes/osclass/helpers/hCache.php` |
| `item_add_prepare_data` | filter | `$data` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminItems.php` |
| `item_bumped` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_comments_after` | action | none | `oc-includes/osclass/gui/item-comments-content.php` |
| `item_comments_before` | action | none | `oc-includes/osclass/gui/item-comments-content.php` |
| `item_contact_form` | action | none | `oc-includes/osclass/gui/item-contact-content.php` |
| `item_content_updated` | action | `(int) $itemId, $locale` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_decrease_stat` | action | `$item` | `oc-includes/osclass/classes/listing/ListingStats.php` |
| `item_description` | filter | `$v['s_description']` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `item_edit` | action | `$catId, $itemId` | `oc-includes/osclass/classes/admin/ajax/PluginAjax.php` |
| `item_edit_prepare_data` | filter | `$data` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_expiration_updated` | action | `(int)$id, $_item['dt_expiration']` | `oc-includes/osclass/classes/model/Item.php` |
| `item_form` | action | `Params::getParam('catId')` | `oc-includes/osclass/classes/admin/ajax/PluginAjax.php` |
| `item_form_new_validation_messages` | action | none | `oc-includes/osclass/classes/form/ItemForm.php` |
| `item_form_new_validation_rules` | action | none | `oc-includes/osclass/classes/form/ItemForm.php` |
| `item_form_validation_messages` | action | none | `oc-includes/osclass/classes/form/ItemForm.php` |
| `item_form_validation_rules` | action | none | `oc-includes/osclass/classes/form/ItemForm.php` |
| `item_increase_stat` | action | `$item` | `oc-includes/osclass/classes/listing/ListingStats.php` |
| `item_mark` | filter | `true, $id, $as` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_marked` | action | `$id, $as` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_meta_checkbox_value` | filter | `osc_esc_html($label), $checked, $meta` | `oc-includes/osclass/helpers/hItems.php` |
| `item_post_redirect_url` | filter | `osc_search_category_url(), $saved->id(), $category` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `item_premium_off` | action | `$id` | `oc-includes/osclass/classes/billing/Premium.php` |
| `item_premium_on` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_prepare_data` | filter | `$aItem` | `oc-includes/osclass/classes/listing/ListingInput.php` |
| `item_price` | filter | `$currencyFormat` | `oc-includes/osclass/helpers/hItems.php` |
| `item_price_null` | filter | `__('Check with seller')` | `oc-includes/osclass/helpers/hItems.php` |
| `item_price_zero` | filter | `__('Free')` | `oc-includes/osclass/helpers/hItems.php` |
| `item_spam_off` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_spam_on` | action | `$id` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `item_title` | filter | `$v['s_title']` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `item_view_beacon_enabled` | filter | `$enabled` | `oc-includes/osclass/helpers/hViews.php` |
| `items_bulk_enabled_by_category` | action | `$aIds, $enable` | `oc-includes/osclass/classes/model/Item.php` |
| `items_processing_reported_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `items_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `manage_item_search_conditions` | action | `$this->mSearch` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `manage_item_search_with_filters` | filter | `$this->withFilters` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `more_actions_manage_items` | filter | `$options_more, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php` |
| `osc_item_edit_meta_textarea_value_filter` | filter | `$value, $field` | `oc-includes/osclass/classes/form/FieldForm.php` |
| `osc_item_meta_textarea_value_filter` | filter | `$value, $meta` | `oc-includes/osclass/helpers/hItems.php` |
| `osc_item_meta_value_filter` | filter | `$value, $meta` | `oc-includes/osclass/helpers/hItems.php` |
| `osc_item_meta_value_pre_filter` | filter | `$value, $meta` | `oc-includes/osclass/helpers/hItems.php` |
| `post_item` | action | none | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `post_item_contact_post` | action | `$item` | `oc-includes/osclass/classes/listing/ListingMailService.php` |
| `post_item_send_friend_post` | action | `$item` | `oc-includes/osclass/classes/listing/ListingMailService.php` |
| `posted_item` | action | `$item` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `pre_item_add` | action | `$aItem, $text` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `pre_item_add_comment_post` | action | `$item` | `oc-includes/osclass/classes/comment/CommentService.php` |
| `pre_item_add_error` | filter | `$text, $aItem` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `pre_item_contact_post` | action | `$item` | `oc-includes/osclass/classes/listing/ListingMailService.php` |
| `pre_item_delete_comment_post` | action | `$item, $commentId` | `oc-includes/osclass/classes/comment/CommentService.php` |
| `pre_item_edit` | action | `$aItem, $text` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `pre_item_edit_error` | filter | `$text, $aItem` | `oc-includes/osclass/classes/listing/ListingService.php` |
| `pre_item_send_friend_post` | action | `$item` | `oc-includes/osclass/classes/listing/ListingMailService.php` |
| `pre_show_item` | filter | `$this->itemManager->findByPrimaryKey($id)` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `pre_show_items` | filter | `$aItems` | `oc-includes/osclass/classes/search/SearchRunner.php` |
| `rss_feed_item` | filter | `$itemArray, osc_item()` | `oc-includes/osclass/classes/controller/CWebSearch.php` |
| `show_item` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `sitemap_items_source` | filter | `$default, array('page' => $page, 'per_page' => $perPage)` | `oc-includes/osclass/classes/Sitemap.php` |
| `sitemap_items_total` | filter | `$this->countLiveItems()` | `oc-includes/osclass/classes/Sitemap.php` |
| `sitemap_url_entry` | filter | `array('loc' => $loc, 'lastmod' => $lastmod, 'changefreq' => $changefreq), $type` | `oc-includes/osclass/classes/Sitemap.php` |
| `sql_search_item_conditions` | filter | `$this->itemConditions` | `oc-includes/osclass/classes/search/query/PluginClauses.php` |

### Other (215)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `account_page_after` | action | `'user-alerts'` | `oc-includes/osclass/gui/account/user-alerts-content.php` |
| `account_page_before` | action | `'user-alerts'` | `oc-includes/osclass/gui/account/user-alerts-content.php` |
| `action_throttle_limit` | filter | `array('max' => $max, 'window' => $window), $context` | `oc-includes/osclass/classes/security/ActionThrottle.php` |
| `actions_manage_alerts` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/AlertsDataTable.php` |
| `actions_manage_keyword_block` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/KeywordBlocksDataTable.php` |
| `actions_manage_rules` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php` |
| `add_admin_toolbar_menus` | action | none | `oc-includes/osclass/classes/AdminToolbar.php` |
| `after_admin_html` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `after_delete_city` | action | `$pk` | `oc-includes/osclass/classes/model/City.php` |
| `after_delete_city_area` | action | `$pk` | `oc-includes/osclass/classes/model/CityArea.php` |
| `after_delete_country` | action | `$pk` | `oc-includes/osclass/classes/model/Country.php` |
| `after_delete_field` | action | `$id` | `oc-includes/osclass/classes/model/Field.php` |
| `after_delete_field_group` | action | `$id` | `oc-includes/osclass/classes/model/FieldGroup.php` |
| `after_delete_form_submission` | action | `$id` | `oc-includes/osclass/classes/model/FormSubmission.php` |
| `after_delete_page` | action | `$id` | `oc-includes/osclass/classes/model/Page.php` |
| `after_delete_region` | action | `$pk` | `oc-includes/osclass/classes/model/Region.php` |
| `after_delete_widget` | action | `$widgetId` | `oc-includes/osclass/classes/controller/admin/CAdminAppearance.php` |
| `after_html` | action | none | `oc-includes/osclass/classes/controller/CWebAjax.php` |
| `after_login` | action | `$user, $redirect` | `oc-includes/osclass/classes/auth/SignIn.php` |
| `after_rewrite_rules` | action | `array(&$rewrite)` | `oc-includes/osclass/classes/Rewrite.php` |
| `after_show_pagination_admin` | action | none | `oc-includes/osclass/helpers/hPagination.php` |
| `alert_email_daily_description` | filter | `$template['s_text'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_daily_description_after` | filter | `osc_mailBeauty($_body, $htmlWords), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_daily_title` | filter | `$template['s_title'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_daily_title_after` | filter | `osc_mailBeauty($_title, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_hourly_description` | filter | `$template['s_text'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_hourly_description_after` | filter | `osc_mailBeauty($_body, $htmlWords), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_hourly_title` | filter | `$template['s_title'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_hourly_title_after` | filter | `osc_mailBeauty($_title, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_weekly_description` | filter | `$template['s_text'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_weekly_description_after` | filter | `osc_mailBeauty($_body, $htmlWords), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_weekly_title` | filter | `$template['s_title'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_email_weekly_title_after` | filter | `osc_mailBeauty($_title, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php` |
| `alert_row_actions` | filter | `$alertActions, osc_alert()` | `oc-includes/osclass/gui/account/user-alerts-content.php` |
| `alerts_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/AlertsDataTable.php` |
| `api_cors_origins` | filter | `$origins, $request` | `oc-includes/osclass/classes/api/http/Cors.php` |
| `api_fields` | filter | `[]` | `oc-includes/osclass/classes/api/serializer/ExtensionMembers.php` |
| `api_listing` | filter | `$data, $context` | `oc-includes/osclass/classes/api/serializer/ListingSerializer.php` |
| `api_listing_input` | filter | `$input, $request, $credential` | `oc-includes/osclass/classes/api/write/ListingWriter.php` |
| `api_listings_prefetch` | action | `$ids, $context` | `oc-includes/osclass/classes/api/serializer/ListingSerializer.php` |
| `api_problem_codes` | filter | `[]` | `oc-includes/osclass/classes/api/Problem.php` |
| `api_rate_limit` | filter | `$limit, $credential, $route` | `oc-includes/osclass/classes/api/ratelimit/RatePolicy.php` |
| `api_request_before` | action | `$request, $route, $credential` | `oc-includes/osclass/classes/api/Kernel.php` |
| `api_response` | filter | `$response, $request, $route` | `oc-includes/osclass/classes/api/Kernel.php` |
| `api_routes` | filter | `[]` | `oc-includes/osclass/classes/api/routing/Router.php` |
| `api_schemas` | filter | `[]` | `oc-includes/osclass/classes/api/schema/ExtensionSchemas.php` |
| `api_scopes` | filter | `[]` | `oc-includes/osclass/classes/apiaccess/Scopes.php` |
| `api_webhook_delivered` | action | `$endpointData, $event, $httpStatus, $attempt` | `oc-includes/osclass/classes/webhook/Delivery.php` |
| `api_webhook_events` | filter | `$events` | `oc-includes/osclass/classes/webhook/Events.php` |
| `api_webhook_payload` | filter | `$payload, $type, $endpointData` | `oc-includes/osclass/classes/webhook/Dispatcher.php` |
| `ban_rule_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php` |
| `base_url` | filter | `$path, $with_index` | `oc-includes/osclass/helpers/hDefines.php` |
| `before_admin_html` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `before_delete_city` | action | `$pk` | `oc-includes/osclass/classes/model/City.php` |
| `before_delete_city_area` | action | `$pk` | `oc-includes/osclass/classes/model/CityArea.php` |
| `before_delete_country` | action | `$pk` | `oc-includes/osclass/classes/model/Country.php` |
| `before_delete_field` | action | `$id` | `oc-includes/osclass/classes/model/Field.php` |
| `before_delete_field_group` | action | `$id` | `oc-includes/osclass/classes/model/FieldGroup.php` |
| `before_delete_form_submission` | action | `$id` | `oc-includes/osclass/classes/model/FormSubmission.php` |
| `before_delete_page` | action | `$id` | `oc-includes/osclass/classes/model/Page.php` |
| `before_delete_region` | action | `$pk` | `oc-includes/osclass/classes/model/Region.php` |
| `before_delete_widget` | action | `$widgetId` | `oc-includes/osclass/classes/controller/admin/CAdminAppearance.php` |
| `before_html` | action | none | `oc-includes/osclass/classes/controller/CWebAjax.php` |
| `before_login` | action | none | `oc-includes/osclass/classes/auth/SignIn.php` |
| `before_login_admin` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `before_rewrite_rules` | action | `array(&$rewrite)` | `oc-includes/osclass/classes/Rewrite.php` |
| `before_show_pagination_admin` | action | none | `oc-includes/osclass/helpers/hPagination.php` |
| `before_validating_login` | action | none | `oc-includes/osclass/classes/auth/SignIn.php` |
| `billing_can_publish` | filter | `$allowed, $userId, $ctx` | `oc-includes/osclass/classes/billing/EntitlementStore.php` |
| `billing_credits_changed` | action | `$userId, $delta, $reason` | `oc-includes/osclass/classes/billing/Wallet.php` |
| `billing_feature_applied` | action | `$featureId, $userId, $price` | `oc-includes/osclass/classes/billing/Billing.php` |
| `billing_feature_duration` | filter | `$days, $this->id, $userId` | `oc-includes/osclass/classes/billing/Feature.php` |
| `billing_feature_price` | filter | `$price, $this->id, $userId` | `oc-includes/osclass/classes/billing/Feature.php` |
| `billing_listing_limit_message` | filter | `_m('You are at your listing limit. Free up a listing -- delete one or let one expire -- to post again.'), $userId ?? osc_logged_user_id(), $item` | `oc-includes/osclass/helpers/hBilling.php` |
| `billing_order_paid` | action | `$order->getId(), $order->getUserId(), $order->getCredits()` | `oc-includes/osclass/classes/billing/Billing.php` |
| `billing_order_refunded` | action | `$order->getId(), $order->getUserId(), $order->getCredits()` | `oc-includes/osclass/classes/billing/Billing.php` |
| `body_class` | filter | `$classes, $class` | `oc-includes/osclass/helpers/hTheme.php` |
| `cache_relevant_cookies` | filter | `array_values(array_unique(array( session_name() ?: 'osclass', 'osclass', 'oc_cache_bypass', 'oc_userLocale', )))` | `oc-includes/osclass/helpers/hHttpCache.php` |
| `change_email_confirm` | action | `Params::getParam('userId'), $change['old'], $change['new']` | `oc-includes/osclass/classes/controller/CWebUserNonSecure.php` |
| `cli_commands` | filter | `array()` | `oc-includes/osclass/classes/cli/Cli.php` |
| `contact_form` | action | none | `oc-includes/osclass/gui/contact-content.php` |
| `contact_form_after` | action | none | `oc-includes/osclass/gui/contact-content.php` |
| `contact_form_top` | action | none | `oc-includes/osclass/gui/contact-content.php` |
| `contact_page_aside` | action | none | `oc-includes/osclass/gui/contact-content.php` |
| `contact_params` | filter | `$params` | `oc-includes/osclass/classes/controller/CWebContact.php` |
| `correct_login_url_redirect` | filter | `$url_redirect` | `oc-includes/osclass/classes/controller/CWebLogin.php` |
| `count_view_on_beacon` | filter | `osc_request_counts_as_view(), $id` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `count_view_on_render` | filter | `osc_request_counts_as_view(), $item` | `oc-includes/osclass/classes/controller/CWebItem.php` |
| `cron` | action | none | `oc-includes/osclass/cron.php` |
| `cron_daily` | action | none | `oc-includes/osclass/cron.php` |
| `cron_hourly` | action | none | `oc-includes/osclass/cron.php` |
| `cron_weekly` | action | none | `oc-includes/osclass/cron.php` |
| `custom_appearance_title` | filter | `__('Appearance')` | `oc-admin/themes/modern/appearance/view.php` |
| `custom_controller` | action | none | `oc-includes/osclass/classes/controller/CWebCustom.php` |
| `custom_field_autocomplete_source` | filter | `$results, $fieldId, $term, $field` | `oc-includes/osclass/classes/controller/CWebAjax.php` |
| `custom_field_input_class` | filter | `$defaultInputClass, $field, $type, $search` | `oc-includes/osclass/classes/form/FieldForm.php` |
| `custom_query` | action | `$mSearch, $keyword, $value` | `oc-includes/osclass/helpers/hItems.php` |
| `d_file_included` | action | `$file, $replacement, $version, $message` | `oc-includes/osclass/classes/utility/Deprecate.php` |
| `d_function_run` | action | `$function, $replacement, $version` | `oc-includes/osclass/classes/utility/Deprecate.php` |
| `d_hook_run` | action | `$hook, $replacement, $version, $message` | `oc-includes/osclass/classes/utility/Deprecate.php` |
| `datatable_listing_class` | filter | `array(), $aRawRows[$key], $row` | `oc-admin/themes/modern/items/index.php` |
| `delete_locale` | action | `$locale` | `oc-includes/osclass/classes/model/OSCLocale.php` |
| `delete_resource` | action | `$resource` | `oc-includes/osclass/classes/listing/PhotoService.php` |
| `edit_page` | action | `$id` | `oc-includes/osclass/classes/controller/admin/CAdminPages.php` |
| `feed` | action | `$feed` | `oc-includes/osclass/classes/controller/CWebSearch.php` |
| `flash_message_text` | filter | `$message['msg']` | `oc-includes/osclass/helpers/hMessages.php` |
| `form_fields` | filter | `$fields, $form, $contextType, $contextId` | `oc-includes/osclass/helpers/hForms.php` |
| `form_render` | action | `$form, $contextType, $contextId` | `oc-includes/osclass/helpers/hForms.php` |
| `form_submit` | action | `$form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php` |
| `form_submit_veto` | filter | `'', $form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php` |
| `form_submitted` | action | `$submissionId, $form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php` |
| `form_validation_errors` | filter | `$result['errors'], $form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php` |
| `gettext` | filter | `$string` | `oc-includes/osclass/helpers/hTranslations.php` |
| `header` | action | none | `oc-includes/osclass/helpers/hTheme.php` |
| `help_box` | action | none | `oc-admin/themes/modern/parts/header.php` |
| `image_jpeg_quality` | filter | `$qualityPref` | `oc-includes/osclass/classes/ImageProcessing.php` |
| `image_max_pixels` | filter | `50000000` | `oc-includes/osclass/classes/ImageProcessing.php` |
| `image_png_compression` | filter | `6` | `oc-includes/osclass/classes/ImageProcessing.php` |
| `init` | action | none | `oc-includes/osclass/classes/controller/CWebApi.php` |
| `init_ajax` | action | none | `oc-includes/osclass/classes/controller/CWebAjax.php` |
| `init_billing` | action | none | `oc-includes/osclass/classes/controller/CWebBilling.php` |
| `init_billing_non_secure` | action | none | `oc-includes/osclass/classes/controller/CWebBillingNonSecure.php` |
| `init_contact` | action | none | `oc-includes/osclass/classes/controller/CWebContact.php` |
| `init_custom` | action | none | `oc-includes/osclass/classes/controller/CWebCustom.php` |
| `init_language` | action | none | `oc-includes/osclass/classes/controller/CWebLanguage.php` |
| `init_login` | action | none | `oc-includes/osclass/classes/controller/CWebLogin.php` |
| `init_main` | action | none | `oc-includes/osclass/classes/controller/CWebMain.php` |
| `init_page` | action | none | `oc-includes/osclass/classes/controller/CWebPage.php` |
| `init_register` | action | none | `oc-includes/osclass/classes/controller/CWebRegister.php` |
| `init_send_mail` | filter | `$mail, $params` | `oc-includes/osclass/utils.php` |
| `invalidate_locale_cache` | action | none | `oc-includes/osclass/helpers/hCache.php` |
| `job_gave_up` | action | `$type, $payload, $error, $id` | `oc-includes/osclass/classes/job/JobWorker.php` |
| `keyword_block_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsKeywordBlock.php` |
| `keyword_block_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/KeywordBlocksDataTable.php` |
| `language_attributes` | filter | `$attrs` | `oc-includes/osclass/helpers/hTheme.php` |
| `language_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminLanguages.php` |
| `listing_list_html` | filter | `null, (array) View::getInstance()->_get('items'), $context` | `oc-includes/osclass/gui/page-fn.php` |
| `listing_row_actions` | filter | `$rowActions, $rowItem, $rowContext` | `oc-includes/osclass/gui/account/parts/item-row.php` |
| `listing_row_badges` | filter | `$rowBadges, $rowItem, $rowContext` | `oc-includes/osclass/gui/account/parts/item-row.php` |
| `listing_row_meta` | filter | `$rowMeta, $rowItem, $rowContext` | `oc-includes/osclass/gui/account/parts/item-row.php` |
| `locations_json_url` | filter | `'https://geo.mindstellar.com/releases/latest.json'` | `oc-includes/osclass/helpers/hUtils.php` |
| `login_admin` | action | `$admin` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `login_admin_form` | action | none | `oc-admin/gui/login.php` |
| `login_admin_image` | filter | `osc_admin_base_url() . 'images/shopclass-logo.svg'` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `login_admin_title` | filter | `'Shopclass'` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `login_admin_url` | filter | `'https://github.com/mindstellar/shopclass/'` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php` |
| `logout` | action | none | `oc-includes/osclass/classes/controller/CWebMain.php` |
| `logout_admin` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminMain.php` |
| `logs_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/LogsDataTable.php` |
| `mail_from` | filter | `$from, $params` | `oc-includes/osclass/utils.php` |
| `mail_from_name` | filter | `$from_name, $params` | `oc-includes/osclass/utils.php` |
| `mail_layout` | filter | `$html !== '' ? $html : $body, $mail, $params` | `oc-includes/osclass/utils.php` |
| `mail_layout_vars` | filter | `array( 'body' => $body, 'subject' => (string) ($params['subject'] ?? ''), 'preheader' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', _osc_mail_text($body))), 0, 120), 'site_name' => osc_page_title(), 'site_url' => osc_base_url(), 'logo_url' => '', 'accent' => '#0b7269', 'footer' => sprintf(__('You received this e-mail from %s.'), osc_page_title()), ), $params` | `oc-includes/osclass/utils.php` |
| `market_allowed_package_hosts` | filter | `$defaultHosts` | `oc-includes/osclass/classes/utility/FileSystem.php` |
| `market_catalog_mirror_base` | filter | `$default, $this->type` | `oc-includes/osclass/classes/market/Catalog.php` |
| `market_catalog_primary_base` | filter | `$default, $this->type` | `oc-includes/osclass/classes/market/Catalog.php` |
| `media_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/MediaDataTable.php` |
| `meta_description_filter` | filter | `$text` | `oc-includes/osclass/functions.php` |
| `meta_keywords_filter` | filter | `$text` | `oc-includes/osclass/functions.php` |
| `meta_title_filter` | filter | `$text` | `oc-includes/osclass/functions.php` |
| `mo_core_messages_path` | filter | `osc_translations_path() . $locale . '/messages.mo', $locale` | `oc-includes/osclass/classes/Translation.php` |
| `mo_core_path` | filter | `osc_translations_path() . $locale . '/core.mo', $locale` | `oc-includes/osclass/classes/Translation.php` |
| `moderator_access` | filter | `self::DEFAULT_PAGES` | `oc-includes/osclass/classes/admin/ModeratorAccess.php` |
| `more_actions_manage_rules` | filter | `$options_more, $aRow` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php` |
| `ngettext` | filter | `$string` | `oc-includes/osclass/helpers/hTranslations.php` |
| `non_remember_login_ttl` | filter | `2 * 3600` | `oc-includes/osclass/helpers/hUsers.php` |
| `osclass_upgrade_package` | filter | `$package_info` | `oc-includes/osclass/classes/upgrade/Osclass.php` |
| `page_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminPages.php` |
| `page_cache_purge` | action | `$reasons` | `oc-includes/osclass/helpers/hHttpCache.php` |
| `page_cache_purge_enabled` | filter | `true` | `oc-includes/osclass/helpers/hHttpCache.php` |
| `page_meta` | action | none | `oc-admin/themes/modern/pages/frm.php` |
| `page_routes` | filter | `$core` | `oc-includes/osclass/classes/routing/PageDispatcher.php` |
| `page_templates` | filter | `WebThemes::getInstance()->getAvailableTemplates()` | `oc-includes/osclass/classes/controller/admin/CAdminPages.php` |
| `pages_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/PagesDataTable.php` |
| `phpmailer_smtp_timeout` | filter | `15` | `oc-includes/osclass/utils.php` |
| `pre_contact_post` | action | `$params` | `oc-includes/osclass/classes/controller/CWebContact.php` |
| `pre_send_mail` | filter | `$mail, $params` | `oc-includes/osclass/utils.php` |
| `public_cache_max_age` | filter | `30` | `oc-includes/osclass/helpers/hHttpCache.php` |
| `regenerate_image` | action | `$resource` | `oc-includes/osclass/classes/listing/PhotoService.php` |
| `regenerated_image` | action | `\ItemResource::getInstance()->findByPrimaryKey($resource['pk_i_id'])` | `oc-includes/osclass/classes/listing/PhotoService.php` |
| `register_email_taken` | action | `$input['s_email']` | `oc-includes/osclass/classes/user/AccountService.php` |
| `register_jobs` | action | none | `oc-includes/osclass/classes/job/JobWorker.php` |
| `register_storage_adapters` | action | `StorageManager::getInstance()` | `oc-includes/osclass/helpers/hStorage.php` |
| `render_admintoolbar` | action | none | `oc-includes/osclass/classes/AdminToolbar.php` |
| `resource_alt` | filter | `$title, osc_resource()` | `oc-includes/osclass/helpers/hItems.php` |
| `resource_download_filename` | filter | `$name, $resource, $variant` | `oc-includes/osclass/helpers/hItems.php` |
| `resource_download_url` | filter | `$url, $resource, $variant` | `oc-includes/osclass/helpers/hItems.php` |
| `resource_original_url` | filter | `osc_resource_path() . osc_resource_id() . '_original.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php` |
| `resource_path` | filter | `$path, $resource` | `oc-includes/osclass/classes/admin/ui/PhotoGrid.php` |
| `resource_preview_url` | filter | `osc_resource_path() . osc_resource_id() . '_preview.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php` |
| `resource_thumbnail_url` | filter | `osc_resource_path() . osc_resource_id() . '_thumbnail.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php` |
| `resource_url` | filter | `osc_resource_path() . osc_resource_id() . '.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php` |
| `response_body` | filter | `$data` | `oc-includes/osclass/classes/security/Csrf.php` |
| `response_cache_control` | filter | `$header` | `oc-includes/osclass/helpers/hHttpCache.php` |
| `response_is_cacheable` | filter | `true` | `oc-includes/osclass/helpers/hHttpCache.php` |
| `rules_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php` |
| `sanitize_html_allowed` | filter | `implode(',', [ 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a[href\|title\|rel]', 'h3', 'h4', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span[style]', 'img[src\|alt\|width\|height]', ])` | `oc-includes/osclass/classes/utility/Sanitize.php` |
| `scripts_defer` | filter | `defined('OC_ADMIN') && OC_ADMIN` | `oc-includes/osclass/classes/Scripts.php` |
| `scripts_loaded` | action | none | `oc-includes/osclass/helpers/hTheme.php` |
| `settings_page_after_group` | action | `$page['id'], $group, $index` | `oc-includes/osclass/classes/admin/ui/SettingsForm.php` |
| `settings_page_saved` | action | `$pageId, $exposed` | `oc-includes/osclass/helpers/hSettings.php` |
| `shutdown_functions` | filter | `[$injectCsrf]` | `oc-includes/osclass/classes/security/Csrf.php` |
| `slug` | filter | `trim($fieldsDescription['s_slug'])` | `oc-includes/osclass/classes/model/Category.php` |
| `static_page_text` | filter | `osc_static_page_field('s_text', $locale), $locale` | `oc-includes/osclass/helpers/hPage.php` |
| `style_url` | filter | `$css` | `oc-includes/osclass/classes/Styles.php` |
| `template_candidates` | filter | `$candidates, $context` | `oc-includes/osclass/helpers/hTheme.php` |
| `tinymce_config` | filter | `$config, $preset` | `oc-includes/osclass/helpers/hUtils.php` |
| `upload_image_extension` | filter | `$imgres->getExt()` | `oc-includes/osclass/classes/listing/PhotoService.php` |
| `upload_image_mime` | filter | `$imgres->getMime()` | `oc-includes/osclass/classes/listing/PhotoService.php` |
| `uploaded_file` | action | `$stored` | `oc-includes/osclass/classes/listing/PhotoService.php` |
| `uploaded_resource` | action | `$row` | `oc-includes/osclass/classes/storage/ResourceUploader.php` |
| `watermark_font_path` | filter | `LIB_PATH . 'assets/fonts/open-sans/OpenSans-Regular.ttf'` | `oc-includes/osclass/classes/ImageProcessing.php` |
| `watermark_font_size` | filter | `30` | `oc-includes/osclass/classes/ImageProcessing.php` |
| `watermark_text_value` | filter | `$watermark_text` | `oc-includes/osclass/classes/ImageProcessing.php` |
| `widget_locations` | filter | `$locations` | `oc-includes/osclass/helpers/hWidgets.php` |

### Plugin (12)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `after_plugin_activate` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `after_plugin_deactivate` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `after_plugin_install` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `after_plugin_uninstall` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `before_plugin_activate` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `before_plugin_deactivate` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `before_plugin_install` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `before_plugin_uninstall` | action | none | `oc-includes/osclass/classes/Plugins.php` |
| `custom_plugin_title` | filter | `__('Plugins')` | `oc-admin/themes/modern/plugins/configuration.php` |
| `mo_plugin_path` | filter | `osc_plugins_path() . $domain . '/languages/' . $locale . '/messages.mo', $locale, $domain` | `oc-includes/osclass/classes/Translation.php` |
| `plugin_icon_url` | filter | `$url, $plugin` | `oc-includes/osclass/helpers/hPlugins.php` |
| `renderplugin_controller` | action | none | `oc-includes/osclass/classes/controller/admin/CAdminPlugins.php` |

### Search (10)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `after_search` | action | none | `oc-includes/osclass/classes/controller/CWebSearch.php` |
| `alert_search_params` | filter | `$params, $request` | `oc-includes/osclass/classes/search/AlertEnvelope.php` |
| `before_search` | action | none | `oc-includes/osclass/classes/controller/CWebSearch.php` |
| `save_latest_searches_pattern` | filter | `$p_sPattern` | `oc-includes/osclass/classes/controller/CWebSearch.php` |
| `search` | action | `$this->mSearch` | `oc-includes/osclass/classes/controller/CWebSearch.php` |
| `search_conditions` | action | `\Params::getParamsAsArray(), $search, $context` | `oc-includes/osclass/classes/search/SearchBuilder.php` |
| `search_pattern` | filter | `trim(strip_tags($params['sPattern'] ?? ''))` | `oc-includes/osclass/classes/search/SearchCriteria.php` |
| `search_results` | filter | `null, $search, \Params::getParamsAsArray()` | `oc-includes/osclass/classes/search/SearchRunner.php` |
| `sql_search_conditions` | filter | `$this->conditions` | `oc-includes/osclass/classes/search/query/PluginClauses.php` |
| `sql_search_fields` | filter | `$this->fields` | `oc-includes/osclass/classes/search/query/PluginClauses.php` |

### Theme (9)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `after_init_web_theme` | action | none | `oc-includes/osclass/classes/theme/WebThemes.php` |
| `api_theme_functions_enabled` | filter | `false` | `oc-includes/osclass/classes/theme/WebThemes.php` |
| `before_init_web_theme` | action | none | `oc-includes/osclass/classes/theme/WebThemes.php` |
| `mo_theme_messages_path` | filter | `osc_themes_path() . $domain . '/languages/' . $locale . '/messages.mo', $locale, $domain` | `oc-includes/osclass/classes/Translation.php` |
| `mo_theme_path` | filter | `osc_themes_path() . $domain . '/languages/' . $locale . '/theme.mo', $locale, $domain` | `oc-includes/osclass/classes/Translation.php` |
| `theme` | filter | `osc_theme()` | `oc-includes/osclass/classes/Translation.php` |
| `theme_activate` | action | `$theme` | `oc-includes/osclass/classes/cli/Cli.php` |
| `theme_screenshot_url` | filter | `$url, $theme` | `oc-includes/osclass/helpers/hTheme.php` |
| `theme_url` | filter | `$script` | `oc-includes/osclass/classes/Scripts.php` |

### User (44)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `actions_manage_users` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/UsersDataTable.php` |
| `activate_user` | action | `$user` | `oc-includes/osclass/classes/user/AccountService.php` |
| `after_delete_user` | action | `$id` | `oc-includes/osclass/classes/model/User.php` |
| `after_username_change` | action | `Session::getInstance()->_get('userId'), Params::getParam('s_username')` | `oc-includes/osclass/classes/controller/CWebUser.php` |
| `api_user` | filter | `$data, $context` | `oc-includes/osclass/classes/api/serializer/UserSerializer.php` |
| `before_user_delete` | action | `$user` | `oc-includes/osclass/classes/user/AccountService.php` |
| `before_user_recover` | action | none | `oc-includes/osclass/classes/controller/CWebLogin.php` |
| `before_user_register` | action | none | `oc-includes/osclass/classes/user/AccountService.php` |
| `before_username_change` | action | `Session::getInstance()->_get('userId'), $username` | `oc-includes/osclass/classes/controller/CWebUser.php` |
| `bot_user_agents` | filter | `array( // Generic: catches the long tail, which is most of it. 'bot', 'crawler', 'crawling', 'spider', 'scraper', 'archiver', 'fetcher', // Search engines. 'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandex', 'sogou', 'exabot', 'seznambot', 'petalbot', 'applebot', 'qwantify', // AI and dataset collectors. 'gptbot', 'oai-searchbot', 'chatgpt-user', 'ccbot', 'claudebot', 'claude-web', 'anthropic-ai', 'perplexitybot', 'google-extended', 'bytespider', 'amazonbot', 'meta-externalagent', 'diffbot', // SEO and marketing crawlers. 'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'blexbot', 'dataforseo', 'screaming frog', 'serpstat', 'megaindex', // Monitoring, previews and libraries. 'uptimerobot', 'pingdom', 'statuscake', 'facebookexternalhit', 'telegrambot', 'whatsapp', 'slackbot', 'discordbot', 'embedly', 'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client', 'java/', 'okhttp', 'libwww-perl', 'headlesschrome', 'phantomjs', )` | `oc-includes/osclass/helpers/hUtils.php` |
| `datatable_user_class` | filter | `array(), $aRawRows[$key], $row` | `oc-admin/themes/modern/users/index.php` |
| `deactivate_user` | action | `$user` | `oc-includes/osclass/classes/user/AccountService.php` |
| `delete_user` | action | `$id` | `oc-includes/osclass/classes/model/User.php` |
| `disable_user` | action | `$user` | `oc-includes/osclass/classes/user/AccountService.php` |
| `enable_user` | action | `$user` | `oc-includes/osclass/classes/user/AccountService.php` |
| `init_user` | action | none | `oc-includes/osclass/classes/controller/CWebUser.php` |
| `init_user_non_secure` | action | none | `oc-includes/osclass/classes/controller/CWebUserNonSecure.php` |
| `manage_user_search_conditions` | action | `$dummy` | `oc-includes/osclass/classes/datatables/UsersDataTable.php` |
| `manage_user_search_with_filters` | filter | `$this->withFilters` | `oc-includes/osclass/classes/datatables/UsersDataTable.php` |
| `more_actions_manage_users` | filter | `$options_more, $aRow` | `oc-includes/osclass/classes/datatables/UsersDataTable.php` |
| `pre_user_post` | action | none | `oc-includes/osclass/classes/user/AccountService.php` |
| `user_add_flash_error` | filter | `$flash` | `oc-includes/osclass/classes/user/AccountService.php` |
| `user_avatar_form` | action | `$profileUser` | `oc-includes/osclass/gui/account/user-profile-content.php` |
| `user_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php` |
| `user_contact_form` | action | `$publicUser` | `oc-includes/osclass/gui/account/user-public-profile-content.php` |
| `user_contact_form_after` | action | `$publicUser` | `oc-includes/osclass/gui/account/user-public-profile-content.php` |
| `user_dashboard` | action | none | `oc-includes/osclass/gui/account/user-dashboard-content.php` |
| `user_edit_completed` | action | `$userId` | `oc-includes/osclass/classes/user/AccountService.php` |
| `user_edit_flash_error` | filter | `$flash, $userId` | `oc-includes/osclass/classes/user/AccountService.php` |
| `user_form` | action | `$user` | `oc-admin/themes/modern/users/frm.php` |
| `user_info` | filter | `$info, $userId, $locale` | `oc-includes/osclass/helpers/hUsers.php` |
| `user_login_form` | action | none | `oc-includes/osclass/gui/account/user-login-content.php` |
| `user_login_form_after` | action | none | `oc-includes/osclass/gui/account/user-login-content.php` |
| `user_menu` | action | none | `oc-includes/osclass/gui/account/nav.php` |
| `user_menu_filter` | filter | `$navItems` | `oc-includes/osclass/gui/account/nav.php` |
| `user_profile_form` | action | `$user` | `oc-admin/themes/modern/users/frm.php` |
| `user_profile_info` | filter | `$aInfo['s_info'], $aUser['pk_i_id'], $aInfo['fk_c_locale_code']` | `oc-includes/osclass/classes/controller/CWebUser.php` |
| `user_register_completed` | action | `$userId` | `oc-includes/osclass/classes/user/AccountService.php` |
| `user_register_failed` | action | `$codes` | `oc-includes/osclass/classes/user/AccountService.php` |
| `user_register_form` | action | none | `oc-admin/themes/modern/users/frm.php` |
| `user_register_form_after` | action | none | `oc-includes/osclass/gui/account/user-register-content.php` |
| `user_signout_all_after` | action | `$userId` | `oc-includes/osclass/classes/auth/SignOut.php` |
| `users_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/UsersDataTable.php` |
| `validate_user` | action | `$user` | `oc-includes/osclass/classes/controller/CWebRegister.php` |

<!-- /generated:hooks -->
