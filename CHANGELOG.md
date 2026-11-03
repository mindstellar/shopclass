# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 7.1.0.beta4

**Shopclass 7.1 brings Shop mode: your site can now be a real online shop.** Add products with sizes and colours, take orders through a cart and checkout, and ship them with tracking, all on your own hosting. Classifieds sites get pay at the end of posting, messaging between buyers and sellers, map area search, place names in each language and faster filters. Shopclass now needs PHP 8.2 or newer.

### New
- Invoices and credit notes show tax per line, and a refund of units takes those units' own tax. Tax plugins report it with `CartTaxLines::setLineTax()`.
- Checkout fields: a plugin can ask the buyer for a tax number or other details with the `checkout_fields` event; they print on the invoice and in the Invoices CSV.
- The API quote has `checkout_fields` and the order takes their answers in `fields`; quote and order lines carry their own `tax`.
- `osc_item_saved_count()` shows how many wishlists hold a listing.
- `AvatarUpload::store()` saves an image file on disk as a user's avatar.
- `osc_sitemap_url()` returns the sitemap URL.
- A theme can declare `osc_add_theme_support('comments', false)`; Comments settings then notes the theme shows no comments.
- `AccountService::registerVerified()` makes an active account for an e-mail a sign-in provider already verified, for social login.
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
- Orders have a **Processing** status, set by hand or by the first partial shipment.
- An order can ship in parcels: pick the quantities, add tracking per parcel, and the buyer gets a mail for each. Mark an order packed as an admin note.
- Each order keeps a history of its status changes, shown on the admin order page.
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
- The REST order has `shipments[]` (one per parcel) and `packed_at`.
- New hooks `billing_order_processing` and `billing_shipment_add_after`. One parcel that ships a whole paid order fires `billing_order_shipped`, not `billing_order_processing`.
- Messaging API: `/account/conversations`, `/admin/conversations`, `/admin/messages`, and the webhooks `message.created` and `conversation.reported`.
- `POST /listings/{id}/publish` and `POST /admin/listings/{id}/publish` publish a listing waiting for payment.
- The API serves `GET /pages`, `/pages/{id|name}`, `/menus` and `/seo`, and the site document carries `mode` and `features.shop`.
- `GET /listings` takes `page`, `ids`, `option[name][]`, `bbox`, `in_stock=true` and `on_sale=true`, and answers `compare_at`, `on_sale`, `low_stock` and `meta.total_capped`.
- `GET /categories` takes `roots=true` and `include=cover`. `PATCH /admin/regions|cities` takes `names`.
- Every bundled theme reads Appearance → Menus, and a child theme takes its parent's menu places, widget sections and route files.
- The pay step shows the amount, bank details with Copy buttons and the deletion date; the post form says Save and pay when a listing will wait for payment.
- Shop admin: one test-mode notice for all payment methods, filter chips, a Waiting for payment tab, and courier suggestions on the order page (`admin_billing_carriers`).
- Tests also run as a shop site in CI.
- **Listings → Settings** can make a name required when a guest posts a listing; it is off by default.
- Addresses follow the country: the account form and checkout ask for a state or county only where people write one, label the postcode as that country does, and check its shape. `osc_address_formats()` and `osc_address_form_script()` let a theme use the same rules, and `osc_address_text()` writes an address in the country's order.
- The State box in an address form lists the shop's own regions for the chosen country, and the Country box is hidden when the shop has one country. A theme can listen for `osc:address-country`.
- Each currency has a **Decimals** setting (JPY 0, KWD 3, most 2) that every price, order, receipt and e-mail follows; the API shows it as `decimals`.
- `GET /countries` lists each country's `address_format`.
- **Settings → Billing → Shop** sets product weights in grams and kilograms or ounces and pounds. Weights stay stored in grams, and the API always uses grams. `osc_weight_unit()`, `osc_weight_display()`, `osc_weight_input()` and `osc_weight_parse()` read and convert them.
- The installer offers Stripe and PayPal beside Razorpay.
- **Invoices and credit notes**: every paid order gets a numbered invoice (`INV-2026-00042`), and every refund a credit note (`CN-2026-00003`) with its tax shown in proportion. Numbers restart each year. **Settings → Billing → Receipts** turns them off and sets the prefixes; **Billing → Invoices** lists them with a CSV download. Orders paid before 7.1 get none.
- Buyers see the invoice and credit notes in their account and in the receipt mail; the REST order has `documents[]` and `refunded_total`.
- **Partial refunds**: **Refund part** on a shop order refunds an amount or some units, with a reason and an option to return them to stock. The order shows **Partly refunded** until the credit notes add up to what was paid, then becomes refunded. Only orders with an invoice can be refunded in part.
- Stripe, PayPal and Razorpay refund part of an order through the provider. A gateway adds this with `PartialRefundableGateway::refundPart()`; other gateways record the refund only.
- Hooks `billing_document_compose` and `billing_document_issued`; the buyer gets a mail when a partial refund is made (`email_order_part_refunded`).

