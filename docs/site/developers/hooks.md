---
title: Hooks and filters
description: How ShopClass hooks work — actions, filters, priority, the naming standard, and the full reference of every name core fires.
sidebar:
  order: 22
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
different argument shapes — the action's callbacks receive a filter's value as their
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
forever, because plugins on installs we cannot see depend on it — so some existing
names do not follow the rules below.

### Names

`{subject}_{event}`, lowercase, words joined by underscores.

- **Subject first.** `item_delete_before`, not `before_delete_item`.
- **Admin-only hooks start with `admin_`.** Public hooks carry no prefix.
- **Lifecycle words come last:** `_before` and `_after`.
- **A filter is named for what it returns**, not for the moment — `item_title`,
  `search_query`.
- **No names built at runtime.** `item_bulk_{$action}` cannot be documented, found by
  search, or pinned by a test. Fire one fixed name and pass the variable part as an
  argument.

### Arguments

- A filter's first argument is the value being filtered. Context follows.
- An action's arguments run most specific first: the entity, then its context.
- Pass ids and records, not rendered HTML — unless the hook exists to filter HTML.
- **Arguments are append-only.** Adding one to the end is safe. Reordering or removing
  one is not: a callback written for the old order keeps running and silently receives
  the wrong thing.

### Actions or filters

- An action returns nothing.
- A filter returns its value, always.
- One name is never both.

## Reference

Every name core fires, with where it is fired and what it passes.

<!-- generated:hooks -->

Core fires 509 names. Generated from the source; do not edit by hand.

### Admin (77)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `admin_alerts_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/AlertsDataTable.php:83` |
| `admin_base_url` | filter | `$path, $with_index` | `oc-includes/osclass/helpers/hDefines.php:83` |
| `admin_body_class` | filter | `array()` | `oc-admin/themes/modern/parts/header.php:30` |
| `admin_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php:215` |
| `admin_comments_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/CommentsDataTable.php:98` |
| `admin_contact_form` | action | — | `oc-includes/osclass/gui/contact-content.php:56` |
| `admin_content_footer` | action | — | `oc-admin/themes/modern/parts/footer.php:11` |
| `admin_date_format` | filter | `$format, $dateOnly` | `oc-includes/osclass/helpers/hUtils.php:374` |
| `admin_edit_completed` | action | `$id, $result['updated']` | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php:346` |
| `admin_favicons` | filter | `$favicons` | `oc-admin/themes/modern/functions.php:86` |
| `admin_footer` | action | — | `oc-admin/themes/modern/parts/footer.php:17` |
| `admin_forgot_form` | action | — | `oc-admin/gui/forgot_password.php:37` |
| `admin_forgot_password_form` | action | — | `oc-admin/gui/recover.php:30` |
| `admin_form_after_save` | action | `$pageId, $exposed, $savedId` | `oc-includes/osclass/helpers/hSettings.php:823` |
| `admin_form_before_save` | filter | `$exposed, $pageId` | `oc-includes/osclass/helpers/hSettings.php:763` |
| `admin_form_render_field` | filter | `$field, $pageId, $values` | `oc-includes/osclass/classes/admin/ui/SettingsForm.php:152` |
| `admin_form_save_failed` | action | `$pageId, $errors, array()` | `oc-includes/osclass/helpers/hSettings.php:611` |
| `admin_header` | action | — | `oc-admin/themes/modern/parts/header.php:28` |
| `admin_item_description` | filter | `$value, $item, $locale` | `oc-includes/osclass/classes/form/admin/Item.php:179` |
| `admin_item_title` | filter | `$value, $item, $locale` | `oc-includes/osclass/classes/form/admin/Item.php:148` |
| `admin_items_reported_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:692` |
| `admin_items_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:109` |
| `admin_keyword_block_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/KeywordBlocksDataTable.php:69` |
| `admin_locations_drawer_fields` | action | `$level, null` | `oc-admin/themes/modern/settings/locations/form.php:153` |
| `admin_locations_row_actions` | filter | `array('edit' => $edit), $level, $row` | `oc-admin/themes/modern/settings/locations/list.php:334` |
| `admin_login_footer` | action | — | `oc-admin/gui/main.php:64` |
| `admin_login_form` | action | — | `oc-admin/gui/login.php:61` |
| `admin_login_header` | action | — | `oc-admin/gui/main.php:25` |
| `admin_logs_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/LogsDataTable.php:80` |
| `admin_media_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/MediaDataTable.php:106` |
| `admin_menu_init` | action | — | `oc-includes/osclass/classes/AdminMenu.php:492` |
| `admin_page_description` | filter | `$description, $page, $locale` | `oc-includes/osclass/classes/form/PageForm.php:256` |
| `admin_page_header` | action | — | `oc-admin/themes/modern/parts/header.php:103` |
| `admin_page_title` | filter | `$title, $page, $locale` | `oc-includes/osclass/classes/form/PageForm.php:216` |
| `admin_pages_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/PagesDataTable.php:69` |
| `admin_post` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminPlugins.php:193` |
| `admin_profile_form` | action | `__get('admin')` | `oc-includes/osclass/classes/admin/form/AdminAccountForm.php:152` |
| `admin_rules_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php:85` |
| `admin_scripts_loaded` | action | — | `oc-includes/osclass/helpers/hTheme.php:984` |
| `admin_title` | filter | `osc_page_title() . ' - Shopclass'` | `oc-admin/themes/modern/parts/header.php:23` |
| `admin_user_profile_info` | filter | `$aInfo['s_info'], $aUser['pk_i_id'], $aInfo['fk_c_locale_code']` | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php:141` |
| `admin_users_table` | action | `$dummy` | `oc-includes/osclass/classes/datatables/UsersDataTable.php:106` |
| `init_admin` | action | — | `oc-includes/osclass/classes/controller/base/AdminSecBaseModel.php:47` |
| `init_admin_admins` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php:48` |
| `init_admin_billing` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminBilling.php:48` |
| `init_admin_categories` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminCategories.php:36` |
| `init_admin_comments` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:35` |
| `init_admin_emails` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminEmails.php:36` |
| `init_admin_fields` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminCFields.php:39` |
| `init_admin_insecure` | action | — | `oc-includes/osclass/classes/controller/base/AdminBaseModel.php:27` |
| `init_admin_items` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminItems.php:36` |
| `init_admin_languages` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminLanguages.php:36` |
| `init_admin_login` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php:30` |
| `init_admin_main` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminMain.php:30` |
| `init_admin_media` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminMedia.php:38` |
| `init_admin_pages` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminPages.php:36` |
| `init_admin_plugins` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminPlugins.php:33` |
| `init_admin_settings` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminSettings.php:29` |
| `init_admin_settings_advanced` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsAdvanced.php:33` |
| `init_admin_settings_billing` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsBilling.php:46` |
| `init_admin_settings_comments` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsComments.php:33` |
| `init_admin_settings_currencies` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCurrencies.php:30` |
| `init_admin_settings_custom` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCustom.php:33` |
| `init_admin_settings_keyword_block` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsKeywordBlock.php:38` |
| `init_admin_settings_latest` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsLatestSearches.php:33` |
| `init_admin_settings_locations` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsLocations.php:44` |
| `init_admin_settings_mail` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsMailserver.php:33` |
| `init_admin_settings_main` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsMain.php:29` |
| `init_admin_settings_media` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsMedia.php:33` |
| `init_admin_settings_permalinks` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsPermalinks.php:33` |
| `init_admin_settings_sitemap` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsSitemap.php:37` |
| `init_admin_settings_spam` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsSpamnBots.php:33` |
| `init_admin_settings_storage` | action | — | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsStorage.php:34` |
| `init_admin_stats` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminStats.php:33` |
| `init_admin_tools` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminTools.php:30` |
| `init_admin_upgrade` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminUpgrade.php:25` |
| `init_admin_users` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php:38` |

