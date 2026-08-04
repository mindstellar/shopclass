# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

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

- Category dropdowns list sub-categories again — the nested `select()` option builder recursed with
  the child array as the selected value and an integer as its options, collapsing each parent's
  children into a single empty option. Affects the admin category parent picker and any nested select.
- Checkbox custom fields render as translatable Yes/No text instead of a broken tick/cross image that
  only the old Bender theme shipped, so every other theme showed a missing image. Overridable via the
  `item_meta_checkbox_value` filter.
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
