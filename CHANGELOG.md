# Changelog

## Shopclass 5.4.0

Public pages are now reverse-proxy and CDN cacheable. The application decides whether each
response may be shared-cached and says so in a standard Cache-Control header, so a proxy can hold
the homepage, search, listings and public profiles briefly — absorbing crawler and traffic load —
without ever storing or serving a personalized page, and without being defeated by analytics or
consent cookies.

### New

- Cache-safe listing view counts. A cached listing page never re-runs PHP, so views were
  under-counting; they are now counted by a small client beacon — a POST fired on page load,
  itself never cached — instead of at render time, so the count stays accurate behind a full-page
  cache. Because it is JavaScript-gated, crawlers that do not run JS no longer inflate views. On by
  default; a theme with its own beacon, or a site that prefers render-time counting, turns it off
  with the `item_view_beacon` preference (set to `0`) or the `item_view_beacon_enabled` filter.
- Core-owned HTTP caching. Public read pages — homepage, search and category results, listing
  detail, static pages and the public seller profile — declare themselves cacheable and emit
  `public, s-maxage=30, max-age=0, must-revalidate`; every other response is `private, no-store`.
  A reverse proxy or CDN can now hold public pages for a short window while browsers always
  revalidate, so a full-page cache needs no plugin. The shared-cache window is filterable
  (`public_cache_max_age`), and the whole header via `response_cache_control`.
- The cache decision keys on Shopclass's own login, session and locale cookies only, so
  third-party analytics and advertising cookies (Google Analytics, AdSense, the cookie-consent
  banner, and the like) no longer defeat the cache — and a logged-in or otherwise personalized
  page is never stored or shared.
- A reference nginx micro-cache config ships in `.docker/nginx/microcache.conf`. It bypasses the
  cache on Shopclass's cookies and respects the app's own Cache-Control, with no hardcoded domain
  hashes and no per-route allow/deny lists to drift out of date.
- Pluggable search backend. A `search_results` filter lets a plugin or theme answer a search from
  an external engine (Manticore, Elasticsearch, …) by returning `['items' => …, 'total' => …]`,
  while core keeps ownership of URL parsing, the view export and the feeds. Return `null` and
  core's MySQL search runs unchanged, so existing sites are unaffected. Alongside it:
  `Search::includeHidden()` surfaces disabled/expired listings for an admin or owner view without
  reaching for `new Search(true)`, `Item::liveConditions()` exposes the "publicly live" predicate
  as one source of truth, and `ItemStats::sumByUser()` sums a counter across a user's listings in
  one aggregate instead of walking the catalogue.
- Sharper built-in search. The default MySQL search now requires every word a visitor types
  rather than matching any one of them, matches partial words by prefix, and honours
  `"quoted phrases"` and `-excluded` terms — so a two-word query stops returning everything that
  merely shares one common word. Results are ranked with a title match weighted above a
  description-only match, and a query too short for the full-text index (below three letters) falls
  back to a substring match instead of returning nothing. Upgrading adds a title `FULLTEXT` index
  to `t_item_description` (a one-time `ALTER TABLE`).
- Themes can register routes to their own controllers. `osc_add_route()` pointing at a file in the
  theme root now resolves instead of 404ing — core's custom-route dispatcher searches the theme
  root alongside the plugins directory (the existing traversal / admin-folder guard still applies).
  A theme no longer needs a parallel router to serve its own pages.
- The `Item` model announces writes the controller-layer item events never saw: `updateLocaleForce()`
  fires `item_content_updated($id, $locale)` and `updateExpirationDate()` fires
  `item_expiration_updated($id, $newDate)`. A search index or cache that mirrors listing content or
  liveness can now react to a bulk expiry or title change made straight through the model, rather
  than reconciling it after the fact.
- `osc_add_route_hook($id, $regexp, $url)` registers a route dispatched by controller instead of by
  file — for an endpoint that acts and redirects rather than rendering, which a file-backed
  `osc_add_route()` route cannot do without first emitting the theme's page chrome.
- `Search::fromPrimaryKeys(array $ids)` hydrates a match set produced elsewhere — an external
  search engine, a plugin's own query — through core's row fetch. It pages to the id count so the
  default page size can no longer silently truncate the result to ten rows, and preserves the
  caller's ranking as the result order.