### Category (5)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `add_category` | action | `(int)($categoryId)` | `oc-includes/osclass/classes/controller/admin/CAdminCategories.php:74` |
| `after_delete_category` | action | `$pkInt` | `oc-includes/osclass/classes/model/Category.php:522` |
| `delete_category` | action | `$pkInt` | `oc-includes/osclass/classes/model/Category.php:493` |
| `edited_category` | action | `(int)($id), $error` | `oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php:782` |
| `edited_category_order` | action | `$error` | `oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php:287` |

### Comment (12)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `activate_comment` | action | `$_id` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:78` |
| `add_comment` | action | `$commentID` | `oc-includes/osclass/classes/actions/ItemActions.php:1986` |
| `before_add_comment` | action | `$aComment` | `oc-includes/osclass/classes/actions/ItemActions.php:1963` |
| `comment_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:319` |
| `comment_form` | action | — | `oc-includes/osclass/gui/item-comments-content.php:128` |
| `comments_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/CommentsDataTable.php:215` |
| `datatable_comment_class` | filter | `array(), $aRawRows[$key], $row` | `oc-admin/themes/modern/comments/index.php:75` |
| `deactivate_comment` | action | `$_id` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:88` |
| `delete_comment` | action | `$_id` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:65` |
| `disable_comment` | action | `$_id` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:111` |
| `edit_comment` | action | `Params::getParam('id')` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:212` |
| `enable_comment` | action | `$_id` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:101` |

### Email (93)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `email_admin_new_item_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php:1358` |
| `email_admin_new_item_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_admin_new_item_description', $content['s_text'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php:1353` |
| `email_admin_new_item_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php:1351` |
| `email_admin_new_item_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_admin_new_item_title', $content['s_title'], $item) ), $words), $item` | `oc-includes/osclass/emails.php:1349` |
| `email_admin_user_registration_title` | filter | `$content['s_title'], $user` | `oc-includes/osclass/emails.php:1545` |
| `email_admin_user_registration_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_admin_user_registration_title', $content['s_title'], $user) ), $words), $user` | `oc-includes/osclass/emails.php:1541` |
| `email_admin_user_regsitration_description` | filter | `$content['s_text'], $user` | `oc-includes/osclass/emails.php:1554` |
| `email_admin_user_regsitration_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_admin_user_regsitration_description', $content['s_text'], $user) ), $words ), $user` | `oc-includes/osclass/emails.php:1549` |
| `email_after_auto_upgrade_description` | filter | `$body, $result` | `oc-includes/osclass/emails.php:1972` |
| `email_after_auto_upgrade_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_after_auto_upgrade_description', $body, $result) ), $words ), $result` | `oc-includes/osclass/emails.php:1967` |
| `email_after_auto_upgrade_title` | filter | `$title, $result` | `oc-includes/osclass/emails.php:1965` |
| `email_after_auto_upgrade_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_after_auto_upgrade_title', $title, $result) ), $words), $result` | `oc-includes/osclass/emails.php:1963` |
| `email_alert_validation_description` | filter | `$page_description[$prefLocale]['s_text'], $alert, $email, $secret` | `oc-includes/osclass/emails.php:49` |
| `email_alert_validation_description_after` | filter | `osc_mailBeauty($_body, $words), $alert, $email, $secret` | `oc-includes/osclass/emails.php:80` |
| `email_alert_validation_title` | filter | `$page_description[$prefLocale]['s_title'], $alert, $email, $secret` | `oc-includes/osclass/emails.php:39` |
| `email_alert_validation_title_after` | filter | `osc_mailBeauty($_title, $words), $alert, $email, $secret` | `oc-includes/osclass/emails.php:73` |
| `email_comment_validated_description` | filter | `$content['s_text'], $aComment` | `oc-includes/osclass/emails.php:460` |
| `email_comment_validated_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_comment_validated_description', $content['s_text'], $aComment) ), $words), $aComment` | `oc-includes/osclass/emails.php:456` |
| `email_comment_validated_title` | filter | `$content['s_title'], $aComment` | `oc-includes/osclass/emails.php:452` |
| `email_comment_validated_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_comment_validated_title', $content['s_title'], $aComment) ), $words), $aComment` | `oc-includes/osclass/emails.php:448` |
| `email_description` | filter | `osc_apply_filter( 'email_alert_validation_description', $page_description[$prefLocale]['s_text'], $alert, $email, $secret )` | `oc-includes/osclass/emails.php:47` |
| `email_item_inquiry_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php:1003` |
| `email_item_inquiry_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_item_inquiry_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php:998` |
| `email_item_inquiry_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php:996` |
| `email_item_inquiry_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_item_inquiry_title', $content['s_title'], $aItem) ), $words), $aItem` | `oc-includes/osclass/emails.php:994` |
| `email_item_validation_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php:1241` |
| `email_item_validation_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_item_validation_description', $content['s_text'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php:1236` |
| `email_item_validation_non_register_user_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php:1490` |
| `email_item_validation_non_register_user_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_item_validation_non_register_user_description', $content['s_text'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php:1485` |
| `email_item_validation_non_register_user_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php:1479` |
| `email_item_validation_non_register_user_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_item_validation_non_register_user_title', $content['s_title'], $item) ), $words ), $item` | `oc-includes/osclass/emails.php:1474` |
| `email_item_validation_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php:1234` |
| `email_item_validation_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_item_validation_title', $content['s_title'], $item) ), $words), $item` | `oc-includes/osclass/emails.php:1232` |
| `email_legend_words` | filter | `$array, @$email['s_internal_name']` | `oc-includes/osclass/classes/EmailVariables.php:341` |
| `email_new_admin_description` | filter | `$content['s_text'], $data` | `oc-includes/osclass/emails.php:1820` |
| `email_new_admin_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_new_admin_description', $content['s_text'], $data) ), $words ), $data` | `oc-includes/osclass/emails.php:1815` |
| `email_new_admin_title` | filter | `$content['s_title'], $data` | `oc-includes/osclass/emails.php:1813` |
| `email_new_admin_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_new_admin_title', $content['s_title'], $data) ), $words), $data` | `oc-includes/osclass/emails.php:1811` |
| `email_new_comment_admin_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php:1127` |
| `email_new_comment_admin_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_new_comment_admin_description', $content['s_text'], $aItem) ), $words), $aItem` | `oc-includes/osclass/emails.php:1123` |
| `email_new_comment_admin_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php:1117` |
| `email_new_comment_admin_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_new_comment_admin_title', $content['s_title'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php:1112` |
| `email_new_comment_user_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php:1754` |
| `email_new_comment_user_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_new_comment_user_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php:1749` |
| `email_new_comment_user_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php:1743` |
| `email_new_comment_user_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_new_comment_user_title', $content['s_title'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php:1738` |
| `email_new_email_description` | filter | `$content['s_text'], $new_email, $validation_url` | `oc-includes/osclass/emails.php:754` |
| `email_new_email_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_new_email_description', $content['s_text'], $new_email, $validation_url) ), $words ), $new_email, $validation_url` | `oc-includes/osclass/emails.php:749` |
| `email_new_email_title` | filter | `$content['s_title'], $new_email, $validation_url` | `oc-includes/osclass/emails.php:744` |
| `email_new_email_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_new_email_title', $content['s_title'], $new_email, $validation_url) ), $words), $new_email, $validation_url` | `oc-includes/osclass/emails.php:740` |
| `email_new_item_non_register_user_description` | filter | `$content['s_text'], $item` | `oc-includes/osclass/emails.php:541` |
| `email_new_item_non_register_user_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_new_item_non_register_user_description', $content['s_text'], $item) ), $words), $item` | `oc-includes/osclass/emails.php:537` |
| `email_new_item_non_register_user_title` | filter | `$content['s_title'], $item` | `oc-includes/osclass/emails.php:533` |
| `email_new_item_non_register_user_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_new_item_non_register_user_title', $content['s_title'], $item) ), $words), $item` | `oc-includes/osclass/emails.php:529` |
| `email_send_friend_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php:906` |
| `email_send_friend_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_send_friend_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php:901` |
| `email_send_friend_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php:899` |
| `email_send_friend_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_send_friend_title', $content['s_title'], $aItem) ), $words), $aItem` | `oc-includes/osclass/emails.php:897` |
| `email_title` | filter | `osc_apply_filter( 'email_alert_validation_title', $page_description[$prefLocale]['s_title'], $alert, $email, $secret )` | `oc-includes/osclass/emails.php:37` |
| `email_user_forgot_pass_word_title` | filter | `$content['s_title'], $user, $password_url` | `oc-includes/osclass/emails.php:601` |
| `email_user_forgot_pass_word_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_user_forgot_pass_word_title', $content['s_title'], $user, $password_url) ), $words ), $user, $password_url` | `oc-includes/osclass/emails.php:596` |
| `email_user_forgot_password_description` | filter | `$content['s_text'], $user, $password_url` | `oc-includes/osclass/emails.php:613` |
| `email_user_forgot_password_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter( 'email_user_forgot_password_description', $content['s_text'], $user, $password_url ) ), $words ), $user, $password_url` | `oc-includes/osclass/emails.php:608` |
| `email_user_registration_description` | filter | `$content['s_text'], $user` | `oc-includes/osclass/emails.php:686` |
| `email_user_registration_description_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_description', osc_apply_filter('email_user_registration_description', $content['s_text'], $user) ), $words), $user` | `oc-includes/osclass/emails.php:682` |
| `email_user_registration_title` | filter | `$content['s_title'], $user` | `oc-includes/osclass/emails.php:676` |
| `email_user_registration_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_user_registration_title', $content['s_title'], $user) ), $words ), $user` | `oc-includes/osclass/emails.php:671` |
| `email_user_validation_description` | filter | `$content['s_text'], $user, $input` | `oc-includes/osclass/emails.php:831` |
| `email_user_validation_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_user_validation_description', $content['s_text'], $user, $input) ), $words ), $user, $input` | `oc-includes/osclass/emails.php:826` |
| `email_user_validation_title` | filter | `$content['s_title'], $user, $input` | `oc-includes/osclass/emails.php:821` |
| `email_user_validation_title_after` | filter | `osc_mailBeauty(osc_apply_filter( 'email_title', osc_apply_filter('email_user_validation_title', $content['s_title'], $user, $input) ), $words), $user, $input` | `oc-includes/osclass/emails.php:817` |
| `email_warn_expiration_description` | filter | `$content['s_text'], $aItem` | `oc-includes/osclass/emails.php:1907` |
| `email_warn_expiration_description_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_description', osc_apply_filter('email_warn_expiration_description', $content['s_text'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php:1902` |
| `email_warn_expiration_title` | filter | `$content['s_title'], $aItem` | `oc-includes/osclass/emails.php:1896` |
| `email_warn_expiration_title_after` | filter | `osc_mailBeauty( osc_apply_filter( 'email_title', osc_apply_filter('email_warn_expiration_title', $content['s_title'], $aItem) ), $words ), $aItem` | `oc-includes/osclass/emails.php:1891` |
| `hook_email_admin_new_item` | action | `$item` | `oc-includes/osclass/classes/actions/ItemActions.php:1075` |
| `hook_email_admin_new_user` | action | `$user` | `oc-includes/osclass/classes/actions/UserActions.php:199` |
| `hook_email_alert_validation` | action | `$aAlert, $email, $secret` | `oc-includes/osclass/classes/controller/CWebAjax.php:270` |
| `hook_email_comment_validated` | action | `$aComment` | `oc-includes/osclass/classes/controller/admin/CAdminItemComments.php:342` |
| `hook_email_contact_user` | action | `Params::getParam('id'), Params::getParam('yourEmail'), Params::getParam('yourName'), Params::getParam('phoneNumber'), Params::getParam('message')` | `oc-includes/osclass/classes/controller/CWebUserNonSecure.php:248` |
| `hook_email_item_inquiry` | action | `$aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:1849` |
| `hook_email_item_validation` | action | `$item` | `oc-includes/osclass/classes/actions/ItemActions.php:1066` |
| `hook_email_item_validation_non_register_user` | action | `$item` | `oc-includes/osclass/classes/actions/ItemActions.php:1064` |
| `hook_email_new_admin` | action | `array( 's_name' => $result['values']['s_name'], 's_username' => $result['values']['s_username'], 's_password' => $result['values']['s_password'], 's_email' => $result['values']['s_email'], )` | `oc-includes/osclass/classes/controller/admin/CAdminAdmins.php:334` |
| `hook_email_new_comment_admin` | action | `$aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:1983` |
| `hook_email_new_comment_user` | action | `$aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:1977` |
| `hook_email_new_email` | action | `Params::getParam('new_email'), $validation_url` | `oc-includes/osclass/classes/controller/CWebUser.php:161` |
| `hook_email_new_item_non_register_user` | action | `$item` | `oc-includes/osclass/classes/actions/ItemActions.php:1068` |
| `hook_email_send_friend` | action | `$aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:1748` |
| `hook_email_user_forgot_password` | action | `$user, $password_url` | `oc-includes/osclass/classes/actions/UserActions.php:516` |
| `hook_email_user_registration` | action | `$user` | `oc-includes/osclass/classes/controller/CWebRegister.php:123` |
| `hook_email_user_validation` | action | `$user, $input` | `oc-includes/osclass/classes/actions/UserActions.php:205` |
| `hook_email_warn_expiration` | action | `$item` | `oc-includes/osclass/cron.php:56` |

