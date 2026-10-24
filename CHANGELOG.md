# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 7.1.0

Shopclass 7.1 adds a shop mode and pay at the end of posting. In shop mode only admins post and shoppers get a buyer account. Over the limit, a seller now saves the listing and pays on the last step.

### New
- Option filters and custom-field filters read a filter index (`t_item_facet`), built in the background after the update. `search:reindex` and System info → Database → Rebuild filter index rebuild it.
- The shop hooks are typed events. A callback whose first parameter is an event class gets the event; the hook names and old callbacks work as before. See [Hooks](https://shopclass.org/docs/developers/hooks/).
- `osc_api_get()` and `osc_api_call()` call the API from PHP inside the site, with no key, and work with the API switched off. See [REST API](https://shopclass.org/docs/developers/api/).
- A theme or plugin can declare `modes` (`classifieds`, `shop`) and the plugins it needs, in `shopclass.json` or the `Modes:` and `Requires Plugins:` headers. See [Package spec](https://shopclass.org/docs/developers/package-spec/).
- **Settings → General → Site is a** sets classifieds or shop; in shop mode only admins post. `osc_site_mode()` reads it.
- Activating a theme that needs plugins opens one screen that installs and activates them, and Appearance warns if one is later removed.
- The API serves `GET /pages`, `/pages/{id|name}`, `/menus` and `/seo`, and the site document carries `mode` and `features.shop`.
- Listings have a `product` member with variants (SKU, price, stock, weight, shipping class), set by an admin.
- A listing can have option rows (Colour x Size and so on), each with its own SKU, price and stock, set in the admin item form or by an admin through the API. `GET /listings` takes `option[name][]=value` and `ids`.
- **Goods orders**: a cart is priced on the server and placed as an order, paid by a card plugin, bank transfer or cash on delivery. Stock is taken once when the order is paid (bank transfer and cash on delivery hold it from placement) and given back on refund or cancel; a line paid after the last one sold is marked oversold.
- **Billing → Orders** shows a goods order's items, address, buyer and oversold lines, and can mark it shipped (carrier, tracking number, https link), delivered or cancelled. **To ship** lists paid orders not yet sent.
- **Settings → Billing → Shop** sets the delivery charge, free delivery from, cash on delivery and its fee, hold days, open unpaid orders per buyer and the order number prefix.
- The shop API: `GET /cart/quote` prices a cart, `POST /account/orders` places an order, and `GET /account/orders`, `/account/orders/{id}`, `/cancel` and `/pay` read, cancel and pay it again. `GET` and `PUT /account/cart` keep a signed-in shopper's cart for 90 days.
- The cart hooks (`cart_shipping_methods`, `cart_shipping_lines`, `cart_tax_lines`, `cart_discount_lines`, `cart_totals`, `cart_free_over`, `cart_problems`, `cart_needs_shipping`, `cart_item_add_after` and `cart_checkout_before`) are fired, so shipping, tax and promotion plugins price a cart. See [Hooks](https://shopclass.org/docs/developers/hooks/) and [Billing](https://shopclass.org/docs/developers/payment-gateways/).
- Buyers get an e-mail when an order is placed, paid, shipped, delivered, cancelled or refunded; one that fails to send is tried again once from the job queue. The owner gets one when a new order comes in.
- Comments can carry a 1 to 5 rating, shown and averaged by the API and in the admin Comments list.
- A signed-in user keeps a wishlist and back-in-stock or price-drop alerts through `account/wishlist` and `account/alerts`.
- **Appearance → Customise** edits a theme's sections and look beside a live preview, saved as a draft until Publish. See [Customiser](https://shopclass.org/docs/developers/customiser/).
- The widgets screen has an eye button that hides and shows a widget without deleting it.
- `osc_kv_list()` pages through a key-value group, and `osc_kv_increment()` adds to a counter in one statement.
- `osc_billing_user_order_count()` counts a user's goods orders by status.
- `osc_billing_order_return_url()` (filter `billing_order_return_url`) is where a gateway sends the buyer after paying; `OrderStore::pendingBefore()` lists one gateway's old pending orders.
- `billing_order_failed` fires when a card payment fails, and the daily job closes card orders left unpaid past the hold days.
- `billing_tracking_url` and `billing_receipt_details` let a plugin set an order's tracking link and add to its receipt, and the order mails take their text from the `email_order_<kind>_title` and `_description` filters.
- `GET /listings` takes `page` for numbered pages with any sort, and answers `meta.page` and `meta.pages`.
- A product variant can have a "was" price (`compare_at`, set by an admin); listings answer `compare_at` and `on_sale`.
- Variants answer `low_stock`, the units left when 1 to 5 are, so a shop can say "Only 3 left".
- `GET /listings` takes `in_stock=true` and `on_sale=true` to list only what a shop can sell now or has marked down.
- `GET /categories?include=cover` adds each category's cover: the first photo of the newest live listing in it or below it.
- `GET /categories?roots=true` lists only the top-level categories.
- **Pay at the end of posting**: over the limit, the listing is saved and paid for on the last step. Switch it in `Settings → Billing → Pricing → Take payment at the end of posting`.
- A saved listing shows as "Waiting for payment" on My listings and in the admin Listings filter; an admin can use "Publish without payment".
- A seller gets a reminder e-mail a day before an unpaid listing is deleted; edit it in `Settings → Emails`.
- `POST /listings/{id}/publish` and `POST /admin/listings/{id}/publish` publish a listing that is waiting for payment.
- In shop mode a shopper's account menu shows buyer pages only, with Your orders and recent orders on the dashboard.
- Digital Goods 2.4.0 adds a buyer Downloads page and account menu entry.
- **Listings → Settings → Search count limit** stops search counts at a number and shows "1,000+" past it; themes use `osc_search_total_text()` and `osc_search_total_capped()`, and the API sends `meta.total_capped`. New sites start at 1000, updated sites at 0 (exact).

### Breaking
- Shopclass now needs PHP 8.2 or newer; the updater refuses a release the server's PHP cannot run.

### Changed
- Searches ignore anything past 191 characters of a filter value, match a NUMBER or DATE range only on numeric values, and match a stored value without its leading spaces.
- Core works with MariaDB 11.6+ snapshot isolation (`innodb_snapshot_isolation=ON`): rows are locked first, and plugin writes should follow the [Write transactions](https://shopclass.org/docs/developers/database/) rule, locking the listing with `ListingStore::lockForWrite()` in `apply()`.
- Option names that differ only by case or accent count as one filter; the listing form refuses such duplicates.
- A subclass of `Search` that overrides `addCondition()` or `addFieldFilter()` must accept the new optional last argument.
- A subclass of `Search` that overrides `countMatches()` must accept the new optional last argument.
- `sql_search_conditions` runs twice per search, once for the page list and once for the count list.
- Raw SQL writes to `t_item_meta` need Rebuild filter index under System info → Database.
- `GET /listings` reads at most 10 `option[name][]` names.
- `item_increase_stat` and `item_decrease_stat` fire after the commit, so they now come after `posted_item` and `edited_item`.
- Settings is grouped by area (General, Content, Email, Security, System) with one tab row each, and Billing lists Shipping, Offers and coupons, Tax and Digital Goods beside its own Settings. A "Find a setting" box in the top bar searches every setting. Ban rules moved from Users to Settings > Security; the URLs are unchanged.
- `SiteMode` moved from `mindstellar\market` to `mindstellar\site`; the old name still works.
- A cash on delivery order ships before it is paid and is marked paid when marked delivered; neither the buyer nor the hold job cancels one once shipped.
- Mails, receipts, bank transfer references and the API show an order number with its prefix.
- `posted_item` and the `listing.created` webhook fire when a held listing is published, not when it is saved.
- A listing waiting for payment takes no slot.

### Security
- A plugin download link from the catalogue is shown only when it is an https address.

### Performance
- Filter counts on 232,000 listings: `option[Colour]=Red` 446 to 53 ms (MariaDB), 526 to 208 ms (MySQL); NUMBER range 76 to 4 ms; date 77 to 1 ms. A save that changes no option rows writes none.
- API calls a theme makes on one page share their work: the route table builds only the routes a call tries, the category tree is read once, and each listing's row, photos, stats and location are read once.
- API listing searches cache the finished rows, without description text when the response asks for none, so the Aisle home page runs 10 statements instead of 21 and a product page 19 instead of 36.
- `GET /menus` caches the linked pages and top category links, and `Currency::listAll()` reads the cached table.
- One listing's rating summary and stars come from one query.

### Fixed
- The API main menu no longer links a top category to a subcategory that has the same slug.
- After-commit callbacks queued behind one that ends the request now run at shutdown.
- Two first upgrade grants for one listing no longer deadlock and lose one.
- Checkbox filters use the `s_value` index.

## Shopclass 7.0.5

This release fixes the installer. On 7.0.4 and earlier the last setup step could fail, so a new site never finished installing.

### Fixed
- "Skip for now" on the installer's country list skips the location import instead of failing it.
- The installer downloads country files from the current location catalog, not from a folder that does not exist.
- A country import during install no longer fails at its last step.
- A failed location import no longer stops the install; the finish page says to add locations later.
- Clicking "Set up my site" a second time no longer fails with "The admin account could not be created".

## Shopclass 7.0.4

The updater now checks the PHP version a new release needs before it changes any file. Shopclass 7.1 will need PHP 8.2, so a site on older PHP is told to upgrade PHP first instead of being broken by the update.

### Changed
- A core update that needs a newer PHP than the server runs is refused before any file changes, with a message naming the version needed.

## Shopclass 7.0.3

On a demo site, the REST API now refuses the key, webhook and location changes the admin screens refuse. This release also fixes the theme preview on public pages.

### Security
- On a demo site (`OSC_DEMO=1`), the REST API refuses to create, rotate or revoke API keys, as the admin screen does.
- On a demo site, the REST API refuses to add, change, delete or test webhooks, as the admin screen does.
- On a demo site, the REST API refuses to add, rename or delete regions, cities and city areas, as the admin screen does.

### Fixed
- A theme preview (`?theme=`) works on public pages again.
- During a theme preview, the `theme-` body class names the previewed theme.

## Shopclass 7.0.2

This release lets a theme preview last for the whole visit, and lets any visitor preview themes on a demo site.

### Changed
- A theme preview (`?theme=`) now lasts for the whole visit. `?theme=` with no name ends it.
- On a demo site (`OSC_DEMO=1`), any visitor can preview an installed theme.

## Shopclass 7.0.1

This release fixes the search filter sidebar, which listed custom fields in the Fields library order instead of the order set inside the form.

### Fixed
- The search filter sidebar lists custom fields in the order set inside the form, like the post form (#574).
- Migrations 0001 and 0025 can run again on a 7.0 database without changing it.

## Shopclass 7.0.0

Shopclass 7.0 is a big release and the base for the features that come next in the 7.x line. It opens your site to apps. A new REST API lets mobile apps, scripts and other sites read your listings, let people post and comment, and run the site with an admin key. Webhooks tell other systems the moment something changes. The API is off until you switch it on in **Settings → API**.

Pages are faster, with more caching, kept database connections and compressed files. With Redis or Valkey on a VPS or in Docker, background jobs start at once and busy counters stay out of the database, and the Docker image needs no cron line. Shared hosting works as before.

Sellers at the listing limit can buy an extra slot or account upgrades with credits. Themes get search snippets, related listings and search suggestions, and plugins get their own database migrations, an HTTPS client and address lookup. The admin is tidier and easier to use: one look for tabs, forms and lists, short help text, a layout that works on a phone, no Bootstrap JavaScript, and a media library for site images.

Plugin authors: please read **Breaking** before you upgrade.

### New
- `osc_admin_count($n)` draws a count as a small badge beside a tab, filter or button label, and `osc_admin_link_group()` takes a `count` per link and then draws a tab strip that scrolls on a phone.

- A `category_modes` settings field sets a mode per category, with an Off switch per main category and subcategories that follow their parent; `osc_category_mode()` reads it.
- A geocode provider can say its request allowance is used up (`GeocodeLimitReached`); the lookup waits for it to renew instead of failing. `ListingGeocode::queueExisting()` looks up older listings in the background.
- A search plugin can answer the admin Listings screen: `search_results` fires with context `admin`, and `SearchFilters` adds `flags`, `itemId` and `contactEmail`.
- `osc.offcanvas` and `osc.dropdown` in the admin, for slide-in panels and dropdown menus with no Bootstrap.
- `osc.collapse` in the admin: show and hide a panel with `data-osc-collapse`, as an accordion with `data-osc-parent`, with `osc:collapse:*` events.
- Watermark opacity, four more positions (top, bottom, left and right centre) and a tiled pattern; a text mark has an outline instead of a solid box.
- Plugins run their own database migrations with `MigrationRunner::forPlugin()`. See [Database](https://shopclass.org/docs/developers/database/).
- `oc-cli.php plugin:install --plugin=<folder>` installs and enables a plugin from a shell.
- Plugins add their own tables to a person's data export with the `personal_data_export` filter.
- A payment that arrives for an order already closed as failed is logged as "paid after close" for the admin.
- `mindstellar\http\CurlHttpClient` gives plugins one HTTPS client with timeouts and a size cap. See [HTTP client](https://shopclass.org/docs/developers/http-client/).
- `osc_mail_admin()` e-mails the site owner a short notice and never throws.
- Geocode provider plugins find a listing's exact position from its street address; pick one in Settings → General → Maps. See [Distance search](https://shopclass.org/docs/developers/distance-search/).
- `$search->filters()` gives a search plugin what to search for as plain values, custom fields included, so it no longer parses `toJson()`.
- **REST API** at `/api/v1` for listings, categories, custom fields, locations, currencies and profiles, with API keys, paging and an OpenAPI description. See [REST API](https://shopclass.org/docs/developers/api/).
- People can sign in through the API, then post, edit and delete listings, upload photos, comment and save searches. A retried request with an `Idempotency-Key` is never done twice.
- Admin keys manage listings, comments, users, categories, settings, keys and the job queue through `/api/v1/admin/`; moderator keys reach listings and comments only.
- **Webhooks** send signed messages when listings, comments and users change, including a `listing.reported` message when a visitor reports a listing (at most once an hour per listing and reason). Failed deliveries retry and a failing endpoint pauses itself. See [Webhooks](https://shopclass.org/docs/developers/api/webhooks/).
- **Settings → API** makes, rotates and revokes keys and manages webhooks. `oc-cli.php api:key:create`, `api:key:list` and `api:key:revoke` do the same from a shell.
- A new **API access** page shows people which apps are signed in to their account and, if you allow it, lets them make their own keys.
- **Sign out of all devices**, for people on their account page, for admins on their profile, for any user from the admin Users screen, and through the API.
- **Redis and Valkey** work as the object cache with `OSC_CACHE=redis`, through phpredis or a built-in client that needs no PHP extension.
- `oc-cli.php jobs:work --listen` runs background jobs as soon as they are due and runs the scheduled tasks too, so it replaces the cron line. The Docker image runs it and its compose file adds Valkey.
- The Docker image sizes its PHP worker pool to the host (three per CPU, within memory) instead of php-fpm's fixed 5; `OSC_FPM_MAX_CHILDREN` sets it.
- `OSC_DB_PERSISTENT` (environment) or `define('OSC_DB_PERSISTENT', true);` keeps each PHP worker's database connection between pages. Off by default.
- `osc_cache_remember($group, $key, $load, $expire)` caches a theme's or plugin's value in a group core drops when that data changes, and uses the per-worker copies of `OSC_CACHE_LOCAL`.
- With Redis or Valkey, listing views and rate limits count in the cache instead of writing to the database on every visit.
- A premium upgrade ends exactly at its end time, not up to an hour later.
- Theme JavaScript can call the API as the signed-in user with `osc_api_session_meta()`.
- Plugins can add API routes, scopes, listing fields, schemas and webhook events, with `ApiKit`, `osc_api_register_schema()` and the `api_problem_codes` filter. See [Plugin endpoints](https://shopclass.org/docs/developers/api/plugin-endpoints/).
- A shared key-value store with the `osc_kv_*()` helpers, so plugins can keep small values without a table of their own. See [Key-value store](https://shopclass.org/docs/developers/kv-store/).
- **Distance search**: visitors find listings within 5 to 100 km or miles of a city or of where they are, and can sort nearest first. Switch it on in **Settings → General**. See [Improving search](https://shopclass.org/docs/configure/search/#distance-search).
- The API searches by distance with `lat`, `lng`, `radius` and `sort=distance`, and each listing has `distance_km`.
- Themes can show a listing's distance with `osc_item_distance()` and draw their own distance field with `osc_search_radius_options()`.
- `osc_item_cover_urls()` gives the first photo of many listings in one query.
- Search reads your database's full-text word length and stopword list, and falls back to a plain text match when a search cannot use the index.
- `register_payment_gateways` hook: gateways register on it, and it fires the first time a page asks for payment methods. Gateways that register on `init` keep working.
- Search snippets: on a keyword search each listing gets its text with the searched words marked, from `osc_item_search_snippet()` and the API's `snippet`. A search plugin can also send category counts, a "did you mean" query and a fuzzy-match flag (`osc_search_category_counts()`, `osc_search_suggestion()`, `osc_search_relaxed()`, and `meta` in the API).
- Related listings: `osc_related_listings()` and `GET /listings/{id}/related` show live listings like one listing (same category, shared title words). A search plugin can pick them with the `item_related_ids` filter.
- Search box and post form suggestions: `GET /search/suggest` lists listings whose titles match, with a thumbnail, and `GET /search/categories` suggests up to three categories for a title. A search plugin can answer either through the `search_suggestions` and `category_suggestions` filters, and may add suggested searches. Themes add `osc_search_suggest_attributes()` to a search input to show the list.
- **Media library**: one screen lists every stored file with a summary, search, a grid view, bulk delete and all kept photo sizes. Rich text editors get a **Media library** button that inserts a site image.
- `osc_admin_tab_nav()` draws page tabs (System info, Billing, status filters) with the same look as the other admin tabs.

### Breaking

- Core no longer looks addresses up through the map's own key (Google or MapQuest); a listing gets an exact position only from an address lookup plugin such as LocationIQ, once it is picked under **Settings → General → Maps → Address lookup**. `ListingGeocode::parse()` is removed. The OpenStreetMaps (MapQuest) key setting is gone, since showing that map never needed it, and `osc_openstreet_api_key()`, `osc_openstreet_geocode_url()` and `osc_google_maps_geocode_url()` are deprecated.
- The admin no longer loads Bootstrap's JavaScript; a plugin that uses `data-bs-toggle`, `bootstrap.Modal` or the like must call `osc_enqueue_script('bootstrap5')`.
- The text watermark's width, height and offset settings are gone; the mark is sized to its text. `createWatermarkImageFromText()` reads only `background_color` (now the outline colour) from its options.
- The live listing count of each country, region, city and category is now an `i_num_items` column on `t_country`, `t_region`, `t_city` and `t_category`; the four `t_*_stats` tables are gone. `CityStats` and the other stats classes keep their own methods; the methods they inherit from `DAO` no longer work.
- `t_category_slug_history` and `t_location_slug_history` are now one table, `t_slug_history`, with an `e_type` column (`CATEGORY`, `REGION` or `CITY`).
- Saved-search alerts go out daily or weekly; hourly alerts become daily on upgrade. `hook_alert_email_hourly` no longer fires.
- Listing photos moved from `t_item_resource` into `t_resource` (owner type `item`), with the same ids, files and links; `fk_i_item_id` is `i_owner_id` there. Use the photo helpers or `ItemResource` instead of SQL on the old table.
- `t_plugin_category`, `t_cron`, `t_alerts_sent`, `t_user_email_tmp` and `t_item_upload_tmp` moved into the key-value store, and `t_locations_tmp` is removed.
- `ItemResource`, `PluginCategory`, `Cron`, `AlertsStats`, `UserEmailTmp` and `ItemTmpUpload` keep their own methods but no longer extend `DAO`, so inherited calls such as `insert()` and `update()` are gone.
- Plugin API routes live under `/api/v1/ext/<plugin>/`, and a route's `plugin` must be that slug. An admin plugin route needs an `admin:` scope, or the plugin's own `ext:` scope for admins or moderators.
- The `api_listing`, `api_user` and `api_category` filters get `$data, $context` instead of the database row.
- Listing Import 0.3 needs Shopclass 7.0: its API moved to `/api/v1/ext/listing-import/`, old plugin keys stop working (make new ones in **Settings → API**), and a record it cannot import is a `422 validation_failed`.
- `/api/` is reserved: rename any page or category with the slug `api`. System info and `doctor` list them.
- `osc_mailBeauty()` no longer fills in a placeholder such as `{WEB_URL}` that a plugin passes inside a value; put it in the template text instead.
- `osc_count_premium_comments()` and `osc_has_premium_comments()` are removed; they always ended in a fatal error.
- `osc_sanitize_phone()` keeps a leading `+`, spaces and dashes; `osc_sanitize_username()` keeps dots and `Sanitize::username()` turns spaces into `_`; `osc_sanitize_int()` returns an int ("1.5" is 1).
- `oc-includes/osclass/mimes.php` is removed, and core uploads take image types only (see `UploadMimes`): extensions such as pdf or zip in the allowed list no longer pass.
- The `memcache` cache driver is removed; `OSC_CACHE=memcache` now uses `memcached`.
- The `osc_admin_*` page-building helpers (`hAdminUi.php`) load only on admin pages, so a public page that calls one stops with an error.
- The `php-date` script (`oc-includes/assets/osclass-legacy/js/date.js`) is removed; core stopped using it when custom date fields became native date inputs.
- Rows from `osc_db_*`, `Db` and `Connection` queries with `?` values come back as text, not numbers, so a strict check such as `=== 1` fails; a number for `LIMIT ?` must be an int. Defining `OSC_DB_REAL_PREPARES` gives numbers back.
- Removed functions, deprecated since 4.0 or never used: `osc_save_permissions()`, `testCurl()`, `testFsockopen()`, `http_chunked_decode()`, `processResponse()`, `processHeaders()`, `download_fsockopen()`, `_get_market_url()`, `_need_update()`, `version_compare2()`, `osc_get_alert_public_key()`, `osc_set_alert_public_key()` and `install_ping_search_engines()`. `osc_check_dir_writable()` and `osc_change_permissions()` now look at the given folder only, not the files under it.
- Removed methods and classes: `Params::_view()`, `PageForm::multilanguage_name_description()`, `Object_Cache_Factory::getCache()` (use `getInstance()`), `LocationsTmp`, `StorageQueue` and `StorageWorker` (use the job queue), and `oc-includes/osclass/timezones.php` (use `DateTimeZone::listIdentifiers()`).

### Security
- `OSC_ADMIN_IP_ALLOW` (in `config.php` or the environment) limits the admin panel to listed addresses and ranges; any other address gets a 404. See [Security](https://shopclass.org/docs/deploy/security/#allow-the-admin-panel-from-your-addresses-only).
- `CurlHttpClient` takes a `resolve` option that pins a host to an address the caller checked, so a plugin fetching a user's URL can close the DNS rebinding gap.

- The admin Languages list escapes each language's name and description, and the installer escapes its language error.
- API keys and refresh tokens are stored hashed, and a browser cookie alone never signs in to the API.
- A new password, whether changed, reset or set by an admin, signs the account out of every device, API keys included.
- The API and the sign-in form share one limit on wrong passwords, and changing your e-mail through the API asks for your password.
- Webhooks only go to ports 80 and 443 on public addresses, unless you allow a private network.
- Signing out deletes the session cookie, on the site and in the admin.
- Limits on sign-in, forms and posting count an IPv6 visitor by its /64 block, so switching addresses inside it no longer resets them.
- A user keeps at most 20 saved searches (**Settings → Spam and bots → Search alerts**), and saving one through the API counts toward the hourly limit.
- Contact form attachments must be a picture, PDF, text or office file whose name matches its content, up to 5 MB (**Settings → General**).
- A listing edit with the wrong secret no longer attaches or resizes the photos it carried, and a post can never resize more photos than the listing may hold.
- Flash, form and sign-in redirect cookies are signed and work once.
- A custom URL field takes only one `http` or `https` address; a second link after a comma or space is refused.
- Backup files are private from the moment they are created.
- Installing a language refuses anything that is not a locale code such as `en` or `en_US`.
- A photo URL that fails to download no longer says why.
- The category picker on the post and edit forms keeps a crafted `catId` in the address inside its script, so the link can no longer run code.
- A placeholder such as `{WEB_LINK}` or `{IP_ADDRESS}` typed into a contact message, name or other text that goes into an e-mail stays as typed; only the template's own placeholders are filled in.

### Performance

- A category's custom fields are cached and dropped when a field or form changes, so category and listing pages skip that query.
- A page that never asks for payment methods no longer builds the payment gateways.
- The e-mail code moved to `mindstellar\mail\Mailer` and `CoreEmails`, so a page that sends no e-mail no longer loads it. The old `osc_sendMail()`, `osc_mail_*` and `fn_email_*` functions keep working.
- Saving a listing runs fewer queries (an edit 19 instead of 24) and resizes photos before it locks any rows; `posted_item` and `edited_item` get the saved row and texts instead of reading them again.
- The "with photos" search reads the photo list once as an IN list instead of looking up each listing (500 ms to 124 ms on 229,000 listings).
- Deleting a listing or account queues one job for its files on remote storage, not one per photo.
- Listing counts per country, region and city are corrected once a week in the background instead of in a slow hourly pass.
- The market catalogue cache no longer rides along with the settings every page loads (about 140 KB).
- Saving a listing whose expiry did not change no longer rewrites it.
- API listings sorted by price page by cursor, and photo URLs in an API post download side by side.
- The API counts requests in the object cache, so with memcached or Redis every web server shares one count.
- Pages run fewer queries: with no object cache a listing page runs 27 instead of 31, and with memcached or Redis home runs 8 instead of 12.
- With memcached or Redis, settings and the country list are cached, a page reads its cache groups in one round trip, and no page writes to the cache on a normal view.
- Database queries send their values in one trip instead of a prepare and an execute; `define('OSC_DB_REAL_PREPARES', true);` in `config.php` brings back real prepared statements.
- A city or region page reads each linked place once, not once per link.
- `osc_esc_html()` skips its entity passes for text with no `&`, which is most text on a page.
- A new database connection sets its character set, autocommit and SQL mode in one statement instead of two.
- With Redis or Valkey and the phpredis extension, each PHP worker keeps its cache connection between pages instead of opening one per page.
- With memcached, each PHP worker keeps its connection between pages instead of opening one per page.
- With the igbinary extension, Redis, Valkey and memcached store cached values smaller and read them faster; the Docker image includes it.
- Region and city lookups in the location boxes may be cached for five minutes by the browser and by a page cache; `AjaxResponse::shared()` sends such JSON.
- The featured listings block is cached for its five-minute rotation and no longer joins the stats table; activating, deactivating or changing a listing's premium state clears it.
- The search total is cached once per search, not per page and sort, so page 2 or another sort skips the count (about 26 ms for a keyword search on 229,000 listings); `Search::count()` reads only the total.
- Widgets for every page area load in one query instead of one per area.
- Listing cover images reuse the photos the page already read instead of querying them again.
- Related listings on a listing page reuse the listing already loaded instead of reading it again (3 fewer queries).
- A keyword search sorted by relevance lets the full-text index pick the listings instead of reading every live one (680 ms to 28 ms on 229,000 listings).
- The Docker image compresses pages, CSS and JS with gzip and lets browsers keep static files (1 hour for CSS/JS, 7 days for images and fonts, 1 year with `?v=`).
- The `.htaccess` core writes now compresses responses and lets browsers keep static files; an older core-written file is updated on upgrade.
- With Redis, Valkey or memcached, custom fields find a category's parents from a small cached list instead of reading every category in every language.
- With Redis, Valkey or memcached and APCu, `OSC_CACHE_LOCAL` lets each PHP worker keep its own copy of the cached values every page reads for up to 15 seconds, so the home page reads about 100 KB from Redis instead of 950 KB. The Docker image loads APCu and turns it on.

### Changed
- The admin works on a phone: list rows become compact cards, tab strips scroll, dialogs keep their buttons in view, and the pager is one row.
- One tab look across the admin, and Billing and Spam and bots show one form per tab with one Save button.
- The user editor is one form with sections, a status panel with the account's actions, and the user's alerts on their own tab.
- Admin help text is one short line per field, and add buttons name what they add, such as "Add listing".
- The listing map choice moved from **Settings → Listings** to **Settings → General → Maps**, next to its Google Maps key and the address lookup, with clearer help text.
- Counts on the Market Updates tab, the Update all button, System info and the Locations filter are badges instead of a number in brackets.
- The category checkbox tree (`osc_admin_tree_picker()`) shows a card per main category with folded subcategories and a live count; a main category with only some subcategories ticked shows a dash and is not saved itself. Its posted names, ids and Check all / Uncheck all still work.
- A seller at the listing limit sees a button on My listings, Credits and Orders that buys an extra slot and opens the post form, or a link to buy credits.
- The payment receipt e-mail links to the Credits page.
- Credits, Buy credits and Orders are tabs on one page.
- A seller over the listing limit sees the slot box with how many slots to buy, not only an error.
- Inside `osc_db_begin()` or `osc_db_transaction()`, a legacy DAO query that hits a deadlock now throws `DbException` instead of returning false, since the server has already rolled the transaction back.
- The admin menu and the page help box open and close with `osc.collapse` instead of Bootstrap; a plugin listening for `shown.bs.collapse` there should listen for `osc:collapse:shown`, and its own `data-bs-toggle` menu markup no longer joins the menu's accordion.
- The object cache starts fresh after an upgrade, so pages and sitemaps built by the old release are not served; a flush also clears what older releases left.
- An account name is at most 50 characters; names already saved are kept, and must be shortened the next time the account is edited.
- An account description is at most 1000 characters in each language.
- The `search_results` filter also gets the context (`request` or `api`) and whether to return bare `t_item` rows.
- Generated files (Composer packages, vendored assets, admin CSS and JS builds) are no longer in git; run `composer install` and `npm run build` after cloning. Release zips are unchanged.
- The RSS feed, sitemaps and robots.txt send the same cache headers as public pages.
- A new password needs at least 5 characters on the server too, not only in the browser.
- Every REST API call needs a key or token, except sign-in, sign-up and the API description; the anonymous-reads setting is gone.
- Visitors who are not signed in get a guest page token, so the site's own pages can read public data from the API.
- With the REST API switched off, the site's own pages still use it with page tokens; apps and keys are refused.
- Admin panel pages get an admin page token, so admin JavaScript can call admin endpoints as the signed-in admin.
- Every public page carries the API meta tag with its page token, printed by `osc_head()` or, for older themes, the `header` hook.
- `mindstellar\logger\OsclassErrors` is now `ErrorHandler`; the old name still works.
- While new files wait for their database upgrade, visitors, the API and web cron get the maintenance page until an admin runs the upgrade (or `php oc-cli.php db:upgrade`), so an upgrade from 6.4 no longer shows database errors.
- The admin warns while an active plugin version is known to break on this core, such as Listing Import before 0.3.0.
- `$model->dao` (`DBCommandClass`) is deprecated and goes away in 8.0. A model builds it only when a plugin reads it, and in debug mode that logs a notice. Use the `osc_db_*` helpers.
- With memcached or Redis, a plugin that writes `t_preference` with raw SQL must call `osc_reset_preferences()`, or other requests keep the old settings for up to 5 minutes.
- Posting, editing and deleting a listing, and signing up, each run in one transaction, so a failure leaves nothing half done and e-mails go out only once it is saved.
- A signed-in user comments under their account's name and e-mail, and a listing that is not live takes comments only from its owner.
- People may post 20 comments an hour (guests 20 per address), on the site and through the API together.
- Asking to change your e-mail to an address someone else holds no longer says it is taken; no link is sent. You may ask 5 times an hour.
- Deleting your account asks for your password.
- An admin comment edit needs a valid author e-mail and a body, on the screen and through the API.
- Unblocking a comment e-mails its author when it goes live.
- Banned e-mails and addresses are checked on sign-in, sign-up and `ItemActions::add()`, as on the post form.
- Admin status changes to listings, users and accounts are logged and fire their hooks.
- **Listings → Settings** and **Users → Settings** save a blank or negative number as 0; currency codes must be three letters.
- Upgrading refreshes an `.htaccess` Shopclass wrote so Apache passes the `Authorization` header to the API; a hand-edited one is left alone.
- A listing's API version (`ETag`) changes when its photos change.
- The installer no longer pings Google and Bing with the sitemap; both services are retired.
- Code moved into `mindstellar\` modules shared by the site and the API: listings, sign-in, accounts, comments, categories, currencies, custom fields, cache and forms. Old class names still work. See [Architecture](https://shopclass.org/docs/developers/architecture/).
- Shared classes are reached with `getInstance()`; `newInstance()` and `instance()` still work but are deprecated.
- A failed query inside a transaction is no longer retried. New helpers for plugins: `Db::withNamedLock()`, `Db::retryOnce()`, `FileSystem::writeAtomic()`, `FileSystem::head()`, `ImageProcessing::usesImagick()` and `Sanitize::name()`, `slug()`, `text()` and `richHtml()`.
- New actions `user_signout_all_after` and `admin_signout_all_after`. `before_validating_login` fires after the empty-field and captcha checks, `before_user_delete` fires for admin deletes too, comment hooks get the comment id as an int, and `pre_item_delete_comment_post` fires only once the author is confirmed.
- `mindstellar\Csrf` is now `mindstellar\security\Csrf`; `BackupManager` is now `BackupService`. Old names still work. `mindstellar\upgrade\Plugin` and `Theme` are deprecated.
- `osc_sanitize_allcaps()` and `osc_sanitize_name()` handle accented letters.

### Fixed
- Plugins and themes that refuse a listing, comment or account delete from a pre-save hook no longer leave the save's database transaction open.
- A long batch of flash messages no longer gets lost after a redirect; the first ones show with "…and N more".
- Admin login cookies stay on the admin path, and a two-step sign-in left at the code page no longer starts a session that skips the page cache.
- The plugin and theme Browse cards show the release this site can install, not a newer one it cannot run.
- Two clicks at once can no longer buy extra photos or longer listings twice; the second is refused and its credits kept.
- The Credits menu link also shows when a free account upgrade is on offer, not only when credits can be bought or are held.
- Sellers can buy account upgrades (extra slots, photos, longer listings, skip the wait) from their Credits page (#561).
- The daily seller recount also catches a listing posted or edited with an expiry date already past, such as an import.
- With no cache server and an `oc-content/uploads/` folder PHP cannot write to, auto-cron no longer runs on every page; its throttle stamp moves to the temp folder.
- A setting changed from the command line reaches web pages at once with Redis, Valkey or memcached, even when the command line uses another site address.
- On settings pages, a checkbox with no label beside it lines up with the other fields.
- Opening a section in the admin sidebar scrolls it into view, so entries below the bottom edge are no longer hidden.
- The Docker stacks run Valkey as a cache only, with no snapshot to disk, so a restart no longer brings back stale cached data.
- The Docker image turns off the PHP JIT, which on PHP 8.5 built wrong search and sitemap links in long-running workers.
- A post that hit a database deadlock is refused with "please try again", instead of showing success for a listing that was not saved.
- A post that hit a snapshot conflict in a write that hides its error is now refused too, instead of writing the rest outside the save. Code that keeps querying after a deadlock without rolling back now gets an error.
- A double click on the listing form's submit button sends one post, not two.
- Search drops braces from a keyword, on the web and in the API; `/search/pattern,{query}` redirects to `/search/pattern,query`.
- A search link carrying an extra list value, such as `?a[]=1`, now redirects to the friendly URL instead of being treated as its own page.
- `db:doctor` lists an extra column or index but no longer exits `1` for it.
- `php oc-cli.php <command> --help` prints the command's summary and no longer runs it.
- The city and region type-ahead lists an exact name first, then the places with the most listings, so a big city is no longer hidden by villages.
- A search URL that sends several categories as `sCategory[]` no longer fails with an error when friendly URLs are on.
- A post or edit form link with `catId[]` in it no longer fails with an error.
- A form or link value sent as a list (`name[]=`) no longer gives a server error on sign-in, CSRF-protected forms, listing and page links, the listing form and many admin screens.
- An admin's `?theme=` preview works on the search page; it was lost in the redirect to the friendly URL.
- Expiry warning e-mails, the most-viewed list, report times and view-count dates use the site's time zone, not the database's. The most-viewed list keeps premium listings past their end date.
- Docker: `SMTP_FROM` may hold a display name, `SMTP_STARTTLS` takes `0`/`1` as well as `on`/`off`, and port 465 no longer tries STARTTLS ([#552](https://github.com/mindstellar/shopclass/issues/552)).
- An unattended install with `OSC_LOCALE` or `--locale` set to a published language downloads it first, and an unknown one stops before the database is touched ([#553](https://github.com/mindstellar/shopclass/issues/553)). The web installer now downloads a language's description and e-mail templates too, and Docker lets the site update or delete a language it downloaded at first boot.
- A watermark scales to the normal and preview sizes, never covers more than 40% of a photo and sits a little in from a corner; thumbnails get no mark.
- A changed profile bio shows at once, not after the next save.
- Searching for a hyphenated word, such as Mid-Century, finds its listings.
- Custom fields can be deleted from the admin again.
- A search page past the last page, and an unknown feed type, return 404.
- robots.txt disallows the real admin path.
- The installer no longer says "No internet connection" when the country list downloaded fine.
- The upgrade screen's update checks no longer fail while the database waits for its upgrade.
- The upgrade screen and changelog page render bold, code and links instead of raw Markdown.
- Checking for APCu or Memcached no longer writes to the error log.
- A seller's listing count goes down when a listing expires; the daily cron recounts it.
- Upgrading on MariaDB 10.2 to 10.4 no longer stops at the utf8mb4 step.
- Restoring a backup on MySQL 5.7 no longer fails on a large table.
- API and keyword searches sorted by relevance keep listings with the same score in the same order on every page.
- A search made only of very short or common words now finds matches instead of nothing, and "sony tv" no longer shows every Sony listing.
- Search results are cached per language, so a language filter no longer shows another language's results.
- **Settings → Media** refuses an image size with a zero side, which broke every photo upload.
- Passing `password` to `osc_sendMail()` no longer changes the SMTP security setting.
- The installer saves a downloaded language into that language's folder.
- An unknown place in a listing no longer causes a server error.
- Editing a listing on the website no longer clears its map position when the site has no map service.
- The alerts chart on **Statistics → Listings** scales to its largest count.
- The category tree no longer breaks on a cache that answers a miss with null.
- Public pages answer 304 to a list of `If-None-Match` tags or a weak `W/` tag.
- `osc_validate_url()` no longer contacts private addresses and gives up after 3 seconds.
- A subcategory under a disabled parent can be disabled, and enabling a category refreshes every cached list that shows it.
- A photo upload request that carries no file, or a malformed one, is refused with an error instead of a server error.

Source: https://github.com/mindstellar/shopclass