- A `search_results` backend can own the whole page, not just the result list: return a `model`
  key and core exports it as the page's `search` object, so the premium rail (`osc_get_premiums()`)
  and `osc_search()` run against the backend instead of falling back to a fresh MySQL `Search`. The
  search model is now exported on every search page, not only under `OSC_DEBUG`.

### Fixed

- Saved-search alerts now match the same listings on replay as when they were saved. `toJson()`
  serialised the search pattern already escaped, and replaying the alert escaped it again, so the
  stored criteria drifted from the original — visible on short keywords, which take the
  substring-match path where the stray quotes changed the result set.
- `osc_route_url()` builds a working URL for a controller (hook) route when rewrite is disabled. It
  previously always emitted `page=custom`, which 404s for a route that has no file; it now emits
  `page=route` for controller routes, matching how the dispatcher resolves them.
- Renewing a listing with no location row no longer warns and mis-moves the listing counters.
  `Item::updateExpirationDate()` read the row through an inner join on the location table and then
  dereferenced it outside its own null check; the guard now covers the whole block and returns
  false when the row is absent.

Source: https://github.com/mindstellar/shopclass

## Shopclass 5.3.0

The first release under the Shopclass name. The admin has been rebuilt, jQuery is gone from
core, sitemaps and S3 storage are now built in, and a long list of security holes is closed.

### Breaking

- The admin is now vanilla JavaScript. jQuery, jQuery-UI, jQuery-validate, jQuery-treeview and
  jQuery-uniform are no longer loaded on any admin page — tabs, modals, autocomplete, the
  datepicker, the category tree, tooltips and form validation were all rewritten with native
  browser APIs. This covers the item add/edit and listing pages too: the `ItemForm` category
  cascade, location fields and photo JavaScript now run on a shared jQuery-free `oscAutocomplete`
  combobox. A plugin that still needs one of those libraries must enqueue it itself, e.g.
  `osc_enqueue_script('jquery')`. The public theme's own scripts are a separate, later effort.
- Core no longer contains any jQuery at all. The admin login screen was the last page pulling it
  in. The unused `jquery-migrate`, `jquery-treeview`, `jquery-nested`, `jquery-validate-additional`,
  `tabber` and `colorpicker` registrations are gone, along with about 260 KB of vendor assets
  nothing referenced. `jquery`, `jquery-ui` and `jquery-validate` stay registered for themes that
  ask for them.
- The photo uploader on the item form was rewritten from jQuery Fine Uploader to a vanilla
  `osc-uploader`. A theme or plugin that hooked Fine Uploader must move to the new one. Enqueued
  scripts are now deferred by default, controllable with a filter.
- The admin theme's legacy float-grid classes (`.grid-system`, `.grid-row`, `.grid-10`…`.grid-100`)
  were removed in favour of Bootstrap 5's `.row`/`.col-*`.
- Minimum PHP is now 8.0.
- `RSSFeed::addItem()` escapes every value itself now. If you build feed items in a plugin, stop
  pre-escaping the link and image URLs or they will be double-encoded.

### New

- Built-in XML sitemap at `/sitemapindex.xml` — paginated item sitemaps plus category, static-page
  and optional location sitemaps. Configure under Settings → Sitemap. The item source is hookable,
  so a search backend like Manticore can drive it from a plugin. Sites already running a sitemap
  plugin are unaffected: core only serves the path when no plugin claims it.
- Built-in S3-compatible storage — offload listing images to S3, R2, Spaces, Wasabi, B2, MinIO or a
  custom endpoint, with public or presigned URLs and an optional CDN base. Uploads, deletes and
  migrations run through a cron-drained queue, so posting stays fast and an outage is not fatal.
  Each image records where it lives, so local and remote coexist and migration is reversible.
  Supersedes the `better-s3` plugin, which keeps working.
- Core spam moderation — a keyword blocklist and visitor reporting that record *why* a listing was
  flagged. Matching listings are quarantined for review (or rejected outright), and the matched
  keyword is shown in the admin instead of leaving you to guess. Reports are one vote per person
  and can auto-hide a listing past a threshold.