### Item (76)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `actions_manage_items` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:336` |
| `activate_item` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1429` |
| `after_delete_item` | action | `$itemId, $item` | `oc-includes/osclass/classes/actions/ItemActions.php:1659` |
| `before_delete_item` | action | `$itemId` | `oc-includes/osclass/classes/actions/ItemActions.php:1645` |
| `before_item_edit` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php:257` |
| `deactivate_item` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1461` |
| `delete_item` | action | `$id` | `oc-includes/osclass/classes/model/Item.php:1246` |
| `disable_item` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1124` |
| `edited_item` | action | `Item::newInstance()->findByPrimaryKey($aItem['idItem'])` | `oc-includes/osclass/classes/actions/ItemActions.php:1308` |
| `enable_item` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1490` |
| `filters_manage_item_search` | action | — | `oc-admin/themes/modern/items/index.php:284` |
| `init_item` | action | — | `oc-includes/osclass/classes/controller/CWebItem.php:43` |
| `invalidate_item_cache` | action | `$itemId` | `oc-includes/osclass/helpers/hCache.php:203` |
| `item_add_prepare_data` | filter | `$aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:181` |
| `item_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminItems.php:1049` |
| `item_comments_after` | action | — | `oc-includes/osclass/gui/item-comments-content.php:136` |
| `item_comments_before` | action | — | `oc-includes/osclass/gui/item-comments-content.php:36` |
| `item_contact_form` | action | — | `oc-includes/osclass/gui/item-contact-content.php:66` |
| `item_contact_throttle_max` | filter | `15` | `oc-includes/osclass/classes/controller/CWebItem.php:673` |
| `item_contact_throttle_window` | filter | `3600` | `oc-includes/osclass/classes/controller/CWebItem.php:674` |
| `item_content_updated` | action | `(int)$id, $locale` | `oc-includes/osclass/classes/model/Item.php:901` |
| `item_decrease_stat` | action | `$item` | `oc-includes/osclass/classes/actions/ItemActions.php:1153` |
| `item_description` | filter | `$v['s_description']` | `oc-includes/osclass/classes/controller/CWebItem.php:887` |
| `item_edit` | action | `$catId, $itemId` | `oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php:239` |
| `item_edit_prepare_data` | filter | `$aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:1164` |
| `item_expiration_updated` | action | `(int)$id, $_item['dt_expiration']` | `oc-includes/osclass/classes/model/Item.php:984` |
| `item_form` | action | `Params::getParam('catId')` | `oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php:234` |
| `item_form_new_validation_messages` | action | — | `oc-includes/osclass/classes/form/ItemForm.php:1118` |
| `item_form_new_validation_rules` | action | — | `oc-includes/osclass/classes/form/ItemForm.php:1096` |
| `item_form_validation_messages` | action | — | `oc-includes/osclass/classes/form/ItemForm.php:1350` |
| `item_form_validation_rules` | action | — | `oc-includes/osclass/classes/form/ItemForm.php:1322` |
| `item_increase_stat` | action | `$item` | `oc-includes/osclass/classes/actions/ItemActions.php:1104` |
| `item_mark` | filter | `true, $id, $as` | `oc-includes/osclass/classes/actions/ItemActions.php:1701` |
| `item_marked` | action | `$id, $as` | `oc-includes/osclass/classes/actions/ItemActions.php:1705` |
| `item_meta_checkbox_value` | filter | `osc_esc_html($label), $checked, $meta` | `oc-includes/osclass/helpers/hItems.php:1687` |
| `item_post_redirect_url` | filter | `osc_search_category_url(), $itemId, $category` | `oc-includes/osclass/classes/controller/CWebItem.php:227` |
| `item_premium_off` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1569` |
| `item_premium_on` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1567` |
| `item_prepare_data` | filter | `$aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:2274` |
| `item_price` | filter | `$currencyFormat` | `oc-includes/osclass/helpers/hItems.php:1557` |
| `item_price_null` | filter | `__('Check with seller')` | `oc-includes/osclass/helpers/hItems.php:1529` |
| `item_price_zero` | filter | `__('Free')` | `oc-includes/osclass/helpers/hItems.php:1532` |
| `item_spam_off` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1607` |
| `item_spam_on` | action | `$id` | `oc-includes/osclass/classes/actions/ItemActions.php:1605` |
| `item_title` | filter | `$v['s_title']` | `oc-includes/osclass/classes/controller/CWebItem.php:883` |
| `item_view_beacon_enabled` | filter | `$enabled` | `oc-includes/osclass/helpers/hViews.php:36` |
| `items_bulk_enabled_by_category` | action | `$aIds, $enable` | `oc-includes/osclass/classes/model/Item.php:1050` |
| `items_processing_reported_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:781` |
| `items_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:401` |
| `manage_item_search_conditions` | action | `$this->mSearch` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:56` |
| `manage_item_search_with_filters` | filter | `$this->withFilters` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:864` |
| `more_actions_manage_items` | filter | `$options_more, $aRow` | `oc-includes/osclass/classes/datatables/ItemsDataTable.php:324` |
| `osc_item_edit_meta_textarea_value_filter` | filter | `$value, $field` | `oc-includes/osclass/classes/form/FieldForm.php:459` |
| `osc_item_meta_textarea_value_filter` | filter | `$value, $meta` | `oc-includes/osclass/helpers/hItems.php:1708` |
| `osc_item_meta_value_filter` | filter | `$value, $meta` | `oc-includes/osclass/helpers/hItems.php:1715` |
| `osc_item_meta_value_pre_filter` | filter | `$value, $meta` | `oc-includes/osclass/helpers/hItems.php:1653` |
| `post_item` | action | — | `oc-includes/osclass/classes/controller/CWebItem.php:138` |
| `post_item_contact_post` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php:687` |
| `post_item_send_friend_post` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php:587` |
| `posted_item` | action | `$item` | `oc-includes/osclass/classes/actions/ItemActions.php:411` |
| `pre_item_add` | action | `$aItem, $flash_error` | `oc-includes/osclass/classes/actions/ItemActions.php:241` |
| `pre_item_add_comment_post` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php:714` |
| `pre_item_add_error` | filter | `$flash_error, $aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:242` |
| `pre_item_contact_post` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php:682` |
| `pre_item_delete_comment_post` | action | `$item, $commentId` | `oc-includes/osclass/classes/controller/CWebItem.php:764` |
| `pre_item_edit` | action | `$aItem, $flash_error` | `oc-includes/osclass/classes/actions/ItemActions.php:1187` |
| `pre_item_edit_error` | filter | `$flash_error, $aItem` | `oc-includes/osclass/classes/actions/ItemActions.php:1188` |
| `pre_item_send_friend_post` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php:582` |
| `pre_show_item` | filter | `$this->itemManager->findByPrimaryKey($id)` | `oc-includes/osclass/classes/controller/CWebItem.php:818` |
| `pre_show_items` | filter | `$aItems` | `oc-includes/osclass/classes/controller/CWebSearch.php:575` |
| `rss_feed_item` | filter | `$itemArray, osc_item()` | `oc-includes/osclass/classes/controller/CWebSearch.php:735` |
| `show_item` | action | `$item` | `oc-includes/osclass/classes/controller/CWebItem.php:898` |
| `sitemap_items_source` | filter | `$default, array('page' => $page, 'per_page' => $perPage)` | `oc-includes/osclass/classes/Sitemap.php:242` |
| `sitemap_items_total` | filter | `$this->countLiveItems()` | `oc-includes/osclass/classes/Sitemap.php:191` |
| `sitemap_url_entry` | filter | `array('loc' => $loc, 'lastmod' => $lastmod, 'changefreq' => $changefreq), $type` | `oc-includes/osclass/classes/Sitemap.php:877` |
| `sql_search_item_conditions` | filter | `$this->itemConditions` | `oc-includes/osclass/classes/model/Search.php:755` |

### Other (181)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `actions_manage_alerts` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/AlertsDataTable.php:151` |
| `actions_manage_keyword_block` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/KeywordBlocksDataTable.php:144` |
| `actions_manage_rules` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php:161` |
| `add_admin_toolbar_menus` | action | — | `oc-includes/osclass/functions.php:605` |
| `after_admin_html` | action | — | `oc-includes/osclass/classes/controller/base/AdminSecBaseModel.php:179` |
| `after_delete_city` | action | `$pk` | `oc-includes/osclass/classes/model/City.php:226` |
| `after_delete_city_area` | action | `$pk` | `oc-includes/osclass/classes/model/CityArea.php:131` |
| `after_delete_country` | action | `$pk` | `oc-includes/osclass/classes/model/Country.php:143` |
| `after_delete_field` | action | `$id` | `oc-includes/osclass/classes/model/Field.php:163` |
| `after_delete_field_group` | action | `$id` | `oc-includes/osclass/classes/model/FieldGroup.php:210` |
| `after_delete_form_submission` | action | `$id` | `oc-includes/osclass/classes/model/FormSubmission.php:293` |
| `after_delete_page` | action | `$id` | `oc-includes/osclass/classes/model/Page.php:265` |
| `after_delete_region` | action | `$pk` | `oc-includes/osclass/classes/model/Region.php:209` |
| `after_delete_widget` | action | `$widgetId` | `oc-includes/osclass/classes/controller/admin/CAdminAppearance.php:135` |
| `after_html` | action | — | `oc-includes/osclass/classes/controller/CWebCustom.php:114` |
| `after_login` | action | `$user, $url_redirect` | `oc-includes/osclass/classes/controller/CWebLogin.php:193` |
| `after_rewrite_rules` | action | `array(&$rewrite)` | `oc-includes/osclass/classes/Rewrite.php:464` |
| `after_show_pagination_admin` | action | — | `oc-includes/osclass/helpers/hPagination.php:192` |
| `alert_email_daily_description` | filter | `$page_description[$prefLocale]['s_text'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:235` |
| `alert_email_daily_description_after` | filter | `osc_mailBeauty($_body, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:282` |
| `alert_email_daily_title` | filter | `$page_description[$prefLocale]['s_title'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:223` |
| `alert_email_daily_title_after` | filter | `osc_mailBeauty($_title, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:273` |
| `alert_email_hourly_description` | filter | `$page_description[$prefLocale]['s_text'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:133` |
| `alert_email_hourly_description_after` | filter | `osc_mailBeauty($_body, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:180` |
| `alert_email_hourly_title` | filter | `$page_description[$prefLocale]['s_title'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:121` |
| `alert_email_hourly_title_after` | filter | `osc_mailBeauty($_title, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:171` |
| `alert_email_weekly_description` | filter | `$page_description[$prefLocale]['s_text'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:337` |
| `alert_email_weekly_description_after` | filter | `osc_mailBeauty($_body, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:384` |
| `alert_email_weekly_title` | filter | `$page_description[$prefLocale]['s_title'], $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:325` |
| `alert_email_weekly_title_after` | filter | `osc_mailBeauty($_title, $words), $user, $ads, $s_search, $items, $totalItems` | `oc-includes/osclass/emails.php:375` |
| `alerts_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/AlertsDataTable.php:192` |
| `ban_rule_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php:579` |
| `base_url` | filter | `$path, $with_index` | `oc-includes/osclass/helpers/hDefines.php:38` |
| `before_admin_html` | action | — | `oc-includes/osclass/classes/controller/base/AdminSecBaseModel.php:176` |
| `before_delete_city` | action | `$pk` | `oc-includes/osclass/classes/model/City.php:195` |
| `before_delete_city_area` | action | `$pk` | `oc-includes/osclass/classes/model/CityArea.php:120` |
| `before_delete_country` | action | `$pk` | `oc-includes/osclass/classes/model/Country.php:122` |
| `before_delete_field` | action | `$id` | `oc-includes/osclass/classes/model/Field.php:134` |
| `before_delete_field_group` | action | `$id` | `oc-includes/osclass/classes/model/FieldGroup.php:184` |
| `before_delete_form_submission` | action | `$id` | `oc-includes/osclass/classes/model/FormSubmission.php:285` |
| `before_delete_page` | action | `$id` | `oc-includes/osclass/classes/model/Page.php:241` |
| `before_delete_region` | action | `$pk` | `oc-includes/osclass/classes/model/Region.php:176` |
| `before_delete_widget` | action | `$widgetId` | `oc-includes/osclass/classes/controller/admin/CAdminAppearance.php:131` |
| `before_html` | action | — | `oc-includes/osclass/classes/controller/CWebCustom.php:109` |
| `before_login` | action | — | `oc-includes/osclass/classes/controller/CWebLogin.php:133` |
| `before_login_admin` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php:46` |
| `before_rewrite_rules` | action | `array(&$rewrite)` | `oc-includes/osclass/classes/Rewrite.php:146` |
| `before_show_pagination_admin` | action | — | `oc-includes/osclass/helpers/hPagination.php:168` |
| `before_validating_login` | action | — | `oc-includes/osclass/classes/controller/CWebLogin.php:50` |
| `billing_can_publish` | filter | `$allowed, $userId, $ctx` | `oc-includes/osclass/classes/billing/Entitlements.php:402` |
| `billing_credits_changed` | action | `$userId, $delta, $reason` | `oc-includes/osclass/classes/billing/Wallet.php:366` |
| `billing_feature_applied` | action | `$featureId, $userId, $price` | `oc-includes/osclass/classes/billing/Billing.php:317` |
| `billing_feature_duration` | filter | `$days, $this->id, $userId` | `oc-includes/osclass/classes/billing/Feature.php:146` |
| `billing_feature_price` | filter | `$price, $this->id, $userId` | `oc-includes/osclass/classes/billing/Feature.php:129` |
| `billing_listing_limit_message` | filter | `_m('You are at your listing limit. Free up a listing -- delete one or let one expire -- to post again.'), $userId ?? osc_logged_user_id(), $item` | `oc-includes/osclass/helpers/hBilling.php:557` |
| `billing_order_paid` | action | `$order->getId(), $order->getUserId(), $order->getCredits()` | `oc-includes/osclass/classes/billing/Billing.php:190` |
| `billing_order_refunded` | action | `$order->getId(), $order->getUserId(), $order->getCredits()` | `oc-includes/osclass/classes/billing/Billing.php:230` |
| `body_class` | filter | `$classes, $class` | `oc-includes/osclass/helpers/hTheme.php:668` |
| `cache_relevant_cookies` | filter | `array_values(array_unique(array( session_name() ?: 'osclass', 'osclass', 'oc_cache_bypass', 'oc_userLocale', )))` | `oc-includes/osclass/helpers/hHttpCache.php:66` |
| `change_email_confirm` | action | `Params::getParam('userId'), $userOldEmail, $userEmailTmp['s_new_email']` | `oc-includes/osclass/classes/controller/CWebUserNonSecure.php:87` |
| `contact_form` | action | — | `oc-includes/osclass/gui/contact-content.php:55` |
| `contact_params` | filter | `$params` | `oc-includes/osclass/classes/controller/CWebContact.php:166` |
| `correct_login_url_redirect` | filter | `$url_redirect` | `oc-includes/osclass/classes/controller/CWebLogin.php:195` |
| `count_view_on_beacon` | filter | `osc_request_counts_as_view(), $id` | `oc-includes/osclass/classes/controller/CWebItem.php:969` |
| `count_view_on_render` | filter | `osc_request_counts_as_view(), $item` | `oc-includes/osclass/classes/controller/CWebItem.php:868` |
| `cron` | action | — | `oc-includes/osclass/cron.php:172` |
| `cron_daily` | action | — | `oc-includes/osclass/cron.php:144` |
| `cron_hourly` | action | — | `oc-includes/osclass/cron.php:75` |
| `cron_weekly` | action | — | `oc-includes/osclass/cron.php:168` |
| `custom_appearance_title` | filter | `__('Appearance')` | `oc-admin/themes/modern/appearance/view.php:18` |
| `custom_controller` | action | — | `oc-includes/osclass/classes/controller/CWebCustom.php:83` |
| `custom_field_autocomplete_source` | filter | `$results, $fieldId, $term, $field` | `oc-includes/osclass/classes/controller/CWebAjax.php:486` |
| `custom_field_input_class` | filter | `$defaultInputClass, $field, $type, $search` | `oc-includes/osclass/classes/form/FieldForm.php:395` |
| `custom_query` | action | `$mSearch, $keyword, $value` | `oc-includes/osclass/helpers/hItems.php:1831` |
| `d_file_included` | action | `$file, $replacement, $version, $message` | `oc-includes/osclass/classes/utility/Deprecate.php:221` |
| `d_function_run` | action | `$function, $replacement, $version` | `oc-includes/osclass/classes/utility/Deprecate.php:40` |
| `d_hook_run` | action | `$hook, $replacement, $version, $message` | `oc-includes/osclass/classes/utility/Deprecate.php:140` |
| `datatable_listing_class` | filter | `array(), $aRawRows[$key], $row` | `oc-admin/themes/modern/items/index.php:163` |
| `delete_locale` | action | `$locale` | `oc-includes/osclass/classes/model/OSCLocale.php:159` |
| `delete_resource` | action | `$resourceRow` | `oc-includes/osclass/classes/storage/ResourceUploader.php:195` |
| `edit_page` | action | `$id` | `oc-includes/osclass/classes/controller/admin/CAdminPages.php:120` |
| `feed` | action | `$feed` | `oc-includes/osclass/classes/controller/CWebSearch.php:741` |
| `flash_message_text` | filter | `$message['msg']` | `oc-includes/osclass/helpers/hMessages.php:148` |
| `form_fields` | filter | `$fields, $form, $contextType, $contextId` | `oc-includes/osclass/helpers/hForms.php:118` |
| `form_render` | action | `$form, $contextType, $contextId` | `oc-includes/osclass/helpers/hForms.php:52` |
| `form_submit` | action | `$form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php:120` |
| `form_submit_veto` | filter | `'', $form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php:110` |
| `form_submitted` | action | `$submissionId, $form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php:139` |
| `form_validation_errors` | filter | `$result['errors'], $form, $result['values'], $contextType, $contextId` | `oc-includes/osclass/classes/controller/CWebForm.php:105` |
| `gettext` | filter | `$string` | `oc-includes/osclass/helpers/hTranslations.php:35` |
| `header` | action | — | `oc-includes/osclass/helpers/hTheme.php:727` |
| `help_box` | action | — | `oc-admin/themes/modern/parts/header.php:112` |
| `image_jpeg_quality` | filter | `$qualityPref` | `oc-includes/osclass/classes/ImageProcessing.php:290` |
| `image_png_compression` | filter | `6` | `oc-includes/osclass/classes/ImageProcessing.php:291` |
| `init` | action | — | `oc-includes/osclass/classes/controller/base/abstract/BaseModel.php:64` |
| `init_ajax` | action | — | `oc-includes/osclass/classes/controller/CWebAjax.php:29` |
| `init_billing` | action | — | `oc-includes/osclass/classes/controller/CWebBilling.php:84` |
| `init_billing_non_secure` | action | — | `oc-includes/osclass/classes/controller/CWebBillingNonSecure.php:30` |
| `init_contact` | action | — | `oc-includes/osclass/classes/controller/CWebContact.php:26` |
| `init_custom` | action | — | `oc-includes/osclass/classes/controller/CWebCustom.php:27` |
| `init_language` | action | — | `oc-includes/osclass/classes/controller/CWebLanguage.php:28` |
| `init_login` | action | — | `oc-includes/osclass/classes/controller/CWebLogin.php:31` |
| `init_main` | action | — | `oc-includes/osclass/classes/controller/CWebMain.php:26` |
| `init_page` | action | — | `oc-includes/osclass/classes/controller/CWebPage.php:32` |
| `init_register` | action | — | `oc-includes/osclass/classes/controller/CWebRegister.php:41` |
| `init_send_mail` | filter | `$mail, $params` | `oc-includes/osclass/utils.php:293` |
| `invalidate_locale_cache` | action | — | `oc-includes/osclass/helpers/hCache.php:341` |
| `keyword_block_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsKeywordBlock.php:118` |
| `keyword_block_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/KeywordBlocksDataTable.php:161` |
| `language_attributes` | filter | `$attrs` | `oc-includes/osclass/helpers/hTheme.php:583` |
| `language_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminLanguages.php:657` |
| `locations_json_url` | filter | `'https://geo.mindstellar.com/releases/latest.json'` | `oc-includes/osclass/helpers/hUtils.php:1118` |
| `login_admin` | action | `$admin` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php:149` |
| `login_admin_form` | action | — | `oc-admin/gui/login.php:34` |
| `login_admin_image` | filter | `osc_admin_base_url() . 'images/shopclass-logo.svg'` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php:286` |
| `login_admin_title` | filter | `'Shopclass'` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php:284` |
| `login_admin_url` | filter | `'https://github.com/mindstellar/shopclass/'` | `oc-includes/osclass/classes/controller/admin/CAdminLogin.php:285` |
| `logout` | action | — | `oc-includes/osclass/classes/controller/CWebMain.php:40` |
| `logout_admin` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminMain.php:44` |
| `logs_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/LogsDataTable.php:158` |
| `mail_from` | filter | `$from, $params` | `oc-includes/osclass/utils.php:384` |
| `mail_from_name` | filter | `$from_name, $params` | `oc-includes/osclass/utils.php:385` |
| `market_allowed_package_hosts` | filter | `$defaultHosts` | `oc-includes/osclass/classes/utility/FileSystem.php:838` |
| `market_catalog_mirror_base` | filter | `$default, $this->type` | `oc-includes/osclass/classes/market/Catalog.php:389` |
| `market_catalog_primary_base` | filter | `$default, $this->type` | `oc-includes/osclass/classes/market/Catalog.php:375` |
| `media_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/MediaDataTable.php:187` |
| `meta_description_filter` | filter | `$text` | `oc-includes/osclass/functions.php:364` |
| `meta_keywords_filter` | filter | `$text` | `oc-includes/osclass/functions.php:415` |
| `meta_title_filter` | filter | `$text` | `oc-includes/osclass/functions.php:278` |
| `mo_core_messages_path` | filter | `osc_translations_path() . $locale . '/messages.mo', $locale` | `oc-includes/osclass/classes/Translation.php:60` |
| `mo_core_path` | filter | `osc_translations_path() . $locale . '/core.mo', $locale` | `oc-includes/osclass/classes/Translation.php:46` |
| `moderator_access` | filter | `array( 'items', 'comments', 'media', 'login', 'admins', 'ajax', 'stats', '' )` | `oc-includes/osclass/classes/controller/base/AdminSecBaseModel.php:33` |
| `more_actions_manage_rules` | filter | `$options_more, $aRow` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php:149` |
| `ngettext` | filter | `$string` | `oc-includes/osclass/helpers/hTranslations.php:129` |
| `non_remember_login_ttl` | filter | `2 * 3600` | `oc-includes/osclass/helpers/hUsers.php:168` |
| `osclass_upgrade_package` | filter | `$package_info` | `oc-includes/osclass/classes/upgrade/Osclass.php:332` |
| `page_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminPages.php:343` |
| `page_meta` | action | — | `oc-admin/themes/modern/pages/frm.php:282` |
| `page_templates` | filter | `WebThemes::newInstance()->getAvailableTemplates()` | `oc-includes/osclass/classes/controller/admin/CAdminPages.php:64` |
| `pages_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/PagesDataTable.php:122` |
| `phpmailer_smtp_timeout` | filter | `15` | `oc-includes/osclass/utils.php:232` |
| `pre_contact_post` | action | `$params` | `oc-includes/osclass/classes/controller/CWebContact.php:164` |
| `pre_send_mail` | filter | `$mail, $params` | `oc-includes/osclass/utils.php:450` |
| `public_cache_max_age` | filter | `30` | `oc-includes/osclass/helpers/hHttpCache.php:142` |
| `regenerate_image` | action | `$resource` | `oc-includes/osclass/classes/actions/ItemActions.php:113` |
| `regenerated_image` | action | `ItemResource::newInstance()->findByPrimaryKey($resource['pk_i_id'])` | `oc-includes/osclass/classes/actions/ItemActions.php:167` |
| `register_email_taken` | action | `$input['s_email']` | `oc-includes/osclass/classes/actions/UserActions.php:122` |
| `register_storage_adapters` | action | `StorageManager::instance()` | `oc-includes/osclass/helpers/hStorage.php:84` |
| `render_admintoolbar` | action | — | `oc-includes/osclass/classes/AdminToolbar.php:196` |
| `resource_download_filename` | filter | `$name, $resource, $variant` | `oc-includes/osclass/helpers/hItems.php:1094` |
| `resource_download_url` | filter | `$url, $resource, $variant` | `oc-includes/osclass/helpers/hItems.php:1124` |
| `resource_original_url` | filter | `osc_resource_path() . osc_resource_id() . '_original.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php:1163` |
| `resource_path` | filter | `osc_base_url() . $_r['s_path'], $_r` | `oc-includes/osclass/classes/form/ItemForm.php:1479` |
| `resource_preview_url` | filter | `osc_resource_path() . osc_resource_id() . '_preview.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php:1149` |
| `resource_thumbnail_url` | filter | `osc_resource_path() . osc_resource_id() . '_thumbnail.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php:1134` |
| `resource_url` | filter | `osc_resource_path() . osc_resource_id() . '.' . osc_resource_field('s_extension'), osc_resource()` | `oc-includes/osclass/helpers/hItems.php:977` |
| `response_body` | filter | `$data` | `oc-includes/osclass/classes/Csrf.php:124` |
| `response_cache_control` | filter | `$header` | `oc-includes/osclass/helpers/hHttpCache.php:148` |
| `response_is_cacheable` | filter | `true` | `oc-includes/osclass/helpers/hHttpCache.php:112` |
| `rules_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/BanRulesDataTable.php:182` |
| `sanitize_html_allowed` | filter | `implode(',', array( 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a[href\|title\|rel]', 'h3', 'h4', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span[style]', 'img[src\|alt\|width\|height]', ))` | `oc-includes/osclass/helpers/hSanitize.php:213` |
| `scripts_defer` | filter | `defined('OC_ADMIN') && OC_ADMIN` | `oc-includes/osclass/classes/Scripts.php:139` |
| `scripts_loaded` | action | — | `oc-includes/osclass/helpers/hTheme.php:986` |
| `send_friend_throttle_max` | filter | `5` | `oc-includes/osclass/classes/controller/CWebItem.php:573` |
| `send_friend_throttle_window` | filter | `3600` | `oc-includes/osclass/classes/controller/CWebItem.php:574` |
| `settings_page_after_group` | action | `$page['id'], $group, $index` | `oc-includes/osclass/classes/admin/ui/SettingsForm.php:86` |
| `settings_page_saved` | action | `$pageId, $exposed` | `oc-includes/osclass/helpers/hSettings.php:830` |
| `shutdown_functions` | filter | `[$injectCsrf]` | `oc-includes/osclass/classes/Csrf.php:127` |
| `slug` | filter | `$fieldsDescription['s_name']` | `oc-includes/osclass/classes/utility/Utils.php:475` |
| `static_page_text` | filter | `osc_static_page_field('s_text', $locale), $locale` | `oc-includes/osclass/helpers/hPage.php:91` |
| `style_url` | filter | `$css` | `oc-includes/osclass/classes/Styles.php:83` |
| `template_candidates` | filter | `$candidates, $context` | `oc-includes/osclass/helpers/hTheme.php:177` |
| `tinymce_config` | filter | `$config, $preset` | `oc-includes/osclass/helpers/hUtils.php:1260` |
| `upload_image_extension` | filter | `$imgres->getExt()` | `oc-includes/osclass/classes/actions/ItemActions.php:974` |
| `upload_image_mime` | filter | `$imgres->getMime()` | `oc-includes/osclass/classes/actions/ItemActions.php:975` |
| `uploaded_file` | action | `ItemResource::newInstance()->findByPrimaryKey($resourceId)` | `oc-includes/osclass/classes/actions/ItemActions.php:1036` |
| `uploaded_resource` | action | `$row` | `oc-includes/osclass/classes/storage/ResourceUploader.php:166` |
| `watermark_font_path` | filter | `LIB_PATH . 'assets/fonts/open-sans/OpenSans-Regular.ttf'` | `oc-includes/osclass/classes/ImageProcessing.php:560` |
| `watermark_font_size` | filter | `30` | `oc-includes/osclass/classes/ImageProcessing.php:563` |
| `watermark_text_value` | filter | `$watermark_text` | `oc-includes/osclass/classes/ImageProcessing.php:561` |
| `widget_locations` | filter | `$locations` | `oc-includes/osclass/helpers/hWidgets.php:69` |