### Breaking
- Shopclass now needs PHP 8.2 or newer; the updater refuses a release the server's PHP cannot run.
- Sites update to 7.1 from 7.0.12; an older 7.0 site is offered 7.0.12 first.
- Releases ship only `shopclass_v*.zip`; `osclass_v*.zip` is no longer built.
- Namespaced core classes moved from `oc-includes/osclass/classes/` to `oc-includes/classes/`, grouped by feature. Class names do not change; code that loads a class file by path must use the new path.

### Changed
- `billing_order_shipped` fires only when the last item ships; every parcel fires `billing_shipment_add_after`. A paid order may now be `processing`, so test `Order::isPaid()` rather than `'paid'`.
- Money uses the currency's decimals, not the language's: `Money::format()`, `osc_format_price()` and `osc_prepare_price()` now print JPY with none and KWD with three.
- An address no longer needs a state or postcode everywhere, and a phone may start with `+` and a country code; the fixed 8-digit phone and 6-digit PIN rules are gone. Money no longer follows the language's decimal setting.
- Core now bundles symfony/http-client 7.4 (was 5.4) with async-aws/s3 3.5; a plugin that implements Symfony's HTTP client interfaces needs the 7.x signatures.
- `ImageProcessing` and `ImageResizer` are deprecated; use `mindstellar\image\Processor`.
- A listing photo upload is turned upright and saved once.
- Prices in Indian locales (en_IN, hi_IN) show Indian digit grouping, e.g. ₹12,34,567.
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
- Appearance → Themes says when you have unpublished Customise changes.
- Unused admin actions are gone: ajax `bulk_actions`, `reinstall_osclass`, `location`, `location_impact`, `fields_order`; Appearance `add_widget`; Tools `import_post`, `backup_post`, `backup-sql*`, `backup-zip*`, `system_info`.
- 43 unused helpers, methods and classes are deprecated and go in 8.0, among them `osc_auto_update()`, `osc_csrf_name()`, `osc_mod_rewrite_loaded()`, `osc_dbdump()`, `SystemInfo` and `FormBuilder`.

### Security
- A plugin download link from the catalogue is shown only when it is an https address.
- An IP ban rule can be an IPv6 address or a CIDR range (`203.0.113.0/24`, `2001:db8::/32`); before, IPv6 visitors could not be banned by IP.

### Performance
- Option filter counts on 232,000 listings: 446 to 53 ms on MariaDB, 526 to 208 ms on MySQL; number and date ranges under 5 ms.
- API calls a theme makes on one page share their work, and API listing searches are cached, so a home page runs about half the queries.
- Deep admin order pages use a deferred join (page 500 of 100,000 orders: about 100 ms to 2 ms).
- Product alerts find their listing through an indexed column (migration 0089).

### Fixed
- Photo processing: JPEGs open without the exif extension, small images keep their size under Imagick, GIF and upper-case formats save correctly, and GD refuses photos too large for the memory limit.
- Portrait phone photos no longer get white bars on the sides when Imagick resizes them.
- Mail Settings no longer takes the browser's saved admin login as an unsaved change, so the test email runs after saving.
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
- The memory limit at start-up is only raised, never cut; a host's 1G or unlimited setting stays.
- An admin can edit a rated comment down to no text, on the screen and in the API.
- Options passed to `Sanitize` string, float, email and encoded methods now reach the filter.
- Saving General Settings with an address lookup set up queues map positions for older listings.
- S3 storage errors and skipped mail attachments are written to the error log.
