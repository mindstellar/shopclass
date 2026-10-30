# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 7.1.0.beta2

**Shopclass 7.1 brings Shop mode: your site can now be a real online shop.** Add products with sizes and colours, take orders through a cart and checkout, and ship them with tracking, all on your own hosting. Classifieds sites get pay at the end of posting, messaging between buyers and sellers, map area search, place names in each language and faster filters. Shopclass now needs PHP 8.2 or newer.

### New
- **Settings → General → Site is a** sets classifieds or shop. In shop mode only admins post and shoppers see buyer pages only. `osc_site_mode()` reads it.
- The installer asks for the site type and offers sample data; the CLI takes `--site-type` and `--sample-data`. **Listings** offers to remove the sample data later.
- New category trees for classifieds and shop sites, on new installs only.
- The installer takes Storefront's look, and installs Storefront from the catalogue when it is missing.
- Listings can be products with option rows (Colour x Size and so on), each with its own SKU, price, stock, weight and shipping class.
- A variant can have an old price shown crossed out, and says how many are left when stock is low.
- **Goods orders**: a cart is priced on the server and paid by a card plugin, bank transfer or cash on delivery. Stock is taken when the order is placed and given back on refund or cancel.
- Cash on delivery orders ship before payment, are marked paid on delivery, and are never cancelled once shipped.
- An unpaid card order is cancelled after the stock hold time, and its stock goes back.
- **Billing → Orders** shows a goods order's items, address and buyer, and marks it shipped (with tracking), delivered or cancelled. A **To ship** list and count show what waits to ship.
- **Settings → Billing → Shop** sets the delivery charge, free delivery, cash on delivery and where it is offered, the stock hold time, open unpaid orders per buyer and the order number prefix.
- Shoppers keep up to 10 delivery addresses and pick one at checkout.
- Buyers get an e-mail when an order is placed, paid, shipped, delivered, cancelled or refunded; the owner gets one for each new order.
- Signed-in users keep a wishlist and back-in-stock or price-drop alerts.
- Comments can carry a 1 to 5 star rating (Settings → Comments).
- A refund the provider is still processing shows as sent, and the order becomes refunded when it finishes. Gateways return `CallbackResult::refundPending()`.
- Digital Goods 2.4.0 adds a buyer Downloads page.
- **Pay at the end of posting**: over the limit, the listing is saved and paid for on the last step. Switch: `Settings → Billing → Pricing → Take payment at the end of posting`.
- A listing waiting for payment takes no slot and shows as "Waiting for payment" on My listings and in the admin; an admin can publish it without payment.
- A seller gets a reminder e-mail a day before an unpaid listing is deleted.
- On-site messaging between buyers and sellers, with an inbox in the account and admin review under Listings → Messages. Switch: Users → Settings, off by default.
- Messages arrive without a reload, with Seen marks and a typing indicator; the Docker image streams them through nchan (`OSC_LIVE`).
- Browser notifications for new messages, switched on by the admin and then by each user.
- Search can keep results to a map area (`bbox=west,south,east,north`) when distance search is on. Themes read it with `osc_search_bbox()`.
- Country, region and city names can be set per language in their edit drawers; the site and the API show the visitor's language.
- Option and custom-field filters read a filter index (`t_item_facet`), built in the background after the update. `search:reindex` or System info → Database rebuilds it.
- **Listings → Settings → Search count limit** stops search counts at a number and shows "1,000+" past it. Themes use `osc_search_total_text()` and `osc_search_total_capped()`.
- **Appearance → Menus** builds the main, footer and extra menus from categories, pages and links. Themes read them with `osc_menu()` and name their menu places with `osc_add_theme_support('menus', …)`.
- **Appearance → Customise** edits a theme's sections and look beside a live preview, saved as a draft until Publish.
- The Widgets screen can hide a widget without deleting it.
- Activating a theme that needs plugins installs and activates them from one screen.
- A theme or plugin can declare `modes` and the plugins it needs, in `shopclass.json` or the `Modes:` and `Requires Plugins:` headers. See [Package spec](https://shopclass.org/docs/developers/package-spec/).
- A widget type can set `locations` in `osc_register_widget()` to the sections it fits.
- A theme can drop core's styles for its fallback pages with `osc_add_theme_support('core-styles', false)`.
- The shop hooks and `translation_load_after` (`TranslationLoaded`) are typed events; old callbacks work as before. See [Hooks](https://shopclass.org/docs/developers/hooks/).
- The cart hooks (`cart_shipping_methods`, `cart_shipping_lines`, `cart_tax_lines`, `cart_discount_lines`, `cart_totals`, `cart_free_over`, `cart_problems`, `cart_needs_shipping`, `cart_item_add_after`, `cart_checkout_before`) let shipping, tax and promotion plugins price a cart.
- `billing_order_failed`, `billing_tracking_url`, `billing_receipt_details`, `billing_order_return_url` and the `email_order_<kind>_title` and `_description` filters for gateway and order plugins; `OrderStore::pendingBefore()` lists a gateway's old pending orders.
- `osc_api_get()` and `osc_api_call()` call the API from PHP inside the site, with no key. `osc_admin_is_browsing()` tells the site's own API calls that an admin is browsing.
- `osc_kv_list()`, `osc_kv_increment()`, `osc_billing_user_order_count()` and `osc_plugin_data_path()` (a private folder per plugin).
- Shop API: `GET /cart/quote`, `account/cart`, `account/orders`, `account/addresses`, `account/wishlist` and `account/alerts`.
- Messaging API: `/account/conversations`, `/admin/conversations`, `/admin/messages`, and the webhooks `message.created` and `conversation.reported`.
- `POST /listings/{id}/publish` and `POST /admin/listings/{id}/publish` publish a listing waiting for payment.
- The API serves `GET /pages`, `/pages/{id|name}`, `/menus` and `/seo`, and the site document carries `mode` and `features.shop`.
- `GET /listings` takes `page`, `ids`, `option[name][]`, `bbox`, `in_stock=true` and `on_sale=true`, and answers `compare_at`, `on_sale`, `low_stock` and `meta.total_capped`.
- `GET /categories` takes `roots=true` and `include=cover`. `PATCH /admin/regions|cities` takes `names`.
- Every bundled theme reads Appearance → Menus, and a child theme takes its parent's menu places, widget sections and route files.
- The pay step shows the amount, bank details with Copy buttons and the deletion date; the post form says Save and pay when a listing will wait for payment.
- Shop admin: one test-mode notice for all payment methods, filter chips, a Waiting for payment tab, and courier suggestions on the order page (`admin_billing_carriers`).
- Tests also run as a shop site in CI.

### Breaking
- Shopclass now needs PHP 8.2 or newer; the updater refuses a release the server's PHP cannot run.

### Changed
- An update to a new X.Y release replaces `oc-admin` and `oc-includes` whole, so files the release dropped are removed. The database step then runs on its own page.
- In shop mode there is no public user profile; the page and `GET /users/{id}` answer 404.
- In shop mode credit packages are hidden: the Packages and Credits menus, the buy-credits and pay-to-publish pages, and `POST /listings/{id}/publish`.
- In shop mode a listing can be posted in a parent category, and its location is optional (the store address is used).
- The admin listing form is grouped into sections.
- Settings is grouped by area (General, Content, Email, Security, System), with a "Find a setting" box in the top bar. Ban rules moved to Settings → Security; URLs are unchanged.
- Settings › Languages marks the site and admin default languages and can change either one.
- When the default language is empty or missing, core uses en_US, else the first language that is on.
- Searches ignore anything past 191 characters of a filter value, and match a NUMBER or DATE range only on numeric values.
- Option names that differ only by case or accent count as one filter.
- Core works with MariaDB 11.6+ snapshot isolation. Plugin writes should follow the [Write transactions](https://shopclass.org/docs/developers/database/) rule.
- A subclass of `Search` that overrides `addCondition()`, `addFieldFilter()` or `countMatches()` must accept the new optional last argument.
- `sql_search_conditions` runs twice per search, once for the page list and once for the count.
- Raw SQL writes to `t_item_meta` need Rebuild filter index under System info → Database.
- `GET /listings` reads at most 3 `option[name][]` names.
- `item_increase_stat` and `item_decrease_stat` fire after the commit, so they now come after `posted_item` and `edited_item`.
- `posted_item` and the `listing.created` webhook fire when a held listing is published, not when it is saved.
- Core's front pages use shared `.oe-*` classes and the `osc_gui_*()` helpers; the old `.oe-bill-*` names still work.
- `osc_admin_panel_open()` prints a light `osc-panel` block instead of `widget-box`; `widget-box` stays for dashboard and stats widgets.
- Cart discounts are worked out before tax, so a tax plugin taxes the discounted price.
- A cart stays in the site currency; an item in another currency is refused with a reason.
- When the chosen delivery method is not offered, the quote uses the first offered one instead of free delivery.
- Cash on delivery and bank transfer take any currency, and the COD fee follows the cart currency.
- Amounts read in one style everywhere ("₹1,234", "−₹150"), including the API's display strings.
- Once a listing waiting for payment goes live, its other open orders are cancelled.
- A language package can set its thousands separator and decimal point.
- Themes that turn off core's styles should style the new pay step classes (`oe-bill-*`, `oe-callout`, `oe-copy*`).

### Security
- A plugin download link from the catalogue is shown only when it is an https address.

### Performance
- Option filter counts on 232,000 listings: 446 to 53 ms on MariaDB, 526 to 208 ms on MySQL; number and date ranges under 5 ms.
- API calls a theme makes on one page share their work, and API listing searches are cached, so a home page runs about half the queries.
- Deep admin order pages use a deferred join (page 500 of 100,000 orders: about 100 ms to 2 ms).
- Product alerts find their listing through an indexed column (migration 0089).

### Fixed
- Static pages, custom fields and e-mail templates get a language tab for every website language, not only the admin ones.
- Any pending order can be cancelled.
- A refused listing, contact or send-to-friend form marks each wrong field with its own message.
- Listings with no price sort last by price.
- Listing save errors name languages and no longer ask for optional titles.
- Add category, Add field and Add form create the row on Save, not before.
- New installs show currency symbols ($, £, €) next to listing prices.
- Admin forms, list checkboxes, toolbar search, listing thumbnails and photo upload work with screen readers and the keyboard.
- A settings page core refuses is left out with an admin notice instead of taking the site down.
- After-commit callbacks queued behind one that ends the request now run at shutdown.
- Two first upgrade grants for one listing no longer deadlock and lose one.
- A mail template with no text uses the bundled English text instead of stopping the request.
- The buy-credits page lists only the payment methods that take the billing currency.
- `osc_user_login_url()` and `osc_register_account_url()` take an optional same-site `return` path.
- A title or description with "&", "<" or ">" is no longer refused as too long.
- Search keeps option filters through redirects and pages past the count limit.
- API: page numbers work over HTTP, and Accept-Language picks the site language.
- The filter index rebuilds itself when it is empty.
- Titles keep their punctuation, and mail subjects no longer show `&amp;`.
- The order number keeps the prefix it was placed with.
- Sign-up no longer says an activation mail was sent when mail is blocked.
