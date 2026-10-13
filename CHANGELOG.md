# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 7.0.0.beta2

Shopclass 7.0 opens your site to apps. A new REST API lets mobile apps, scripts and other sites read your listings, let people post and comment, and run the site with an admin key. Webhooks tell other systems the moment something changes. The API is off until you switch it on in **Settings → API**.

It is also faster on a VPS or in Docker. With Redis or Valkey, background jobs start at once and busy counters stay out of the database, and the Docker image no longer needs a cron line. On plain shared hosting everything still works as before.

Beta 2 gives themes search snippets, related listings and search box suggestions, which a search plugin can take over.

Plugin authors: please read **Breaking** before you upgrade.

### New

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

### Breaking

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
- A custom URL field takes only `http` and `https` addresses.
- Backup files are private from the moment they are created.
- Installing a language refuses anything that is not a locale code such as `en` or `en_US`.
- A photo URL that fails to download no longer says why.
- The category picker on the post and edit forms keeps a crafted `catId` in the address inside its script, so the link can no longer run code.

### Performance

- A page that never asks for payment methods no longer builds the payment gateways.
- The e-mail code moved to `mindstellar\mail\Mailer` and `CoreEmails`, so a page that sends no e-mail no longer loads it. The old `osc_sendMail()`, `osc_mail_*` and `fn_email_*` functions keep working.
- Saving a listing runs fewer queries (an edit 19 instead of 24) and resizes photos before it locks any rows; `posted_item` and `edited_item` get the saved row and texts instead of reading them again.
- The "with photos" search checks each listing's photos directly instead of joining them all.
- Deleting a listing or account queues one job for its files on remote storage, not one per photo.
- Listing counts per country, region and city are corrected once a week in the background instead of in a slow hourly pass.
- The market catalogue cache no longer rides along with the settings every page loads (about 140 KB).
- Saving a listing whose expiry did not change no longer rewrites it.
- API listings sorted by price page by cursor, and photo URLs in an API post download side by side.
- The API counts requests in the object cache, so with memcached or Redis every web server shares one count.
- Pages run fewer queries: with no object cache a listing page runs 27 instead of 31, and with memcached or Redis home runs 8 instead of 12.
- With memcached or Redis, settings and the country list are cached, a page reads its cache groups in one round trip, and no page writes to the cache on a normal view.
- Database queries send their values in one trip instead of a prepare and an execute; `define('OSC_DB_REAL_PREPARES', true);` in `config.php` brings back real prepared statements.

### Changed

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

Source: https://github.com/mindstellar/shopclass