### Plugin (12)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `after_plugin_activate` | action | — | `oc-includes/osclass/classes/Plugins.php:491` |
| `after_plugin_deactivate` | action | — | `oc-includes/osclass/classes/Plugins.php:672` |
| `after_plugin_install` | action | — | `oc-includes/osclass/classes/Plugins.php:434` |
| `after_plugin_uninstall` | action | — | `oc-includes/osclass/classes/Plugins.php:633` |
| `before_plugin_activate` | action | — | `oc-includes/osclass/classes/Plugins.php:475` |
| `before_plugin_deactivate` | action | — | `oc-includes/osclass/classes/Plugins.php:647` |
| `before_plugin_install` | action | — | `oc-includes/osclass/classes/Plugins.php:399` |
| `before_plugin_uninstall` | action | — | `oc-includes/osclass/classes/Plugins.php:603` |
| `custom_plugin_title` | filter | `__('Plugins')` | `oc-admin/themes/modern/plugins/configuration.php:21` |
| `mo_plugin_path` | filter | `osc_plugins_path() . $domain . '/languages/' . $locale . '/messages.mo', $locale, $domain` | `oc-includes/osclass/classes/Translation.php:88` |
| `plugin_icon_url` | filter | `$url, $plugin` | `oc-includes/osclass/helpers/hPlugins.php:370` |
| `renderplugin_controller` | action | — | `oc-includes/osclass/classes/controller/admin/CAdminPlugins.php:219` |