- Captcha is provider-agnostic. Cloudflare Turnstile is supported alongside reCAPTCHA, chosen under
  Settings → Spam and bots, verified server-side and failing closed. Captcha now also applies to the
  admin login. Existing reCAPTCHA installs are unaffected.
- Rebuilt the first-run installer — a four-step flow with a live "Test connection" check, plain
  database-error messages instead of raw error numbers, and a real progress rail. It loads no
  jQuery, jQuery-UI, Bootstrap or vtip, and writes its rows in transactions so a failed install
  rolls back instead of leaving a half-built database.
- Configuration can come entirely from the environment; `config.php` is now optional. Set
  `OSC_IGNORE_CONFIG_FILE=1` to ignore one entirely, or `OSC_CONFIG_FILE=/path` to load it from
  outside the web root.
- Database connections accept a port — `host:port` in the installer, a fifth connection argument, or
  `DB_PORT` in `config.php`. Connections also use a 10-second connect timeout.
- Versioned database migrations — an ordered runner with a `t_migration` ledger for the schema and
  data changes the struct.sql reconciler cannot express (drops, renames, backfills), plus a CI check
  proving a fresh install and an upgraded install end up with the same schema.
- One calm system page now stands behind every full-page message: fatal errors, database
  unavailable, not installed, not configured and maintenance mode. It renders even when the app
  cannot boot, and shows internals only under `OSC_DEBUG`.
- Native Cleanup tool under Tools, removing expired, unactivated, spam and orphaned content.
  Supersedes the Butler plugin.
- Activity log management under Tools → Activity log. The log was written from all over the admin
  but never surfaced, never pruned and could not be turned off. It now has a filterable viewer, an
  on/off switch, a retention window the daily cron enforces, and a Clear log button.
- Rebuilt the admin Categories manager — a real tree with drag-to-reorder and drag-to-nest, inline
  counts, a status pill, a drawer editor, and a delete confirmation that spells out what goes with it.
- Per-admin dark/light theme toggle, persisted server-side. Dark mode is complete: every component
  reads a design token and flips with `data-bs-theme`.
- The admin mirrors correctly under `dir="rtl"` using logical CSS properties, with no separate
  stylesheet.
- `memcached` object-cache driver; the old `memcache` driver is deprecated. The cache backend can
  also be selected from the environment.
- Install smoke test in CI — a release zip is unpacked, installed against a real database through
  the actual installer, and signed into before it can become a release.
- Item reporting is gate-able: an `item_mark` filter can veto a report and an `item_marked` action
  fires after it counts.
- `osc_csrf_token_form()` helper, complementing `osc_csrf_token_url()`.
- Images can be offered as friendly-named downloads. A new resource download endpoint serves a file
  as `<owner-slug>-<id>.<ext>` (e.g. `red-toyota-corolla-4831.jpg`) instead of the id-based name it
  is stored under, resolving the name from the owner — listing title, page title or user display
  name — for listing images, page images, avatars and ownerless resources alike. Themes link to it
  with `osc_resource_download_url()`; inline display is unchanged. A private (signed-URL) bucket
  redirects to a short-lived signed URL rather than being proxied.

### Security

- Sign-in attempts are rate limited. Nothing had ever bounded password guessing — no counter, no
  delay, no lockout. Failed sign-ins and reset requests are now counted per address and per account
  over a rolling window (20 and 10 per 15 minutes by default) and refused before any password is
  hashed, so a rejected attempt costs almost nothing where it used to buy a bcrypt hash. Attempts
  are recorded against the name as typed, so the limiter cannot be used to discover which accounts
  exist. Configurable under Settings → Spam and bots, including an off switch and a reset button.
  Adds a `t_login_attempt` table on upgrade.
- The sign-in and reset forms no longer reveal which usernames and e-mail addresses are registered.
  "The user doesn't exist" and "The password is incorrect" have become one answer, and both paths
  now do the same work — the missing-user case used to return about thirty times faster, so a
  stopwatch answered what the message didn't.
