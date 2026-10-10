# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 7.0.0

This release adds a REST API. Apps, scripts and other sites can read your listings, let users post and comment, run the site with an admin key, and get a signed webhook when something changes.

Site owners make keys and webhook endpoints in **Settings → API**, and users manage their own sign-ins on a new **API access** page. The API is off by default; switch it on there.

Plugin authors should read the Breaking section before upgrading.

### New

- Redis and Valkey work as the object cache with `OSC_CACHE=redis`, through phpredis or a built-in client that needs no extension.
- `oc-cli.php jobs:work --listen` starts background jobs as soon as they are due: at once with Redis or Valkey, within seconds without. It also runs the due scheduled tasks, so it replaces the cron line. The Docker image runs it and its compose file adds Valkey.
- A premium upgrade ends at its end time through a background job, not up to an hour later.
- With Redis or Valkey, listing views and rate limits count in the cache instead of writing to the database on every request.
- A `listing.reported` webhook is sent when a visitor reports a listing, at most once an hour per listing and reason.
- A REST API at `/api/v1` for listings, categories, fields, locations, currencies and profiles, with API keys, paging and OpenAPI. See [REST API](https://shopclass.org/docs/developers/api/).
- Users can sign in through the API with a password and a refresh token, then post, edit and delete listings, upload photos, comment and save searches. `Idempotency-Key` makes a retried write safe.
- Admin keys run the site through `/api/v1/admin/`: listings, comments, users, taxonomy, settings, API keys and the job queue. Moderator keys reach listings and comments only.
- Webhooks send signed POSTs for listing, comment and user events, with retries, auto-pause and secret rotation. See [Webhooks](https://shopclass.org/docs/developers/api/webhooks/).
- **Settings → API** makes, rotates and revokes keys and manages webhook endpoints. `oc-cli.php api:key:create`, `api:key:list` and `api:key:revoke` do the keys from a shell.
- Users get an **API access** page to see the apps signed in to their account and, when you allow it, to make personal keys.
- **Sign out of all devices** for users (account page), for admins (their profile; also revokes their API keys) and, from the admin Users screen, for any user.
- `POST /api/v1/account/sign-out-everywhere` and `POST /api/v1/admin/users/{id}/sign-out-everywhere` sign a user out of every device.
- Theme JavaScript can call the API as the signed-in user with `osc_api_session_meta()`. See [Authentication](https://shopclass.org/docs/developers/api/authentication/).
- Plugins can add API routes, scopes, listing fields and webhook events. See [Plugin endpoints](https://shopclass.org/docs/developers/api/plugin-endpoints/).
- API routes carry a version. Plugins get `ApiKit` (with `listingContext()` and `listingsById()`), `osc_api_register_schema()` and the `api_problem_codes` and `api_schemas` filters.
- The `api_listing`, `api_user` and `api_category` filters get `$data, $context`, not the database row.
- `osc_item_cover_urls()` gives the first photo of many listings in one query.
- A shared key-value store, `t_key_value`, with the `osc_kv_*()` helpers. See [Key-value store](https://shopclass.org/docs/developers/kv-store/).

### Breaking

- Saved-search alerts go out daily or weekly; hourly ones become daily on upgrade. `hook_alert_email_hourly` no longer fires.
- `PluginCategory` no longer extends `DAO`, and the `t_plugin_category` table is removed; its public calls still work.
- `t_item_resource` is removed: listing photos are the `item` rows of `t_resource`, with the same ids, files and URLs, and `fk_i_item_id` is `i_owner_id` there. Read them with the photo helpers; `ItemResource` keeps its own calls, but inherited `DAO` calls are gone.
- `Cron`, `AlertsStats`, `UserEmailTmp` and `ItemTmpUpload` no longer extend `DAO`, and their tables move into `t_key_value`. Their own calls still work; inherited `DAO` calls such as `insert()` and `update()` are gone.
- Listing Import 0.3 needs Shopclass 7.0. Its old plugin keys stop working: make new keys in **Settings → API**.
- Listing Import's API lives under `/api/v1/ext/listing-import/`; its 0.2 paths are gone.
- Plugin API routes live only under `/api/v1/ext/<plugin>/`, and the route's `plugin` must be that slug. An admin plugin route needs an `admin:` scope, or the plugin's own declared `ext:` scope for admins or moderators.
- A record Listing Import cannot import is a `422 validation_failed`.
- `osc_count_premium_comments()` and `osc_has_premium_comments()` are removed. They called a method that never existed, so any call ended in a fatal error.
- `osc_sanitize_phone()` keeps a leading `+` and separators and no longer reformats; saved numbers keep their spaces and dashes.
- `osc_sanitize_username()` keeps dots, and `Sanitize::username()` turns spaces into `_`.
- `osc_sanitize_int()` returns an int: "1.5" is 1, not "15".
- The `/api/` path is reserved; a page or category with the slug `api` must be renamed. System info and `doctor` list any.
- `oc-includes/osclass/mimes.php` is removed. Uploads accept image types only, listed in `UploadMimes`.
- The `memcache` cache driver is removed; `OSC_CACHE=memcache` now uses `memcached`.

### Security

- A listing edit with the wrong secret no longer attaches the photos it carried.
- Webhooks go only to ports 80 and 443, and only to public addresses unless you allow a private network. The site connects to the address it checked.
- API keys and refresh tokens are stored hashed, and a cookie alone never signs in to the API.
- Signing out also deletes the session cookie, on the site and in the admin.
- A new password (changed, reset or set by an admin) signs the user out of every device, API sign-ins and keys included.
- API sign-ins and the web sign-in form share one limit on wrong passwords.
- A user keeps at most 20 saved searches, set under **Settings → Spam and bots → Search alerts**.
- Changing the e-mail through the API asks for the current password.
- A photo URL that is refused or fails to download no longer says why.
- Sign-in, form and posting limits count an IPv6 visitor by its /64, so changing address inside it no longer resets them.
- Saving a search through the API or while signed in counts toward the hourly alert limit, as guests already did.
- Flash, form and sign-in redirect cookies are signed for their one use and expire with the cookie.
- A custom URL field takes only `http` and `https` addresses.
- A file written with a private mode, such as a backup's SQL dump, is private from the moment it is created.
- Installing a language refuses a code that is not a locale code such as `en` or `en_US`.
- A listing post refused for coming too soon, or an edit with the wrong secret, resizes no photo, and a save resizes no more photos than the listing may hold.
- A file attached to the contact form or the contact publisher form must be a picture, PDF, text or office document whose name matches its content, up to 5 MB, set in **Settings → General**.

### Performance

- Deleting a listing or account with files on remote storage queues one job for all its files, not one per file.
- The API counts requests in the object cache set by `OSC_CACHE`; with memcached all web servers share one count. Write and hourly caps count in the database.
- The market catalogue cache moved out of the site preferences, which every page loads (about 140 KB on a site that has browsed the market).
- Photo URLs in an API listing write download at the same time, not one after another, within 30 seconds in all.
- Saving a listing whose expiry did not change no longer rewrites it.
- API listings sorted by `price` page by cursor, not offset, and cursors last a week.
- A listing edit hands `edited_item` the row it locked with the edit on it, instead of reading the listing again.
- A listing save runs fewer queries: an API post 25 instead of 31, an edit 19 instead of 24. Places are looked up in one query, and `posted_item` and `edited_item` get the texts the save wrote.
- The "with photos" search checks each listing's photos directly instead of joining every photo and grouping.
- Listing photos are resized before the save's transaction, so it no longer holds row locks while images are processed.

### Changed

- `mindstellar\form\base\FormBuilder`, `FormInputs` and `InputInterface` moved to `mindstellar\form\`; the old names still work.
- The object cache classes moved to `mindstellar\cache\` (`CacheManager`, `CacheDriver`, `MemoryCache`, `ApcuCache`, `MemcachedCache`, `RedisCache`); the old `Object_Cache_*` and `iObject_Cache` names still work.
- Shared core classes are reached with `getInstance()`. `newInstance()` and `instance()` still work but are deprecated.
- Listing counts per country, region and city are recounted once a week as background jobs, instead of a slow hourly pass; the `t_locations_tmp` table is removed.
- The search page is split into a URI resolver and a search runner the API reuses. Its hooks and filters are unchanged.
- The installer no longer pings Google and Bing with the sitemap; both endpoints are retired.
- A listing's API `ETag` and `If-Match` version change when its photos change.
- Unblocking one comment e-mails its author when it goes live.
- Enabling a category refreshes the caches that list it.
- A subcategory under a disabled parent can be disabled.
- Category and custom field labels are escaped in core forms.
- Comment hooks receive the comment id as an int.
- `pre_item_delete_comment_post` fires only once the comment's author is confirmed.
- Upgrading refreshes an `.htaccess` Shopclass wrote so Apache passes the `Authorization` header to the API; a hand-edited one is left alone.
- Web sign-in checks bans against the account's e-mail.
- Web sign-up refuses a banned address as well as a banned e-mail.
- `before_validating_login` fires after the empty-field and captcha checks.
- Editing a user's status in the admin fires its hooks and log, and the user's listings follow.
- A failed sign-in no longer uses up the saved return address.
- Changing a password (user or admin) signs out every device.
- New actions `user_signout_all_after` and `admin_signout_all_after`.
- Posting, editing and deleting a listing on the site runs in one transaction, and its e-mails go out once it is saved.
- A user may post 20 comments an hour (a guest, 20 per address; an IPv6 /64 counts as one address), on the site and through the API together.
- Currency codes must be three letters.
- Sign-up on the site, through the API and on the Users screen runs in one transaction, so a failed sign-up leaves no account behind.
- `ItemActions::add()` refuses a banned e-mail or address, as the post form does.
- Every photo delete is logged the same way, as `item` / `deleteResource`.
- Listing writes and sign-in move to `mindstellar\listing` and `mindstellar\auth`; `ItemAccess`, `UserReauth` are deprecated. See [Architecture](https://shopclass.org/docs/developers/architecture/).
- Accounts, comments, categories, currencies and custom fields move to their own `mindstellar\` modules, shared by the site and the API.
- A signed-in user comments under their account's name and e-mail, and a listing that is not live takes comments only from its owner.
- Asking to change your e-mail to an address another account holds no longer says it is taken; no link is sent.
- A user may ask for 5 e-mail changes an hour.
- Deleting your account asks for your password under the same wrong-password limit as other password checks.
- `before_user_delete` fires for an admin's delete too, and every account delete is logged.
- Admin listing status changes (activate, block, spam, premium) are logged.
- An admin comment edit needs a valid author e-mail and a body, on the screen and through the API.
- `mindstellar\Csrf` is now `mindstellar\security\Csrf`; the old name still works.
- `BackupManager` is now `BackupService`, and `BackupFailure` is now `BackupException`.
- **Listings → Settings** and **Users → Settings** check their numbers: a negative or blank number saves as 0.
- Adding, renaming or deleting a country runs in one transaction, so a failure leaves nothing half done.
- New `Db::withNamedLock()`, `Db::retryOnce()`, `FileSystem::writeAtomic()`, `FileSystem::head()` and `ImageProcessing::usesImagick()` replace the copies core kept of each. A failed query inside a transaction is no longer retried.
- `Formatting::iniBytes()` is the one php.ini size parser; `SystemChecks::iniBytes()` and `MediaSettingsScreen::sizeToKb()` forward to it.
- The market's `.last-backup` pointer is written whole through `FileSystem::writeAtomic()`.
- `cron.php` matches the CLI `cron-type` in any case.
- A custom field's slug avoids the reserved `api`, as a field group's already did. `LocationService::uniqueSlug()` is private.
- Search-alert tokens are sealed with `SecretBox`; tokens from 6.x still open. `alert_public_key` is no longer created.
- `ActionThrottle` counts in `t_rate_counter`.
- `SignedPayload`, `RateLimit` and the token classes accept a clock for tests.
- Every `osc_sanitize_*()` helper forwards to `mindstellar\utility\Sanitize`; new `Sanitize::name()`, `slug()`, `text()` and `richHtml()`.
- `osc_sanitize_allcaps()` and `osc_sanitize_name()` handle accented letters; `Sanitize::allcaps()` no longer lower-cases mixed-case text or escapes it.
- Update-check state moved to the key-value store; `osc_update_check_state()` reads it.
- `osc_admin_pager()` and `osc_admin_pagination()` draw one pager; admin lists page with `iPage`, and old `pageNum` links still work.
- The Cleanup, Maintenance and Activity log settings are declared settings forms, with the same preferences and defaults.
- `mindstellar\upgrade\Plugin` and `Theme` are deprecated. The core updater and the market installer share download and unzip.

### Fixed

- A search made only of stopwords, or of words below the server's FULLTEXT minimum, now matches by substring instead of finding nothing.
- **Settings → Media** refuses an image size with a zero side, which made every photo upload fail.
- A search mixing real words with stopwords drops the stopwords, and a too-short word ("sony tv") must still appear in the text.
- Passing `password` to `osc_sendMail()` no longer changes the SMTP security setting; `ssl` does.
- The installer saves a downloaded language's files into that language's folder.
- `Formatting::formatSlug()` gives the same slug as `osc_sanitizeString()`; its pattern was broken.
- An unknown place id in a listing no longer causes a server error.
- The alerts chart on Statistics → Listings scales to its largest count, not its last.
- The ban rules are read once per request, not once per address checked.
- The search result cache key includes the locale, so a language filter no longer shows another language's cached results.
- The locations pager reloads the list in place again.
- A stopword table set on the database server is read for search.
- A posting wait or throttle window longer than a day is no longer cut to a day.
- A category translated to a new language can no longer take the reserved `api` slug.
- The category tree no longer breaks on a cache driver that answers a miss with null.
- Public pages answer 304 to an `If-None-Match` list or a weak `W/` tag, as the API does.
- The API's `email` and `uri` formats refuse what the web forms refuse.
- `osc_validate_url()` with its header check no longer asks private addresses, and gives up after 3 seconds.
- The pseudo-cron request keeps the URL's port and query and gives up connecting after 5 seconds.

## Shopclass 6.4.6

This release fixes `php oc-cli.php cron`, which ran the hourly, daily and weekly tasks every time
instead of only the due ones, so a cron line every five minutes sent daily alerts every five minutes.

### Fixed

- `php oc-cli.php cron` with no `--type` runs only the tasks that are due, as the docs say.

## Shopclass 6.4.5

This release fixes the admin password reset, which ended in an error page after a new
password was set.

### Fixed

- Setting a new admin password from the reset link no longer ends in an error page.

## Shopclass 6.4.4

This release helps sites where PHP cannot write the site's files: the updater now stops before it
changes anything and says which folders are blocked, and `php oc-cli.php core:update` updates from
the shell as the file owner. It also runs on PHP 8.5 without deprecation notices.

### New

- `php oc-cli.php core:update` downloads and installs the latest release, then upgrades the database, as the user who runs it (#550).
- System info shows the user PHP runs as, the files' owner and any folders PHP cannot write.

### Fixed

- The updater checks it can write every file before it changes anything, and names the blocked folders instead of reporting success (#550).
- CLI commands print one line, not an HTML error page, when the database cannot be reached.
- No deprecation notices on PHP 8.4 and 8.5 (#551).

## Shopclass 6.4.3

This release fixes security issues in the admin plugins screen, listing editing, password reset
e-mails on some Docker setups, redirects and sign-in forms. New hourly spam limits can be set in
Settings → Spam and bots. Sites updating from 6.3 now finish the update when PHP's cache does not
re-check files.

### New

- Settings → Spam and bots has hourly limits for comments, uploads, forms, contact and alerts.
- The item form fires `osc:item-fields-loaded` on `#plugin-hook` when custom fields load or clear.

### Security

- A signed-in user could overwrite the title, description, custom fields and photos of the next listing by id when editing their own.
- The edit form no longer shows saved custom-field values of a hidden listing to people who cannot edit it.
- A banned e-mail address could sign up, post or comment by adding a space or stray symbol to it.
- A crafted link to the admin plugins screen could run script in the admin's browser.
- Installing a plugin from the plugins screen error frame needs a security token.
- The admin listings and users screens escape the sort values from the address bar.
- Turning user alerts on or off in the admin needs a security token.
- The theme preview and `?page=custom` pages only load PHP files inside the themes or plugins folders.
- Redirects after a failed security check, and after sign-in, stay on the site.
- Forms sent from another site are refused for visitors who are not signed in.
- Listing field groups no longer take submissions as public forms; forms take 10 an hour per address.
- Guests may post 20 comments an hour per address; comments on hidden listings are refused.
- City autocomplete returns at most 10 results and nothing for an empty term.
- A new account takes over guest listings with its e-mail only after the address is confirmed.
- Photo uploads on the listing form follow the registered-users-only setting; guests may upload 100 an hour per address.
- With no `WEB_PATH`, e-mail links use `OSC_CLI_URL`; without it, mails with a secret link are not sent.
- The Docker image no longer ships the `tests` and `scripts` folders, and nginx denies them and log files under `oc-content`.
- The Docker image warns at start when `OSC_REAL_IP_HEADER` is set without `OSC_REAL_IP_TRUSTED`, since any client can then fake its address.
- File downloads follow redirects over HTTP and HTTPS only.
- Plugin and theme update backups are protected from download.

### Fixed

- Sites updating from 6.3 no longer keep running 6.3 after the update when PHP's OPcache does not re-check files (#550).
- The listing activation link validates a listing that waits for admin approval and says so, instead of three conflicting messages (#549).
- Cron, the job worker and CLI commands in the Docker image also clear the whole page cache; the purge address is derived from `OSC_MICROCACHE` when not set.
- Custom-field labels name their control; date ranges and radio lists are labelled groups.
- Changing or clearing the category no longer leaves the previous category's custom fields.
- A failed custom-field request no longer puts an error page into the item form.

## Shopclass 6.4.2

This release fixes several security issues on listing pages: photo deletion, spam listings shown to
the public, an unescaped edit link and the contact pages. Site-wide changes such as a new theme or
settings now clear the whole page cache, so visitors see them at once.

### New

- Changing the theme, site settings, permalinks, maintenance mode, plugins, languages, currencies, widgets, categories or pages now clears the whole page cache through one hook, `page_cache_purge` (or `osc_purge_page_cache()`); the Docker image clears its own micro-cache.

### Security

- Changing the password now goes through the sign-in throttle, so the current password cannot be guessed without limit; a malformed form no longer causes an error page.
- A signed-out visitor could delete a photo from a member's listing with only the photo's code; now only the owner, the holder of a guest listing's secret, or an admin can.
- A listing marked as spam was still shown on its page to everyone; now only its owner and admins can see it, as with a disabled listing.
- The photo delete links on the listing edit form printed the `secret` from the URL unescaped, so a crafted link could run script; they now use the listing's stored secret, escaped.
- The contact and send-to-friend pages worked for listings that are not validated, disabled or spam; the public now gets a not-found page there too.

### Fixed

- `doctor`, `jobs:status` and System info no longer report a job held for later as a stuck queue; they count only jobs that are due.
- Deleting your own comment on a listing no longer also tries to post a new comment.
- A member deleting their own comment now fires the `delete_comment` hook, as an admin delete does.
- Photo delete links built with `osc_item_resource_delete_url()` now reach the delete action.

## Shopclass 6.4.1

This release fixes two security issues: custom-field values that could run script on a listing
page, and an e-mail change link that also worked as a password-reset link. It also fixes a false
mod_ssl warning on the mail settings page.

### New

- A nightly `:edge` Docker image built from develop, versioned with its build time; the in-app updater is off on it.

### Security

- An e-mail change link also worked as a password-reset link. Each account code is now bound to its purpose and stored hashed; links sent before the upgrade stop working.
- A listing could store custom-field values meant for another category, and a URL field could break out of its link and run script; such values are now dropped, and URL, dropdown and radio values are escaped.

### Fixed

- Mail settings no longer warn that Apache `mod_ssl` is missing; they check PHP's `openssl` extension instead (#548).

## Shopclass 6.4.0

This release makes your site safer to run, easier to keep up to date, and faster.

Admins can now add a second sign-in step: a code from an authenticator app on their phone.
Shopclass can install security releases by itself when you switch it on, and e-mails you how it
went. You choose the update channel: stable releases only, or release candidates and betas too.
Saved search alerts no longer hold database code, and the upgrade converts the ones you have.

Buyers get a receipt by e-mail when they pay, and can print it from their orders page. When the
payment plugin supports it, a paid order can be refunded from its order screen and links to the
payment in the provider's dashboard. The new Stripe Payment plugin in the market does both.

Core now draws every account page, the public profile and the contact page, with hooks a theme
can add to; the bundled Storefront 2.0 theme is built on them. E-mails go out in one tidy layout
a theme can restyle. Photos can be saved as WebP, large phone photos shrink before upload, and
iPhone HEIC photos are accepted.

Pages load faster: a cached page asks the database far less, and listing pages read every
card's badges in one query. Slow work such as cleanup runs in a background job queue that
plugins can use too. A new server can get a site with one command, with free HTTPS.

**Tools → Backup and restore** backs up and restores your site, and **Tools → System info**
checks your database and repairs it. The database is tidier: related rows are linked, and
usernames are unique.

jQuery and other unused libraries no longer ship; a theme or plugin that needs jQuery must bring
its own. Theme and plugin authors should read the Breaking section before upgrading.

### New

- Admins can turn on two-step sign-in with a code from an authenticator app, with one-time backup codes to copy or download. `oc-cli.php user:2fa-off` turns it off for an admin who is locked out.
- An update channel (stable, release candidates or betas) replaces the prerelease switch, and security releases can install themselves, with an e-mail to the admin. Off by default.
- The core updater checks each download against GitHub's checksum.
- Buyers get a receipt by e-mail when an order is paid, and can print it from their orders page (**Settings → Billing → Receipts**).
- A paid order has a **Refund** button when its payment plugin can refund (`RefundableGateway`). Test Payments supports it.
- `Orders::attachRef()` lets a payment plugin store its checkout id on a pending order, and `DashboardLinkGateway` adds a link to the payment in the provider's dashboard.
- Every HTML e-mail goes out in one layout with a header, the message in a card and a footer. A theme replaces it with `templates/email-layout.php`; plugins use the `mail_layout_vars` and `mail_layout` filters.
- **Tools → Backup and restore** backs up the database, the files or both as one zip, in the background, downloaded or saved on the server.
- Backups can go to a private **Backups bucket** on S3 (Settings → Storage), and download, restore and **Backups kept** work there too. Bucket backups need `WEB_PATH` set.
- A restore runs in the background behind the maintenance page, saves a safety copy first, and puts it back if loading fails. It can restore only the database or only the files.
- `oc-cli.php backup:create`, `backup:list`, `backup:restore` and `backup:delete` do the same from a shell, with no time limit and restore even when web restore is off.
- System info reminds you to make a backup when none was saved on the server in the last 30 days.
- **Tools → System info → Database** lists where the database differs from what Shopclass expects, and **Repair** fixes what it can. `oc-cli.php db:doctor` and `db:repair` do the same.
- System info → Database and `oc-cli.php db:doctor --strict` show whether a site is ready for strict SQL mode, and list columns holding zero dates. Writes strict mode refuses are logged by table and column.
- A shared background job queue for plugins: `osc_job_enqueue()` queues work, `osc_job_register_handler()` runs it, and cron does the rest. See [Background jobs](https://shopclass.org/docs/developers/jobs/).
- Jobs can carry a `unique_key` that folds repeated work into one waiting job. New `osc_job_enqueue_many()`, `osc_job_ensure()`, `osc_job_stats()` and `osc_job_describe()`.
- **System info → Jobs** shows what is waiting, running and gave up, with recent activity from the activity log.
- `php oc-cli.php jobs:work` drains the queue on its own schedule, and `jobs:status` reports it per type (`--type=`). `storage:work` still works as an alias.
- A `job_gave_up` hook fires when a background job stops retrying, and `oc-cli.php doctor` warns about failed or long-waiting jobs.
- `osc_admin_when()`, `osc_admin_duration()` and `osc_cron_last_run()` format times for admin screens.
- `install.sh`, attached to each release, sets up a Docker site in one command, with free HTTPS and a www redirect when given a domain.
- The Docker image serves HTTPS itself when `OSC_TLS_DOMAIN` is set: it gets and renews a Let's Encrypt certificate and redirects `OSC_TLS_REDIRECT_FROM` names. The image is on Docker Hub as `mindstellar/shopclass`.
- **Media → Settings → Photo format** replaces Force JPEG: keep the original format (default), save as JPEG, or save as WebP (about a third smaller). `webp` is now an allowed extension by default.
- Photos larger than the normal size shrink in the browser before upload, so large phone photos no longer fail the size limit. **Media → Settings → Browser resize** turns it off.
- The listing form takes HEIC photos while Browser resize is on: the browser turns them into JPEG before upload.
- A guest's message is sent only after they confirm their e-mail address; that browser is then trusted for 30 days.
- Messages allow 1 link and 5000 characters by default (**Settings → Spam and bots → Messages**). Each one carries a *Report the sender* link: an admin can ban the address, a member can block the sender for 30 days.
- **Sign-in protection** lists the addresses and accounts with recent failed sign-ins, marks the blocked ones and unblocks one at a time.
- **Your listings** has status tabs, the listing limit, each listing's views, a Delete link that asks first, and the paid upgrades the seller can buy. New helper `osc_item_upgrade_offers()`.
- New hooks `account_page_before` and `account_page_after` on every account page, including credits, and filters `listing_row_badges`, `listing_row_meta`, `listing_row_actions` and `listing_list_html` for listing lists. A `listing_row_actions` entry can be a POST button with the CSRF token.
- The public profile shows the member's picture, place, a Business badge, an Edit link on your own profile, and a Message button with the captcha. New hooks `user_contact_form` and `user_contact_form_after`.
- **Your profile** is grouped into Photo, Your details, Contact, Location and About you, with the account type, the neighbourhood, an About field per language and a Download your data link. New hook `user_avatar_form` and classes `.oe-group`, `.oe-grid`, `.oe-avatar-field` and `.oe-avatar-empty`.
- **Alerts** lists each saved search's keywords, category, place, price and filters, and asks before it stops one. New helpers `osc_alert_criteria()` and `osc_alert_summary()`, and filter `alert_row_actions`.
- New hooks `user_login_form`, `user_login_form_after` and `user_register_form_after` for extra fields and social sign-in buttons. The sign-in details username field carries `data-username-check`.
- The contact page has new hooks `contact_form_top`, `contact_form_after` and `contact_page_aside`, and marks name and subject optional. Core's save-this-search form carries `data-osc-alert-form`.
- The credits page shows listings used against the limit and links to your orders.
- The dashboard's greeting, buttons, heading and See all line carry classes a theme can hide.
- A plugin's account page takes its heading from the title its route was registered with.
- `osc_item_adjacent_url()` and `osc_item_adjacent_id()` give themes the next and previous live listing in one query.
- `osc_resource_alt()` gives a theme alt text for a listing photo, filterable as `resource_alt`.
- Links a seller writes in a listing description carry `rel="nofollow"`; links into your own site are left alone.
- Paginated search and category pages declare `rel="prev"` and `rel="next"`.
- Public profiles and the contact page have a meta description.
- Every HTML page sends a `Server-Timing` header with how long it took to build.
- The installer can remove an install that did not finish and start again.
- Tools → Cleanup can remove the profile pictures of deleted accounts.
- Installed plugins and themes have a **Details** link, and a package not in the catalog shows its own README and screenshots. Plugin cards show the icon beside the name, theme cards the screenshot across the top.
- Appearance warns when a child theme and its parent would both declare the same function.
- New developer docs pages: Admin editors, Child themes, and Hooks and filters (every hook and filter core fires).
- The query builder takes table aliases (`'t_item AS i'`) and gains `selectRaw()`, `whereNull()`, `whereNotNull()`, `orWhereNull()` and `orWhereNotNull()`.
- `ItemActions::prepareDataFrom()` builds a listing from plain data, including custom fields from `meta` and the owner from `ownerId`. An admin-mode edit from it needs no secret.
- `ItemActions::asImport()` skips the posting wait and e-mails, but listing limits and moderation still apply.
- `Params::getParamBool()`, `getParamEmail()` and `getParamEnum()` read a typed request value and never return an array. `Params::withRequest()` runs code against given values in place of the request.
- `mindstellar\security\RateLimit` limits requests per key, and `mindstellar\security\AddressGuard` checks an address is public before the server fetches it.
- `osc_core_url()` builds any core page's URL from the same table the rewrite rules come from, so a link and its rule cannot drift apart.
- `osc_admin_category_picker()`, `osc_admin_location_picker()`, `osc_admin_user_picker()` and `osc_admin_photo_grid()` are the parts of an entity editor, for plugins as well as core.
- `osc_admin_field()` takes `'type' => 'richtext'`, a `'translate_name'` pattern and an `'error'` slot per field. Declared settings pages can carry a rich-text field too.
- `osc_admin_plugin_page()` gives a plugin's admin screen the title, **?** help and icon actions core screens have.
- A plugin can add its own `oc-cli.php` commands with the `cli_commands` filter.
- `php oc-cli.php` takes the site address from a new `OSC_CLI_URL`, and says so when it has neither that nor `WEB_PATH`.
- The `alert_search_params` filter lets a plugin save its own search values with an alert, and `search_conditions` also gets the search and a context (`'request'` or `'alert'`).
- A declared form's `persist` callable can return `FormSpec::WRITE_NULL` to store NULL.
- `LocationImporter::normalizeKey()` is public, so a plugin can match place names the same way.
- `osc-table-stack` gives a plugin's admin table the phone card layout core lists use, and `field-group` joins controls that read as one field.

### Breaking

- jQuery, jQuery UI and jQuery Validate no longer ship, and the `jquery`, `jquery-ui` and `jquery-validate` script ids are gone. A theme or plugin that uses them must ship and register its own copy.
- The `phpseclib` and `mcrypt_compat` libraries are gone. A plugin that still calls `mcrypt_*()` needs its own copy.
- `t_storage_queue` is now `t_job_queue`, and job types are namespaced (`offload` became `storage.offload`). Queued jobs carry across, and `StorageQueue` and `StorageWorker` still work.
- `t_alerts`, `t_item_description`, `t_meta_fields` and `t_form_submission` have foreign keys: a row whose parent is missing is refused. Guest alerts store `fk_i_user_id` as `NULL`, not `0`.
- `t_user.s_username` is unique (`uk_user_username` replaces `idx_s_username`), and usernames made only of digits are refused. The upgrade fills empty usernames with the user id and renames duplicates to `<name>_<id>`.
- `Search::toJson(true)` returns the same JSON as `toJson()`. `Search::setJsonAlert()` with an old-format alert applies only its categories, price, pattern, picture and premium flags.
- The `send_friend_throttle_max`, `item_contact_throttle_max` and matching `_window` filters are replaced by one `action_throttle_limit` filter, which gets `array('max', 'window')` and the form name.
- Route values captured on friendly URLs arrive decoded, as query values do; a plugin that decoded them itself must stop.
- The `delete_user` hook no longer removes avatars; deleting the user does, after the delete succeeds.
- Removed the old Locations screen's admin CSS: `.locations`, `#l_countries`, `#i_regions`, `#i_cities`.

### Security

- Search alerts store the search's values, not SQL, and run through the search page's builder, with categories, sort and paging held to their own shape.
- The upgrade converts saved alerts and discards their stored SQL, so back up `t_alerts` first if you may need it. An alert holding anything core did not write is paused and listed under **Users → Alerts**.
- Search-alert tokens from before 6.2.0 are refused.
- A search's `sLocale` value reached SQL unescaped, open to anonymous visitors. Only escaped locale codes are accepted now.
- The `?theme=` preview could load `functions.php` from outside the themes folder. It must now name an installed theme, and a theme path cannot leave `oc-content/themes/`.
- A listing could attach, and then delete, a photo it did not upload. An `ajax_photos[]` name must now be a file staged under the form's own upload token.
- Deleting a theme whose folder is a symlink deleted what the link pointed at, not the link. This is fixed for every delete core does.
- Deleting a theme another theme extends is refused, and so is a theme name that is not installed. **Appearance** activates only an installed theme.
- A theme or plugin name made only of dots, such as `..`, is refused.
- Deleting your own listing needs a CSRF token; `osc_item_delete_url()` adds it, and e-mailed delete links keep working.
- The public profile's contact form checks the CSRF token, the sender's fields and the member's status, and limits messages per visitor.
- The email template test send needs a CSRF token.
- Text typed into the contact, contact-the-seller, contact-a-user and share forms is sent as text, never HTML. The site contact form has an hourly limit, and share checks the ban list.
- A listing contact attachment is sent from PHP's upload folder, not copied into `oc-content/uploads/`. Both contact forms refuse script files.
- Search alert emails escape listing titles and the subscriber's name and address. The `alert_email_*_description_after` filters now get that escaped text.
- "Keep the photo at its full size" stored the upload byte for byte, so anything appended survived on disk. The full-size copy is re-encoded now, which also drops camera metadata.
- The upgrade no longer starts from a link; it asks first.
- Backups are kept out of the site folder under names no one can guess, and the page warns if their folder is open to the web. Each site in a shared bucket gets its own folder.
- Restoring a backup from the admin asks for your password again, and your 2FA code when 2FA is on. `OSC_DISABLE_WEB_RESTORE` turns web restore off.
- The link that confirms an e-mail address change works once, expires after 24 hours, and can no longer be used as a password-reset code.
- Contact-form and listing-post events no longer count toward the sign-in limit, and signing in no longer resets those limits.
- Deleting an account also deletes the messages it sent through custom forms.
- Ajax replies on the public side are labelled as JSON instead of `text/html`.
- `t_user.s_email` is NOT NULL on old installs too, so its unique index holds.
- Tools → System info warns when visitor addresses look wrong because the site sits behind a proxy that does not pass on the real IP.

### Performance

- With memcached or APCu, a cached page runs 4 to 9 database queries instead of 11 to 16: languages, widgets, form groups, currencies and footer pages are cached.
- Listing pages load urgent, highlighted and bump state for all their cards in one query, also when billing is off.
- Category searches and their page counts use a new index: on a 230,000-listing site the count drops from about 38 ms to 6 ms.
- New indexes for the admin log, alerts, latest searches, the user list and the expiry reminders, and admin lists no longer use `SQL_CALC_FOUND_ROWS`.
- The unpacked release is about 4.7 MB smaller, and each page loads two fewer libraries.
- A new database connection needs 2 round trips after the login instead of 5, which matters most when the database is on another server.
- A request value with no `<`, `>` or `&` skips HTMLPurifier, and HTMLPurifier caches its rules in signed files under `oc-content/uploads/`.
- Dates in listing loops translate month and day names once per request.
- Dropped `idx_s_content_type` on listing photos, which no query could use.

### Changed

- **Tools → System info** has Overview, Database, Server, Jobs, Security and Cache tabs, and Tools opens on it. Background jobs, Cache, the blocked sign-in list and database check and repair moved there; old links still work.
- Backup data and Import data left the Tools menu; their old links still work.
- **Upgrade Shopclass**, **Backup and restore** and **System info** share one look: one box that says what needs doing.
- Upgrades run the database migrations only, so the "some queries failed" screen is gone.
- Tools → Cleanup runs in the background until nothing matches, so a large backlog no longer times out. Its Reported listings rule has an age (30 days until you set it).
- Featured listings rotate every 5 minutes instead of on every page view, so pages that show them can be cached.
- Free bumps pause while a seller has more live listings than their limit; paid bumps still work.
- **Media → Force aspect** is now **Photo shape → Keep each photo's own shape**, on for new sites.
- The Docker image includes ImageMagick, and new installs use it where it is loaded, so wide-colour and CMYK photos keep their colours. **JPEG quality** is now **Photo quality**, since it sets WebP quality too.
- The release no longer ships the Sample Forms, Sample Widgets and Test Payments plugins. They install from the market; sites that have them keep them.
- Releases ship as `shopclass_v*.zip`. `osclass_v*.zip` still ships for sites updating from older versions.
- HTMLPurifier 4.19.1 and TinyMCE 8.9.2.
- The installer checks for MySQL 5.7.5+ or MariaDB 10.2+.
- Pending e-mail address changes expire after 7 days.
- A theme that declares its chrome with `'account' => true` gets core's credits pages inside it, ahead of its `user-custom.php`.
- The credits page hides Buy when there is no package or payment method (`osc_billing_can_buy()`), and billing tables stack on a phone.
- Each page of search results points its canonical link at itself, not at page 1.
- The listing and page editors have a Status panel in their rail. Blocking a listing and marking it as spam ask first.
- The listing's category is picked from one searchable list showing the whole path, and its seller is linked to an account.
- Both editors share one locale tab strip, one rich-text setup and a save bar that counts unsaved changes. A rejected save keeps what was typed, with the message under its field.
- The email template editor matches the page editor: placeholders, including the ones every email gets, insert with a click, and a test sends from the rail.
- The rich-text editor uses the admin's own colours and follows the light/dark toggle.
- Language tabs open on your own admin language and mark it with a dot.
- The admin is set at 14px instead of 16px, so more fits on screen; it still follows a larger browser font size.
- Every list screen has the same filter bar, with bulk actions beside it, and rows read in three text sizes.
- Comments can be searched by author, address or text, and "Hidden comments" is a filter.
- Both filter panels pick a country from a list and suggest the region and city inside it.
- Recalculate location stats and category stats left the Statistics menu. They are buttons on Listings → Locations → Data and Listings → Categories; old URLs still work.
- A category's own fields sit on a responsive grid.
- On a phone both editors give small controls a 24px tap target and show photos two across.
- Hook priority accepts any whole number, negative included, instead of only 0 to 10. A plugin registered outside that range now runs.
- `printMultiLangTitleDesc()`, `ItemForm::category_multiple_selects()` in the admin and `ItemForm::photos_javascript()` are deprecated. They keep working.

### Fixed

- Sessions no longer reset on every page on hosts with long session ids, which logged users out and broke forms (thanks @tonybyng).
- Hourly, daily and weekly cron jobs no longer run twice when two requests start them at once, so alert e-mails are not sent twice.
- When memcached is down or frozen, pages keep working from the database.
- Two upgrades started at once no longer run side by side.
- Category counts stay right when premium changes or ends, and when a category moves to a new parent.
- Deleting a category with thousands of listings no longer times out; a large one is hidden at once and emptied in the background.
- A listing title longer than 100 characters no longer leaves a listing untitled under strict SQL mode. Titles are capped at 100.
- The activity log no longer loses an entry under strict SQL mode when its text is too long.
- Two sign-ups at the same moment can no longer end with the same username.
- `t_cron` and `t_plugin_category` get a primary key, so duplicate rows cannot appear, and a missing cron row is restored.
- `t_city_area.pk_i_id` is AUTO_INCREMENT, so a city area can be added without choosing its id.
- The daily sweep of orphaned uploads works through all of them, not only the first 500.
- Every email carries a plain-text copy alongside the HTML, so it reads in any mail app and is less likely to be marked as spam.
- Search alert emails greet registered subscribers by name, not e-mail address.
- The listing-expiry warning email closes its last paragraph.
- With a captcha on, the contact, contact-seller and send-to-friend forms show it, so they can be sent.
- The contact page refuses an empty message, and a failed send on any contact form shows the reason with what was typed.
- The site contact form's attachment works when enabled.
- The seller contact form's "Invalid email address" message can be translated again.
- The save-this-search form on core's search page saves the alert without JavaScript.
- Guest alert sign-ups are limited to 10 an hour per address and refuse banned emails.
- The admin alerts list shows the whole saved search.
- **Your listings** shows blocked, spam, expired and pending listings with their real status, in themes with their own list too.
- Saving the profile form no longer resets the account type, neighbourhood or map position, and no longer loads every city when no region is set.
- Editing "About you" checks its size, as registration does.
- The account menu marks Credits as the current page on the credits page.
- The sign-in form no longer fires `user_form`; the profile form fires it again, as older themes did.
- A page that shows a flash message or a refilled form is no longer publicly cacheable.
- A search for a category that does not exist answers 404 instead of listing every ad.
- Odd search input no longer breaks the search page: list values where one is expected, numbers like `1e20`, or a custom-field value sent as a list.
- The category recount no longer builds invalid SQL on a site with no categories.
- Paging a category works when its permalink has two keywords, such as `{CATEGORY_NAME}-c{CATEGORY_ID}`.
- An empty listing, page or category permalink setting no longer answers the front page with the wrong screen.
- "My listings" keeps the type filter when friendly URLs are off.
- Page titles no longer carry a double space when a part such as the city is empty.
- Count helpers such as `osc_count_comments()` return 0, not -1, when nothing was loaded.
- A photo whose files cannot be saved shows an error instead of a broken image.
- A photo over 50 megapixels is refused before it is opened. The `image_max_pixels` filter changes the limit.
- The installer works on a port other than 80 or 443.
- The installer stops before creating tables in a database that already holds a Shopclass site, and accepts only the existing database settings on a configured site.
- While the database is down, the installer shows a notice instead of the install form.
- **Test connection** catches a user that cannot write, a bad table prefix and an unknown host.
- `oc-cli.php install` writes the `--web-url` address to `config.php`. The installer escapes every value it writes there, and writes the file as 0644.
- A child theme now wins over its parent on anything both declare with `osc_add_theme_support()`.
- A child theme's own stylesheets and scripts no longer resolve to its parent's folder.
- Appearance names the theme a child extends, and warns when that parent is missing or about to be deleted.
- A theme in a folder with a dot in its name, such as `my.theme`, shows under Appearance.
- A plugin symlinked into `oc-content/plugins/` gets its own hook names, so install and uninstall run.
- The plugin and theme catalogue refreshes once a day again.
- The toolbar's update counts match the Updates tabs, and a failed update says the previous version was put back.
- Package READMEs show lists, code, links, rules and images correctly in the details dialog.
- Upgrade release notes show as real paragraphs and lists with working links.
- The admin user search filters by name, e-mail and partial username.
- On the user editor, choosing a country reloads the regions.
- A refused page save no longer stores part of the page, and a title or internal name of only spaces is refused.
- Saving an email template with a taken internal name no longer saves half of it.
- Settings screens show the unsaved-changes save bar.
- The Show e-mail box on the listing editor keeps its state.
- Admin list screens no longer break on a bad `iDisplayLength` or `iPage` value.
- Bulk actions on listings report the right count, and the comments screen says how many changed.
- On **Media**, a photo's listing link opens the listing editor.
- A widget select whose options come from a function shows them.
- Moving a listing to no country no longer prints a failed database query.
- `php oc-cli.php storage:work` reports failed jobs.
- No more deprecation notices on PHP 8.5 from image resizing, downloads and contact-form attachments.
- The page editor's rich-text body is readable in dark mode.
- Suggestions under a field inside a dialog are no longer drawn behind it.
- The admin sidebar no longer jumps when a group opens or closes, and a refused save keeps its highlight.
- Submenu dots no longer overlap their labels in right-to-left languages.
- The current-theme card fits on a phone.
- Pagination puts its navigation landmark on a `<nav>` around the list.

## Shopclass 6.3.0

This release is mostly about making themes easier to build and the admin easier to live in.

A theme can now tell core about itself — where its header and footer live, which views and
widget zones it owns, and whether core may write the document head. Core's own pages, like
account deletion and the credits screens, then render inside your theme instead of on a page of
their own, and you can add a view without patching core. If your theme says nothing, nothing
changes for it.

Core will also draw all 13 account and sign-in pages for you when your theme does not ship them,
using a documented set of CSS classes. That means you can restyle them without writing any PHP.

In the admin, Listings → Locations has been rebuilt, so it now handles a country with 90,000
towns as comfortably as one with 50. Plugins and Appearance share one look, and each package
tells you plainly whether it runs on your version instead of leaving you to compare numbers.
There is also a bundled Test Payments gateway for trying out credits without moving real money,
maintenance mode can show a banner instead of closing the site, and a settings page can now take
an image, such as a theme logo.

### Security

- Deleting a user's alert in the admin had no CSRF check. It is a POST with a token now.
- Importing a language from the translation repository had no CSRF check either.
- `?page=route` ran any hook named in the request, so anyone could fire `cron_hourly`. It now
  runs only registered route hooks, after `init`.
- **Deleting an account happened on a plain link.** `?page=user&action=delete&id=…&secret=…`
  removed the account as soon as the page was requested, so a mail scanner, a browser prefetch
  or a leaked referrer was enough to destroy it. That link now shows a confirm form, and the
  deletion needs a POST with a token and the current password. Old theme links land on the
  confirm page and delete nothing. Themes may ship `user-delete_account.php`; core falls back
  to its own view.
- A member's "About you" text was not escaped on the public profile page core falls back to, so
  anyone who could register could store a script that ran for every visitor to that profile. It
  is escaped now, as the bundled themes already did.

### New

- A bundled Test Payments plugin: a gateway that moves no money, for testing credits, upgrades
  and refunds. It is also the documented example of a declared settings page.
- A theme names its header and footer files with `osc_add_theme_support('chrome', …)`. Core
  still looks for `header.php`/`footer.php` and `common/header.php`/`common/footer.php` on its
  own, so existing themes need no change.
- Themes declare extra view names with `osc_add_theme_support('views', …)`, so core's list of
  names a static page may not take is no longer hardcoded.
- Every front-end page now looks for its view in a fixed order: `osc_locate_template()`, the
  `template_candidates` filter, then per-category `item-{id}.php` and `search-{category}.php`.
  A theme adds a view without a core patch.
- Themes name and describe their widget zones with
  `osc_add_theme_support('widget_locations', …)`; the admin screen shows the label and
  description instead of the raw slug. Themes that only carry a `Widgets:` line are unchanged.
- `osc_head()`, `osc_body_class()` and `osc_language_attributes()` let a theme hand the
  document head and the body classes to core, so a page core renders is described like any
  other. Each part of the head is opt-out.
- Email address, username and password are one **Sign-in details** page instead of three
  pages holding one field each. All three routes still resolve, and the one asked for focuses
  its own field. Deleting an account stays separate.
- Core also renders the plugin page mount, both contact forms, the share-a-listing form and the
  save-this-search field when the theme ships none. Every theme carried the same markup for
  these. The contact forms fire `contact_form` and `admin_contact_form`, so a plugin's extra
  field reaches all of them.
- A theme need only ship `item-post.php`: editing a listing falls back to it, since the two
  forms carry the same fields. A theme that ships `item-edit.php` still wins for that route.
- `ItemForm` now supplies what differs between publishing and editing —
  `route_hidden()`, `location_record()`, `selected_country()`, `selected_region()` and
  `plugin_item_fields()` — so a theme stops deriving the action, the hidden fields and the
  location defaults for itself. Both bundled themes had written the same branch.
- Core loads the photo uploader and the location combobox itself on the publish and edit
  routes, so a theme no longer enqueues them by hand above its form.

- Core wires the search bar's town/city field: an input carrying `data-ac="location_cities"`
  gets suggestions and resolves the chosen row's id, and `osc-ui-common` now loads on the
  home and search pages. Both bundled themes had written this binder themselves.
- `osc_show_item_comments()` renders a listing's comment thread and post form, so a theme
  gets the feature — on by default — without laying out a form whose field names are core's.
  It fires `item_comments_before`, `comment_form` and `item_comments_after`, and ships
  zero-specificity defaults a theme overrides with a single class.
- Settings pages are documented for plugin authors, with a worked example of both the array
  form and the builder — see `docs/site/developers/settings-pages.md`.
- `osc_register_settings_page()` declares a whole settings page — title, menu entry, fields —
  and core renders and saves it. The plugin writes no markup and no save handler.
  `osc_settings_value()` reads a field back.
- `osc_admin_form_open()`, `osc_admin_form_close()`, `osc_admin_form_section()` and
  `osc_admin_field_row()` let an admin screen declare its form instead of writing the markup.
- A declared settings field can follow one value of a select or radio: `depends_value`, or `dependsOn($master, $value)`.
- A declared settings page can take an image upload: `->image('logo', $label)`, read back with `osc_settings_image_url()`.
- `osc_reset_users()` rewinds the user loop, like `osc_reset_items()`.
- `osc_admin_field()` and its per-type shorthands (`osc_admin_text()`, `osc_admin_number()`,
  `osc_admin_select()`, `osc_admin_textarea()`, `osc_admin_radio_group()`, `osc_admin_secret()`)
  render an admin form field from core, so a plugin no longer writes markup against the admin
  theme's class names. Width follows the field type and every value is escaped. An admin theme's
  own copy still wins where it defines one.
- Core renders all 13 account and sign-in views — dashboard, listings, alerts, profile, the
  three settings pages, sign in, register, the two password-reset steps, the public profile and
  the plugin account slot — when the theme ships none. A theme that ships one still wins.
- A published set of `.oe-*` CSS classes for those pages, documented at
  `docs/site/developers/account-pages.md`, so a theme restyles them in CSS with no PHP.
- `osc_gui_account_view()` picks one account view, in this order: the theme's file, a parent
  theme's, core's page inside the theme's header and footer, then core's own bare page.
- Maintenance mode can keep the public site up and show an editable banner instead of a 503.

### Changed

- Every `UNIQUE KEY` in the schema is named. Databases upgraded from older versions carry up to
  eight copies of the same unique index on `t_admin` and `t_user`; the upgrade drops them.
- Plugins and Appearance share one look: installed packages are tiles with the artwork beside
  the name, a state badge, one primary action, and removal held apart from the routine ones.
- A package with no artwork of its own borrows the catalogue's icon before falling back to its initial.
- The compatibility badge now answers one question about your install: "Works with 6.3",
  "Tested up to 6.2", "Needs 6.4 or newer" or "No version declared".
- Hand-written admin settings screens are deprecated in the docs; declare a settings page instead.
- Every admin form screen is now drawn from the same core field parts, so widths, hints and
  spacing match across the panel. No class is renamed, so a plugin's styling still applies.
- Admin list tables show dates as `2026-09-14 06:14`, with the long date on hover. The
  `admin_date_format` filter changes the format.
- **New installs keep the database server's strict SQL modes; upgrades opt in.** Core used to
  switch those modes off, so a value too long for its column was quietly cut short instead of
  refused. A fresh install now writes `define('OSC_DB_STRICT_MODE', true);` into `config.php`
  and keeps them on.

  Upgrades keep the old behaviour, because a third-party plugin that has been truncating a
  value for years would start failing mid-request. To opt an existing site in, add that same
  line to `config.php`, or set `OSC_DB_STRICT_MODE=1` in the environment when there is no
  `config.php` (a container). Remove it to go back. Try it on a copy first if the site runs
  third-party plugins that write to the database.
- Registration and the profile form now name the field that is too long instead of cutting the
  value short. The limits match the database: 100 characters for a name, username, e-mail,
  website, region, city or address, 45 for a phone number, 80 for a country, 15 for a postcode.
  Publishing gained the postcode and phone checks it was missing.
- The admin account screen reports every error at once and redraws what was typed, instead of
  discarding the form on the first failure. An unchanged save now confirms, and a refused write
  reports rather than staying silent.
- `admin_edit_completed` receives the admin id as an int, where it received the raw request
  string. A listener comparing it with `===` or `is_string()` sees a different value.
- Eight settings screens — General, Billing, Comments, Mail server, Spam and bots, Keyword
  blocklist, Latest searches and Advanced — now save through a declaration. Each reports every
  error at once and keeps what you typed. A refused save now writes nothing; some of them used
  to store the first few fields before giving up.
- Preference rows written by those screens record `e_type` from the field type rather than
  always `STRING`; a checkbox that is off stores `0` where a few of them stored an empty
  string. Nothing in core reads either.
- Permalinks saves through a declaration too; writing `.htaccess` and rebuilding the rewrite
  cache run only after a successful save.
- Sitemap saves through a declaration too; a robots.txt that cannot be written comes back
  with what was typed instead of being discarded.
- Media saves through a declaration too; a watermark that is not a PNG now refuses the whole
  save instead of saving everything else beside an error.
- Storage saves through a declaration too; a secret key containing `&` or `<` is now stored as
  typed instead of being mangled.
- The friendly-URL structure boxes hide and show through the shared conditional-field
  attribute instead of a script of their own.
- A declared settings page's action row follows the page as you scroll and counts what has
  changed since it loaded, staying quiet until something has.
- A preference-backed page writes only the values that actually differ, so a save that
  changes nothing says so instead of reporting success.
- Locations shows one level at a time, 50 rows a page, with a path, listing counts and status; its page-level global JS functions are removed.
- Locations adds search (this level or everywhere) with an A–Z strip, edits in a side drawer, and asks for the name (or, for a bulk delete, the listing count) before a delete that removes listings.
- Locations gains a Data tab to install, preview and update catalog countries and recalculate listing counts; the add form offers to import a catalog country instead.

### Fixed

- The "Delete my account" button looked like an ordinary button instead of a dangerous one,
  because the theme's own styling overrode core's.
- A numeric custom field lost what was typed into it on the search results page, while every
  other field kept it. Reported by @tonybyng (#535).
- The "Add category" button pointed at nothing: the header was registered before the URL it uses
  was built. Reported by @tonybyng (#534).
- Every admin confirm dialog failed with "Probable invalid request": deleting a listing, a user,
  an alert, a ban rule, an admin, a language, a comment, a page, a widget, a blocked keyword, a
  currency, a media file, a plugin or a theme. They post now.
- The admin "update available" badge stayed after a plugin, theme or core update.
- Plugins and themes hosted in the registry showed a placeholder instead of their icon.
- The language "Update" button did nothing when the server could not reach the translation repository; it says so now.
- A plugin left active but not installed after a failed install can be installed again.
- The username check and change-username form no longer strip dots that registration keeps.
- The username blacklist catches dotted and underscored look-alikes such as `ad.min`.
- An empty username blacklist, or one with a trailing comma, no longer refuses every username.
- Photo uploads match the file extension exactly; `photo.pn` or a name with no extension is refused.
- A real photo is no longer refused when the browser sends a generic file type for it.
- CSRF tokens are no longer injected into JSON replies that do not declare a content type.
- Users → Add failed with an invalid-email error: the form had lost its E-mail field.
- Order lists show the payment gateway's name instead of its id.
- The admin account form's e-mail box refuses an invalid address instead of silently rewriting
  it — `john doe@example.test` was stored as `johndoe@example.test` and reported as saved.
- Admin and mail-server passwords are stored exactly as typed; one with a leading or trailing
  space was trimmed on save while the sign-in form reads it raw.
- The Mail settings screen no longer dies on a server that is not mod_php: it called
  `apache_mod_loaded()` to check for `mod_ssl` without asking whether the function exists.
- A blank "custom" retention on Latest searches no longer saves the switch beside it before
  refusing the form.
- The `.htaccess` body Permalinks shows for copy-paste was missing the `mod_mime` block the
  same screen writes and compares against, so pasting it produced a warning that could not be
  cleared.
- `osc_admin_text()` honours an explicitly passed `email`, `url` or `tel` type instead of
  forcing every box to `text`.
- Municipality was capped at 50 characters against a column holding 200.
- The contact phone on a published listing is format-checked again; the check read a key the
  save path never set, so any number of digits was accepted.
- `t_user.s_country` and `t_item_location.s_country` are widened from `VARCHAR(40)` to
  `VARCHAR(80)`, the width of the `t_country.s_name` they copy — a country name longer than 40
  characters was stored cut in half. Upgrading runs an `ALTER TABLE` on both.
- Publishing refused any region or city name over 50 characters, though the columns hold 100
  and the location catalog offers names up to 60. The caps are the columns' widths now.
- Registration could report success while creating no account: a value the column could not
  hold left the visitor told to check their inbox for an account that does not exist. The
  failed write is now noticed, on the profile save and the listing's location write too.
- Several of core's queries failed silently on a database with `ONLY_FULL_GROUP_BY` on, and
  each one returned nothing instead of reporting it: saved-search alerts stopped being sent,
  a category's custom fields vanished from the listing form and from search, the latest-searches
  list emptied, the statistics charts drew blank and a theme's search footer links disappeared.
  They run under the strict modes now, on MariaDB as well as MySQL.
- The daily statistics counted by day-of-month, so over a range longer than a month the 5th of
  January and the 5th of February landed on one point. No core screen changes, since every core
  screen asks for eleven days. A theme or plugin calling `new_users_count()`, `new_items_count()`,
  `new_comments_count()` or `new_alerts_count()` over a longer range now gets one point per day.
- "Save the latest user searches" could not be switched on: the controller compared the
  submitted value against `on`, the value a browser invents for a checkbox that declares none.
- Mail settings offered Encryption as a free-text box whose help said "blank, ssl or tls";
  it is those three options now.
- A number field inside a sentence — "Break comments into pages with __ comments per page" —
  filled the whole width and pushed the rest of the sentence onto its own line, on Comments and
  Listing settings. The words either side are now slots the field sits between, which also
  lets a translator move the field within the sentence.
- The date and time format columns were 150px wide, so every option's label wrapped beneath
  its own radio; a locale with longer month names wrapped harder. They size to their content
  now, and picking a format no longer runs through inline `onclick` handlers.
- Typing a custom date or time format without first selecting its radio silently discarded the
  value on save.
- The moderation-count field on Comments settings never hid itself when moderation was off —
  the class its script looks for was not in the markup.
- CSRF tokens were added to GET forms, so a search carried the token in its URL. The token is
  unique per visitor, so every search got its own canonical URL and its own cache entry, and the
  token leaked into shared links and referrer logs. GET forms are skipped now, and `nocsrf` is
  no longer needed on them.
- Form labels pointed at a field's name instead of its id. Clicking the label did nothing, and
  a screen reader read the field as unnamed. It affected every custom field on the listing form
  and on search.
- A radio custom field built each option's id by appending to the previous one, giving
  `colour1`, `colour12`, `colour123`. Its group label now names the list through
  `aria-labelledby`, and radio and checkbox inputs no longer emit `type` twice.
- `ItemForm::title_input()` and `description_textarea()` defaulted to the `en_US` locale;
  they now default to the visitor's. `ItemForm::locale_field_id()` returns the id these
  fields actually carry, so a theme can label them.
- Editing a listing written in another language saved the text under the language you were
  browsing in and left the original alone, so every edit added another copy. The form now saves
  under the language the text came from; `osc_item_content_locale()` reports which that is.
- The publish form filled the region and city selects from the first country in the list
  while the country select still read "Select a country", offering another country's
  places. Both now stay empty until a country is chosen.
- A theme that shipped no view for an account page rendered a blank document.
- The profile page never showed the picture you had already set — only a file field and,
  once one existed, a checkbox to remove the thing you could not see.
- Deleting an account was a nav entry alongside Alerts and Credits. It sits at the foot of
  the profile page now, past everything routine.
- The listings page printed a pager reading "1" when everything fitted on one page.
- The credits, buy and orders pages were titled "Site name - Site name", and the
  account-delete page carried only the site name. Any route without a title case of its own
  had the site name doubled.
- `?page=register` with no action answered 200 with an empty body.
- A long word in a heading — a search term, a listing title — pushed the page wider than a
  phone screen instead of wrapping.
- The credits pages ignored a theme's own button and panel styling, because their markup
  carried only the older `oe-bill-*` class names. Each element now also carries the published
  name, so restyling the documented classes reaches them.
- The credits and orders ledgers scrolled the whole page sideways on a phone instead of
  scrolling the table.
- Account deletion and the three credits pages rendered without the account sidebar, so moving
  between them and the settings pages gained and lost a nav. They use the account layout now,
  and the nav marks which one you are on.
- A static page whose slug matched a name core newly reserved could not be edited at all —
  not its title, not its body — because the reserved set was checked even when the name was
  not changing.
- A theme in a directory with a hyphen in its name, which is how most are distributed, did
  not appear in the appearance screen or the CLI at all.
- Flash messages carry `role="status"`, or `role="alert"` on an error, so a screen reader is
  told they appeared. The dismiss control is keyboard-operable without a theme script, and two
  queued messages no longer share one `id`.
- The profile form's "About you" field is labelled with the id it actually renders, and its
  region and city lists follow the country without a page reload.
- Pages core renders lost the theme's header and footer on the default theme, which keeps them
  in `common/` rather than the theme root.
- The credits, buy and orders pages each carried their own copy of the same stylesheet, which had
  drifted: an unstyled `History` heading, links in the browser's default blue, unbranded radios.
- `npm run lint` now runs; added the missing ESLint flat config.
- `setJsMessage()` now shows errors and warnings in their own tint instead of the info one.
- The categories edit drawer no longer puts an invalid ARIA role on an `<aside>`.
- Untranslated strings under RTL no longer flip trailing punctuation to the front of the line.
- CSRF tokens are no longer added to forms inside JSON responses.
- A page with several identical form tags stamped each of them with a CSRF token per form, so a settings screen shipped five copies in every form.
- On a site with an object cache, a change to a user's account was ignored for up to a minute:
  a member who had just activated could not sign in, and a banned one kept working. Only a
  password change cleared the cached row; every write to that user does now.
- The location recount no longer fails on places deleted while it runs.
- The location recount keeps a batch queued when its write fails, and stops instead of polling forever.

## Shopclass 6.2.0

Back up your database before upgrading: this release rebuilds foreign keys across twenty-four
tables. It also lets sites sell credits and charge for listings, lets people download a copy of
their own data, closes three paths that could execute arbitrary files under the plugins
directory, and removes Google Analytics from the core. Search engines get more to work
with too: every listing index now has its own description and a single canonical.

### Security

- **A listing description was stored exactly as submitted on sites using the rich editor (CVE-2026-104479).**
  Params' XSS check strips every tag, so it is switched off wherever a rich editor is in
  use, and nothing replaced it — a script in a description ran for every visitor who opened
  the listing, the listing's author included. Descriptions are now sanitised against an
  allow-list of the markup the toolbars actually produce; scripts, iframes, event handlers
  and any URL scheme other than http/https/mailto do not survive it. Only sites that turned
  on "use TinyMCE on the frontend" were affected — it is off unless an admin enables it, and
  everywhere else every tag was already stripped. The public listing editor also loses its
  source-view button, which is not what made this exploitable but is no longer worth
  offering.
- **The plugin admin-page route would execute any file inside the plugins directory.**
  `?page=plugins&action=renderplugin&file=…` ends in `require_once`, and decided what to
  run by looking for the literal `../` — so anything else under the plugins tree was
  executed as PHP. One of its two checks also compared `strpos()` with `==`, so a path
  beginning `..\` (offset 0, which `== false`) passed the test meant to stop it. The path
  is now resolved before it is used, by the same guard the AJAX routes use.
- **The `custom` AJAX action would execute any file inside the plugins directory.** It
  guarded only against `../`, so any path that stayed inside the directory was included and
  run as PHP regardless of what it was — a README, a lockfile, a language catalogue, or
  anything a plugin had written there from a request. The front-end copy of the action needs
  no login at all. The target must now be a `.php` file, and the path is resolved before it is
  used so that symlinks and encodings cannot land outside the plugins directory. Plugins
  reached through `osc_ajax_plugin_url()` are unaffected: that helper has always named a
  `.php` file inside the plugins directory.
- **Search-alert tokens are now authenticated.** They were AES-256-CTR with no MAC, a
  malleable combination: anyone holding one valid token had a known plaintext, and could
  edit the ciphertext into a token for a search of their choosing. Tokens are now AES-256-GCM
  and a tampered one fails to decrypt rather than decrypting to something chosen. Tokens
  issued by an earlier release are still accepted, so an alert link in an already-rendered
  page keeps working.
- The plugin list read out of preferences is no longer unserialized with object support
  enabled, matching how the rewrite-rule cache already read its own.
- Removed a dead fallback in the alert cipher that used Rijndael with the initialisation
  vector set to the key. It could never run — the openssl extension it tested for is a hard
  requirement — but it was the only remaining use of `phpseclib` in the core.
- Dropped the `pensiero/php-openssl-cryptor` dependency. Nothing calls it now that alert
  tokens are encrypted directly, and it was an unmaintained wrapper around what the openssl
  extension already provides. `phpseclib` itself stays: the `mcrypt_*` functions it backs are
  still there for plugins written before PHP 7.2 removed them.

### Performance

- **Translations are no longer parsed out of their binary catalogue on every request.** Core,
  theme, and one catalogue per enabled plugin were each read and rebuilt into one object per
  translated string before any page logic ran. Each catalogue is now compiled once and read
  back in a single step, which measures around fourteen times quicker per catalogue on a
  1,400-string catalogue — and saves the megabyte or so of objects each one left resident,
  the scarcer of the two on shared hosting. A replaced language pack is picked up on its own;
  an install that cannot write to its uploads directory simply parses as before, as does one
  whose cached copy is unreadable.

### Fixed

- A dead SMTP host no longer holds a php-fpm worker for five minutes; `osc_sendMail()` caps
  connect and command timeouts at 15 seconds, and Mail Settings' **Send a test email**
  button is restored.
- A search carrying a category id rather than a slug returned 404, though the category
  existed. Both spellings now resolve.
- A static page built from blocks rendered with no `<head>` — no title, description or
  canonical. It now renders through the theme's page view.
- Price, photo, premium and custom-field filters each claimed their own canonical, so
  every permutation was a separate indexable URL. They now point at the page they
  filter.
- Static pages had no canonical, and the sign-in and registration forms were
  indexable. Both fixed; the contact page stays indexable and gains a canonical.
- Listing indexes in the same category all served the same meta description. They now
  lead with category, location and count, and city and region pages get one at all.
- Stripping HTML for a description glued words together across tags. Also affects
  static-page and listing excerpts.
- The category-city and category-region sitemaps listed a second, duplicate URL for every
  page. Clear the sitemap cache after upgrading (Settings -> Sitemap -> Regenerate).
- Activating, deactivating, installing, or uninstalling a plugin, or switching a theme,
  did not take effect until php-fpm restarted when `opcache.validate_timestamps` is off
  (the usual production setting) — the change appeared not to apply, or a stale class ran
  against new state and fataled, auto-deactivating the plugin. These operations now reset
  opcache so the next request recompiles.
- Logged-in visitors could be served a cached anonymous page by a reverse proxy or the
  nginx micro-cache. Identity lives as keys inside one cookie named `md5(WEB_PATH)`, which
  the cookie-name bypass could not match; the app now also sets a fixed-name `oc_cache_bypass`
  cookie in lockstep with login, and the caching contract matches that alongside the `osclass`
  session cookie and `oc_userLocale`. Nothing leaked (the response was already `private,
  no-store`), but logged-in users saw stale/anonymous pages.
- The chosen front-end language cookie now expires after 24 hours instead of a year, so a
  visitor who switches language is not kept out of the shared cache long after that visit.
- The web installer returned a 500 on a fresh install. The billing helper reads a
  preference as it loads, so requiring it during bootstrap asked for a database the
  installer had not configured yet. Unreleased; it never reached a published build.

- Sites are told when a newer translation is available again. The check returned false
  before doing anything and asked a market that no longer exists.

- Sample content is created again on a fresh install. Both installers were missing the
  billing helper the listing form calls, so seeding stopped with an undefined function
  and the install finished without it.

- Cloudflare Turnstile (and reCAPTCHA) tokens were passed through HTMLPurifier
  before siteverify. The token is opaque, not HTML; purifying it can empty or
  alter the value so every captcha check fails. The posted field is now read
  unpurified via Params::getParamString($name, false, false) on POST only.
  The previous empty-token guard used ORed inequalities and was always true.
- Two wallet writes in the same second no longer log a failed insert. The balance was always
  correct; the error was noise.

- **The package smoke test blamed every submission for preferences it had not created.**
  Rendering the search page mints a search-alert token, which writes
  `alert_private_key` and `alert_public_key`; those landed between the harness's before
  and after snapshots, so the diff attributed them to whatever package was being tested.
  Core's lazy writes now happen before the baseline is taken.
- **Plugin fields stopped appearing on the listing edit form.** `plugin_edit_item()` passes
  `edit&itemId=123`, from when the request was built by pasting that into a query string;
  the rewritten script sends it through `URLSearchParams`, which encodes the whole thing as
  one value. The hook therefore arrived as `item_edit&itemId=123`, matched nothing, and
  every plugin that renders on the edit form silently rendered nothing. A theme passing the
  same shape keeps working.

- **Deleting a custom field that had been submitted through a form failed.** The delete
  removed the field's values, its category assignments and its form memberships, then hit a
  foreign key on the submitted values it had not cleared and stopped — leaving the field in
  place but stripped of everything attached to it. The submitted values are now removed with
  it. This is the same fault that stopped categories being deleted when they had custom
  fields assigned.
- Every delete cascade now runs in a transaction, so a delete that cannot finish leaves the
  record exactly as it was instead of removing its children and failing on the parent.
- Deleting a form now removes the submissions made through it, deleting a listing removes its
  report and moderation history, and deleting a region or city removes its recorded slug
  history. None of these tables has a foreign key, so nothing was clearing them: the rows
  stayed behind, and an id later reused by a new record inherited them.
- A listing's counts are now decremented once it has actually been removed. A delete that
  failed still took the listing out of every category, location and user total.
- Deleting a form reported success when it had removed nothing.
- Running a database upgrade repeatedly no longer adds a duplicate copy of a foreign key each
  time. The schema reconciler compared keys including their `ON DELETE` clause, so a key whose
  rule had changed read as missing and was appended rather than replaced.
- A database upgrade no longer rewrites columns that had not changed. Where a table's
  definition padded between a column's name and its type to keep them aligned, the reconciler
  read the type as empty, decided it differed from the live one and issued a `CHANGE COLUMN`
  for it — rebuilding the table on every upgrade to arrive back where it started.
- Several schema changes made between 5.0 and 6.0 had never been written as migrations and
  reached an upgrading site only because the reconciler noticed they were missing: the
  storage-offload table and its column on listing images, right-to-left locale support, the
  numeric custom-field type, per-field settings, and the widening of the two user IP columns
  that truncated every IPv6 address. They are migrations now, so the upgrade no longer depends
  on the repair pass to arrive at a complete schema. Sites already on 5.2 or later are
  unaffected — every step checks first and does nothing where the change is already present.
- A listing URL that matches nothing returned 410 Gone, claiming a listing had existed there
  and was permanently deleted — for any id, including ones never issued. It now returns 404.
- Listings awaiting moderation or blocked, and unknown category/location/user subdomains,
  returned 400 Bad Request; they now return 404.
- Error pages now send a `Cache-Control` header, so a crawler walking dead URLs can be
  absorbed by a reverse proxy instead of costing a page render per hit.
- The account menu offered "Credits" and "Buy credits" whenever billing was on, so a site
  that enabled it only to cap listings sent every seller to an empty state. They now appear
  only where credits can be bought, or are already held.
- A seller at their listing limit only found out after writing the whole listing. Opening
  the post form now turns them back with the same message.
- Account-menu entries added by a plugin rendered below the log-out row, and pushed log out
  into the middle of the list. Log out is kept last.
- The installer downloaded storefront 1.0.1 when a fresh install had no bundled copy of the
  theme, two releases behind. It now fetches 1.2.0, from a single pinned version.
- **"My listings" and public seller profiles listed every premium listing on the site.** The
  not-expired filter contributed an ungrouped `OR`, so the query read `(mine AND live) OR (any
  premium listing)` — other sellers' rows appeared with the owner's own controls beside them,
  the counts and pager were inflated to match, and a premium listing that was admin-disabled or
  awaiting moderation could reach a public profile. The same clause also dropped the expiry and
  spam tests entirely on a site with no premium listings.
- The photo uploader told a seller the site-wide photo limit even when they held a raised
  one, capping them in the browser below what the upload actually accepts.
- Posting a comment refused any address whose top-level domain was longer than three
  characters — `.info`, `.online`, `.store`, `.agency` — reporting it as a missing email.
  The same form accepted a local part containing spaces. It now validates the way the
  contact form and registration always have.
- The `nospam` listing filter did not exclude spam. It asked for an option name the filter
  builder had no case for, so the one test it exists for was silently never applied.
- **Four of the five forms on Settings → Billing saved nothing.** Pricing (which owns the
  seller listing limit), offline payments, upgrades and seller limits posted actions the
  settings router had no case for, so each one landed on the General settings page and
  discarded the values with no error. Only the enable/disable toggle worked.

### Changed

- Micro-cache entries are kept for a day rather than dropped after a minute idle, and
  `docker-compose.prod.yml` turns the micro-cache on.
- CSRF tokens stamp their issue time on a half-hour bucket rather than the exact second.
  Two renders of a page are otherwise identical, so the second-level stamp was the only
  thing making them differ — which is what stopped a cache or a validator recognising them
  as the same page. How long a token is accepted is unchanged.
- The description editor on the post and edit listing pages refused to load, reporting that
  no TinyMCE license key had been provided. The front-end editor was the only one of the five
  that did not declare the bundled GPL build.
- Buying credits did nothing: the Continue button re-rendered the package picker and placed
  no order. A matched rewrite rule wrote its own params over the request, so once the buy
  page had a permalink the `action=checkout` the form posts arrived as the route's
  `action=buy`. A rule no longer overwrites a value the POST body supplies — a form's hidden
  fields are its intent, the URL only says where it was rendered — which fixes the same trap
  for any page that gains a permalink later. Unreleased; it never reached a published build.
- `osc_tinymce_config()` is the one place every rich-text editor is configured from, with a
  `basic` and a `full` preset. The settings each editor shared were copied into five call
  sites, which is how the front-end one was left without a licence key; a `tinymce_config`
  filter now also gives plugins their first way into these editors.
- The new billing permalinks could 404 for good after upgrading. The permalink table
  rebuilds when its stamped version stops matching the code's, which is true from the first
  request after new files land — before that release's migration has seeded the preferences
  the new routes are built from. A request that won that race compiled without them and
  stamped the new version anyway, so it never rebuilt again. The upgrade now recompiles the
  table once its migrations have run. Unreleased; it never reached a published build.
- Auto-cron runs its work in the same process on PHP-FPM, after the page has been sent,
  instead of asking the site for `?page=cron` over HTTP. That request only ever existed to
  get the work off the visitor's page load, and an origin behind a proxy cannot make it —
  it resolves its own public address to the proxy and never reaches itself, so nothing ran
  and nothing said so. Setups without FPM keep the old request. Nobody waits either way.
- The wallet, buy-credits and orders pages have permalinks (`user/credits`,
  `user/credits/buy`, `user/orders`) instead of query strings — the only account links that
  still had them. The old `?page=billing` form keeps resolving, so existing links and the
  gateway callback are unaffected.
- A release that ships no migration no longer sends the admin to the upgrade screen. The
  version is carried across on the next admin page load, with a notice saying so. Releases
  with migrations waiting still go to the screen, where the schema reconcile runs under
  supervision — an install carried across automatically has not been reconciled.
- `osc_get_locations_sql_url()` is deprecated in favour of the published location
  catalogue, and the unreachable installer function that was its only caller is gone.

- TinyMCE updated to 8.8.2. The editor now declares the GPL licence it is bundled
  under; version 8 refuses to start without one.

- The published Docker image runs PHP 8.5.

- PHPMailer updated to 7.1.1 and HTMLPurifier to 4.19.0.

- Translations now come from the Shopclass translations repository rather than the
  Osclass one. 32 languages carried over, re-merged against the current strings.

- A language catalogue that translates nothing is no longer compiled or shipped. The
  English ones were header-only files every lookup missed before falling back to the
  text it would have used anyway.

- Email templates ship as `mail.json` only; the parallel `mail.sql` copy is gone. Installing
  a language with no templates of its own now imports the bundled English set under that
  language instead of under `en_US`.
- Security policy now states supported versions, private reporting, response times and scope.
- Review routing added for security, database, controller, helper, schema, release and
  build-output paths.

- Foreign keys on dependent tables — descriptions, stats, slug history, custom-field values
  and link tables — now declare `ON DELETE CASCADE`, so the database removes them with their
  parent. Tables whose removal has side effects (listings, comments, uploaded files, the
  location hierarchy) deliberately keep the previous behaviour and are still removed by the
  code that performs those side effects. Existing installs are converted on upgrade.

### New

- The Docker image can now remove a cached page before it expires: it carries
  `ngx_cache_purge`, and `OSC_MICROCACHE` writes a purge endpoint reachable only from
  inside the container. That is what the nginx Cache plugin needs to hold public pages for
  an hour instead of core's thirty seconds.
- Public pages carry an `ETag`, so a returning visitor or a crawler gets a small "nothing
  changed" reply instead of the page again. They were already told to revalidate on every
  use but had nothing to revalidate against, so every check re-sent the whole page. nginx
  answers these from its own copy once it holds one.
- `response_body` filters the finished page, after CSRF tokens are injected and before
  anything reaches the client — one place for anything needing the whole body, instead of
  a second output buffer racing the first. Returning an empty string sends no body.
- `invalidate_item_cache` fires whenever a listing's rendered output goes stale — an edit,
  an image added or removed, the listing deleted, and a storage offload once it has
  rewritten the image URLs. That last case fired nothing at all before, so a proxy or CDN
  went on serving a page pointing at local files the offload had moved.
- `oc-cli.php storage:work` drains the storage-offload queue and nothing else, so it can be
  scheduled every minute. The queue was reachable only through the hourly cron tier, which
  runs a whole schedule block besides — on a busy site the backlog never cleared.
- `billing_listing_limit_message` filters the message a seller sees at their listing limit,
  for a plugin that sells extra slots and needs to say so.

- `osc_user_listing_limit()`, `osc_user_listings_used()`, `osc_user_listings_remaining()`,
  `osc_user_can_publish()` and `osc_listing_limit_message()` let a theme show a seller their
  listing quota before they hit it. -1 means unlimited.

- CI fails when the committed vendor and asset trees no longer match what
  composer.lock and package-lock.json declare. Releases ship those trees verbatim, so
  a bump that edits only a manifest would hand users the old library.

- Translation templates are published to the translations repository automatically when
  the strings they hold change, so translators are never working from an older set.

- CI fails when the translation templates no longer match the source they are extracted
  from, so a template cannot quietly stop offering newer strings to translators.

- `_x()`, `_ex()` and `_mx()` translate a string with a context, so two identical English
  words that are different words in another language can each be translated correctly.
  The context is for translators and is never shown.

- Delete hooks for the records that had none: `before_delete_field` / `after_delete_field`,
  `before_delete_field_group` / `after_delete_field_group`, `before_delete_page` /
  `after_delete_page`, `before_delete_country` / `after_delete_country`,
  `before_delete_region` / `after_delete_region`, `before_delete_city` / `after_delete_city`,
  `before_delete_city_area` / `after_delete_city_area`, `before_delete_form_submission` /
  `after_delete_form_submission`, `before_delete_widget` / `after_delete_widget`, and
  `after_delete_category` to pair with the existing `delete_category`. Each `before_` hook
  runs before the delete's transaction opens and each `after_` hook only once it has
  committed, so a plugin's own database work is never rolled back with a failed delete.
- **Sites can now sell credits and charge for listings.** Off by default, so nothing changes
  until you turn it on. Once enabled from **Settings → Billing**, you choose how many
  listings a seller may have live at once for free, and price extra listing slots and
  featured listings in credits.
  A built-in **bank transfer** option lets buyers pay by wire or cash — write your own
  payment instructions and settle each order by hand once the money arrives, with no card
  processor or API keys involved. **Billing → Packages** is where you define the credit
  bundles buyers choose from at checkout.
- Buyers get a wallet page showing their credit balance and history, a page to buy credit
  bundles, and a page listing their own past orders — plus a **Feature this listing** action
  that spends credits to run a listing as featured for a set number of days.


- **People can download a copy of their own data.** Signing in and following the link on the
  account page returns everything the site holds about them as JSON — profile, listings,
  comments, saved searches, orders and credit history — streamed straight to the browser
  rather than written anywhere. Erasure already existed; this is the other half of a
  data-subject request, and it is the only piece that was missing.

  Which tables hold personal data, whether each is included, and what deleting an account
  does to each, are recorded in one place (`mindstellar\privacy\PersonalData::map()`) with a
  reason attached — including for the sections deliberately kept, like the accounting
  records a sale leaves behind. A test fails if a table with a user column is added to the
  schema without an entry, because the failure mode otherwise is silent: data nobody can
  see and nobody knows to look for.

  Password hashes and account secrets are never included; they authenticate rather than
  describe.

### Breaking

- **`oc-includes/assets/chart-js/` has been removed.** Chart.js was added in 2021 and
  never used: no core file, admin page, bundled plugin or theme has ever loaded it, and
  the admin's charts are Google Charts. It shipped 122 KB to every install. A third-party
  plugin loading that path directly should bundle its own copy.

- **Back up your database before upgrading.** This release rebuilds foreign keys on
  twenty-four tables so that the database removes dependent rows along with their parent.
  Each key is checked against the whole table as it is rebuilt, so the time it takes
  follows the number of rows: measured over a quarter of a million listings and three
  quarters of a million custom-field values, the whole rebuild took about six seconds.
  A much larger site, or one on slow shared hosting, should expect longer, though not the
  kind of wait that needs planning around. If a timeout page appears, the upgrade is
  still running and will finish; sites with shell access can sidestep that entirely with
  `php oc-cli.php db:upgrade`.
  Before each key is rebuilt, any row still pointing at a parent that no longer exists is
  removed. A healthy database has none. If yours does, they are rows nothing could reach
  and the new key could not be added while they remained — the backup is what lets you
  look at them afterwards if you want to.
  An upgrade that is interrupted is safe to resume: each step is recorded as it completes
  and every step can be re-run, so starting the upgrade again finishes it.

- **Google Analytics has been removed from core.** The **Tracking ID** field is gone from
  Settings → General and no measurement snippet is rendered on public pages any more. Sites
  that were using it should paste their own snippet into a **Custom Code** widget under
  Appearance → Widgets, or install a plugin that provides it.
- `osc_google_analytics_id()` is deprecated. It still returns whatever measurement ID was
  saved before the upgrade — the stored value is left untouched, so a theme that prints its
  own snippet keeps working — but core no longer reads it and nothing can set it.

## Shopclass 6.1.0

Plugins and themes can now be found, installed and updated from inside the admin. Packages
declare which Shopclass and PHP versions they support, and the site is only ever offered a
version it can actually run — so an install on an older release is routed to the last version
that still works there rather than one that would fail on boot. Update checks, which have been
silently reporting nothing since 3.x, work again. The download and extraction path every
plugin, theme and core update passes through has been hardened, and packages that ship no
artwork render a built-in placeholder instead of a broken image. Container deployments should
read the upgrade note below before redeploying.

### Breaking

- **Container deployments: back up `oc-content/plugins` and `oc-content/themes` before upgrading.**
  Earlier production images kept those directories inside the container's writable layer, where
  anything installed was already discarded on every redeploy. This release moves them onto named
  volumes so installs finally persist — but the first redeploy onto the new arrangement seeds those
  volumes from the image, so packages installed into a still-running old container are not carried
  across and cannot be recovered afterwards. Copy them out first, and reinstall once the upgrade is
  done. Sites installed from the zip are unaffected. A stale entry may remain in the active-plugins
  preference for a package whose files are gone; it is ignored and harmless.

### New

- Plugins and themes can be browsed, installed and updated from the admin. Plugins and
  Appearance each gain **Browse** and **Updates** tabs backed by a published catalog of packages;
  search, category filter and sort run in the browser, and a package with no artwork falls back to
  a built-in placeholder. Installs verify the publisher host and a SHA-256 checksum, stage and
  validate the package before touching the live directory, and roll back to a backup if the swap
  fails.
- Plugin and theme update checks work again. The market helpers they relied on have returned
  `false` unconditionally since 3.x, so the admin update badges could never report anything; they
  now resolve against the catalog and offer the highest version the site can actually run, rather
  than the newest that exists.
- `oc-cli.php` gains `market:refresh`, `market:search`, `market:info`, `market:install` and
  `market:update` for headless and container installs.
- Container deployments keep what they install. `oc-content/plugins` and `oc-content/themes`
  are persisted alongside uploads and downloads, and the entrypoint reconciles bundled packages
  from a pristine copy in the image on every start — installing what is missing, refreshing only
  what the image has newer, and never touching a package the site owner installed. Package
  installs are now gated separately from the core self-updater (`OSC_DISABLE_PACKAGE_INSTALLS`,
  off by default), so a container can update plugins and themes in place while core continues to
  update by deploying a new image.
- Catalog listings carry a download count, and Browse can sort by it. The figure is GitHub's
  cumulative count of release-asset downloads, so it includes CI, mirrors and bots and is not an
  install count; it is shown only where there is one, and the default ordering stays most
  recently updated.
- Plugins and themes can declare `Requires Shopclass`, `Tested up to`, and `Requires PHP` in
  their header block. All three are optional — a package that declares nothing is treated as
  before, never as incompatible — and they are parsed for both plugins and themes.
- `mindstellar\market\Compatibility` evaluates those fields into one of four verdicts and
  picks the highest release a site can actually run. A site on 6.1 offered a package whose
  newest version requires 7.0 resolves to that package's last 6.x-compatible release rather
  than being offered an update that would fatal on boot.
- `osc_theme_screenshot_url()` and `osc_plugin_icon_url()` resolve a package's artwork, or a
  bundled placeholder when it has none, with `osc_theme_has_screenshot()` /
  `osc_plugin_has_icon()` to tell the two apart. Both are filterable.
- `tools/package-lint.php` validates a package directory against the published package
  specification, and `deprecated-api.json` lists every deprecated core symbol with its
  replacement. Both ship as release assets so external tooling reads one authoritative copy
  instead of maintaining its own.
- The package contract and the market design are documented in `docs/PACKAGE-SPEC.md` and
  `docs/MARKET.md`.

### Changed

- A package's compatibility is no longer decided by an exact string match against a
  comma-separated version list, which judged a package declaring `6.0.2` incompatible with
  6.0.3. The legacy list is still honoured when a package declares nothing newer.
- A download that returns a non-2xx status, an empty body, or a body failing its expected
  checksum is now a failure rather than a file written to disk and reported as success.

### Security

- Zip extraction now resolves every entry against the destination and rejects the whole
  archive if any entry escapes it, rather than skipping that entry and continuing. Absolute
  paths, Windows drive prefixes, backslash traversal, and symlink entries are all rejected,
  and entry-count, per-entry size, total size and compression-ratio caps stop a zip bomb
  before it is decompressed.
- Package downloads can carry an expected SHA-256, verified before extraction, and a
  checksum-carrying package is restricted to an allowlist of release hosts so a tampered
  source cannot redirect an install elsewhere. Packages resolved from a site's own update
  URI are unaffected.
- Redirect and total-transfer limits were added to the download path, which previously
  followed redirects without a cap and had no overall timeout.

### Fixed

- The catalog no longer bakes a compatibility verdict per version at build time — one static
  file is served to sites on many core versions, so a verdict computed against whatever core
  the build happened to run against was wrong for every other one, including the reference
  plugin showing as incompatible on its own catalog. It now publishes the raw `requires` /
  `requires_php` / `tested` fields plus a package-level supported range, and every verdict is
  computed locally, as it already was for the install/update gate itself.
- A prerelease core was refused any package requiring the release it belongs to — `6.1.0.beta2`
  could not install a package declaring `Requires Shopclass: 6.1.0`, because the beta sorts below
  the release. Compatibility now compares against the release a prerelease belongs to, so testers
  are not locked out of the series they are testing.
- The Appearance screen no longer renders a broken image for a theme that ships no
  `screenshot.png`; it also gained lazy loading, real alternative text, and intrinsic
  dimensions so the grid no longer reflows.
- `Zip::isPathValid()` never rejected anything — its condition evaluated false for every
  ordinary path, so the destination check had been dead since it was written.
- The plugin and theme update-package builders assembled their result and then returned
  nothing, and their GitHub branch tested `stripos(...) === true`, which that function never
  returns. Neither could ever have produced a package.
- `osc_downloadFile()` discarded the result of the download it performed and always reported
  success.

## Shopclass 6.0.3

An SEO pass on the public pages: self-referential canonicals, correct handling of empty and
query-string search URLs, and a valid breadcrumb graph.

### Changed

- Public pages emit a self-referential `<link rel="canonical">` — item detail, the homepage and
  search/category pages. The search canonical is the unsorted, page-1 URL, so paginated and
  sort/facet permutations of a result set consolidate onto one indexable URL.
- A valid but empty category or location page now returns `200` with
  `<meta name="robots" content="noindex, follow">` instead of a soft `404`, so a real landing page is
  not de-indexed while it holds no listings. Empty free-text or faceted searches still return `404`.
- The core breadcrumb is now a valid schema.org `BreadcrumbList` — the list is wrapped in the
  `BreadcrumbList` scope and each crumb carries a `position`, so the breadcrumb rich result can be
  parsed (themes rendering their own breadcrumb are unaffected).

### Fixed

- A query-string search on the rewritten `/search` route (e.g. `/search?sPattern=x`) now
  301-redirects to the friendly URL instead of returning `404`.
- Deleting a category assigned to a meta field group no longer fails silently — the group ↔
  category mapping is now cleared as part of the delete cascade, so the foreign key no longer
  blocks removal and the category no longer reappears in the tree.

### Security

- The `generator` meta tag no longer publishes the exact version, so a visitor cannot read it to
  target a known-vulnerable release.

## Shopclass 6.0.2

A maintenance release: the production container can send mail through an external SMTP relay, and the
core feature preferences are consolidated back into one section.

### New

- Container mail relay — the production Docker image installs msmtp and renders its config from the
  environment (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM`, `SMTP_TLS`,
  `SMTP_STARTTLS`), so a deployed container sends registration, password-reset and contact email
  through a real provider with no in-app SMTP setup. With no relay configured, mail is logged and
  dropped by a shim so the failure is loud rather than silent.
- Opt-in `:edge` container image channel — a push to `develop` whose head commit message contains
  `[publish-edge]` refreshes `ghcr.io/mindstellar/shopclass:edge` (gated on the same tests as a
  release) without cutting a versioned tag, so deployers can preview changes before the next release.

### Changed

- Core feature preferences — sitemap, spam moderation, cleanup, item stats and the admin activity log
  — now live in the shared `osclass` preference section instead of their own, so every core setting is
  found in one place. An automatic migration relocates existing values on upgrade; nothing is lost.

## Shopclass 6.0.1

A security and maintenance release on top of 6.0.0: it hardens the public "send to a friend" form and
lets the Docker image recover the real client IP when it runs behind a trusted proxy.

### New

- Real client IP behind a proxy — set `OSC_REAL_IP_HEADER` (e.g. `CF-Connecting-IP` for a Cloudflare
  tunnel, `X-Forwarded-For` for a load balancer) so the image restores the visitor's IP into
  `REMOTE_ADDR`, which login throttling and abuse keying rely on. `OSC_REAL_IP_TRUSTED` sets the
  trusted-proxy CIDRs (defaults to any peer — correct only when the sole ingress is that proxy). Off
  by default.

### Security

- The "send to a friend" form emailed a listing to a recipient taken straight from the request, from
  the site's own address — an anonymous mail-relay surface. It now ships off by default
  (`enable_send_friend`) and, when enabled, requires a logged-in user (`reg_user_can_send_friend`, on
  by default), both under Settings → Listings. A theme that still links to it is bounced back with a
  notice rather than breaking. Both it and the contact-seller form are now rate limited per source
  address (filterable via `send_friend_throttle_max` / `item_contact_throttle_max`), so neither can be
  driven as a spam relay.

## Shopclass 6.0.0

The first stable release under the Shopclass name — the culmination of the Osclass modernization.
Since the last stable (Osclass 5.2.0) the admin has been rebuilt on Bootstrap 5 and stripped of
jQuery, the front end made sessionless so public pages are reverse-proxy/CDN cacheable, sitemaps and
S3 storage brought into core, search made pluggable and sharper, and a long list of security holes
closed. The bundled public theme is now Storefront — a modern, responsive, vanilla-JS front end —
replacing Bender. PHP 8.0 is now the floor.

### New

- Cache-safe listing view counts via a JS beacon (an uncached POST), so counts stay accurate behind
  a full-page cache and non-JS crawlers stop inflating them. Toggle via the `item_view_beacon`
  preference or `item_view_beacon_enabled` filter.
- Core-owned HTTP caching: public read pages emit `public, s-maxage=30, must-revalidate` (all else
  `private, no-store`), keyed only on Shopclass's own login/session/locale cookies, so a reverse
  proxy or CDN can cache them with no plugin and third-party analytics/ad cookies don't defeat it.
  Filterable via `public_cache_max_age` and `response_cache_control`; reference nginx micro-cache
  config in `.docker/nginx/microcache.conf`.
- Built-in XML sitemap at `/sitemapindex.xml` — paginated item, category, static-page and optional
  location sitemaps, configurable under Settings → Sitemap and hookable for a search backend. Core
  only serves it when no sitemap plugin claims the path.
- Built-in S3-compatible storage — offload images to S3, R2, Spaces, Wasabi, B2 or MinIO with public
  or presigned URLs and an optional CDN base. Uploads/deletes/migrations run through a cron-drained
  queue and each image records its location, so local and remote coexist. Supersedes the `better-s3`
  plugin.
- Pluggable search backend: a `search_results` filter lets a plugin answer searches from an external
  engine (Manticore, Elasticsearch), and returning a `model` key hands it the whole page (premiums,
  `osc_search()`). Return `null` and core's MySQL search runs unchanged.
- Sharper built-in search — requires every word, matches prefixes, honours `"quoted phrases"` and
  `-excluded` terms, ranks title above description, and falls back to substring for short queries.
  Adds a title `FULLTEXT` index (one-time `ALTER TABLE`). `Search::fromPrimaryKeys(array $ids)`
  hydrates an externally-produced match set, paging to the id count and preserving caller ranking.
- Command-line interface (`oc-cli.php`) for maintenance: `cron`, `db:upgrade`, `cache:flush`,
  `sitemap:warm`, `user:create-admin`, `user:reset-password`, plugin management
  (`plugin:list`/`activate`/`deactivate`), theme management (`theme:list`/`activate`), and a
  `doctor` health check.
- Headless install — `oc-cli.php install --unattended` provisions a fresh site (schema, seed data,
  baseline migrations, admin account) from environment variables or flags, with no interactive step,
  so a container or one-click platform can self-provision on first boot. Idempotent: a no-op once
  installed. DB settings and `WEB_PATH` from the environment or `config.php` are authoritative; when
  they come from the environment no `config.php` is written, keeping the container filesystem
  read-only.
- Official production Docker image (`Dockerfile`) — a single self-contained container (nginx +
  php-fpm + supervisor) that self-provisions on first boot via the headless installer and applies
  pending migrations on every start, so a container platform or `docker compose -f
  docker-compose.prod.yml up` brings up an installed, running site with no manual step. The default
  storefront theme is bundled from its release; configure via `DB_*`, `WEB_PATH` and `OSC_ADMIN_*`,
  and offload uploads to S3 for multi-instance scaling. Published to GHCR on every release, tagged
  with the exact version plus a moving channel alias (`:6.0.0.rc2` and `:rc`; `:latest` for stable).
  The image sets `OSC_DISABLE_SELF_UPDATE=1` so the admin's file-writing self-updater is turned off
  (it would be discarded on the next redeploy) — update by deploying a newer image tag; the
  entrypoint's `db:upgrade` migrates the schema. The same flag disables self-update on any immutable
  install.
- Demo mode from the environment — set `OSC_DEMO=1` to enable the read-only public-demo lockdown in
  a container, where `OSC_IGNORE_CONFIG_FILE` skips the `config.php` `define('DEMO', true)`. A value
  in `config.php` still wins.
- Translation templates are generated and shipped — a build step (`npm run i18n`) extracts every
  translatable string from the source into `oc-content/languages/core.pot` and `messages.pot`, so a
  translator can start a new locale, and compiles the bundled locale's `.po` to `.mo` so the binary
  catalogues never go stale. The release zip also drops build-only files (`Dockerfile`, `.docker/`,
  compose files, `phpcs.xml`).
- Core spam moderation — a keyword blocklist and visitor reporting that record why a listing was
  flagged, quarantine matches for review, and auto-hide past a threshold. Gate-able via the
  `item_mark` filter / `item_marked` action. Supersedes the Butler plugin.
- Provider-agnostic captcha — Cloudflare Turnstile alongside reCAPTCHA, verified server-side, failing
  closed, now also on admin login.
- Rebuilt first-run installer — four steps with a live "Test connection" check, plain error messages,
  transactional writes, and no jQuery/Bootstrap.
- Configuration can come entirely from the environment; `config.php` is optional
  (`OSC_IGNORE_CONFIG_FILE`, `OSC_CONFIG_FILE`). DB connections accept `host:port` / `DB_PORT` and a
  10-second connect timeout.
- Versioned database migrations — an ordered runner with a `t_migration` ledger, plus a CI check that
  a fresh and an upgraded install reach the same schema.
- Native Cleanup tool (expired/unactivated/spam/orphaned content) and Activity log management
  (filterable viewer, on/off switch, cron-enforced retention) under Tools.
- Rebuilt admin Categories manager — a real tree with drag-to-reorder/nest, inline counts, and a
  drawer editor.
- Per-admin dark/light theme toggle (persisted server-side) and correct RTL mirroring via logical
  CSS, with no separate stylesheet.
- `memcached` object-cache driver, selectable from the environment; the old `memcache` driver is
  deprecated.
- Friendly-named image downloads — a resource endpoint serves `<owner-slug>-<id>.<ext>`, linked via
  `osc_resource_download_url()`; a private bucket redirects to a short-lived signed URL.
- Themes can register routes to their own controllers: `osc_add_route()` now resolves a file in the
  theme root, and `osc_add_route_hook($id, $regexp, $url)` registers a controller-dispatched route
  that can act and redirect.
- New model events `item_content_updated` and `item_expiration_updated` fire on direct-model writes;
  `item_post_redirect_url` filters the post-publish redirect; `ItemForm::category_select()` accepts
  an `$attributes` array; `osc_csrf_token_form()` complements `osc_csrf_token_url()`.
- Autocomplete custom-field type: a text field whose suggestions come from a core AJAX endpoint —
  the distinct existing values of that field, gated to searchable fields so nothing else is
  enumerable — rendered through the shared vanilla `oscAutocomplete` combobox with no per-field JS
  (FieldForm emits `data-osc-*` attributes; a static init wires the widget). Plugins can supply
  their own source via the `custom_field_autocomplete_source` filter; themes style `.osc-ac-list`.
- Public form JavaScript can defer to the footer: the form validation and location-picker
  methods (`CommentForm`/`ContactForm`/`SendFriendForm`/`UserForm::js_validation()`,
  `ItemForm::location_javascript_new()`/`location_javascript()`) and `osc_render_form()` take an
  opt-in flag that enqueues their inline `<script>` after the file scripts instead of echoing it in
  place, wiring dependencies (e.g. the autocomplete lib) automatically. Off by default, so themes
  that call these in-place are unchanged. New helper `osc_enqueue_script_code($code, $deps, $id)`
  exposes the underlying footer inline-script queue, now id-deduplicated.
- Install smoke test in CI — a release zip is unpacked, installed against a real database, and signed
  into before it can become a release.
- Storefront is the new default public theme — a modern, responsive, vanilla-JS front end that
  replaces Bender as the bundled default. Fresh installs ship and activate it, and the release build
  bundles it from its own repository.
- Category slug changes now redirect permanently — renaming a category records its former slug and
  301-redirects old inbound links (and indexed search results) to the current canonical URL instead
  of 404ing. Old-slug-to-category mappings are stored so renames never chain, and the category tree
  and row object caches are invalidated on every category add/edit/reorder/delete so the new URL
  resolves immediately.

### Breaking

- Minimum PHP is now 8.0.
- The admin is vanilla JavaScript — jQuery and its plugins are no longer loaded on any admin page
  (tabs, modals, autocomplete, datepicker, category tree, validation all rewritten natively), and
  core ships no jQuery at all. `jquery`/`jquery-ui`/`jquery-validate` stay registered for themes that
  enqueue them; a plugin needing one must enqueue it itself.
- The item-form photo uploader moved from jQuery Fine Uploader to a vanilla `osc-uploader`, and
  enqueued scripts are now deferred by default (filter-controllable). A theme/plugin that hooked Fine
  Uploader must migrate.
- Removed the admin's legacy float-grid classes (`.grid-system`, `.grid-row`, `.grid-10`…`.grid-100`)
  in favour of Bootstrap 5's `.row`/`.col-*`.
- `RSSFeed::addItem()` now escapes values itself — stop pre-escaping link/image URLs in plugins or
  they double-encode.
- `ItemForm::category_select()` gained a trailing `$attributes = []` parameter. A theme that
  overrides this method (or any `Form`/`ItemForm` extension point) with the old signature becomes a
  compile-time fatal on upgrade, since PHP requires the override to stay signature-compatible — add
  the parameter to the override, or accept future options through the array.
- Removed the unused `INSTANT` alert frequency — core never dispatched it. Its mail builder,
  `hook_alert_email_instant` hook and `alert_email_instant` template are gone (an upgrade deletes the
  dead template); `osc_runAlert('INSTANT')` is now a no-op.

### Security

- Sign-in attempts are rate limited — failed sign-ins and reset requests counted per address and per
  account over a rolling window (20/10 per 15 min), refused before any password is hashed, and
  recorded against the name as typed so the limiter can't enumerate accounts. Adds `t_login_attempt`.
- Sign-in and reset forms no longer reveal which usernames/emails are registered — one answer for
  both cases, doing equal work so timing can't tell them apart.
- The database layer moved onto a parameterised query API; the audit behind it found and fixed
  several SQL injection vectors, including one reachable from anonymous public search.
- CSRF and remember-me rebuilt as stateless HMAC-signed tokens backed by a per-install key, not the
  session, so anonymous pages stay cacheable. Reset/activation codes are single-use and stored
  hashed; the remember-me cookie is HttpOnly/Secure/SameSite and a password change revokes it
  everywhere.
- Core HTTP fetches now verify the peer's TLS certificate by default. `osc_file_get_contents()` had
  forced `verify_ssl=false`, so every core fetch — including `install_locations()`, which runs the
  SQL it downloads — ran unauthenticated. It now defaults to `true`, caps redirects, pins to HTTP(S),
  and aborts stalled transfers.
- The saved-search alert endpoint no longer trusts a caller-supplied `userid` — an anonymous request
  could activate a recurring alert on any account, skipping confirmation; the owner now comes from
  the session.
- The installer carries a CSRF nonce on every state-changing step (closing a hole that could finalise
  an install with no admin), re-validates admin email/username server-side, and never reflects
  passwords into the page.
- Fixed a stored XSS in the admin search-alerts list and escaped remaining user-controlled output
  across admin datatables, statistics widgets and the comment editor. Watermark uploads are validated
  by content, not filename; added the missing CSRF check on `upgrade_db`; search-alert subscription
  can require a logged-in user.
- Public comment and abuse-report forms now require the configured captcha and carry a CSRF token,
  closing an unauthenticated spam/forgery vector on the two state-changing public forms.
- `redirectTo()` strips CR/LF from the `Location` URL, closing a header-injection / response-splitting
  vector on redirects built from request-derived values.

### Changed

- Rebranded from Osclass to Shopclass (new teal identity, rewritten README) and relicensed to
  GPL-3.0-or-later, retaining the Apache-2.0 notice for Osclass-derived code.
- The admin was rebuilt on a Bootstrap 5.3 design system — collapsible sidebar shell, unified content
  header, restyled settings/tables/callouts/messages, and on-brand dark-mode charts and login.
- Crawlers no longer count as listing views (a denylist, extensible via the `bot_user_agents`
  filter), so **view counts drop after upgrading** — that's them becoming accurate; revertible under
  Tools → Cleanup. Render-time counting is wrapped in a `count_view_on_render` filter for themes that
  count client-side.
- The front end is now sessionless: login identity, flash messages, form-repopulation values, the
  interface language, the post-flood wait and pre-save photo staging all moved off `$_SESSION` (into
  signed cookies or the database), so browsing, forms and posting hold no session and stay
  reverse-proxy cacheable across app servers. Existing sessions/remember-me survive the upgrade; only
  the admin and installer still use a session, and the `_setForm`/`_getForm` and
  `Session::_get('userId')` APIs keep working through shims.
- The RSS feed was modernised on `DOMDocument` — image `<enclosure>`, stable `<guid>`, single-escaped
  URLs, `rss_feed_item` filter — fixing a CDATA-breakout injection.
- Object cache gained an atomic `osc_cache_increment()`; the retired APC driver was removed (an
  unknown `OSC_CACHE` now falls back to the default); captcha verification uses an 8s timeout.
- Retired the unused `t_keywords` table (a migration drops it) and moved the build toolchain from
  Grunt to `sass-embedded` + `esbuild`.

### Performance

- Login went from ~1.4s to ~175ms — the bcrypt cost had been 15 since 2014 and is now 12, still above
  the recommended floor; existing passwords re-hash on next login, overridable with `BCRYPT_COST`.
- Listing statistics no longer grow without bound — `t_item_stats` kept a row per listing per day
  with seven indexes and was never pruned; it now keeps one row per listing plus a small site-wide
  daily rollup, six indexes gone, data migrated across.
- Anonymous browse/search pages are cacheable (lazy sessions/CSRF, stateless alerts); listing search
  uses an exact cheap `COUNT(*)`, the per-item resource N+1 is batched, `User::findByPrimaryKey` is
  cached, and query logging is gated behind `OSC_DEBUG_DB`.
- Auto-cron self-requests are throttled to at most once per 5 minutes instead of firing on every page
  view, so a busy site no longer spawns an FPM worker per hit.

### Fixed

- Five admin strings that passed a non-existent text domain (`'admin'`/`'modern'`/`'osclass'`) to
  `__()`/`_e()` always rendered untranslated; they now use the default `core` domain and are
  translatable.
- Category dropdowns list sub-categories again — the nested `select()` option builder recursed with
  the child array as the selected value and an integer as its options, collapsing each parent's
  children into a single empty option. Affects the admin category parent picker and any nested select.
- Checkbox custom fields render as translatable Yes/No text instead of a broken tick/cross image that
  only the old Bender theme shipped, so every other theme showed a missing image. Overridable via the
  `item_meta_checkbox_value` filter.
- The admin/one-click upgrade now records the new version in the `version` preference. The upgrade
  swaps the code files and upgrades the database in a single request, so the `OSCLASS_VERSION`
  constant loaded at the start still held the pre-upgrade value when the version was written — the
  preference lagged a version behind after every upgrade. It's now read from the freshly-synced code
  on disk.
- Pagination: the `list-last` class now lands on the final item (it was overwritten and never
  applied, so the last page's styling was off), a Pagination object can be rendered more than once
  without duplicating classes, and an out-of-range `iPage` no longer renders a bogus page number.
  The list is now a labelled navigation landmark with `aria-current` on the active page and
  `aria-label`s on the first/prev/next/last arrows. `osc_pagination_items()` no longer emits an
  undefined-variable notice outside profile/list contexts.
- Send-to-friend no longer 500s on an empty or malformed form. It dispatched the email without
  validating the recipient, so an empty/bad address reached PHPMailer and threw. It now validates
  the sender/recipient names and emails up front (like the contact-seller form) and returns a
  field-level error instead.
- `osc_sendMail()` no longer lets a bad address or dead mailserver 500 the page: a malformed
  recipient/BCC/reply-to threw from `addAddress()` before the existing `send()` guard was reached.
  The whole dispatch is now guarded, so any mail failure degrades to a logged warning and a
  `false` return for every caller.
- The database debug panel (`OSC_DEBUG_DB`) now counts queries issued through the new
  `mindstellar\database\Connection` API, which previously bypassed the log and left the panel
  reading zero — including `OSC_DEBUG_DB_EXPLAIN` plans for its SELECTs. Parameterised queries are
  shown with their real values inlined (debug display only; execution still binds). The panel is
  redesigned — a docked, collapsible summary (totals, slowest query, duplicate/slow/error counts)
  over a query list with color-coded timing, SQL highlighting, per-query EXPLAIN tables that flag
  full scans/missing keys/filesort, and duplicate-query flags for spotting N+1s.
- `osc_format_price()` drops the fractional part when a price is whole at the locale's precision, so
  `1,234` no longer renders as `1,234.00` while `1,234.50` keeps its decimals; the locale thousands
  separator and decimal point are unchanged.
- No more deprecation notices on PHP 8.4 or 8.5 (implicitly-nullable params and old cast spellings).
- `Plugins::hasHook()` reports whether a hook still has a listener, not merely whether one was ever
  registered — an emptied priority bucket used to read as true forever.
- Saved-search alerts: no longer stop firing mid-cron (the reused `Search` never cleared its keyword
  pattern, poisoning later alerts; restore resets it), each search is isolated in a try/catch so one
  error doesn't drop the rest of the run, and they match the same listings on replay as when saved
  (`toJson()` double-escaping fixed; old alerts repaired on replay).
- `osc_route_url()` emits `page=route` (not `page=custom`) for controller routes, so a fileless route
  no longer 404s when rewrite is disabled.
- `Item::updateExpirationDate()` — fixed a malformed UPDATE that silently never changed the date, and
  a null-deref that warned and mis-moved the counters when a listing had no location row.
- Storage settings persist the selected S3 provider and a locked region (R2's `auto`), reflect
  Better-S3's real activation state, and reliably queue new uploads for offload.
- Publishing a listing no longer cascades into foreign-key errors when the parent insert returns no
  row — `ItemActions::add()` checks the new id first, and ids are captured from the insert itself
  (`DAO::insertGetId()`) rather than a shared `insert_id` another statement could reset. A
  transaction left open at end of request is rolled back and logged.
- The "new version available" notice no longer sticks on an older release (the releases feed isn't
  newest-first; it now picks the highest version), and a failed update check no longer counts as a
  check or resets the daily timer.
- Assorted: unsaveable "Search alerts" setting; "most viewed" ignoring visibility/ordering; General
  Settings fatal when the update check had never run; sitemap XSL served as `text/xsl`;
  reported-listing double counting; real image compression (`image_png_compression` /
  `image_jpeg_quality` filters); env-only install 503 / WEB_PATH / utf8 bugs; friendly-URL
  fall-through to the home page; listing count not incremented on an email-change reassign; invalid
  `composer.json` version; non-numeric price TypeError on post; canonical-host redirect emitting a
  bare `:` port; login/admin-auth pages starting a session just to remember a return URL; "remove
  photo" leaving temp files; `Resource::findByOwner()` fataling on a poisoned cache entry.

Source: https://github.com/mindstellar/shopclass