### Search (9)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `after_search` | action | — | `oc-includes/osclass/classes/controller/CWebSearch.php:686` |
| `before_search` | action | — | `oc-includes/osclass/classes/controller/CWebSearch.php:131` |
| `save_latest_searches_pattern` | filter | `$p_sPattern` | `oc-includes/osclass/classes/controller/CWebSearch.php:281` |
| `search` | action | `$this->mSearch` | `oc-includes/osclass/classes/controller/CWebSearch.php:590` |
| `search_conditions` | action | `Params::getParamsAsArray()` | `oc-includes/osclass/classes/controller/CWebSearch.php:533` |
| `search_pattern` | filter | `trim(strip_tags(Params::getParam('sPattern')))` | `oc-includes/osclass/classes/controller/CWebSearch.php:274` |
| `search_results` | filter | `null, $this->mSearch, Params::getParamsAsArray()` | `oc-includes/osclass/classes/controller/CWebSearch.php:544` |
| `sql_search_conditions` | filter | `$this->conditions` | `oc-includes/osclass/classes/model/Search.php:869` |
| `sql_search_fields` | filter | `$this->search_fields` | `oc-includes/osclass/classes/model/Search.php:879` |

### Theme (8)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `after_init_web_theme` | action | — | `oc-includes/osclass/classes/themes/WebThemes.php:43` |
| `before_init_web_theme` | action | — | `oc-includes/osclass/classes/themes/WebThemes.php:41` |
| `mo_theme_messages_path` | filter | `osc_themes_path() . $domain . '/languages/' . $locale . '/messages.mo', $locale, $domain` | `oc-includes/osclass/classes/Translation.php:51` |
| `mo_theme_path` | filter | `osc_themes_path() . $domain . '/languages/' . $locale . '/theme.mo', $locale, $domain` | `oc-includes/osclass/classes/Translation.php:70` |
| `theme` | filter | `osc_theme()` | `oc-includes/osclass/classes/Translation.php:50` |
| `theme_activate` | action | `$theme` | `oc-includes/osclass/classes/cli/Cli.php:837` |
| `theme_screenshot_url` | filter | `$url, $theme` | `oc-includes/osclass/helpers/hTheme.php:1076` |
| `theme_url` | filter | `osc_base_url() . str_replace(osc_base_path(), '', $this->theme_path)` | `oc-includes/osclass/classes/themes/WebThemes.php:235` |