- The database layer moved onto a parameterised query API. Every core model binds its values
  instead of interpolating them. The audit behind that migration found and fixed several SQL
  injection vectors, including one reachable from anonymous public search.
- CSRF and remember-me were rebuilt as stateless HMAC-signed tokens backed by a per-install key
  rather than the session, so anonymous pages stay cacheable. Reset and activation codes are
  single-use and stored hashed; the remember-me cookie is HttpOnly, Secure over HTTPS and SameSite,
  and changing a password revokes it everywhere.
- The installer carries a nonce on every state-changing step, closing a hole that could finalise an
  install with no admin account. It also re-validates the admin e-mail and username server-side,
  escapes everything it echoes, and no longer reflects passwords back into the page source.
- Fixed a stored XSS in the admin search-alerts list, and escaped remaining user-controlled output
  across the admin datatables, the statistics widgets and the comment editor.
- Watermark uploads are validated by content rather than by the filename the client sent.
- Added the missing CSRF check on the `upgrade_db` action.
- Search-alert subscription can require a logged-in user, to curb anonymous e-mail harvesting.

### Performance

- Logging in took about 1.4 seconds, nearly all of it one password check: the bcrypt cost had been
  15 since 2014. It is now 12, about 175ms, still well above the recommended floor. Existing
  passwords keep working and are re-hashed on next login. Override with
  `define('BCRYPT_COST', 14);`.
- Listing statistics no longer grow without bound. `t_item_stats` kept a row per listing per active
  day, carried seven indexes and was never pruned, so it became the largest and hottest table on a
  busy site — and every reader had to sum a listing's whole history to get one number. It now keeps
  one row per listing with a small site-wide daily rollup for the charts. Six indexes are gone and
  existing data is migrated across.
- Anonymous browse and search pages are cacheable. Sessions and CSRF tokens are created lazily, so
  a first-time visitor gets no session cookie and no no-cache headers. Search alerts were made
  stateless so result pages don't need a session either.
- Listing search uses an exact, cheap `COUNT(*)`, the per-item resource N+1 on listing pages is
  batched, `User::findByPrimaryKey` is cached, and query logging is gated behind `OSC_DEBUG_DB`.

### Changed

- Rebranded from Osclass to Shopclass, with a new teal identity and a rewritten README.
- Relicensed to GPL-3.0-or-later. Headers carry a GPLv3 notice and dual copyright, retaining the
  Apache-2.0 notice for the Osclass-derived code.
- The admin was rebuilt on a design system ("Workshop Bench") over Bootstrap 5.3 — a collapsible
  sidebar shell, a unified content header, and restyled settings, tables, callouts and messages.
- Crawlers no longer count as listing views, so **view counts will be lower after upgrading**. This
  is the numbers becoming accurate. Views were gated on a list of known browsers, which cannot work:
  a crawler appends to an ordinary browser string, so Googlebot, Bingbot and GPTBot all matched.
  Both counters now use a denylist, extensible via the new `bot_user_agents` filter, and both can be
  reverted under Tools → Cleanup.
- The RSS feed was modernised — a spec-correct image `<enclosure>`, a stable `<guid>`, and correctly
  single-escaped URLs, rebuilt on `DOMDocument` with a new `rss_feed_item` filter. This also fixes a
  stored-injection bug where a description containing `]]>` could break out of the feed's CDATA.
- Captcha verification now uses an 8-second timeout so a stalled provider cannot hang a login, and
  the admin warns when a provider is selected but its keys are empty.
- Listings row actions restyled: "Show more" became a compact overflow menu, and the
  "opens in a new tab" hint reveals on hover or focus.
- The statistics charts and the login screen are on-brand and follow dark mode.
- Retired the unused `t_keywords` table, a legacy search index nothing populated or read. A
  migration drops it on upgrade.
- Build toolchain moved from Grunt to `sass-embedded` + `esbuild` (`npm run build`).
- The object cache gained an atomic `osc_cache_increment()` — native on the memcached and APCu
  drivers, with a get/set fallback elsewhere — so a lock-free counter cannot clobber itself under
  concurrent requests.
- The retired APC object-cache driver was removed — APC was superseded by OPcache + APCu and is
  absent on the PHP 8 floor, so it only ever fell through to the per-request default. An `OSC_CACHE`
  naming an unknown driver now falls back to that default instead of erroring.
