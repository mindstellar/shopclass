---
title: Helper functions
description: "The full list of osc_* helper functions core defines, grouped by file, with what each one does and which are deprecated."
sidebar:
  order: 31
---

Helpers are the `osc_*` functions themes and plugins call to read and write site data.
They are a public API: core does not rename or remove them.

```php
// Inside the item loop of a theme.
echo osc_item_title();
echo osc_format_price(osc_item_price());
```

A helper marked **Deprecated** still works, but do not use it in new code.

New core code calls the services and models under `mindstellar\` instead; see
[Architecture](/docs/developers/architecture/). Helpers stay for themes and plugins.

## Reference

<!-- generated:helpers -->

Core defines 1153 helpers, 20 of them deprecated. Generated from the source; do not edit by hand.

### alerts (1)

`oc-includes/osclass/alerts.php`

| Helper | What it does |
|---|---|
| `osc_runAlert($type = null, $last_exec = null)` | Send the saved-search alert emails due for one frequency band. Each saved search is isolated, so a failing one does not abort the rest of the run. |

### formatting (1)

`oc-includes/osclass/formatting.php`

| Helper | What it does |
|---|---|
| `osc_sanitizeString($string)` | Turn a string into a URL-safe slug: tags, accents, entities and punctuation removed, whitespace collapsed to single hyphens. |

### functions (31)

`oc-includes/osclass/functions.php`

| Helper | What it does |
|---|---|
| `osc_admin_toolbar_comments()` | Add a toolbar counter for comments awaiting moderation, when there are any. |
| `osc_admin_toolbar_logout()` | Add logout link |
| `osc_admin_toolbar_spam()` | Add a toolbar counter for listings marked as spam, when there are any. |
| `osc_admin_toolbar_update_core($force = false)` | Add the toolbar entry announcing a core update, when one is recorded as available. |
| `osc_admin_toolbar_update_counter(string $kind, bool $force = false)` | Add a toolbar counter for plugin, theme or language updates, when any are available. |
| `osc_admin_toolbar_update_languages($force = false)` | Toolbar counter for language updates; see osc_admin_toolbar_update_counter(). |
| `osc_admin_toolbar_update_plugins($force = false)` | Toolbar counter for plugin updates; see osc_admin_toolbar_update_counter(). |
| `osc_admin_toolbar_update_themes($force = false)` | Toolbar counter for theme updates; see osc_admin_toolbar_update_counter(). |
| `osc_check_languages_update($force = false)` | Number of languages with an update available; see osc_update_check_count(). |
| `osc_check_plugins_update($force = false)` | Number of plugins with an update available; see osc_update_check_count(). |
| `osc_check_themes_update($force = false)` | Number of themes with an update available; see osc_update_check_count(). |
| `osc_draw_admin_toolbar()` | Draws admin toolbar |
| `osc_expire_premium_items()` | End time-limited premium upgrades whose date has passed, returning how many were ended. |
| `osc_footer_link_title($f = null)` | Label for one search-footer link: keyword prefix, category and location name. |
| `osc_footer_link_url($f = null)` | URL for one search-footer link. |
| `osc_force_jpeg_extension($content)` | Force the upload_image_extension filter to jpg. |
| `osc_force_jpeg_mime($content)` | Force the upload_image_mime filter to image/jpeg. |
| `osc_item_tinymce_footer()` | Print the TinyMCE init script for the listing description fields, in the footer. |
| `osc_item_tinymce_header()` | Enqueue TinyMCE on the public publish and edit pages only. |
| `osc_meta_edit($catId = null, $item_id = null)` | Print the custom-field inputs for the edit form, pre-filled from the listing. |
| `osc_meta_generator()` | Print the generator meta tag. |
| `osc_meta_noindex()` | Emit &lt;meta name="robots" content="noindex, follow"&gt; when a controller has marked the current response as thin/empty (e.g. a valid but empty category or location browse page). Keeps the URL crawlable and 200, without indexing an empty page. |
| `osc_meta_publish($catId = null)` | Print the custom-field inputs for the publish form. |
| `osc_meta_search($catId = null)` | Print the custom-field inputs for the search form. |
| `osc_run_cleanup()` | Run the enabled Tools &gt; Cleanup rules once, in this request: one batch per rule. Returns the total number removed. The daily task and the admin's "Run cleanup now" queue background jobs instead, which keep going until nothing matches. |
| `osc_search_footer_links()` | Related region or city links for the search-page footer, one row per location group. Empty when friendly URLs are off, when a city is already selected, or on a query error. |
| `osc_search_meta_description()` | Description for a listing-index page: what is being listed, where, and how much of it there is, before any category blurb. |
| `osc_show_maintenance()` | Print the maintenance-mode banner while maintenance mode is on. |
| `osc_ui_common_header()` | Load what the public location and photo fields need, from the head. |
| `osc_update_check_count(string $kind, bool $force = false)` | Number of plugins, themes or languages with an update, from the saved check unless forced. Without $force it schedules a background re-check once the saved one is a day old. |
| `osc_update_check_scan(string $kind)` | Re-scan installed plugins, themes or languages for updates and save the result. |

### hAdminMenu (18)

`oc-includes/osclass/helpers/hAdminMenu.php`

| Helper | What it does |
|---|---|
| `osc_add_admin_menu_page($menu_title, $url, $menu_id, $capability = 'administrator', $icon_url = null, $position = null)` | Add menu entry |
| `osc_add_admin_submenu_divider($menu_id, $submenu_title, $submenu_id, $capability = null)` | Add submenu divider under menu id $menu_id |
| `osc_add_admin_submenu_page($menu_id, $submenu_title, $url, $submenu_id, $capability = 'administrator')` | Add submenu under menu id $menu_id |
| `osc_admin_menu_appearance($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the appearance menu page |
| `osc_admin_menu_categories($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the categories menu page |
| `osc_admin_menu_items($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the listings menu page |
| `osc_admin_menu_pages($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the pages menu page |
| `osc_admin_menu_plugins($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the plugins menu page |
| `osc_admin_menu_settings($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the settings menu page |
| `osc_admin_menu_stats($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the stats menu page |
| `osc_admin_menu_tools($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the tools menu page |
| `osc_admin_menu_users($submenu_title, $url, $submenu_id, $capability = null, $icon_url = null)` | Add a submenu entry under the users menu page |
| `osc_current_menu()` | Id of the admin menu section matching the current request URL. |
| `osc_draw_admin_menu()` | Draws menu with sections and subsections |
| `osc_remove_admin_menu()` | Remove the whole menu |
| `osc_remove_admin_menu_page($menu_id)` | Remove menu section with id $menu_id |
| `osc_remove_admin_submenu_divider($menu_id, $submenu_id)` | Remove submenu divider with id $submenu_id under menu id $menu_id |
| `osc_remove_admin_submenu_page($menu_id, $submenu_id)` | Remove submenu with id $submenu_id under menu id $menu_id |

### hAdminUi (43)

`oc-includes/osclass/helpers/hAdminUi.php`

| Helper | What it does |
|---|---|
| `osc_admin_action_button(array $action)` | One action, as a link or a button. |
| `osc_admin_action_section(array $opts = array())` | A titled block of actions — the "do a thing" panel (connection test, queue, migration, cleanup, maintenance) that is not a label\|control form. Renders a heading, an optional intro, optional body content, and each action as a button with its own help line. |
| `osc_admin_category_picker(array $opts = array())` | The category chooser: a control showing the chosen path, and a searchable list of the whole tree behind it. |
| `osc_admin_checkbox(array $opts)` | A checkbox with its label on one line and its hint underneath. |
| `osc_admin_definition(array $rows)` | Label/value rows -- the read-only counterpart to a form. A row may set 'html' =&gt; true to pass markup through, which is why the default escapes. |
| `osc_admin_disclosure_close()` | Close what osc_admin_disclosure_open() opened. |
| `osc_admin_disclosure_open($title, array $opts = array())` | A collapsible group, for the settings a screen has to offer and nobody changes twice a year. A &lt;details&gt;, so it works with no JavaScript and is findable by the browser's own in-page search. |
| `osc_admin_duration($seconds)` | A short length of time: "just now", "5 minutes", "3 hours", "2 days". |
| `osc_admin_editor_close($actions = array(), array $opts = array())` | Close the editor, ending on the sticky save bar that counts what has changed. |
| `osc_admin_editor_open(array $opts = array())` | Open an editing screen: the form, its hidden route, the error summary, and the main column of the two-column grid. |
| `osc_admin_editor_rail(array $opts = array())` | Close the main column and open the rail beside it. Sticky from 992px up; above the content on anything narrower, so a status panel is reachable without scrolling past the body. |
| `osc_admin_error_summary(array $errors, array $opts = array())` | The list of what a rejected save refused, at the head of a form. Fills the same #error_list the client-side validator writes to, so both read the same. |
| `osc_admin_field(array $spec)` | Render one labelled field. Every other function here is sugar over this one, so this is the only new name a plugin has to depend on. |
| `osc_admin_field_attrs(array $attrs)` | Extra attributes as an escaped string. A value of true renders the attribute alone (`readonly`), false and null drop it. |
| `osc_admin_field_choices($id, $name, $value, array $spec)` | The option list of a radio group. Each option is its own label wrapping its own control, so the whole line is a hit target and no id can drift from its label. |
| `osc_admin_field_class($type, array $spec)` | The width class a field gets. Width follows the field's meaning, not the page it happens to sit on; 'width' overrides it when a screen genuinely needs something else. |
| `osc_admin_field_control($type, $id, array $spec)` | The control alone, without its row, label or hint. Split out so every field type shares one escaping path and one width decision. |
| `osc_admin_field_help(array $spec)` | The hint under a field, in the same help-box the admin already uses. |
| `osc_admin_field_id(array $spec)` | A field's DOM id: its own, or one derived from its name so the label is clickable without every caller having to invent one. |
| `osc_admin_field_row($label, array $fields, array $opts = array())` | One labelled row holding several fields. |
| `osc_admin_form_actions(array $actions = array(), array $opts = array())` | The submit row at the foot of a form. With no arguments this is a lone "Save changes" -- the case that covers most settings screens. |
| `osc_admin_form_close($actions = null, array $opts = array())` | Close what osc_admin_form_open() opened, optionally with the submit row. |
| `osc_admin_form_open(array $opts = array())` | Open an admin form: the element, the hidden route fields it posts to, and the wrappers the row layout needs. |
| `osc_admin_form_row_close()` | Close the row osc_admin_form_row_open() opened. |
| `osc_admin_form_row_open($label = '', array $opts = array())` | One labelled row of a form. |
| `osc_admin_form_section($title, array $opts = array())` | A titled group of fields within a screen, with an optional explanatory paragraph. |
| `osc_admin_location_picker(array $opts = array())` | Where a record is: the country select, the region and city inputs with their hidden ids, and the rest of the address behind a disclosure. |
| `osc_admin_number(array $opts)` | A number, at number width. Keys: the shared set, plus 'min', 'max', 'step', 'prefix', 'suffix'. |
| `osc_admin_page_head($title, array $actions = array(), array $opts = array())` | The heading a screen opens on. |
| `osc_admin_panel_close()` | Close the panel osc_admin_panel_open() opened. |
| `osc_admin_panel_open($title = '', array $opts = array())` | A titled panel. Opens the box and its content area; osc_admin_panel_close() ends both. |
| `osc_admin_photo_grid(array $opts = array())` | A record's photos as a grid of tiles: the ones it already has, the ones uploaded ahead of the save, one drop target and the count against the site's ceiling. |
| `osc_admin_publish_panel(array $opts = array())` | The rail's status panel: what state the record is in, the facts about it, and the actions that change that state. |
| `osc_admin_radio_group(array $opts)` | A choice list. Keys: the shared set, plus 'options', 'selected'. An option may be a string label or array('label' =&gt; …, 'custom_html' =&gt; …). |
| `osc_admin_secret(array $opts)` | An API key or password. Keys: the shared set, plus 'reveal', 'masked'. |
| `osc_admin_select(array $opts)` | A dropdown. Keys: the shared set, plus 'options', 'selected', 'placeholder'. |
| `osc_admin_settings_form($page, array $opts = array())` | The whole form of a declared page (osc_register_settings_page()): the element, the hidden route, every declared field with the value it should show, and the submit row. |
| `osc_admin_status($state, $word)` | A status pill: a tint, a shape, and the word. An unmapped state still renders, in the neutral tint, so a plugin's own word degrades instead of vanishing. |
| `osc_admin_text(array $opts)` | Single-line text. Keys: the shared set, plus 'placeholder', 'width', 'prefix', 'suffix'. |
| `osc_admin_textarea(array $opts)` | Multi-line text. Keys: the shared set, plus 'rows', 'monospace'. |
| `osc_admin_tree_picker(array $opts)` | A category checkbox tree with "Check all / Uncheck all" toggles, shared by every screen that assigns fields, groups or plugin config to categories. |
| `osc_admin_user_picker(array $opts = array())` | Who a record belongs to: the card when a registered user matches, and a search that fills the named fields when one is picked. |
| `osc_admin_when($datetime)` | A date as "5 minutes ago" or "in 5 minutes", with the full date as a tooltip. |

### hApi (11)

`oc-includes/osclass/helpers/hApi.php`

| Helper | What it does |
|---|---|
| `osc_api_enabled()` | Whether the REST API answers at all. |
| `osc_api_public_reads()` | Whether public data may be read with no credential at all. Off by default. |
| `osc_api_register_field(string $object, string $slug, string $name, array $schema, array $views = ['public'])` | Declare a field a plugin adds to a listing, user or category, so the OpenAPI document shows it and `?fields=ext.<slug>.<name>` selects it. The plugin sends the value under `ext.<slug>.<name>` from the `api_listing`, `api_user` or `api_category` filter. A public field reaches everyone, an owner field the owner and admins, an admin field admins only. |
| `osc_api_register_route(string $method, string $path, array $spec)` | Add an API endpoint, through the `api_routes` filter. The path is below /api/v1/ and must start with `ext/<plugin-slug>/`; other paths are refused when the table is built, except a route with both `deprecated` and `sunset`, which may keep a plugin's old path until its sunset day. A second route with the same method and path is refused and logged. |
| `osc_api_register_schema(string $slug, string $name, array $schema)` | Add a component schema for a plugin's routes, through the `api_schemas` filter. It is named `Ext<Slug><Name>` (`acme-ratings`, `Rating`: `ExtAcmeRatingsRating`), so it never clashes with core's components, which are not part of the plugin contract. A second, different schema under the same name is refused and logged; the first one stays. |
| `osc_api_session_meta()` | A `<meta name="shopclass-api">` tag for the page head. Its content is JSON: the API's address (`url`), the page token (`token`, '' when nobody is signed in), the `header` to send it in and when it expires (`expires_at`). |
| `osc_api_session_token()` | The page token theme JavaScript sends as the X-Shopclass-Token header to call the API as the signed-in user, from this site's own pages. It lives two hours. |
| `osc_api_url(string $path = '', ?string $version = null)` | The absolute URL of an API path, with or without friendly URLs. |
| `osc_is_api_request()` | Whether this request is for the REST API: `?page=api`, or the /api/ path while friendly URLs are on. It works before the router has run. |
| `osc_user_api_access_url()` | The account page listing the user's API sign-ins and personal keys, for a theme's account menu. |
| `osc_webhook_emit(string $type, array $data, array $options = [])` | Queue a webhook event for every enabled endpoint that subscribes to it. A plugin's event is named `ext.<plugin-slug>.<name>` and registered on `api_webhook_events` first. |

### hBilling (65)

`oc-includes/osclass/helpers/hBilling.php`

| Helper | What it does |
|---|---|
| `osc_billing_bump_cooldown_hours()` | Hours a listing must wait between bumps -- the row ItemUpgrades grants IS the cooldown. |
| `osc_billing_bump_credits()` | Credit price of a bump. 0 with billing_bump_enabled on means free, not off. |
| `osc_billing_bump_enabled()` | Whether bump-to-top is registered as a purchasable upgrade at all. |
| `osc_billing_bump_paused(?int $userId = null, bool $fresh = false)` | Whether free bumps are paused for $userId: a bump costs them nothing and they hold more live listings than their ceiling. A paid bump is never paused. |
| `osc_billing_bump_paused_message(?int $userId = null)` | Why a seller cannot bump for free, or '' when they can. |
| `osc_billing_bump_paused_reset()` | Forget the remembered free-bump answers, so the next check reads the database. |
| `osc_billing_buy_url()` | Buy credits: packages plus the configured payment methods. |
| `osc_billing_can_buy()` | Whether buying credits leads anywhere: a payment method is on and a package is for sale. Gateways are preference reads, so they gate the packages query. |
| `osc_billing_currency()` | ISO 4217 code credits are priced in, always upper-case. |
| `osc_billing_feature(string $id)` | A registered billing feature by id, or null when nothing is registered under it. |
| `osc_billing_features()` | Every registered billing feature, keyed by id. |
| `osc_billing_free_live_listings()` | Free listing slots: how many of a seller's listings may be live (published, not yet expired -- see EntitlementStore::liveListings()) at once before a listing.slot entitlement is needed. 0 = unlimited, which is also what an unset preference reads as -- an upgraded install stays unlimited. |
| `osc_billing_gateway_name(string $gatewayId)` | The gateway's display name for an order list, falling back to the stored id when the plugin that took the payment is no longer installed. |
| `osc_billing_highlight_credits()` | Credit price of highlighting a listing. |
| `osc_billing_highlight_days()` | Days a highlight runs for. |
| `osc_billing_highlight_enabled()` | Whether highlighting is registered as a purchasable upgrade at all. |
| `osc_billing_no_wait_credits()` | Credit price of waiving the flood wait. |
| `osc_billing_no_wait_days()` | Days the waiver holds once bought. |
| `osc_billing_no_wait_enabled()` | Whether waiving the flood wait is registered as a purchasable limit at all. |
| `osc_billing_offline_enabled()` | Whether the bundled bank-transfer gateway is switched on. |
| `osc_billing_offline_instructions()` | Admin-authored payment instructions shown on the offline checkout screen. |
| `osc_billing_orders_url()` | The buyer's own past orders. |
| `osc_billing_packages()` | The packages a buyer can choose from at checkout. |
| `osc_billing_photos_credits()` | Credit price of the raised photo cap. |
| `osc_billing_photos_enabled()` | Whether raising a seller's photo cap is registered as a purchasable limit at all. |
| `osc_billing_photos_quantity()` | Photo cap granted while the entitlement is held. |
| `osc_billing_premium_credits()` | Credit price of featuring a listing. 0 with billing_premium_enabled on means free, not off. |
| `osc_billing_premium_days()` | Days a featured listing runs for. (int) '' is 0, so an unset preference defaults to 30. |
| `osc_billing_premium_enabled()` | Whether featuring a listing is registered as a purchasable feature at all. |
| `osc_billing_runtime_credits()` | Credit price of the extra runtime. |
| `osc_billing_runtime_days()` | Extra days over the category ceiling granted while the entitlement is held. |
| `osc_billing_runtime_enabled()` | Whether extra listing runtime is registered as a purchasable limit at all. |
| `osc_billing_slot_credits()` | Credit price of one listing.slot purchase. 0 with billing_slot_enabled on means free. |
| `osc_billing_slot_enabled()` | Whether buying an extra listing slot is registered as a purchasable feature at all. |
| `osc_billing_slot_quantity()` | Slots granted per listing.slot purchase. |
| `osc_billing_upgrade_url(int $itemId)` | The POST target for featuring one of the user's own listings. The item id travels in the URL so a theme's "feature this listing" form needs no hidden field beyond the CSRF token the shutdown injector already adds. |
| `osc_billing_urgent_credits()` | Credit price of marking a listing urgent. |
| `osc_billing_urgent_days()` | Days an urgent mark runs for. |
| `osc_billing_urgent_enabled()` | Whether marking a listing urgent is registered as a purchasable upgrade at all. |
| `osc_billing_wallet_url()` | Balance and ledger history. Rewritten like every other account route when the site has rewriting on (rewrite_billing_wallet, 'user/credits' by default); the query-string form still resolves either way, so links already out there keep working. |
| `osc_item_can_be_featured(?array $item = null)` | Whether a listing is eligible to be featured for credits: billing switched on, featuring itself switched on, and the listing not already featured. A price of 0 with billing_premium_enabled on means every seller can feature for free -- it does not mean unavailable. |
| `osc_item_can_bump(?array $item = null)` | Whether an item may be bumped right now: bump is switched on, the item belongs to the logged-in user, and there is no live cooldown row. Bump has no state of its own beyond that row -- the cooldown IS the row's expiry, not a second concept. |
| `osc_item_extra_runtime_days(?int $userId = null)` | Extra days $userId may run a listing beyond its category's expiration ceiling, raised by a listing.runtime entitlement. 0 (not osc_items_wait_time_for_user()'s "no preference to fall back to") when billing is off, no user is known, or the user holds nothing -- there is no old global preference this one overrides, so 0 is simply "no extra". -1 means unlimited extra runtime; treat it that way, never compare it numerically. |
| `osc_item_has_upgrade(string $upgrade, ?array $item = null)` | Whether an item currently holds $upgrade. Defaults to the item in view. |
| `osc_item_is_highlighted(?array $item = null)` | Whether an item currently holds the item.highlight upgrade. Defaults to the item in view. |
| `osc_item_is_urgent(?array $item = null)` | Whether an item currently holds the item.urgent upgrade. Defaults to the item in view. |
| `osc_item_premium_expiration(?array $item = null)` | Raw expiration datetime of a featured listing, or null when it is not currently featured. Defaults to the item currently in view, the same convention osc_item_field() uses, so it drops straight into an item loop. |
| `osc_item_upgrade_expiration(string $upgrade, ?array $item = null)` | Raw expiration datetime of an item's $upgrade row, or null when there is no row at all -- the same "raw value regardless of active state" convention osc_item_premium_expiration() follows. |
| `osc_item_upgrade_offers(?array $item = null)` | The paid upgrades the logged-in owner can buy for an item right now, as data: each entry is ['feature', 'label', 'credits']. Empty unless the item is the owner's own and is live (enabled, active, not expired). |
| `osc_item_upgrade_url(int $itemId, string $feature)` | The POST target for applying $feature to $itemId. Generalises osc_billing_upgrade_url() to any item-scoped feature; that one is kept, unchanged, for the listing.premium links already out there. |
| `osc_item_upgrades(?array $item = null)` | Upgrade ids currently in force on an item. |
| `osc_items_wait_time_for_user(?int $userId = null)` | Seconds $userId must wait between posts -- 0 while a listing.no_wait entitlement is held, osc_items_wait_time() otherwise. Defaults to osc_logged_user_id(). Guests (no user id) always read the global wait: this is an anti-flood control and anonymous posting has no entitlements to check. |
| `osc_listing_limit_message(?int $userId = null, array $item = array())` | The message a seller is shown at their listing limit, in the one wording the post form and the submit path both use. $item carries the listing being posted where there is one (the submit path), and is empty where there is not (opening the form). |
| `osc_max_images_for_user(?int $userId = null)` | The photo cap in force for $userId, raised by a listing.photos entitlement. Defaults to osc_logged_user_id(). -1 means unlimited -- treat it that way, never compare it numerically. Falls back to osc_max_images_per_item() (where 0, not -1, is that helper's own "unlimited") whenever billing is off, no user is known, or the user holds nothing. |
| `osc_prime_item_upgrades(array $items)` | Batch-load item-upgrade state for $items into the request cache ItemUpgradeStore::prime() fills, so osc_item_upgrades()/osc_item_is_highlighted()/ osc_item_is_urgent() called inside a listing loop cost one query for the whole page rather than one per card. Call this where a result set is built (core already does, for search/category/home); a theme working a custom loop can call it too. |
| `osc_register_billing_feature(string $id, array $spec)` | Register a feature so credits can be spent on it. Thin wrapper over FeatureRegistry::register() -- see that method for the $spec shape. |
| `osc_register_billing_item_upgrades()` | Register the three built-in item upgrades, each gated on its own *_enabled preference so a disabled upgrade is not merely unpriced but absent from the registry entirely. Called once below, and callable again by anything that changes those preferences without a fresh request (the admin settings save). |
| `osc_register_billing_premium()` | Register listing.premium, gated on its own billing_premium_enabled preference -- the same split every newer feature below uses, so a disabled premium feature is absent from the registry entirely, not merely priced at 0. Called once below, and callable again by the admin Pricing save so a toggle takes effect without a fresh request. |
| `osc_register_billing_seller_limits()` | Register the three optional seller-limit entitlements, each gated on its own *_enabled preference for the same reason as osc_register_billing_item_upgrades(): a disabled limit is absent from the registry entirely, not merely unpriced. Called once below, and callable again by the admin Seller limits save. |
| `osc_register_billing_slot()` | Register listing.slot -- the feature that raises a seller's listing-slot ceiling -- only while billing_slot_enabled is on, the same conditional-registration pattern osc_register_billing_seller_limits() uses: a disabled feature is absent from the registry entirely, not merely free or unpriced. CONSUMES_CAPACITY because it raises the seller's slot ceiling while held and is never spent -- EntitlementStore::withinFreeQuota() reads it back through capacity(), the same way listing.photos raises osc_max_images_for_user(). Called once below, and callable again by the admin Pricing save. |
| `osc_user_can_publish(?int $userId = null)` | Whether $userId may publish another listing right now -- the same answer the post route enforces, filter included, so a theme that hides its "post a listing" button on false is hiding it exactly when the form would refuse. There is no listing yet at this point, so a plugin filtering billing_can_publish on $ctx['item'] sees none here. |
| `osc_user_credits(?int $userId = null)` | A user's credit balance -- the logged-in buyer's own by default. Callers that pass $userId explicitly must own that decision themselves; the wallet page never does, so it can only ever read osc_logged_user_id()'s balance. |
| `osc_user_listing_limit(?int $userId = null)` | How many listings $userId may hold live at once. -1 means unlimited -- treat it that way, never compare it numerically -- which is what billing being off, an unknown user, or a site that never set a cap all read back. Defaults to osc_logged_user_id(). |
| `osc_user_listings_remaining(?int $userId = null)` | Listings $userId may still publish before hitting the ceiling. -1 means unlimited, the same sentinel osc_user_listing_limit() uses; otherwise never below 0, so a seller already over the line reads back 0 rather than a negative. |
| `osc_user_listings_used(?int $userId = null)` | How many live listings $userId currently holds -- what osc_user_listing_limit() is spent against. Counts listings awaiting moderation and admin-disabled ones too (see EntitlementStore::liveListings()), so it matches the gate rather than what is publicly visible. 0 while billing is off or no user is known. |

### hCache (16)

`oc-includes/osclass/helpers/hCache.php`

| Helper | What it does |
|---|---|
| `osc_cache_add($key, $data, $expire = 0)` | Store a value under $key only if it is not cached yet. |
| `osc_cache_category_generation()` | Current generation number for the category cache (Category::toTree and Category::findByPrimaryKey). Folded into their cache keys so a bump makes every stored entry — every locale, every id, tree and by-key alike — unreachable at once, the practical way to invalidate a key space whose members are not enumerable. |
| `osc_cache_close()` | Close the active cache driver. |
| `osc_cache_delete($key)` | Drop the entry stored under $key. |
| `osc_cache_flush()` | Empty the whole cache. |
| `osc_cache_get($key, &$found)` | Read the value stored under $key. |
| `osc_cache_increment($key, $by = 1, $initial = 0, $expire = 0)` | Atomically increment a numeric cache key, creating it at $initial on first sighting, and return the new value. This is what a lock-free hit counter needs: concurrent callers do not clobber one another the way a get()/set() would. |
| `osc_cache_init()` | Initialize Cache factory instance using singleton |
| `osc_cache_search_generation()` | Current generation number for the search/latest-items cache. Callers fold it into their cache key so a bump (see osc_invalidate_search_cache) makes every previously stored entry unreachable at once — the practical way to invalidate a key space whose members are not enumerable (one entry per filter combination). |
| `osc_cache_set($key, $data, $expire = 0)` | Store a value under $key, overwriting any existing entry. |
| `osc_cache_stats()` | Normalised statistics for the active cache driver, or null when it has none. |
| `osc_invalidate_category_cache()` | Bump the category-cache generation, invalidating every cached category tree and category row. Called on the category lifecycle events (add, edit, reorder, delete) so a renamed or moved category leaves the cache immediately instead of lingering for the cache TTL — a slug-change 301 depends on this to resolve the new canonical URL right away. |
| `osc_invalidate_item_cache($itemId)` | Invalidate cached data that is a pure function of an item id (currently the item's resource/photo list). Without this the object cache is TTL-only, so a persistent backend (memcached/apcu) serves a stale copy of an item the user just edited for up to OSC_CACHE_TTL seconds. |
| `osc_invalidate_locale_cache()` | Drop the memoised list of enabled locales (osc_settings_locales()) after a locale is added, edited, enabled, disabled or deleted. |
| `osc_invalidate_search_cache()` | Bump the search-cache generation, invalidating every cached search/latest-items result. Called on the item lifecycle events that change what a search returns (post, edit, disable, enable, spam on/off) so a quarantined or edited listing leaves the search cache immediately instead of lingering for the cache TTL. |
| `osc_invalidate_user_cache($userId)` | Invalidate the cached row for one user (User::findByPrimaryKey). Used when a user's password changes: the cached row carries s_password, which is the remember-me binding, so a persistent object cache serving the old hash would delay "log out on password change" by up to the cache TTL. The osc_cache_* helpers suffix every key with the current user locale, so the row is cached once per locale that has requested it; clear each enabled locale's entry. |

### hCategories (24)

`oc-includes/osclass/helpers/hCategories.php`

| Helper | What it does |
|---|---|
| `osc_categories_select($name = 'sCategory', $category = null, $default_str = null)` | Prints category select |
| `osc_category()` | Gets current category |
| `osc_category_description($locale = '')` | Gets the description of the current category |
| `osc_category_field($field, $locale = '')` | Low level function: Gets the value of the category attribute |
| `osc_category_id($locale = '')` | Gets the id of the current category |
| `osc_category_move_to_children()` | Descend the category pointer into the current category's children. |
| `osc_category_move_to_parent()` | Move the category pointer back up to the current category's parent. |
| `osc_category_name($locale = '')` | Gets the name of the current category |
| `osc_category_parent_id()` | Returns category's parent id |
| `osc_category_price_enabled()` | Returns if the category has the prices enabled or not |
| `osc_category_slug($locale = '')` | Gets the slug of the current category. WARNING: This slug could NOT be used as a valid W3C HTML tag attribute as it could have other characters besides [A-Za-z0-9-_] We only did a urlencode to the variable |
| `osc_category_total_items()` | Gets the total items related with the current category |
| `osc_count_categories()` | Gets the total of categories. If categories are not loaded, this function will load them. |
| `osc_count_subcategories()` | Gets the total of subcategories for the current category. If subcategories are not loaded, this function will load them and it will prepare the the pointer to the first element |
| `osc_count_subcategories2()` | Gets the total of subcategories for the current category. If subcategories are not loaded, this function will load them and it will prepare the the pointer to the first element |
| `osc_export_categories($categories = null)` | Export a category tree to the view, loading the full tree when none is given. |
| `osc_get_categories()` | Low level function: Gets the list of categories as a tree |
| `osc_get_category($by, $what)` | Get th category by id or slug |
| `osc_get_non_empty_categories()` | Gets list of non-empty categories |
| `osc_goto_first_category()` | Reset the pointer of the array to the first category |
| `osc_has_categories()` | Let you know if there are more categories in the list. If categories are not loaded, this function will load them. |
| `osc_has_subcategories()` | Let you know if there are more subcategories for the current category in the list. If subcategories are not loaded, this function will load them and it will prepare the pointer to the first element |
| `osc_priv_count_categories()` | Gets the number of categories |
| `osc_priv_count_subcategories()` | Gets the number of subcategories |

### hDatabase (14)

`oc-includes/osclass/helpers/hDatabase.php`

| Helper | What it does |
|---|---|
| `osc_db_begin()` | Begin a database transaction. |
| `osc_db_commit()` | Commit the current database transaction. |
| `osc_db_count(string $table, string $where = '', array $params = [])` | Count the rows of $table that match a raw WHERE fragment. |
| `osc_db_execute(string $sql, array $params = [])` | Run a parameterized INSERT/UPDATE/DELETE and return affected rows. |
| `osc_db_in_transaction()` | Whether a database transaction is currently open. |
| `osc_db_insert_id(string $sql, array $params = [])` | Run a parameterized INSERT and return the generated AUTO_INCREMENT id. |
| `osc_db_rollback()` | Roll back the current database transaction. |
| `osc_db_scalar(string $sql, array $params = [])` | Run a parameterized SELECT and return the first column of the first row. |
| `osc_db_select(string $sql, array $params = [])` | Run a parameterized SELECT and return every row as an associative array. |
| `osc_db_select_one(string $sql, array $params = [])` | Run a parameterized SELECT and return the first row, or null. |
| `osc_db_stringify_row(array $row)` | A row with every value a string, as Db::stringifyRow() gives it. |
| `osc_db_stringify_rows(array $rows)` | Apply osc_db_stringify_row() to a list of rows. |
| `osc_db_table(string $table)` | Start an immutable fluent query builder for $table. |
| `osc_db_transaction(callable $fn)` | Run $fn inside a database transaction (or a savepoint when nested), committing on success and rolling back on any Throwable. |

### hDatabaseInfo (4)

`oc-includes/osclass/helpers/hDatabaseInfo.php`

| Helper | What it does |
|---|---|
| `osc_db_host()` | **Deprecated since 4.0.0.** Gets database host |
| `osc_db_name()` | **Deprecated since 4.0.0.** Gets database name |
| `osc_db_password()` | **Deprecated since 4.0.0.** Gets database password |
| `osc_db_user()` | **Deprecated since 4.0.0.** Gets database user |

### hDefines (104)

`oc-includes/osclass/helpers/hDefines.php`

| Helper | What it does |
|---|---|
| `osc_add_option_menu($option = null)` | Prints the additional options to the menu |
| `osc_admin_base_path()` | Gets the root path of oc-admin |
| `osc_admin_base_url($with_index = false)` | Gets the root url of oc-admin for your installation |
| `osc_asset_url_versioned($url)` | Append a cache-busting version query to an asset URL. |
| `osc_assets_url($file = '', $assets_base_url = null)` | Gets the complete path of a given common asset |
| `osc_base_path()` | Gets the root path for your installation |
| `osc_base_url($with_index = false)` | Gets the root url for your installation |
| `osc_breadcrumb($separator = '&raquo;', $echo = true, $lang = array())` | Render the breadcrumb trail for the current page. |
| `osc_change_language_url($locale)` | Gets url for changing website language (for users) |
| `osc_change_user_email_confirm_url($userId, $code)` | Gets confirmation url of change email |
| `osc_change_user_email_url()` | Gets url to change email |
| `osc_change_user_password_url()` | Gets url for changing password |
| `osc_change_user_username_url()` | Gets url to change username |
| `osc_comment_url($locale = '')` | Create automatically the url of the item's comments page |
| `osc_contact_url()` | Create automatically the contact url |
| `osc_content_path()` | Gets the content path |
| `osc_core_url($name, $args = array())` | URL of a core page, built from the shared route table. |
| `osc_current_admin_theme()` | Gets the current oc-admin theme |
| `osc_current_admin_theme_js_url($file = '')` | Gets the complete url of a given js's file |
| `osc_current_admin_theme_path($file = '')` | Gets the complete path of a given admin's file |
| `osc_current_admin_theme_styles_url($file = '')` | Gets the complete url of a given style's file |
| `osc_current_admin_theme_url($file = '')` | Gets the complete url of a given admin's file |
| `osc_current_web_theme()` | Gets the current theme for the public website |
| `osc_current_web_theme_js_url($file = '')` | Gets the complete path of a given js file using the theme path as a root |
| `osc_current_web_theme_path($file = '')` | Gets the complete path of a given file using the theme path as a root |
| `osc_current_web_theme_styles_url($file = '')` | Gets the complete path of a given styles file using the theme path as a root |
| `osc_current_web_theme_url($file = '')` | Gets the complete url of a given file using the theme url as a root |
| `osc_forgot_admin_password_confirm_url($adminId, $code)` | Gets url for confirmation admin password recover proces |
| `osc_forgot_user_password_confirm_url($userId, $code)` | Gets url for confirm the forgot password process |
| `osc_get_cities($region = '')` | Gets list of cities (from a region) |
| `osc_get_countries()` | Gets list of countries |
| `osc_get_currencies()` | Gets list of currencies |
| `osc_get_domain()` | Host part of the install's base URL. |
| `osc_get_osclass_location()` | Get location |
| `osc_get_osclass_section()` | Get section |
| `osc_get_regions($country = '')` | Gets list of regions (from a country) |
| `osc_is_404()` | Get if the user is on 404 error page |
| `osc_is_ad_page()` | Get if user is on ad page |
| `osc_is_change_email_page()` | Get if user is on change email page |
| `osc_is_change_password_page()` | Get if user is on change password page |
| `osc_is_change_username_page()` | Get if user is on change username page |
| `osc_is_contact_page()` | Get if user is on a contact page |
| `osc_is_current_page($location, $section)` | Get if the user is on page |
| `osc_is_custom_page($value = null)` | Get if the user is on custom page |
| `osc_is_edit_page()` | Get if user is on edit page |
| `osc_is_forgot_page()` | Get if the user is on forgot page |
| `osc_is_home_page()` | Get if user is on homepage |
| `osc_is_item_contact_page()` | Get if user is on a item contact page |
| `osc_is_list_alerts()` | Get if the user is on user's alerts page |
| `osc_is_list_items()` | Get if the user is on user's items page |
| `osc_is_login_form()` | **Deprecated since 3.5.7.** Get if user is on login form |
| `osc_is_login_page()` | Get if user is on login page |
| `osc_is_moderator()` | Check is an admin is a super admin or only a moderator |
| `osc_is_public_profile()` | Get if the user is on public profile page |
| `osc_is_publish_page()` | Get if user is on publish page |
| `osc_is_recover_page()` | Get if the user is on recover page |
| `osc_is_register_page()` | Get if user is on register page |
| `osc_is_search_category_page()` | Get if user is on search category page |
| `osc_is_search_page()` | Get if user is on search page |
| `osc_is_static_page()` | Get if user is on a static page |
| `osc_is_subdomain()` | Whether the request is being served from a subdomain. |
| `osc_is_user_dashboard()` | Get if user is on user dashboard |
| `osc_is_user_profile()` | Get if user is on user profile |
| `osc_item_activate_url($secret = '', $id = '')` | Gets url for activate an item |
| `osc_item_admin_edit_url($id)` | Create automatically the url to for admin to edit an item |
| `osc_item_comments_url($page = 'all', $locale = '')` | Create automatically the url of the item's comments page |
| `osc_item_delete_url($secret = '', $id = '')` | Gets url for delete an item. Without $secret the link is for the signed-in owner and carries a CSRF token, which the delete action requires. |
| `osc_item_edit_url($secret = '', $id = '')` | Gets url for editing an item |
| `osc_item_post_url()` | Create automatically the url to post an item |
| `osc_item_post_url_in_category()` | Create automatically the url to post an item in a category |
| `osc_item_resource_delete_url($id, $item, $code, $secret = '')` | Gets url for deleting a resource of an item |
| `osc_item_send_friend_url()` | Gets url of send a friend (current item) |
| `osc_item_url($locale = '')` | Create automatically the url of the item details page |
| `osc_item_url_from_item($item, $locale = '')` | Create item url from item data without exported to view. |
| `osc_item_url_ns($id, $locale = '')` | Create the no friendly url of the item using the id of the item |
| `osc_lib_path()` | Gets the libraries path |
| `osc_plugins_path()` | Gets the plugins path |
| `osc_premium_url($locale = '')` | Create automatically the url of the item details page |
| `osc_recover_user_password_url()` | Gets url for recovering password |
| `osc_register_account_url()` | Create automatically the url to register an account |
| `osc_route_admin_ajax_url($id, $args = array())` | Admin ajax URL of a registered route. |
| `osc_route_admin_url($id, $args = array())` | Admin URL of a registered route, rendered through the plugin controller. |
| `osc_route_ajax_url($id, $args = array())` | Public ajax URL of a registered route. |
| `osc_route_url($id, $args = array())` | URL of a registered route, with rewriting on or off. |
| `osc_search_category_url()` | Create automatically the url of a category |
| `osc_subdomain_base_url($params = array())` | Base URL of the subdomain matching the search parameter the install keys subdomains on. |
| `osc_subdomain_name()` | Display name of the subdomain being served. |
| `osc_subdomain_slug()` | Slug of the subdomain being served. |
| `osc_theme_asset_url($file = '')` | URL of a theme asset, taken from the parent when the active theme does not carry it. |
| `osc_themes_path()` | Gets the themes path |
| `osc_translations_path()` | Gets the translations path |
| `osc_uploads_path()` | Gets the translations path |
| `osc_user_activate_alert_url($id, $secret, $email)` | Gets user alert activate url |
| `osc_user_activate_url($id, $code)` | Create automatically the url to activate an account |
| `osc_user_alerts_url()` | Gets current user alerts' url |
| `osc_user_dashboard_url()` | Create automatically the url of the users' dashboard |
| `osc_user_delete_url()` | Confirm page for deleting the signed-in account. |
| `osc_user_export_url()` | Link that downloads the signed-in person a copy of their own data. |
| `osc_user_list_items_url($page = '', $typeItem = '')` | Gets current user alert activate url |
| `osc_user_login_url()` | Create automatically the login url |
| `osc_user_logout_url()` | Create automatically the logout url |
| `osc_user_profile_url()` | Gets current user url |
| `osc_user_resend_activation_link($id, $email)` | Re-send the activation link |
| `osc_user_unsubscribe_alert_url($id = '', $email = '', $secret = '')` | Gets current user alert unsubscribe url |

### hErrors (3)

`oc-includes/osclass/helpers/hErrors.php`

| Helper | What it does |
|---|---|
| `osc_die($title, $message, $options = array())` | Stop Shopclass and render a full-page system message on the shared, on-brand system page (no Bootstrap, self-contained). The message is trusted HTML, so callers may include links and &lt;code&gt;; embedded .button/.btn links are styled automatically. |
| `osc_get_absolute_url()` | Best-effort base URL of the install, derived from the current request. |
| `osc_request_host()` | The request's host, with the port when the site runs on one other than 80 or 443. Some nginx packages pass the host without its port; it is added back unless a proxy is in front. |

### hFields (8)

`oc-includes/osclass/helpers/hFields.php`

| Helper | What it does |
|---|---|
| `osc_field_is_visible($field, $item = null)` | Whether a custom field is visible for an item under its conditional-logic show_when rule. Reuses the same evaluator as the save path (FieldValidator::evaluateCondition), so display and persistence never diverge. |
| `osc_field_resolve_type($field)` | Resolve a field row's real type id. New/plugin types persist their identity in s_meta ('type') while e_type holds the storage primitive; built-in types store their id directly in e_type. extendField() merges s_meta into the row, so a persisted 'type' surfaces as $field['type']. |
| `osc_field_type($id)` | The spec for a registered field type, or null when it is not registered. |
| `osc_field_type_storage($id)` | The storage primitive (t_meta_fields.e_type value) a field type stores as. |
| `osc_field_types()` | All registered field types, keyed by id. |
| `osc_get_category_field_groups($categoryId)` | The groups (with their inherited fields) that apply to a category, honouring the same category inheritance as loose fields. |
| `osc_get_field_groups()` | All field groups. |
| `osc_register_field_type($id, $spec)` | Register a custom-field type so the field builder can offer it. Thin wrapper over FieldTypeRegistry::register(). |

### hForms (6)

`oc-includes/osclass/helpers/hForms.php`

| Helper | What it does |
|---|---|
| `osc_form_context_display($type, $id)` | Describe a submission's placement context for the admin: ['label' =&gt; string, 'url' =&gt; ?string]. Never throws. |
| `osc_form_context_from_widget($widgetRow)` | Derive a placement context (type, id) from the t_widget row a form is rendered in. A page-builder block lives at location "page.&lt;id&gt;"; anything else is keyed by the widget row id. Phase 3 stamps this pair onto each submission. |
| `osc_form_fields($formId, $contextType = 'widget', $contextId = 0)` | A form's fields for a placement context — its stored fields (Field::findByGroup) after the `form_fields` filter, so plugins may add/remove/reorder per context. Used by BOTH the render (osc_render_form) and the submit route (CWebForm), so what is rendered and what is validated/stored can never diverge. |
| `osc_form_widget_options()` | Options for the core.form widget's form picker: forms flagged "available as a block" (s_meta.placeable). Returned as [{value,label}] and resolved lazily by the widget config renderer, so newly-created forms appear without re-registering the widget type. An item-only form full of item-meta fields is deliberately not listed here. |
| `osc_register_form_context($type, $spec)` | Register a form placement-context type so its submissions read sensibly in the admin. Thin wrapper over FormContextRegistry::register(). |
| `osc_render_form($formId, $contextType = 'widget', $contextId = 0, $enqueueJs = false)` | Render a form's fields as a standalone &lt;form&gt; anywhere. Reuses the item form's per-field render (FieldForm::renderFieldList) so a field looks and behaves identically to the publish form — conditional logic, cascades and field-type rendering all come along. |

### hHttpCache (14)

`oc-includes/osclass/helpers/hHttpCache.php`

| Helper | What it does |
|---|---|
| `osc_cache_relevant_cookies()` | The cookie names that mean "this response is personalized" — core's PHP session, the chosen front-end locale, and the fixed-name cache-bypass flag Cookie::set() writes whenever the visitor carries identity state (front-end user, admin). |
| `osc_etag_matches(string $header, string $etag)` | Whether a conditional header names $etag: `*`, or any tag in the list, weak or strong. |
| `osc_etag_tags(string $header)` | The tags in an If-Match or If-None-Match header, quoted, with any `W/` removed. A proxy that compresses the answer (nginx gzip, Cloudflare) weakens the tag it passes on. |
| `osc_mark_response_cacheable($cacheable = true)` | Declare the current response a public, cacheable read page. |
| `osc_page_cache_purge_flush($http = null)` | Send the purge osc_purge_page_cache() asked for: fire `page_cache_purge` with the reasons, then, when OSC_PAGE_CACHE_PURGE_URL is set (the Docker image), clear nginx's own cache there. Runs at shutdown, after the response has gone; never throws. |
| `osc_page_cache_purge_pending()` | Whether a whole-cache purge is waiting for the end of this request. |
| `osc_page_cache_purge_url()` | Where to send the whole-cache PURGE: OSC_PAGE_CACHE_PURGE_URL when set, else the Docker image's internal address when OSC_MICROCACHE is on (so `docker exec` commands find it too). Empty when there is nothing to purge. |
| `osc_purge_page_cache(string $reason = '')` | Ask for the whole page cache to be cleared once this request ends. |
| `osc_response_etag($body)` | Answer a repeat request with "nothing changed" instead of the page again. |
| `osc_response_etag_value($body)` | The validator for a page body: what identifies "this exact content" to a client. |
| `osc_response_is_cacheable()` | Whether the current response may be served from / stored in a shared cache: an opted-in public read page, requested with GET or HEAD, with no logged-in web or admin user, no active PHP session, and no personalization cookie present (nothing per-visitor this request). |
| `osc_response_server_timing($body)` | Tell the browser how long an HTML page took to build, in a Server-Timing header. The page itself is unchanged, so its ETag and any cached copy are too. |
| `osc_send_response_cache_headers()` | Emit the Cache-Control header for a front-end response, once, after the page has been built. |
| `osc_server_timing_value(float $start, float $now)` | The Server-Timing value for a page built between $start and $now, in milliseconds. |

### hItems (115)

`oc-includes/osclass/helpers/hItems.php`

| Helper | What it does |
|---|---|
| `osc_comment()` | Gets comment array form view |
| `osc_comment_author_email()` | Gets author email of current comment |
| `osc_comment_author_name()` | Gets author name of current comment |
| `osc_comment_body()` | Gets body of current comment |
| `osc_comment_field($field, $locale = '')` | Gets a specific field from current comment |
| `osc_comment_id()` | Gets id of current comment |
| `osc_comment_pub_date()` | Gets publication date of current comment |
| `osc_comment_title()` | Gets title of current commnet |
| `osc_comment_user_id()` | Gets user id of current comment |
| `osc_count_custom_items()` | Gets number of custom items |
| `osc_count_item_comments()` | Gets number of item comments of current item |
| `osc_count_item_meta()` | Gets number of item meta field |
| `osc_count_item_resources()` | Gets number of resources in array resources of current item |
| `osc_count_items()` | Gets number of items in current array items |
| `osc_count_latest_items($total_latest_items = null, $options = array())` | Gets number of latest items |
| `osc_delete_comment_url()` | Gets  link to delete the current comment of current item |
| `osc_format_price($price, $symbol = null)` | Formats the price using the appropiate currency. |
| `osc_get_item_meta()` | Gets item meta fields |
| `osc_get_item_resources()` | Gets current resource of current array resources of current item |
| `osc_has_custom_items()` | Gets next item of custom items |
| `osc_has_item_comments()` | Gets next comment of current item comments |
| `osc_has_item_meta()` | Gets next item meta field if there is, else return null |
| `osc_has_item_resources()` | Gets next item resource if there is, else return null |
| `osc_has_items()` | Gets next item if there is, else return null |
| `osc_has_latest_items($total_latest_items = null, $options = array(), $withPicture = false)` | Gets next item of latest items query |
| `osc_item()` | Gets current item array from view |
| `osc_item_address()` | Gets address of current item |
| `osc_item_adjacent_id(string $direction = 'next', ?int $itemId = null)` | Id of the nearest live listing by id order: 'next' is the next higher id, 'prev' the next lower. Live means what public search shows: enabled, active, not spam, and premium or not expired. |
| `osc_item_adjacent_url(string $direction = 'next', ?int $itemId = null)` | URL of the nearest live listing by id order, as osc_item_url_from_item() builds it. |
| `osc_item_category($locale = '')` | Gets category from current item |
| `osc_item_category_description($locale = '')` | Gets category description from current item, if $locale is unspecified $locale is current user locale |
| `osc_item_category_id()` | Gets category id of current item |
| `osc_item_category_price_enabled($catId = null)` | Checks to see if the price is enabled for this category. |
| `osc_item_city()` | Gets city of current item |
| `osc_item_city_area()` | Gets city area of current item |
| `osc_item_city_area_id()` | Gets city area of current item |
| `osc_item_city_id()` | Gets city of current item |
| `osc_item_comments_page()` | Gets page of comments in current pagination |
| `osc_item_contact_email()` | Gets contact email of current item |
| `osc_item_contact_name()` | Gets contact name of current item |
| `osc_item_contact_phone()` | Gets contact phone of current item |
| `osc_item_content_locale()` | The locale the current item's title and description actually resolve to. |
| `osc_item_country()` | Gets country name of current item |
| `osc_item_country_code()` | Gets country code of current item Country code are two letters like US, ES, ... |
| `osc_item_currency()` | Gets currency of current item |
| `osc_item_currency_symbol()` | Gets currency symbol of an item |
| `osc_item_description($locale = '')` | Gets description from current item, if $locale is unspecified $locale is current user locale |
| `osc_item_dt_expiration()` | Gets date expiration of current item |
| `osc_item_field($field, $locale = '')` | Gets a specific field from current item |
| `osc_item_formated_price()` |  |
| `osc_item_formatted_price()` | Gets formatted price of current item |
| `osc_item_id()` | Gets id from current item |
| `osc_item_ip()` | Gets IP of current item |
| `osc_item_is_active()` | Gets if current item is active |
| `osc_item_is_enabled()` | Gets if current item is enabled |
| `osc_item_is_expired()` | Return true if item is expired, else return false |
| `osc_item_is_inactive()` | Gets if current item is inactive |
| `osc_item_is_premium()` | Gets true if current item is marked premium, else return false |
| `osc_item_is_spam()` | Gets if item is marked as spam |
| `osc_item_latitude()` | Gets latitude of current item |
| `osc_item_link_bad_category()` | Retrun link for mark as bad category the current item. |
| `osc_item_link_expired()` | Gets link for mark as expired the current item |
| `osc_item_link_offensive()` | Gets link for mark as offensive the current item |
| `osc_item_link_repeated()` | Gets link for mark as repeated the current item |
| `osc_item_link_spam()` | Gets link for mark as spam the current item |
| `osc_item_longitude()` | Gets longitude of current item |
| `osc_item_map_type()` | Get selected map type. |
| `osc_item_meta()` | Gets item meta field |
| `osc_item_meta_id()` | Gets item meta id |
| `osc_item_meta_name()` | Gets item meta name |
| `osc_item_meta_slug()` | Gets item meta slug |
| `osc_item_meta_value()` | Gets item meta value |
| `osc_item_mod_date()` | Gets modification date of current item |
| `osc_item_price()` | Gets price of current item |
| `osc_item_pub_date()` | Gets publication date of current item |
| `osc_item_region()` | Gets region of current item |
| `osc_item_region_id()` | Gets region id of current item |
| `osc_item_secret()` | Gets secret string of current item |
| `osc_item_show_email()` | Gets true if can show email user at frontend, else return false |
| `osc_item_status()` | Gets status of current item. b_active = true  -&gt; item is active b_active = false -&gt; item is inactive |
| `osc_item_title($locale = '')` | Gets title from current item, if $locale is unspecified $locale is current user locale |
| `osc_item_total_comments()` | Gets total number of comments of current item |
| `osc_item_user_id()` | Gets user id from current item |
| `osc_item_views($viewAll = false)` | return number of views of current item |
| `osc_item_zip()` | Gets zip code of current item |
| `osc_list_items_per_page()` | Gets number of items per page for current pagination |
| `osc_list_page()` | Current page of the search pagination. |
| `osc_list_total_pages()` | Total number of pages in the search pagination. |
| `osc_priv_count_item_resources()` | **Deprecated since 2.4.** Gets number of item resources |
| `osc_priv_count_items()` | **Deprecated since 2.4.** Gets number of items |
| `osc_query_item($params = null)` | Perform a search based on custom filters and conditions export the results to a variable to be able to manage it from custom_items' helpers |
| `osc_reset_custom_items()` | Set the internal pointer of array customItems to its first element, and return it. |
| `osc_reset_items()` | Set the internal pointer of array items to its first element, and return it. |
| `osc_reset_latest_items()` | Set the internal pointer of array latestItems to its first element, and return it. |
| `osc_reset_resources()` | Set the internal pointer of array resources to its first element, and return it. |
| `osc_resource()` | Gets resource array from view |
| `osc_resource_alt()` | Alt text for the photo the resource loop is on: the title of the listing it belongs to. |
| `osc_resource_download_filename($resource, $variant = '')` | A human-friendly download filename for a resource: a slug of its owner's title plus the resource id and variant, e.g. "red-toyota-corolla-4831.jpg" or "jane-doe-12-thumbnail.png". Falls back to the owner type, then "file", so the result is always a valid, unique name — the id keeps it collision-free and the extension is the stored one. The slug is hard-limited to [a-z0-9-] so the value is safe to place in a Content-Disposition header. |
| `osc_resource_download_url($variant = '')` | Download URL for the current resource in the loop, routed through the resource controller so the file is delivered with a friendly Content-Disposition name. Inline display should keep using osc_resource_url() (direct static/CDN); this is for an explicit "Download" link. $variant is one of ResourceLocator::variants(). |
| `osc_resource_extension()` | Gets extension of current resource |
| `osc_resource_field($field, $locale = '')` | Gets a specific field from current resource |
| `osc_resource_id()` | Gets id of current resource |
| `osc_resource_name()` | Gets name of current resource |
| `osc_resource_original_url()` | Gets original resource url of current resource |
| `osc_resource_owner_title($resource)` | A human title for whatever a resource belongs to, used to build a friendly download name: the listing title for an item image, the page title for a page image, the display name for a user avatar. Empty string when the owner has no title, or the resource is ownerless. |
| `osc_resource_path()` | Gets path of current resource |
| `osc_resource_preview_url()` | Gets preview url of current resource |
| `osc_resource_thumbnail_url()` | Gets thumbnail url of current resource |
| `osc_resource_type()` | Gets content type of current resource |
| `osc_resource_url()` | Gets url of current resource |
| `osc_show_item_comments()` | Render the comments block for the current listing: the thread and the form. |
| `osc_total_active_items()` | Gets total number of active items |
| `osc_total_active_items_today()` | Gets total number of active items today |
| `osc_total_items()` | Gets total number of all items |
| `osc_total_items_today()` | Gets total number of all items today |

### hJobs (15)

`oc-includes/osclass/helpers/hJobs.php`

| Helper | What it does |
|---|---|
| `osc_auto_cron_dispatch(bool $responseSent = false)` | Runs scheduled tasks after the response when auto-cron is on, at most once per five minutes. Web pages queue the work to run at shutdown; an API response is already sent, so it runs now. |
| `osc_job_count(string $status = 'pending', ?string $type = null)` | How many jobs are in one status, optionally of one type. |
| `osc_job_dead_letters(int $limit = 50)` | The jobs that stopped retrying, newest first, each with its last error. |
| `osc_job_describe(string $type, string $name, ?callable $detail = null)` | Name a job type for the admin's Background jobs screen and activity log. |
| `osc_job_enqueue(string $type, array $payload = array(), array $options = array())` | Put a job on the queue. |
| `osc_job_enqueue_many(string $type, array $payloads, array $options = array())` | Put many jobs of one type on the queue in a few inserts. |
| `osc_job_ensure(string $type, array $payload = array(), array $options = array())` | Queue a job of $type only when none is pending or running. |
| `osc_job_forget(int $id)` | Throw away a job that stopped retrying. |
| `osc_job_has_handler(string $type)` | Whether a handler is registered for a job type. |
| `osc_job_register_handler(string $type, callable $handler)` | Say which callable runs $type. Register from the `register_jobs` hook, so the handler exists in a cron request too -- not only where the job was queued. |
| `osc_job_registered_types()` | Every type something has registered for, sorted. |
| `osc_job_retry(int $id)` | Put a job that stopped retrying back on the queue, attempts cleared. |
| `osc_job_run(int $maxSeconds = 20)` | Drain the queue now, for up to $maxSeconds. Cron already does this; call it only where a job should happen without waiting for the next tick. |
| `osc_job_stats(?string $type = null)` | Pending, running and failed counts, when the oldest pending job was created, and how many pending jobs are due now and since when. |
| `osc_job_summary()` | Jobs per status as status =&gt; count, with every status present. |

### hKv (5)

`oc-includes/osclass/helpers/hKv.php`

| Helper | What it does |
|---|---|
| `osc_kv_claim(string $group, string $key, int $ttl, string $state = 'locked')` | Take a key for $ttl seconds: true for exactly one caller while the key is absent or expired, false while someone else holds it. Delete the key to release it early. |
| `osc_kv_delete(string $group, string $key)` | Remove a key. |
| `osc_kv_delete_group(string $group)` | Remove every key of a group, e.g. a plugin's own when it is uninstalled. |
| `osc_kv_get(string $group, string $key, $default = null)` | Read a key. |
| `osc_kv_set(string $group, string $key, $value, ?int $ttl = null)` | Write a key, replacing any value, state and expiry it had. |

### hLocale (20)

`oc-includes/osclass/helpers/hLocale.php`

| Helper | What it does |
|---|---|
| `osc_all_enabled_locales_for_admin($indexed_by_pk = false)` | Gets list of enabled locales |
| `osc_count_web_enabled_locales()` | Gets number of enabled locales for website |
| `osc_current_admin_locale()` | Get the actual locale of the admin. |
| `osc_current_user_locale()` | Get the actual locale of the user. |
| `osc_get_admin_locales()` | Gets list of enabled admin locales |
| `osc_get_current_user_locale()` | Gets current locale object |
| `osc_get_locales()` | Gets list of locales |
| `osc_goto_first_locale()` | Reset iterator of locales |
| `osc_has_web_enabled_locales()` | Iterator for enabled locales for website |
| `osc_locale()` | Gets locale object |
| `osc_locale_code()` | Gets current locale's code |
| `osc_locale_currency_format()` | Gets current locale's currency format |
| `osc_locale_dec_point()` | Gets current locale's decimal point |
| `osc_locale_field($field, $locale = '')` | Gets locale generic field |
| `osc_locale_name()` | Gets current locale's name |
| `osc_locale_num_dec()` | Gets current locale's number of decimals |
| `osc_locale_text_direction()` | Gets current locale's test direction |
| `osc_locale_thousands_sep()` | Gets current locale's thousands separator |
| `osc_priv_count_locales()` | Private function to count locales |
| `osc_set_current_user_locale($locale)` | Persist the visitor's chosen front-end locale in a dedicated cookie. |

### hLocation (25)

`oc-includes/osclass/helpers/hLocation.php`

| Helper | What it does |
|---|---|
| `osc_city()` | Gets current city |
| `osc_city_area()` | Gets current city area |
| `osc_city_area_items()` | Gets city area's items |
| `osc_city_area_name()` | Gets city area's name |
| `osc_city_area_url()` | Gets city area's url |
| `osc_city_items()` | Gets city's items |
| `osc_city_name()` | Gets city's name |
| `osc_city_url()` | Gets city's url |
| `osc_count_cities($region = '%%%%')` | Gets number of cities |
| `osc_count_city_areas($city = '%%%%')` | Gets number of city areas |
| `osc_count_countries()` | Gets number of countries |
| `osc_count_regions($country = '%%%%')` | Gets number of regions |
| `osc_country()` | Gets current country |
| `osc_country_items()` | Gets country's items |
| `osc_country_name()` | Gets country's name |
| `osc_country_url()` | Gets country's url |
| `osc_has_cities($region = '%%%%')` | Iterator for cities, return null if there's no more cities |
| `osc_has_city_areas($city = '%%%%')` | Iterator for city areas, return null if there's no more city areas |
| `osc_has_countries()` | Iterator for countries, return null if there's no more countries |
| `osc_has_regions($country = '%%%%')` | Iterator for regions, return null if there's no more regions |
| `osc_install_json_locations($location = null)` | Install or update one country's locations from the published catalog. |
| `osc_region()` | Gets current region |
| `osc_region_items()` | Gets region's items |
| `osc_region_name()` | Gets region's name |
| `osc_region_url()` | Gets region's url |

### hMaintenance (10)

`oc-includes/osclass/helpers/hMaintenance.php`

| Helper | What it does |
|---|---|
| `osc_maintenance_default_message()` | Default visitor copy when the admin has not saved a message. |
| `osc_maintenance_is_restoring($path)` | Whether `.maintenance` was written by a backup restore. |
| `osc_maintenance_is_upgrading($path)` | Whether `.maintenance` was written by the package upgrader. |
| `osc_maintenance_lockout_enabled()` | Whether public visitors should get HTTP 503 while `.maintenance` exists. |
| `osc_maintenance_lockout_from_pref($value)` | Whether a stored lockout preference means "503 the public site". |
| `osc_maintenance_locks_everyone($path)` | Whether `.maintenance` holds a marker that locks out everyone but admins: an upgrade or a restore in progress. |
| `osc_maintenance_set($on, $path = null)` | Turn maintenance mode on or off by writing or removing the `.maintenance` file, and clear the page cache when it changed. |
| `osc_maintenance_should_lockout_request($fileExists, $lockoutEnabled, $isAdmin, $isCli, $upgrading = false)` | Should this request be answered with HTTP 503? |
| `osc_maintenance_visitor_message()` | Plain-text message shown on the public banner and the 503 page. |
| `osc_sanitize_maintenance_message($raw)` | Strip tags, trim, and cap the admin-written maintenance message. |

### hMessages (7)

`oc-includes/osclass/helpers/hMessages.php`

| Helper | What it does |
|---|---|
| `osc_add_flash_error_message($msg, $section = 'pubMessages')` | Adds an ephemeral message to the session. (error style) |
| `osc_add_flash_info_message($msg, $section = 'pubMessages')` | Adds an ephemeral message to the session. (info style) |
| `osc_add_flash_message($msg, $section = 'pubMessages')` | Adds an ephemeral message to the session. (error style) |
| `osc_add_flash_ok_message($msg, $section = 'pubMessages')` | Adds an ephemeral message to the session. (ok style) |
| `osc_add_flash_warning_message($msg, $section = 'pubMessages')` | Adds an ephemeral message to the session. (warning style) |
| `osc_get_flash_message($section = 'pubMessages', $dropMessages = true)` | The pending flash messages of a section, dropping them from the session by default. |
| `osc_show_flash_message($section = 'pubMessages', $class = 'flashmessage', $id = 'flashmessage')` | Shows all the pending flash messages in session and cleans up the array. |

### hPage (15)

`oc-includes/osclass/helpers/hPage.php`

| Helper | What it does |
|---|---|
| `osc_count_static_pages()` | Gets the total of static pages. If static pages are not loaded, this function will load them. |
| `osc_get_static_page($internal_name, $locale = '')` | Gets the specified static page by internal name. |
| `osc_has_static_pages()` | Let you know if there are more static pages in the list. If static pages are not loaded, this function will load them. |
| `osc_reset_static_pages()` | Move the iterator to the first position of the pages array It reset the osc_has_page function so you could have several loops on the same page |
| `osc_static_page()` | Gets current page object |
| `osc_static_page_field($field, $locale = '')` | Gets current page field |
| `osc_static_page_id()` | Gets current page ID |
| `osc_static_page_meta($field = null)` | Gets current page meta information |
| `osc_static_page_mod_date()` | Gets current page modification date |
| `osc_static_page_order()` | Get page order |
| `osc_static_page_pub_date()` | Gets current page publish date |
| `osc_static_page_slug()` | Gets current page slug or internal name |
| `osc_static_page_text($locale = '')` | Gets current page text |
| `osc_static_page_title($locale = '')` | Gets current page title |
| `osc_static_page_url($locale = '')` | Gets current page url |

### hPageTemplates (5)

`oc-includes/osclass/helpers/hPageTemplates.php`

| Helper | What it does |
|---|---|
| `osc_page_builder_location($pageId = null)` | The widget location key for a static page's block canvas. Page blocks are ordinary t_widget rows stored at this location, so the whole widget stack (types, ordering, render dispatch) composes a page with no parallel system. |
| `osc_page_template($id)` | The spec for a registered page template, or null when the id is not registered. |
| `osc_page_templates()` | All registered page templates, keyed by id. |
| `osc_register_page_template($id, $spec)` | Register a static-page template so it can be picked from the page editor and rendered for a static page. Thin wrapper over PageTemplateRegistry::register(). |
| `osc_show_page_widgets()` | Render the current static page's blocks — the widgets placed on its canvas. Themes call this from a template-widgets.php; the core fallback renderer calls it too. Emits nothing when the page has no blocks. |

### hPagination (6)

`oc-includes/osclass/helpers/hPagination.php`

| Helper | What it does |
|---|---|
| `osc_comments_pagination()` | Gets the pagination links of comments pagination |
| `osc_pagination($params = null)` | Gets generic pagination links |
| `osc_pagination_items($extraParams = array(), $field = false)` | Gets the pagination links of the user's or public profile's listings |
| `osc_pagination_showing($from, $to, $filtered, $total = null)` | The "Showing x to y of z results" line under a paginated table. |
| `osc_search_pagination()` | Gets the pagination links of search pagination |
| `osc_show_pagination_admin($aData)` | Print the admin list pager: the range line, a "go to page" form and the page links. osc_admin_pagination() and osc_admin_pager() both draw through this. |

### hPlugins (29)

`oc-includes/osclass/helpers/hPlugins.php`

| Helper | What it does |
|---|---|
| `osc_add_filter($hook, $function, $priority = 5)` | Add a filter |
| `osc_add_hook($hook, $function, $priority = 5)` | Add a hook |
| `osc_admin_ajax_hook_url($hook = '', $params = array())` | Gets the ajax url |
| `osc_admin_configure_plugin_url($file = '')` | Gets the configure admin's url |
| `osc_admin_plugin_page($route, array $opts = array())` | Say what a plugin's own admin screen is, for the page header core draws around it: the browser title, the help behind the "?" and the icon actions beside the heading. Call it when the plugin loads; the screen's file runs after the header is drawn. |
| `osc_admin_render_plugin($file = '')` | Show custom plugin administrationfile |
| `osc_admin_render_plugin_url($file = '')` | Gets urls for custom plugin administrations options |
| `osc_ajax_hook_url($hook = '', $params = array())` | Gets the ajax url |
| `osc_ajax_plugin_url($file = '')` | Gets the path for ajax |
| `osc_apply_filter($hook, $content, ...$args)` | Apply a filter to a text |
| `osc_get_plugins()` | Get list of the plugins |
| `osc_is_this_category($name, $id)` | If the plugin is attached to the category |
| `osc_plugin_check_update($plugin)` | **Deprecated since 6.3.0 use mindstellar\market\PackageIndex::forPlugins()-&gt;pendingUpdates() instead.** Check if there's a new version of the plugin |
| `osc_plugin_configure_url($plugin)` | Gets plugin's configure url |
| `osc_plugin_configure_view($plugin)` | Show the default configure view for plugins (attach them to categories) |
| `osc_plugin_folder($file)` | Fix the problem of symbolics links in the path of the file |
| `osc_plugin_get_info($plugin)` | Returns plugin's information |
| `osc_plugin_has_icon($plugin = null)` | Whether a plugin has an icon asset on disk, as opposed to the fallback placeholder osc_plugin_icon_url() returns. |
| `osc_plugin_icon_url($plugin = null)` | Public URL of a plugin's icon, or a bundled placeholder when it has none. Checks assets/icon.svg, then assets/icon.png, then assets/icon-256.png on disk, in that order. |
| `osc_plugin_is_enabled($plugin)` | Gets if a plugin is enabled or not |
| `osc_plugin_is_installed($plugin)` | Gets if a plugin is installed or not |
| `osc_plugin_path($file)` | Fix the problem of symbolics links in the path of the file |
| `osc_plugin_relative_path($file)` | A plugin file's path inside the plugins folder, e.g. `my-plugin/index.php`. |
| `osc_plugin_resource($file)` | Gets the path to a plugin's resource |
| `osc_plugin_url($file)` | Fix the problem of symbolics links in the path of the file |
| `osc_register_plugin($path, $function)` | Register a plugin file to be loaded |
| `osc_remove_filter($hook, $function)` | Remove a filter's function |
| `osc_remove_hook($hook, $function)` | Remove a hook's function |
| `osc_run_hook($hook, ...$args)` | Run a hook |

### hPreference (130)

`oc-includes/osclass/helpers/hPreference.php`

| Helper | What it does |
|---|---|
| `osc_active_plugins()` | Gets list of active plugins |
| `osc_admin_language()` | Gets website's admin default language |
| `osc_admin_log_retention_days()` | Retention window for admin activity logs, in days. 0 keeps rows forever. Defaults to 90 days when unset, so the log cannot grow without bound. |
| `osc_admin_theme()` | Gets current admin theme |
| `osc_akismet_key()` | Gets akismet key |
| `osc_allowed_extension()` | Gets allowed extensions of uploads |
| `osc_auto_cron()` | Gets if autocron is enabled |
| `osc_auto_update()` | Gets auto update settings |
| `osc_billing_enabled()` | Whether the billing section is switched on. |
| `osc_captcha_provider_pref()` | Gets the configured captcha provider preference. |
| `osc_comment_spam_delay()` | Gets how many seconds between comment post to not consider it SPAM |
| `osc_comments_enabled()` | Gets if comments are enabled or not |
| `osc_comments_per_page()` | Gets comments per page |
| `osc_contact_attachment()` | Gets if contact attachment is enabled |
| `osc_contact_email()` | Gets contact email |
| `osc_count_bot_views()` | Whether requests from crawlers count as listing views. Defaults to off — a crawler is not a reader, and on a well-indexed site bots are the majority of traffic, so counting them both inflates the numbers and multiplies the writes. |
| `osc_csrf_name()` | Gets csrf session name |
| `osc_currency()` | Gets default currency |
| `osc_date_format()` | Gets date format |
| `osc_default_order_field_at_search()` | Gets default order field at search |
| `osc_default_order_type_at_search()` | Gets default order type at search |
| `osc_default_results_per_page_at_search()` | Gets default results per page at search |
| `osc_default_show_as_at_search()` | Gets default show as at search |
| `osc_delete_preference($key = '', $section = 'osclass')` | generic function to delete preferences |
| `osc_enable_send_friend()` | Whether the "share listing / send to a friend" form is available at all. |
| `osc_force_aspect_image()` | Force image aspect |
| `osc_force_jpeg()` | Force uploaded images to be JPEG |
| `osc_get_bool_preference($key, $section = 'osclass')` | generic function to retrieve preferences as bool |
| `osc_get_preference($key, $section = 'osclass')` | generic function to retrieve preferences |
| `osc_get_preference_section($section = 'osclass')` | generic function to retrieve preferences |
| `osc_image_format()` | How new photos are saved: original, jpeg or webp. |
| `osc_images_enabled_at_items()` | Gets if images are o not enabled in item's form |
| `osc_installed_plugins()` | Gets list of installed plugins |
| `osc_is_admin_log_enabled()` | Whether admin activity logging (t_log) is enabled. Defaults to on when the preference has never been set, so existing installs keep logging as before. |
| `osc_is_watermark_image()` | Return if need mark images with image |
| `osc_is_watermark_text()` | Return if need mark images with text |
| `osc_item_attachment()` | Gets item attachment is enabled |
| `osc_item_spam_delay()` | Gets how many seconds between item post to not consider it SPAM |
| `osc_item_stats_retention_days()` | Retention window for the site-wide daily stats rollup, in days. 0 keeps rows forever, which is the default: the rollup is a handful of rows per day for the whole site regardless of its size, and the admin reports chart looks back as far as ten months. |
| `osc_item_views_enabled()` | Whether listing view counting is enabled. Defaults to on when the preference has never been set, so existing installs keep counting as before. |
| `osc_items_wait_time()` | Gets how many seconds should an user wait to post a second item (0 for no waiting) |
| `osc_keep_original_image()` | Gets if original images should be kept |
| `osc_language()` | Gets website's default language |
| `osc_languages_last_version_check()` | Gets when was the last version check |
| `osc_last_version_check()` | Gets when was the last version check |
| `osc_logged_user_item_validation()` | Gets if validation for logged users is required or not |
| `osc_login_attempt_retention_days()` | How long recorded attempts are kept, in days, before the daily cron drops them. Only the window matters to the limiter; the rest is history. 0 keeps them forever. |
| `osc_login_throttle_enabled()` | Whether failed sign-ins are counted and limited. On unless turned off. |
| `osc_login_throttle_max_account()` | Failures against one account within the window before it needs a captcha, or is blocked where no captcha can be shown. |
| `osc_login_throttle_max_ip()` | Failures from one address within the window before it is blocked. Counts every account the address tried, so it is the looser of the two limits -- a shared office or NAT address is several people behind one IP. |
| `osc_login_throttle_window()` | How far back failures are counted, in minutes. Doubles as how long a block lasts, since a block lifts once enough attempts have aged past the window. |
| `osc_mailserver_auth()` | Gets if the mailserver requires authetification |
| `osc_mailserver_host()` | Gets mailserver's host |
| `osc_mailserver_mail_from()` | Gets mail from |
| `osc_mailserver_name_from()` | Gets name from |
| `osc_mailserver_password()` | Gets mailserver's password |
| `osc_mailserver_pop()` | Gets if the mailserver requires authetification |
| `osc_mailserver_port()` | Gets mailserver's port |
| `osc_mailserver_ssl()` | Gets if use SSL on the mailserver |
| `osc_mailserver_type()` | Gets mailserver's type |
| `osc_mailserver_username()` | Gets mailserver's username |
| `osc_market_external_sources()` | Gets if third party sources are allowed to install new plugins and themes |
| `osc_max_characters_per_description()` | Gets how many characters are allowed for the listings description |
| `osc_max_characters_per_title()` | Gets how many characters are allowed for the listings title. Never more than the title column holds, whatever is stored. |
| `osc_max_images_per_item()` | Gets how many images are allowed per item (o for unlimited) |
| `osc_max_latest_items()` | Gets max latest items |
| `osc_max_latest_items_at_home()` | Return max. number of latest items displayed at home index |
| `osc_max_results_per_page_at_search()` | Gets max results per page at search |
| `osc_max_size_kb()` | Gets max kb of uploads |
| `osc_mod_rewrite_loaded()` | Gets if mod rewrite is loaded or not (if apache runs on cgi mode, mod rewrite will not be detected) |
| `osc_moderate_admin_edit()` | Gets if admin needs to moderate edited items. |
| `osc_moderate_admin_post()` | Gets if admin needs to moderate newly posted items. |
| `osc_moderate_comments()` | Gets how many comments should be posted before auto-moderation |
| `osc_moderate_items()` | Gets how many items should be moderated to enable auto-moderation |
| `osc_normal_dimensions()` | Gets normal size images' dimensions |
| `osc_notify_contact_friends()` | Gets if notification are sent to admin when a send-a-friend message is sent |
| `osc_notify_contact_item()` | Gets if notification are sent to admin when a contact message is sent |
| `osc_notify_new_comment()` | Gets if notification of new comments is enabled or not to admin |
| `osc_notify_new_comment_user()` | Gets if notification of new comments is enabled or notto users |
| `osc_notify_new_item()` | Gets if notification are sent to admin with new item |
| `osc_notify_new_user()` | Gets if notification are sent to admin with new user |
| `osc_num_rss_items()` | Gets number of items to display on RSS |
| `osc_page_description()` | Gets website description |
| `osc_page_title()` | Gets website's title |
| `osc_plugins_last_version_check()` | Gets when was the last version check |
| `osc_preview_dimensions()` | Gets preview images' dimensions |
| `osc_price_enabled_at_items()` | Gets if the prices are o not enabled on the item's form |
| `osc_purge_latest_searches()` | How long latest searches are kept before they are purged. |
| `osc_recaptcha_comments_enabled()` | Gets if the captcha for the comment form is enabled or not |
| `osc_recaptcha_items_enabled()` | Gets if recaptcha for items is enabled or not |
| `osc_recaptcha_private_key()` | Gets recaptcha private key |
| `osc_recaptcha_public_key()` | Gets recaptcha public key |
| `osc_recaptcha_reports_enabled()` | Gets if the captcha for the report-listing form is enabled or not |
| `osc_recaptcha_version()` | Return version of recaptcha |
| `osc_reg_user_can_contact()` | Gets if only users can contact to seller |
| `osc_reg_user_can_send_friend()` | Whether sharing a listing by email requires a logged-in web user. |
| `osc_reg_user_post()` | Gets if only registered users can publish new items or anyone could |
| `osc_reg_user_post_comments()` | Gets if only users can post comments |
| `osc_reset_preferences()` | Reload preferences |
| `osc_rewrite_enabled()` | Gets if nice urls are enabled or not |
| `osc_rewrite_rules()` | Gets the rewrite rules (generated via generate_rules.php at root folder) |
| `osc_save_latest_searches()` | Gets if save searches is enabled or not |
| `osc_save_webp()` | Whether new photos are stored as WebP. Only when the server can write WebP. |
| `osc_selectable_parent_categories()` | Gets if parent categories are enabled or not |
| `osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')` | generic function to insert/update preferences |
| `osc_subdomain_host()` | Return subdomain host |
| `osc_subdomain_type()` | Return subdomain type |
| `osc_theme()` | Gets current theme |
| `osc_themes_last_version_check()` | Gets when was the last version check |
| `osc_thumbnail_dimensions()` | Gets thumbnails' dimensions |
| `osc_time_cookie()` | Gets cookie's life |
| `osc_time_format()` | Gets time format |
| `osc_timezone()` | Gets timezone |
| `osc_tinymce_frontend()` | Gets if TinyMCE is enabled on frontend. |
| `osc_turnstile_secret_key()` | Gets the Cloudflare Turnstile secret key. |
| `osc_turnstile_site_key()` | Gets the Cloudflare Turnstile site key (public, safe for markup). |
| `osc_update_check_save(string $kind, array $state)` | Save an update check's result for $kind, and drop the preference rows it used to live in. |
| `osc_update_check_state(string $kind)` | The saved result of the last update check for core, plugins, themes, languages or the location catalog. It lives in the key-value store because preferences load on every request. |
| `osc_update_core_json()` | Gets json response when checking if there is available a new version |
| `osc_use_imagick()` | Gets if use of imagick is enabled or not |
| `osc_user_registration_enabled()` | Gets if user registration is enabled |
| `osc_user_validation_enabled()` | Gets is user validation is enabled or not |
| `osc_username_blacklist()` | Gets list of blacklsited terms for usernames |
| `osc_users_enabled()` | Gets if users are enabled or not |
| `osc_version()` | Gets current version |
| `osc_warn_expiration()` | Gets number of days to warn about an ad being expired |
| `osc_watermark_place()` | Return watermark place |
| `osc_watermark_text()` | Return watermark text |
| `osc_watermark_text_color()` | Return watermark text color |
| `osc_week_starts_at()` | Gets week start day |

### hPremium (47)

`oc-includes/osclass/helpers/hPremium.php`

| Helper | What it does |
|---|---|
| `osc_count_premium_meta()` | Gets number of premium meta field |
| `osc_count_premium_resources()` | Gets number of resources in array resources of current premium |
| `osc_count_premiums()` | Gets number of premiums in current array premiums |
| `osc_get_premium_meta()` | Gets premium meta fields |
| `osc_get_premium_resources()` | Gets current resource of current array resources of current premium |
| `osc_get_premiums($max = 2)` | Gets new premiums ads |
| `osc_has_premium_meta()` | Gets next premium meta field if there is, else return null |
| `osc_has_premium_resources()` | Gets next premium resource if there is, else return null |
| `osc_has_premiums()` | Gets next premium if there is, else return null |
| `osc_premium()` | Gets current premium array from view |
| `osc_premium_address()` | Gets address of current premium |
| `osc_premium_category($locale = '')` | Gets category from current premium |
| `osc_premium_category_description($locale = '')` | Gets category description from current premium, if $locale is unspecified $locale is current user locale |
| `osc_premium_category_id()` | Gets category id of current premium |
| `osc_premium_city()` | Gets city of current premium |
| `osc_premium_city_area()` | Gets city area of current premium |
| `osc_premium_comments_page()` | Gets page of comments in current pagination |
| `osc_premium_contact_email()` | Gets contact email of current premium |
| `osc_premium_contact_name()` | Gets contact name of current premium |
| `osc_premium_country()` | Gets country name of current premium |
| `osc_premium_country_code()` | Gets country code of current premium Country code are two letters like US, ES, ... |
| `osc_premium_currency()` | Gets currency of current premium |
| `osc_premium_currency_symbol()` | Gets currency symbol of an item |
| `osc_premium_description($locale = '')` | Gets description from current premium, if $locale is unspecified $locale is current user locale |
| `osc_premium_field($field, $locale = '')` | Gets a specific field from current premium |
| `osc_premium_formated_price()` | Gets formatted price of current premium |
| `osc_premium_id()` | Gets id from current premium |
| `osc_premium_is_active()` | Gets if current premium is active |
| `osc_premium_is_inactive()` | Gets if current premium is inactive |
| `osc_premium_is_premium()` | Gets true if current premium is marked premium, else return false |
| `osc_premium_is_spam()` | Gets if premium is marked as spam |
| `osc_premium_latitude()` | Gets latitude of current premium |
| `osc_premium_longitude()` | Gets longitude of current premium |
| `osc_premium_mod_date()` | Gets modification date of current premium |
| `osc_premium_price()` | Gets price of current premium |
| `osc_premium_pub_date()` | Gets publication date of current premium |
| `osc_premium_region()` | Gets region of current premium |
| `osc_premium_secret()` | Gets secret string of current premium |
| `osc_premium_show_email()` | Gets true if can show email user at frontend, else return false |
| `osc_premium_status()` | Gets status of current premium. b_active = true  -&gt; premium is active b_active = false -&gt; premium is inactive |
| `osc_premium_title($locale = '')` | Gets title from current premium, if $locale is unspecified $locale is current user locale |
| `osc_premium_total_comments()` | Gets total number of comments of current premium |
| `osc_premium_user_id()` | Gets user id from current premium |
| `osc_premium_views()` | return number of views of current premium |
| `osc_premium_zip()` | Gets zip code of current premium |
| `osc_priv_count_premiums()` | Gets number of premiums |
| `osc_reset_premiums()` | Set the internal pointer of array premiums to its first element, and return it. |

### hResources (7)

`oc-includes/osclass/helpers/hResources.php`

| Helper | What it does |
|---|---|
| `osc_get_resource_url(array $resource, string $variant = '')` | Public URL for a resource row's variant, routed through the same resource_path and resource_url filters the item helpers use — so remote-adapter URL substitution and private-bucket presigning apply identically to any owner type. |
| `osc_get_resources(string $ownerType, int $ownerId)` | All resources belonging to an owner, as an array of resource rows. |
| `osc_media_library_query(string $type, int $iPage, int $perPage)` | A page of normalised media rows plus the total for a filter. $type is 'all', 'item' (listing photos), or a t_resource owner type ('user', 'page', 'library', a plugin type). Each row carries: src ('item'\|'resource'), id, owner_id, owner_type, s_name, s_extension, s_content_type, s_path, s_storage. |
| `osc_media_owner_types()` | Distinct, well-formed owner types currently present in t_resource. |
| `osc_media_row_urls(array $row)` | The thumbnail and full URLs for a normalised media row (see osc_media_library_query). Uses the storage-aware osc_get_resource_url so offloaded files resolve correctly. |
| `osc_resource_owner_exists(string $ownerType, int $ownerId)` | Whether the owner record a resource points at still exists. |
| `osc_sweep_orphan_resources()` | Walk t_resource and delete resources whose owner no longer exists. |

### hSanitize (11)

`oc-includes/osclass/helpers/hSanitize.php`

| Helper | What it does |
|---|---|
| `osc_esc_html($str = '')` | Escape html |
| `osc_esc_js($str)` | Escape single quotes, double quotes, &lt;, &gt;, & and line endings |
| `osc_sanitize_allcaps($value)` | Sanitize string that's all-caps |
| `osc_sanitize_html($value)` | Sanitise rich text to the markup a Shopclass editor can produce. Scripts, iframes, event handlers and any URL scheme but http, https and mailto are removed. Arrays are walked, so a per-locale description map can be passed straight in. |
| `osc_sanitize_int($value)` | Sanitize a whole number |
| `osc_sanitize_name($value)` | Sanitize capitalization for a name: trimmed, all-caps lowered, each word capitalised. |
| `osc_sanitize_phone($value)` | Sanitize a phone number: digits, a leading '+' and common separators are kept, with no country-specific formatting. |
| `osc_sanitize_string($value)` | Turn a string into a URL-safe slug (not an HTML-escaped string). |
| `osc_sanitize_text($value)` | Reduce a value to plain text, taking every tag out along with what it contained. Like Params::getParam() it escapes what it keeps, so the result is stored pre-escaped. |
| `osc_sanitize_url($value)` | Sanitize a website URL. |
| `osc_sanitize_username($value)` | Sanitize a username |

### hSearch (63)

`oc-includes/osclass/helpers/hSearch.php`

| Helper | What it does |
|---|---|
| `osc_alert_form()` | Load the form for the alert subscription |
| `osc_count_latest_searches()` | Gets the total number of latest searches done in the website |
| `osc_count_list_cities($region = '%%%%')` | Gets the total number of cities in list_cities |
| `osc_count_list_countries()` | Gets the total number of countries in list_countries |
| `osc_count_list_regions($country = '%%%%')` | Gets the total number of regions in list_regions |
| `osc_get_canonical()` | The canonical URL exported for the current page. |
| `osc_get_latest_searches($limit = 20)` | Gets the latest searches done in the website |
| `osc_get_raw_search($conditions)` | Strip a search condition set down to the filters a visitor actually chose, with category ids resolved to names. Takes a decoded t_alerts.s_search; for an alert stored as search values the result also carries `params`, the stored values, and for a held alert it is only `held`, the reason. |
| `osc_has_latest_searches()` | Gets the next latest search |
| `osc_has_list_cities($region = '%%%%')` | Gets the next city in the list_cities list |
| `osc_has_list_countries()` | Gets the next country in the list_countries list |
| `osc_has_list_regions($country = '%%%%')` | Gets the next region in the list_regions list |
| `osc_latest_search()` | Gets the current latest search |
| `osc_latest_search_date()` | Gets the current latest search date |
| `osc_latest_search_text()` | Gets the current latest search pattern |
| `osc_latest_search_total()` | Gets the current latest search total |
| `osc_list_city()` | Gets list of cities with items |
| `osc_list_city_id()` | Gets the ID of current "list city" |
| `osc_list_city_items()` | Gets the number of items of current "list city" |
| `osc_list_city_name()` | Gets the name of current "list city" by name |
| `osc_list_city_slug()` | Gets the list of current "list city" by slug |
| `osc_list_city_url()` | Gets the url of current "list city" |
| `osc_list_country()` | Gets list of countries with items |
| `osc_list_country_code()` | Gets the number of items of current "list country" |
| `osc_list_country_items()` | Gets the number of items of current "list country" |
| `osc_list_country_name()` | Gets the name of current "list country" |
| `osc_list_country_url()` | Gets the url of current "list country" |
| `osc_list_orders()` | Gets available search orders |
| `osc_list_region()` | Gets list of regions with items |
| `osc_list_region_id()` | Gets the ID of current "list region" |
| `osc_list_region_items()` | Gets the number of items of current "list region" |
| `osc_list_region_name()` | Gets the name of current "list region" by name |
| `osc_list_region_slug()` | Gets the slug of current "list region" |
| `osc_list_region_url()` | Gets the url of current "list region" |
| `osc_remove_slash($var)` | Replace every slash in a value, or in each value of an array, with a space. |
| `osc_search()` | Gets search object |
| `osc_search_alert()` | Gets alert of current search |
| `osc_search_alert_subscribed()` | Gets current search page |
| `osc_search_category()` | Gets current search category |
| `osc_search_category_description($locale = '')` | Description of the category the current search is filtered to. Takes the first of a multi-category search, so it always agrees with osc_search_category_name(). |
| `osc_search_category_id()` | Gets current search category id |
| `osc_search_category_name($locale = '')` | Name of the category the current search is filtered to. Takes the first of a multi-category search, so it always agrees with osc_search_category_description(). |
| `osc_search_city()` | Gets current search city |
| `osc_search_country()` | Gets current search country |
| `osc_search_end()` | Gets current search end item record |
| `osc_search_has_pic()` | Gets if "has pic" option is enabled or not in the search |
| `osc_search_only_premium()` | Gets if "only premium" option is enabled or not in the search |
| `osc_search_order()` | Gets current search order |
| `osc_search_order_type()` | Gets current search order type |
| `osc_search_page()` | Gets current search page |
| `osc_search_pattern()` | Gets current search pattern |
| `osc_search_price_max()` | Gets current search max price |
| `osc_search_price_min()` | Gets current search min price |
| `osc_search_region()` | Gets current search region |
| `osc_search_show_all_url($params = array())` | Gets for a default search (all categories, noother option) |
| `osc_search_show_as()` | Gets current search "show as" variable (show the items as a list or as a gallery) |
| `osc_search_start()` | Gets current search start item record |
| `osc_search_total_items()` | Gets current search total items |
| `osc_search_total_pages()` | Gets total pages of search |
| `osc_search_url($params = null)` | Gets search url given params |
| `osc_search_user()` | Gets current search users |
| `osc_subscribe_alert(string $token, string $email)` | Subscribe an email to a saved search. The owner is the signed-in user, never the caller: a guest gets a confirmation email, a signed-in active user is subscribed at once. |
| `osc_update_search_url($params = array(), $forced = false)` | Update the search url with new options |

### hSecurity (23)

`oc-includes/osclass/helpers/hSecurity.php`

| Helper | What it does |
|---|---|
| `osc_alert_cipher_key()` | **Deprecated since 7.0.0; new tokens are sealed with SecretBox.** Key of the alert tokens minted before 7.0, derived from the alert_private_key preference. |
| `osc_ban_rules(string $scope = 'all')` | The ban rules in force. Expired rules are left out, and so are rules that block messages only, unless $scope is 'messages'. |
| `osc_csrf_check()` | Check if CSRF token is valid, die in other case |
| `osc_csrf_token_form()` | Hidden CSRF input fields to drop inside a &lt;form&gt;, for templates that want to emit the token explicitly instead of relying on the shutdown auto-injector. Mark the &lt;form&gt; with class="nocsrf" when using this so the injector skips it and the token is not duplicated — the same convention FormBuilder uses. |
| `osc_csrf_token_url()` | Create a CSRF token to be placed in a url |
| `osc_decrypt_alert($string)` | Decrypt an alert token. Tokens from before 7.0 (raw AES-GCM under the alert_private_key preference) still open; the older CTR format is refused, as the alert cron runs its conditions. |
| `osc_decrypt_alert_legacy($string)` | **Deprecated since 6.4.0; a token of this format is not trustworthy.** Read a token minted before alert tokens were authenticated. |
| `osc_dummy_password_verify($password)` | Spend the work of a password check against a hash that cannot match. |
| `osc_encrypt_alert($alert)` | Encrypt an alert payload into a SecretBox token. |
| `osc_genRandomPassword($length = 8)` | Creates a random password. |
| `osc_get_alert_private_key()` | Persistent per-install key of the alert tokens minted before 7.0. |
| `osc_get_alert_public_key()` | **Deprecated since 7.0.0; nothing reads this key.** Persistent per-install public key, once meant for search-alert tokens. |
| `osc_hash_password($password)` | Hash a password in available method (bcrypt/sha1) |
| `osc_is_banned($email = '', $ip = null, string $scope = 'all')` | Check if an email and/or IP are banned |
| `osc_is_email_banned($email, $rules = null)` | Check if email is banned |
| `osc_is_ip_banned($ip, $rules = null)` | Check if IP is banned |
| `osc_is_username_blacklisted($username)` | Check if username is blacklisted |
| `osc_login_throttle_message($seconds)` | Wording for a sign-in refused by \mindstellar\security\LoginThrottle. |
| `osc_proxy_ip_mismatch()` | Whether the current request's REMOTE_ADDR looks like a proxy's address instead of the visitor's — a forwarding header disagrees with it. Detection only; core still reads the visitor IP from REMOTE_ADDR alone, see osc_is_banned() and osc_validate_spam_delay(). |
| `osc_random_string($length)` | A random string of $length characters from A-Z, a-z, 0-9, "." and "/". |
| `osc_set_alert_private_key()` | Mint the install's persistent alert private key if it has none yet. |
| `osc_set_alert_public_key()` | **Deprecated since 7.0.0; nothing reads this key.** Mint the install's persistent alert public key if it has none yet. |
| `osc_verify_password($password, $hash)` | Verify an user's password |

### hSettings (20)

`oc-includes/osclass/helpers/hSettings.php`

| Helper | What it does |
|---|---|
| `osc_admin_form($id)` | A fluent builder over the same spec osc_register_settings_page() takes. |
| `osc_register_settings_page($id, $spec)` | Declare a settings page. Thin wrapper over SettingsPageRegistry::register(). |
| `osc_settings_cast($type, $value)` | Turn a stored preference string back into the shape its field type implies. |
| `osc_settings_depends_met(array $fields, $name, array $values, array $seen = array())` | Whether a field's 'depends' chain is satisfied by the submitted values. |
| `osc_settings_field_active($pageId, $name, array $values, ?array $fields = null)` | Whether a declared field is part of this submission at all. |
| `osc_settings_field_locales(array $field)` | The locales one field expands over: every enabled locale for a translated text or textarea, and none at all for anything else. |
| `osc_settings_hook_values(array $fields, array $values)` | The submitted values as a hook listener may see them: everything except the secrets. |
| `osc_settings_image_url(string $pageId, string $name, string $variant = '')` | The URL of the image stored in a declared page's image field, or '' when none is. |
| `osc_settings_locales($refresh = false)` | Every enabled locale as code =&gt; name, in the order the site lists them. |
| `osc_settings_master_on($value)` | Whether a master field's submitted value counts as switched on. |
| `osc_settings_menu_init()` | Put every declared page that asked for one into its menu section. Registered on admin_menu_init, which runs after plugins have loaded and therefore after they have declared their pages. |
| `osc_settings_page($id)` | The spec for a registered settings page, or null when it is not registered. |
| `osc_settings_page_conflicts()` | Page ids more than one plugin tried to register, and how many times each. Empty on a healthy install; an entry means a plugin's settings page is not the one being shown, and its saved values are under a section nothing reads. |
| `osc_settings_page_url($id)` | The admin URL of a declared settings page. |
| `osc_settings_pages()` | All registered settings pages, keyed by id. |
| `osc_settings_sanitize(array $field)` | The submitted value of one field, sanitised by its type. |
| `osc_settings_save($pageId, $id = null)` | Sanitise, validate and store every field on a declared page. |
| `osc_settings_validate(array $field, $value)` | Check one submitted value against what its field declares. Returns an error message, or null when the value is good. |
| `osc_settings_value($pageId, $name, $id = null)` | The stored value of one declared field, or the field's declared default when nothing has been saved yet. |
| `osc_settings_values($pageId, $id = null)` | Every declared field on a page, keyed by name, resolved the same way osc_settings_value() resolves one. |

### hSitemap (5)

`oc-includes/osclass/helpers/hSitemap.php`

| Helper | What it does |
|---|---|
| `osc_sitemap_clear_cache()` | Drop every cached sitemap document so the next request rebuilds it. This is the entry point behind an admin "Regenerate / Clear cache" action. |
| `osc_sitemap_default_robots_txt()` | Default robots.txt body: disallow the admin panel and advertise the sitemap. Intended as the seed content for the admin robots.txt editor. |
| `osc_sitemap_robots_line()` | The `Sitemap:` directive that advertises the sitemap index, as an absolute URL. Built at runtime from osc_base_url(), so it can be appended to a robots.txt without ever baking a host into a tracked file. |
| `osc_sitemap_stylesheet_url($file)` | Core-served URL of a sitemap XSL stylesheet. |
| `osc_sitemap_warm_cache()` | Pre-generate every enabled sitemap document into the object cache. |

### hSpam (8)

`oc-includes/osclass/helpers/hSpam.php`

| Helper | What it does |
|---|---|
| `osc_item_report_clear($id)` | Clear an item's reports when it is re-enabled, so the reports already on file do not immediately auto-block it again. |
| `osc_item_report_record($id, $as)` | Record a report (deduplicated, one vote per reporter) whenever a listing is marked, and auto-disable it once enough distinct people have reported it. |
| `osc_keyword_block_enabled()` | Is the keyword blocklist active? |
| `osc_keyword_block_hard_block()` | Should a keyword hit reject the listing at post time (hard block) rather than quarantine it for review? Off by default; quarantine is the default behaviour. |
| `osc_keyword_spam_enforce($item)` | Run the keyword filter over a freshly posted or edited listing and, on a hit, quarantine it: flag it spam and disable it (both through ListingService so the lifecycle hooks fire and the search index stays in step), then record why in the moderation log. Reversible — an admin re-enables it from the usual screen. |
| `osc_keyword_spam_hard_block($flash_error, $aItem)` | Optional hard-block path (off by default): reject the listing before it is inserted when it contains a blocked keyword. Wired onto the pre_item_add_error filter, so returning a non-empty string aborts the insert. |
| `osc_report_autoblock_enabled()` | Is report-driven auto-blocking active? |
| `osc_report_threshold()` | Distinct-reporter count at which a listing is auto-disabled. |

### hStorage (2)

`oc-includes/osclass/helpers/hStorage.php`

| Helper | What it does |
|---|---|
| `osc_register_storage_adapter($adapter)` | Register a storage adapter with the storage manager. |
| `osc_storage_register_remote()` | Register the bundled S3-compatible adapter from saved preferences, when an install has filled in all four connection settings. Idempotent and cheap — it returns immediately once the adapter is registered for this request, and register() keys by id — so it is safe to call from the `init` hook, from the upload hooks, and before the worker runs. |

### hTheme (39)

`oc-includes/osclass/helpers/hTheme.php`

| Helper | What it does |
|---|---|
| `osc_add_theme_support(string $feature, $args = true)` | Declare that the active theme supports $feature. |
| `osc_admin_render_theme_url($file = '')` | Gets urls for current theme administrations options |
| `osc_body_class($class = '', bool $echo = true)` | The &lt;body&gt; class attribute: `<body <?php osc_body_class(); ?>>`. |
| `osc_body_class_list($class = '')` | What page this is, as a list of class tokens for &lt;body&gt;. |
| `osc_enqueue_script($id)` | Enqueue script |
| `osc_enqueue_script_code($code, $dependencies = null, $id = null)` | Enqueue a block of inline JavaScript into the footer, after the file scripts. The admin/front target is detected from the current request. |
| `osc_enqueue_style($id, $url = null)` | Add style to be loaded If style is already registered only id is needed to enqueue style |
| `osc_get_footer()` | Render the theme's closing chrome. See osc_get_header(). |
| `osc_get_header()` | Render the theme's opening chrome. False when the theme has none, so a caller can fall through to core's own shell. |
| `osc_gui_account_view(string $themeView)` | Render core's own fallback page for one of the account and auth views. |
| `osc_gui_custom_heading()` | The heading of a plugin's account page: the title its route was registered with, or "Your account" when the route gave none. |
| `osc_gui_page_view(string $themeView)` | Core's fallback for a page outside the account section: the plugin mount and the seller-contact form. Same three steps as osc_gui_account_view() -- the theme's own view, else core's partial inside the theme's chrome, else core's shell -- kept separate from it because these are not account pages and the account nav has no business on them. |
| `osc_gui_view(string $themeView, string $contentFile, array $opts = array())` | Render a page core owns, through whatever the active theme provides. |
| `osc_head()` | Everything core has to say inside &lt;head&gt;, followed by the `header` hook that carries enqueued styles, scripts and whatever plugins add. |
| `osc_head_hook_guard()` | Warn, in debug builds only, when the `header` hook runs more than once in a request. |
| `osc_language_attributes(bool $echo = true)` | The document's language attributes: `lang="en-US" dir="ltr"`. |
| `osc_load_scripts()` | Print the HTML tags to make the script load |
| `osc_load_styles()` | Print the HTML tags to make the style load |
| `osc_locate_template($candidates, string $context = '')` | The first view in $candidates that some theme in the stack can render. |
| `osc_print_bulk_actions($id, $name, $options, $class = '', $attributes = '')` | Print a bulk-action &lt;select&gt;. Each option is an attribute map whose 'label' key is its text. |
| `osc_register_render_target(string $id, string $path)` | Register a named render target: an opaque id mapped to an absolute file path. osc_render_file() checks this registry before its own filesystem lookups, so core can expose a file outside the theme/plugin directories -- e.g. an oc-includes/ partial -- to ?page=custom&file=&lt;id&gt;. The request only ever supplies the id; the path is never request-controlled. Thin wrapper over \mindstellar\theme\RenderTargetRegistry::register(). |
| `osc_register_script($id, $url, $dependencies = null)` | Add script to be loaded |
| `osc_register_style($id, $url, $dependencies = null)` | Register style with dependencies |
| `osc_remove_script($id)` | Remove script from the queue, so it will not be loaded |
| `osc_remove_style($id)` | Remove style from the queue, so it will not be loaded |
| `osc_remove_theme_support(string $feature)` | Withdraw a feature the active theme declared. |
| `osc_render_file($file = '')` | Render the specified file |
| `osc_render_file_url($file = '')` | Gets urls for render custom files in front-end |
| `osc_render_target(string $id)` | Absolute path registered for $id, or null when nothing is registered under it. |
| `osc_resend_flash_messages($section = 'pubMessages')` | Re-send the flash messages of the given section. Usefull for custom theme/plugins files. |
| `osc_theme_chrome()` | The active theme's page chrome: the view that opens the document and the one that closes it, as absolute paths, or null when the theme has none. |
| `osc_theme_has_chrome()` | Whether the active theme can wrap a core-rendered page. |
| `osc_theme_has_screenshot($theme = null)` | Whether a theme has a screenshot on disk, as opposed to the fallback placeholder osc_theme_screenshot_url() returns. |
| `osc_theme_screenshot_url($theme = null)` | Public URL of a theme's screenshot, or a bundled placeholder when it has none. Checks screenshot.png, then screenshot.jpg, then screenshot.webp on disk, in that order — themes in the wild ship all three. |
| `osc_theme_supports(string $feature)` | Arguments the active theme declared for $feature, or false when it declared nothing. Callers must treat false as "do what we did before", never as an error. |
| `osc_theme_template_paths()` | The theme stack a view is resolved against, as absolute directory paths: active theme, then its parent when it declares one, then the bundled fallback theme core keeps for views nobody else supplies. |
| `osc_theme_view_names()` | Every view name that is spoken for: the ones core asks any theme for, plus the ones the active theme declared with osc_add_theme_support('views', array('user-wishlist', 'template-promo')). |
| `osc_unregister_script($id)` | Remove script from the queue, so it will not be loaded |
| `osc_unregister_style($id)` | Remove style from the queue, so it will not be loaded |

### hUsers (62)

`oc-includes/osclass/helpers/hUsers.php`

| Helper | What it does |
|---|---|
| `osc_alert()` | Gets current alert fomr view |
| `osc_alert_criteria(?array $alert = null)` | What an alert searches for, as labelled parts: each entry is ['label', 'value']. Empty for an alert on all listings. A paused alert (one the upgrade could not keep) returns one 'held' entry. Defaults to the current alert in the loop. |
| `osc_alert_date()` | Gets aate of current alert |
| `osc_alert_field($field)` | Gets a specific field from current alert |
| `osc_alert_id()` | Gets id of current alert |
| `osc_alert_is_active()` | Gets active of current alert |
| `osc_alert_search()` | Gets search field of current alert. |
| `osc_alert_secret()` | Gets secret of current alert |
| `osc_alert_summary()` | One line naming what the current alert searches for, e.g. "bike · Cycling · Leeds". |
| `osc_alert_type()` | Gets type of current alert |
| `osc_alert_unsub_date()` | Gets unsub date of current alert |
| `osc_count_alerts()` | Gets number of alerts in array alerts |
| `osc_has_alerts()` | Gets next alert if there is, else return null |
| `osc_has_user_avatar(?int $userId = null)` | Whether a user has an uploaded avatar. |
| `osc_is_admin_user_logged_in()` | Gets true if admin user is logged in |
| `osc_is_web_user_logged_in()` | Gets true if user is logged in web |
| `osc_logged_admin_email()` | Gets logged admin email |
| `osc_logged_admin_id()` | Gets logged admin id |
| `osc_logged_admin_name()` | Gets logged admin name |
| `osc_logged_admin_username()` | Gets logged admin username |
| `osc_logged_user_email()` | Gets logged user mail |
| `osc_logged_user_id()` | Gets logged user id |
| `osc_logged_user_name()` | Gets logged user name |
| `osc_logged_user_phone()` | Gets logged user phone |
| `osc_prepare_user_info()` | Gets next user in users array |
| `osc_reset_users()` | Rewinds the user loop, so osc_prepare_user_info() can be read again. |
| `osc_resolve_web_user()` | Resolve the current front-end user for this request, or null. |
| `osc_run_web_user_identity()` | Resolve front-end identity early in the bootstrap so the historical Session::_get('userId') readers see a cookie-authenticated user even before any osc_is_web_user_logged_in() call. No-op — and no session, no DB query — for an anonymous, cookieless visitor, which keeps such requests cacheable. |
| `osc_total_users($condition = '')` | Gets number of users |
| `osc_user()` | Gets user array from view |
| `osc_user_access_date()` | Gets last access date |
| `osc_user_access_ip()` | Gets last access ip |
| `osc_user_address()` | Gets address of current user |
| `osc_user_avatar_url(?int $userId = null, string $variant = 'thumbnail')` | Public URL of a user's avatar, or a bundled placeholder when they have none. |
| `osc_user_city()` | Gets city of current user |
| `osc_user_city_area()` | Gets city area of current user |
| `osc_user_city_area_id()` | Gets city area id of current user |
| `osc_user_city_id()` | Gets city id of current user |
| `osc_user_comments_validated()` | Gets number of comments validated of current user |
| `osc_user_country()` | Gets country of current user |
| `osc_user_email()` | Gets email of current user |
| `osc_user_field($field, $locale = '')` | Gets a specific field from current user |
| `osc_user_id()` | Gets id of current user |
| `osc_user_info($locale = '')` | Gets description/information of current user |
| `osc_user_is_company()` | Gets type (company/user) of current user |
| `osc_user_items_validated()` | Gets number of items validated of current user |
| `osc_user_latitude()` | Gets latitude of current user |
| `osc_user_list_items_pub_profile_url($page = '', $itemsPerPage = false)` | Gets current items page from public profile |
| `osc_user_longitude()` | Gets longitude of current user |
| `osc_user_name()` | Gets name of current user |
| `osc_user_phone()` | Gets phone_land if exist, else if exist return phone_mobile, else return string blank |
| `osc_user_phone_land()` | Gets phone of current user |
| `osc_user_phone_mobile()` | Gets cell phone of current user |
| `osc_user_public_profile_url($id = null)` | Gets user's profile url |
| `osc_user_regdate()` | Gets registration date of current user |
| `osc_user_region()` | Gets region of current user |
| `osc_user_region_id()` | Gets region id of current user |
| `osc_user_username()` | Gets username of current user |
| `osc_user_website()` | Gets website of current user |
| `osc_user_zip()` | Gets postal zip of current user |
| `osc_web_user_apply_identity($user)` | Populate the request-scoped identity for the given user. |
| `osc_web_user_login($user, $remember = false)` | Log a front-end user in by issuing the signed identity cookie. |

### hUtils (48)

`oc-includes/osclass/helpers/hUtils.php`

| Helper | What it does |
|---|---|
| `osc_add_route($id, $regexp, $url, $file, $user_menu = false, $location = 'custom', $section = 'custom', $title = 'Custom')` | Register a file-backed custom route. |
| `osc_add_route_hook($id, $regexp, $url)` | Register a controller route dispatched by class instead of by file. |
| `osc_admin_date($date, $dateOnly = false)` | Renders a date for an admin list table: a compact, unambiguous value with the site's long format kept on hover and for screen readers. |
| `osc_admin_date_format($dateOnly = false)` | Compact numeric format for an admin list table date column. |
| `osc_autop($text, $line_breaks = true)` | Convert plain-text line breaks into HTML paragraphs and line breaks. |
| `osc_captcha_enabled()` | Whether a usable captcha provider is active (not 'none'). |
| `osc_captcha_provider()` | Resolves the active captcha provider for this request. |
| `osc_captcha_script_url()` | The active provider's client script URL. |
| `osc_captcha_widget_html($context = '', $deferred = false)` | Builds the active provider's captcha widget markup as a string. |
| `osc_cron_last_run()` | When cron last ran, on any schedule, as a UNIX time. 0 when it never has. |
| `osc_escape_string($string)` | Escapes letters and numbers of a string |
| `osc_field($item, $field, $locale)` | Generic function for view layer, return the $field of $item with specific $locale |
| `osc_format_date($date, $dateformat = null)` | Formats the date using the appropiate format. |
| `osc_get_http_referer()` | Where the visitor came from, when it is on this site: the rewrite's referer, the stored one, then the Referer header. |
| `osc_get_i18n_repository_url($path = '')` | Get i18n repository URL. |
| `osc_get_locations_json_url()` | Get URL of location files JSON. |
| `osc_get_locations_sql_url($location)` | **Deprecated since 6.2.0.** URL of a country's legacy location SQL dump. |
| `osc_get_param($key)` | Get variable from $_GET or $_POST |
| `osc_get_subdomain_params()` | The search parameters the current subdomain pins, as osc_search_url() takes them. |
| `osc_google_analytics_id()` | **Deprecated since 6.2.0.** Get the Google Analytics measurement ID a previous release stored. |
| `osc_google_maps_api_key()` | Get Google Maps API key. |
| `osc_google_maps_geocode_url($address)` | Get Google Maps geocode URL. |
| `osc_highlight($txt, $len = 300, $start_tag = '<strong>', $end_tag = '</strong>')` | Gets prepared text, with: - higlight search pattern and search city - maxim length of text |
| `osc_is_bot_request()` | Whether this request came from a crawler rather than a person. |
| `osc_keep_form(array $values, string $error)` | Keep a failed form's values for the next page, plus the reason as 'contact_error', and show the reason as a flash message. Read them back with osc_gui_kept(). |
| `osc_local_referer($fallback)` | The page the request came from when it is on this site, else $fallback. The host is compared, not a prefix, so a look-alike host cannot pass. |
| `osc_openstreet_api_key()` | Get Open Street Maps API key. |
| `osc_openstreet_geocode_url($address)` | Get OpenStreetMaps geocode URL. |
| `osc_pop_admin_login_redirect()` | Admin counterpart of osc_pop_login_redirect(). Single-use. |
| `osc_pop_login_redirect()` | Read, validate and clear the login-redirect cookie set by osc_set_login_redirect(). |
| `osc_pop_signed_redirect($cookieName)` | Read, validate and clear a signed-redirect cookie. Always deletes it (single-use). |
| `osc_private_user_menu($options = null)` | Prints the user's account menu |
| `osc_request_counts_as_view()` | Whether this request should be counted in the listing view statistics. |
| `osc_server_is_nginx()` | Whether the site is served by nginx, which ignores .htaccess entirely: rewriting is configured in the server block instead, so anything written to that file is inert. |
| `osc_server_rewrite_rules()` | The rewrite rules this server needs to route every request through index.php -- an nginx location block, or the .htaccess body Apache reads. |
| `osc_server_software()` | The web server's reported identity, lowercased, e.g. "nginx/1.27.0". |
| `osc_set_admin_login_redirect($url, $keepExisting = false)` | Admin counterpart of osc_set_login_redirect(), under its own cookie so the front-end and admin flows never collide. Used by the admin login page and by the admin auth gate, which remembers the protected page an unauthenticated admin was trying to reach. |
| `osc_set_login_redirect($url, $keepExisting = false)` | Remember where a visitor came from across the login POST without a session. |
| `osc_set_signed_redirect($cookieName, $url, $keepExisting = false)` | Store a same-site destination in a short-lived, HMAC-signed standalone cookie — the shared core behind the login/admin-login redirect helpers. Never starts a session. |
| `osc_show_captcha($context = '', $deferred = false)` | Echoes the active provider's captcha widget. |
| `osc_show_recaptcha($section = '')` | Print recaptcha html. |
| `osc_show_widgets($location)` | Print all widgets belonging to $location |
| `osc_show_widgets_by_description($description)` | Print all widgets named $description |
| `osc_signed_redirect_verify($value)` | Verify a signed-redirect cookie value and return its same-site URL, or '' if the value is absent, tampered, expired or off-site. Does not touch the cookie. |
| `osc_tinymce_config($preset = 'basic', array $overrides = array())` | The TinyMCE config every editor in the product starts from, as the JSON object literal tinymce.init() takes. |
| `osc_turnstile_configured()` | Whether both Cloudflare Turnstile keys are configured. |
| `osc_upload_token()` | The unguessable token that ties temp photo uploads on a listing form to the browser that made them, without a session. Read from (or minted into) the `oc_upload` cookie once per request; it is the capability ItemTmpUpload checks so a visitor can only delete the photos they uploaded. |
| `osc_write_signed_redirect_cookie($cookieName, $value, $expiry)` | Write (or, with a past expiry, delete) a standalone signed-redirect cookie. Standalone — not the session container — so it never starts a session. |

### hValidate (15)

`oc-includes/osclass/helpers/hValidate.php`

| Helper | What it does |
|---|---|
| `osc_validate_category($value)` | Validate if exist category $value and is enabled in db |
| `osc_validate_email($email, $required = true)` | Validate an email address Source: http://www.linuxjournal.com/article/9585?page=0,3 |
| `osc_validate_int($value)` | Validate one or more numbers (no periods) |
| `osc_validate_locale($locale, $admin = false)` | Validate locale  string. Check against available locale list |
| `osc_validate_location($city, $sCity, $region, $sRegion, $country, $sCountry)` | Validate if exist $city, $region, $country in db |
| `osc_validate_max($value = null, $max = 255)` | Validate if $value is less than $max |
| `osc_validate_min($value = null, $min = 6)` | Validate if $value is more than $min |
| `osc_validate_nozero($value)` | Validate one or more numbers (no periods), must be more than 0. |
| `osc_validate_number($value = null, $required = false)` | Validate $value is a number or a numeric string |
| `osc_validate_phone($value = null, $count = 10, $required = false)` | Validate $value is a number phone, with $count length |
| `osc_validate_range($value, $min = 6, $max = 255)` | Validate if $value belongs at range between min to max |
| `osc_validate_spam_delay($type = 'item')` | Validate time between two items added/comments |
| `osc_validate_text($value = '', $count = 1, $required = true)` | Validate the text with a minimum of non-punctuation characters (international) |
| `osc_validate_url($value, $required = false, $get_headers = false)` | Validate if $value url is a valid url. |
| `osc_validate_username($value, $min = 1)` | validate username, accept letters plus underline, without separators |

### hViews (4)

`oc-includes/osclass/helpers/hViews.php`

| Helper | What it does |
|---|---|
| `osc_item_view_beacon_enabled()` | Whether the client view beacon owns view counting. Default ON (unset preference = enabled). |
| `osc_item_view_beacon_url($id)` | The endpoint the beacon POSTs to for a given listing id. |
| `osc_render_item_view_beacon()` | Emit the beacon &lt;script&gt; at the end of the listing detail page. CWebItem sets $GLOBALS['osc_view_beacon_item_id'] only while rendering a public listing detail with the beacon enabled, so this fires there and nowhere else. It is baked into the cached HTML, so it runs for every visitor of the cached page and reports one view per real browser load. sendBeacon is fire-and-forget and survives page unload; fetch(keepalive) is the fallback. |
| `osc_view_beacon_suppress_render_count($countsAsView, $item = null)` | Suppress the render-time view increment when the beacon owns counting, so a cached page and the beacon never both count (and a no-JS bot is not counted at render while a human is counted by the beacon). Filters `count_view_on_render`, whose value is otherwise osc_request_counts_as_view(). |

### hWidgets (4)

`oc-includes/osclass/helpers/hWidgets.php`

| Helper | What it does |
|---|---|
| `osc_register_widget($id, $spec)` | Register a widget type so it can be placed from the admin appearance screen and rendered on the front end. Thin wrapper over WidgetRegistry::register(). |
| `osc_render_widget($widgetRow)` | Render a single widget row. |
| `osc_widget_locations()` | The widget sections the active theme offers, in the order it offers them: slug =&gt; array('label' =&gt; string, 'description' =&gt; string). |
| `osc_widget_types()` | All registered widget types, keyed by id. |

### locales (3)

`oc-includes/osclass/locales.php`

| Helper | What it does |
|---|---|
| `osc_checkLocales()` | Insert or refresh a database row for every locale found on disk, importing its mail templates the first time a locale is seen. |
| `osc_listLanguageCodes()` | List the locale codes that have a folder under the translations path. |
| `osc_listLocales()` | Read every translation folder and return its locale descriptor, keyed by locale code. |

### utils (47)

`oc-includes/osclass/utils.php`

| Helper | What it does |
|---|---|
| `osc_calculate_location_slug($type)` | Fill in the missing slugs for one location table, returning how many rows were updated. |
| `osc_changeVersionTo($version = null)` | Change version to param number |
| `osc_change_permissions($dir = ABS_PATH)` | **Deprecated since 4.0.0.** Walk a directory and chmod anything under it that is not writable to 0755. |
| `osc_check_captcha()` | Verifies the submitted captcha token for the active provider. |
| `osc_check_dir_writable($dir = ABS_PATH)` | **Deprecated since 4.0.0.** Walk a directory and report whether the files under it are writable. |
| `osc_check_language_update($update_uri, $version = null, $disable = false)` | Whether the translations repository has a newer version of an installed language. |
| `osc_check_plugin_update($update_uri, $version = null)` | **Deprecated since 4.0.0 use mindstellar\market\PackageIndex::forPlugins()-&gt;pendingUpdates() instead.** Whether a plugin's update URI advertises a version newer than the installed one. |
| `osc_check_recaptcha()` | Verify the reCAPTCHA token on the current POST request. |
| `osc_check_theme_update($update_uri, $version = null)` | **Deprecated since 4.0.0 use mindstellar\market\PackageIndex::forThemes()-&gt;pendingUpdates() instead.** Whether a theme's update URI advertises a version newer than the installed one. |
| `osc_copy($source, $dest)` | Copy a file, warning instead of throwing on failure. |
| `osc_copyemz($file1, $file2)` | **Deprecated since 4.0.0.** Copy a file by reading it whole and writing it out again. |
| `osc_dbdump($path, $file)` | Dump osclass database into path file |
| `osc_deleteDir($path)` | Tries to delete the directory recursively. |
| `osc_deleteResource($id, $admin, $resource = null)` | Remove resources from disk |
| `osc_doRequest($url, $_data)` | VERY BASIC Perform a POST request, so we could launch fake-cron calls and other core-system calls without annoying the user |
| `osc_downloadFile($sourceFile, $downloadedFile, $post_data = null)` | Download a URL to a local path, warning instead of throwing on failure. |
| `osc_file_get_contents($url, $post_data = null, $verify_ssl = true, $timeout = 0)` | Shopclass file_get_contents implementation |
| `osc_isExpired($dt_expiration)` | check if the item is expired |
| `osc_is_ssl()` | Whether the current request arrived over HTTPS. |
| `osc_is_update_compatible($section, $element, $osclass_version = OSCLASS_VERSION)` | Whether a package's published descriptor lists the running core version as compatible. |
| `osc_item_is_counted(array $item)` | Whether a listing counts toward the category, location and user totals: enabled, active, not spam, and premium or not expired. The daily recount uses the same rule. |
| `osc_mailBeauty($text, $params)` | Expand the {PLACEHOLDER} tokens in an email body: the caller's pairs first, then the site-wide ones. |
| `osc_mail_layout(string $body, array $params = array())` | Wrap an e-mail body in the site's e-mail layout: a theme's templates/email-layout.php, else core's. A body that is already a whole HTML document, or a send with 'layout' =&gt; false, goes out as it is. |
| `osc_mail_layout_file(?array $bases = null)` | The e-mail layout file: templates/email-layout.php in the active theme or its parent, else core's own. |
| `osc_mail_links_trusted($mail, array $params = array())` | Keep mail links off an address taken from the request Host header. With OSC_CLI_URL set, links to the request address point at it instead; without it, a mail that carries a secret link (params 'secret_link') is refused. |
| `osc_mail_upload_attachment($field)` | A visitor's uploaded file, ready to attach to a mail straight from PHP's temporary upload. |
| `osc_market_changes_blocked()` | Whether the admin may change plugins and themes from the market at all: not on a demo site, and not where package installs are disabled. |
| `osc_mkdir($dir, $mode = 0755, $recursive = true)` | Create a directory, warning instead of throwing when it cannot be created. |
| `osc_package_installs_disabled()` | Whether installing/updating market packages (plugins/themes) is disabled for this installation. Distinct from osc_self_update_disabled(): that flag stops core from overwriting itself on an immutable deployment. Packages are not core — on a deployment where oc-content is a persistent volume, a package write survives a redeploy just fine, so this defaults to enabled and is only set where the site owner has no persistent oc-content to write into. |
| `osc_phpmailer_limit_smtp_wait($mail)` | Apply osc_phpmailer_smtp_timeout_seconds() to a PHPMailer instance and to the SMTP object it will use on send(). |
| `osc_phpmailer_smtp_timeout_seconds()` | Seconds PHPMailer may wait on one SMTP connect or command. |
| `osc_prepare_price($price)` | Format a stored integer price for display. |
| `osc_prune_array(&$input)` | Drop null and empty elements from an array in place, recursively. |
| `osc_redirect_to($url, $code = null)` | Flush pending flash messages, send a Location header and end the request. |
| `osc_replace_double_slash($path)` | replace double slash with single slash |
| `osc_save_permissions($dir = ABS_PATH)` | **Deprecated since 4.0.0.** Record the current permission bits of a directory and everything under it. |
| `osc_self_update_disabled()` | Whether the in-app self-updater (downloading a package and overwriting core files) is disabled for this installation. Set on immutable deployments — the Docker image sets OSC_DISABLE_SELF_UPDATE=1 — where the running code is baked into an image and a file-writing upgrade would be discarded on the next redeploy while leaving the database ahead of the code. Those installs update by deploying a newer image; the entrypoint's `db:upgrade` migrates the schema. |
| `osc_sendMail($params)` | Send one email through PHPMailer, using the site's configured mail transport. |
| `osc_serialize($data)` | Serialize the data (usefull at plugins activation) |
| `osc_translate_categories($locale)` | Translate current categories to new locale |
| `osc_unserialize($data)` | Unserialize the data (usefull at plugins activation) |
| `osc_unzip_file($file, $to)` | Unzip's a specified ZIP file to a location |
| `osc_update_cat_stats()` | Update category stats |
| `osc_update_cat_stats_id($id)` | Recount items for a given a category id |
| `osc_update_location_stats($force = false, $limit = 1000)` | Update locations stats. I moved this function from cron.daily.php:update_location_stats |
| `osc_web_restore_disabled()` | Whether restoring a backup from the admin is turned off (OSC_DISABLE_WEB_RESTORE in config.php, or the environment). Making backups is not affected. |
| `osc_zip_folder($archive_folder, $archive_name)` | Common interface to zip a specified folder to a file using ziparchive or pclzip |

<!-- /generated:helpers -->