### User (36)

| Name | Kind | Arguments | Fired at |
|---|---|---|---|
| `actions_manage_users` | filter | `$options, $aRow` | `oc-includes/osclass/classes/datatables/UsersDataTable.php:271` |
| `activate_user` | action | `$user` | `oc-includes/osclass/classes/actions/UserActions.php:572` |
| `after_delete_user` | action | `$id` | `oc-includes/osclass/classes/model/User.php:396` |
| `after_username_change` | action | `Session::newInstance()->_get('userId'), Params::getParam('s_username')` | `oc-includes/osclass/classes/controller/CWebUser.php:196` |
| `before_user_delete` | action | `$user` | `oc-includes/osclass/classes/controller/CWebUser.php:397` |
| `before_user_recover` | action | — | `oc-includes/osclass/classes/controller/CWebLogin.php:248` |
| `before_user_register` | action | — | `oc-includes/osclass/classes/controller/CWebRegister.php:67` |
| `before_username_change` | action | `Session::newInstance()->_get('userId'), $username` | `oc-includes/osclass/classes/controller/CWebUser.php:179` |
| `bot_user_agents` | filter | `array( // Generic — catches the long tail, which is most of it. 'bot', 'crawler', 'crawling', 'spider', 'scraper', 'archiver', 'fetcher', // Search engines. 'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandex', 'sogou', 'exabot', 'seznambot', 'petalbot', 'applebot', 'qwantify', // AI and dataset collectors. 'gptbot', 'oai-searchbot', 'chatgpt-user', 'ccbot', 'claudebot', 'claude-web', 'anthropic-ai', 'perplexitybot', 'google-extended', 'bytespider', 'amazonbot', 'meta-externalagent', 'diffbot', // SEO and marketing crawlers. 'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'blexbot', 'dataforseo', 'screaming frog', 'serpstat', 'megaindex', // Monitoring, previews and libraries. 'uptimerobot', 'pingdom', 'statuscake', 'facebookexternalhit', 'telegrambot', 'whatsapp', 'slackbot', 'discordbot', 'embedly', 'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client', 'java/', 'okhttp', 'libwww-perl', 'headlesschrome', 'phantomjs', )` | `oc-includes/osclass/helpers/hUtils.php:677` |
| `datatable_user_class` | filter | `array(), $aRawRows[$key], $row` | `oc-admin/themes/modern/users/index.php:134` |
| `deactivate_user` | action | `$user` | `oc-includes/osclass/classes/actions/UserActions.php:611` |
| `delete_user` | action | `$id` | `oc-includes/osclass/classes/model/User.php:356` |
| `disable_user` | action | `$user` | `oc-includes/osclass/classes/actions/UserActions.php:687` |
| `enable_user` | action | `$user` | `oc-includes/osclass/classes/actions/UserActions.php:649` |
| `init_user` | action | — | `oc-includes/osclass/classes/controller/CWebUser.php:31` |
| `init_user_non_secure` | action | — | `oc-includes/osclass/classes/controller/CWebUserNonSecure.php:34` |
| `manage_user_search_conditions` | action | `$dummy` | `oc-includes/osclass/classes/datatables/UsersDataTable.php:70` |
| `manage_user_search_with_filters` | filter | `$this->withFilters` | `oc-includes/osclass/classes/datatables/UsersDataTable.php:349` |
| `more_actions_manage_users` | filter | `$options_more, $aRow` | `oc-includes/osclass/classes/datatables/UsersDataTable.php:259` |
| `pre_user_post` | action | — | `oc-includes/osclass/classes/actions/UserActions.php:153` |
| `user_add_flash_error` | filter | `$flash_error` | `oc-includes/osclass/classes/actions/UserActions.php:139` |
| `user_bulk_filter` | filter | `$bulk_options` | `oc-includes/osclass/classes/controller/admin/CAdminUsers.php:771` |
| `user_dashboard` | action | — | `oc-includes/osclass/gui/account/user-dashboard-content.php:49` |
| `user_edit_completed` | action | `$userId` | `oc-includes/osclass/classes/actions/UserActions.php:467` |
| `user_edit_flash_error` | filter | `$flash_error, $userId` | `oc-includes/osclass/classes/actions/UserActions.php:405` |
| `user_form` | action | — | `oc-includes/osclass/gui/account/user-login-content.php:46` |
| `user_info` | filter | `$info, $userId, $locale` | `oc-includes/osclass/helpers/hUsers.php:487` |
| `user_menu` | action | — | `oc-includes/osclass/gui/account/nav.php:110` |
| `user_menu_filter` | filter | `$navItems` | `oc-includes/osclass/gui/account/nav.php:41` |
| `user_profile_form` | action | `$profileUser` | `oc-includes/osclass/gui/account/user-profile-content.php:120` |
| `user_profile_info` | filter | `$aInfo['s_info'], $aUser['pk_i_id'], $aInfo['fk_c_locale_code']` | `oc-includes/osclass/classes/controller/CWebUser.php:74` |
| `user_register_completed` | action | `$userId` | `oc-includes/osclass/classes/actions/UserActions.php:228` |
| `user_register_failed` | action | `$error` | `oc-includes/osclass/classes/actions/UserActions.php:147` |
| `user_register_form` | action | — | `oc-includes/osclass/gui/account/user-register-content.php:51` |
| `users_processing_row` | filter | `$row, $aRow` | `oc-includes/osclass/classes/datatables/UsersDataTable.php:295` |
| `validate_user` | action | `$user` | `oc-includes/osclass/classes/controller/CWebRegister.php:124` |

<!-- /generated:hooks -->