- The render-time item view increment is wrapped in a `count_view_on_render` filter (default
  unchanged), so a theme that counts views client-side (a beacon) can turn off render-time counting
  without disabling the counter.
- The chosen interface language is kept in its own cookie instead of the session, so switching
  language no longer starts a session and a language-switched anonymous page stays cacheable.
- Signing in no longer needs a server-side session. Front-end login carries identity in an
  HMAC-signed cookie — bound to the account's password hash, so a password change still invalidates
  every outstanding login — rather than `$_SESSION`, while the historical `Session::_get('userId')`
  readers keep working through a request-scoped shim. A logged-in request now holds no session, so
  pages stay reverse-proxy cacheable and the site runs across several app servers without sticky
  sessions; "remember me" only controls how long the cookie lives (a non-persistent login gets a
  short-lived, browser-session cookie). Existing sessions and remember-me cookies keep working across
  the upgrade, and admin sessions are unchanged.
- Flash messages are carried in a short-lived, signed cookie instead of the session, so setting or
  showing one no longer starts a session — post-action pages stay reverse-proxy cacheable. The cookie
  is HMAC-signed because a flash is echoed as HTML; a tampered value renders nothing.
- Form values typed into a listing, contact, register or profile form survive a validation error in a
  short-lived signed cookie instead of the session, so a form that fails validation no longer starts
  one. The `_setForm`/`_getForm` API is unchanged, so no theme needs updating.
- The item-post flood wait and photo-upload staging moved off the session into the database. The
  "wait a moment before posting again" limit is recorded per address in the login-throttle ledger,
  which — unlike the old session/cookie counter a visitor could reset by clearing cookies — is
  enforceable and correct across app servers. Photos uploaded to a listing form before it is saved
  are tracked in a new table keyed by an unguessable per-form cookie. With these, the front end now
  holds no session for browsing, forms or posting; only the admin and the installer still use one.

### Fixed

- No more deprecation notices on PHP 8.4 or 8.5. Sixteen signatures relied on implicitly nullable
  parameters and seven casts used the old `(boolean)`/`(double)` spellings. Nothing behaves
  differently; on a site with error logging on, each was a notice per file load.
- The "Search alerts" setting under Settings → Spam and bots could not be saved — the form posted an
  action the page never routed.
- The "most viewed" list ignored whether a listing was visible, so blocked, unactivated, spam and
  expired listings could be advertised as the site's most popular. It also ordered by an arbitrary
  single day's views rather than the total.
- The General Settings page fataled under PHP 8 when the site had never checked for updates.
  "Last checked on" now shows "never".
- Sitemap XSL stylesheets are served as `text/xsl`, so browsers render the readable sitemap view
  instead of downloading the file.
- The admin "reported listings" counts are correct now — a listing reported on several days used to
  be counted, and offered for deletion, once per day.
- Image compression actually compresses. PNG/GIF used level 0 (uncompressed) and JPEG quality
  depended on whether GD or ImageMagick was installed. Both are now unified and tunable via the
  `image_png_compression` and `image_jpeg_quality` filters, and ImageMagick output is stripped of
  metadata.
- The final step of an environment-only install could 503 because the location endpoint loaded
  `config.php` directly instead of resolving the database from the environment.
- An environment-only install could fail to boot with "Undefined constant WEB_PATH"; the site URL is
  now derived from the request as a fallback.
- The installer created the database as `utf8` while the rest of the schema is `utf8mb4`.
- The installer could fail to render when the object-cache helper registered hooks before the plugin
  API had loaded.
- Friendly URLs no longer fall through to the home page for item, category, contact and search URLs.
- `Item::updateExpirationDate()` emitted a malformed UPDATE and silently never changed the date.
- A user's listing count was not incremented when items were reassigned after an e-mail change.
- Corrected an invalid `version` string in `composer.json` that broke every Composer command.
- Item title sanitisation and locale handling in the Item model.
- A 500 error on the update check (`check_version`).
- Storage settings keep the S3 provider you selected. The provider dropdown was a display-only
  prefill helper, so the form always reopened on "Amazon S3" no matter which backend was configured.
- A provider that locks its region (Cloudflare R2's `auto`) no longer saves a blank region — the
  field was rendered disabled, and browsers never submit a disabled field.
- The "Better S3 plugin is active" storage warning reflects the plugin's real activation state,
  not a leftover preference that persisted after the plugin was disabled.
- Newly uploaded images are reliably queued for offload. The offload hook only queued when the S3
  adapter happened to be registered on that request; the configured remote is now registered at the
  point of use, so an upload is never silently left on local disk.
- Reading an owner's resources no longer fatals when the object cache returns a non-array for the
  key (a colliding or poisoned entry). `Resource::findByOwner()` treats anything that is not an
  array as a cache miss and reads fresh, self-healing past a bad entry — this could otherwise halt a
  bulk avatar migration or break any request that lists an owner's resources.
- The canonical-host redirect (e.g. www → apex) no longer emits a malformed `Location: https://host:/…`
  — a bare `:` with no port — on default-port requests. The port is appended only when it is a real,
  non-default port for the scheme.
- Opening the login page no longer starts a session. It stashed the referer in `$_SESSION` so it
  could return you to the page you came from after signing in, which left even a visitor who never
  logs in carrying a session cookie that defeats reverse-proxy caching; the destination now rides a
  short-lived, HMAC-signed, same-site cookie set only when there is somewhere to return to.
- Posting a listing no longer throws a PHP 8 `TypeError` when the price is non-numeric; the value is
  guarded before it is scaled.
- The admin login page, the admin auth gate (which remembers the page an unauthenticated admin was
  heading to) and the "only registered users can post" bounce no longer start a session to remember
  where to return — the destination rides the same short-lived, signed, same-site cookie the
  front-end login page already uses.
- The "remove photo" button on the listing form now unlinks the temporary upload immediately instead
  of leaving it for the hourly cron — it had recorded the file under its pre-rotate name while the
  uploader deletes by the rotated one.
- Publishing a listing no longer cascades into a burst of foreign-key errors and an empty
  `posted_item` hook when the parent insert produces no row. `ItemActions::add()` now checks the new
  id immediately after the insert and, if it is missing, logs the failure and returns a clean "could
  not be saved" message before any of the child inserts (locales, location, resources, meta, stats)
  run, instead of FK-failing all five against a non-existent item.
- The intermittent cause of that is fixed at the source: a new row's id is now captured from the
  insert statement itself (new `DAO::insertGetId()`) instead of a later, decoupled read of the shared
  connection's `insert_id`, which any other statement on the handle (transaction control, a form or
  widget save, a plugin query) could reset to 0. This covers listing, comment, resource and user
  creation and the admin location/field forms — each now attaches its child rows to the real id
  rather than to 0.
- A database transaction left open at the end of a request — a `begin()` with no matching
  `commit()`/`rollBack()` (an escaped exception, a plugin's raw `osc_db_begin`, or mismatched
  nesting) — is now rolled back explicitly and logged, instead of being discarded silently when the
  connection closes and taking every write since the `begin` with it, unlogged. Connections also
  start in a known autocommit state so no request can inherit a stray transaction from another.
- The "a new version is available" admin notice could get stuck on an older release when prerelease
  updates are enabled. The check assumed the releases feed was newest-first and took the first entry;
  the feed is not ordered that way, so a newer release listed lower down was never offered. It now
  picks the highest version across the whole feed, so the newest available build is always the one
  offered.
- A failed update check (GitHub unreachable or rate-limited) no longer counts as a check: previously
  the error response was mistaken for a valid release, which reset the once-a-day timer and could
  hold the "last checked" time and notice on stale data for a full day. A failed check now keeps the
  last known-good result untouched and simply retries about an hour later.

Source: https://github.com/mindstellar/shopclass

## Osclass 5.2.0

- New: Added support for PHP 8.0+ to 8.3
- New: MySQL 8 support
- Fixed: [#462](https://github.com/mindstellar/Osclass/issues/462)
- Fixed: Multiple reported security issues
- Fixed: Assorted bug fixes and performance improvements

Source: https://github.com/mindstellar/Osclass
