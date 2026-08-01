# Changelog

Older releases are archived in [ChangelogHistory.txt](ChangelogHistory.txt).

## Shopclass 5.4.0

First release candidate for the 5.4.0 line, after an extended run on production. Public read pages
are now reverse-proxy/CDN cacheable out of the box, search gains a pluggable backend and a sharper
built-in matcher, a new command-line interface covers maintenance tasks, and a round of hardening
closes gaps in core's network fetches and saved-search alerts.

### New

- Cache-safe listing view counts. A JS beacon (an uncached POST) now counts views instead of
  render time, so counts stay accurate behind a full-page cache and non-JS crawlers stop inflating
  them. Toggle via the `item_view_beacon` preference or `item_view_beacon_enabled` filter.
- Core-owned HTTP caching. Public read pages emit `public, s-maxage=30, must-revalidate` (all else
  `private, no-store`), so a reverse proxy or CDN can cache them with no plugin. Filterable via
  `public_cache_max_age` and `response_cache_control`.
- The cache decision ignores third-party analytics/ad cookies, keying only on Shopclass's own
  login, session and locale cookies; a personalized page is never stored or shared.
- Reference nginx micro-cache config in `.docker/nginx/microcache.conf` — bypasses on Shopclass's
  cookies, respects the app's Cache-Control, with no hardcoded route lists to drift.
- Pluggable search backend. A `search_results` filter lets a plugin answer a search from an
  external engine (Manticore, Elasticsearch, …); return `null` and core's MySQL search runs
  unchanged.
- Sharper built-in search. MySQL search now requires every word, matches prefixes, and honours
  `"quoted phrases"` and `-excluded` terms, ranks title matches above description, and falls back to
  substring for short queries. Adds a title `FULLTEXT` index (one-time `ALTER TABLE`).
- Command-line interface (`oc-cli.php`) for maintenance: `cron`, `db:upgrade`, `cache:flush`,
  `sitemap:warm`, `user:create-admin`, `user:reset-password`, and a `doctor` health check. Refuses
  non-CLI access; the legacy `php index.php -p cron -t hourly` still works.
- Themes can register routes to their own controllers — `osc_add_route()` now resolves a file in the
  theme root, not just the plugins directory.
- New model events: `updateLocaleForce()` fires `item_content_updated` and `updateExpirationDate()`
  fires `item_expiration_updated`, so a cache or index can react to direct-model writes.
- `osc_add_route_hook($id, $regexp, $url)` registers a controller-dispatched route — for an endpoint
  that acts and redirects rather than rendering.
- `Search::fromPrimaryKeys(array $ids)` hydrates an externally-produced match set through core's row
  fetch, paging to the id count (no silent 10-row truncation) and preserving the caller's ranking.
- A `search_results` backend can own the whole page by returning a `model` key, so premiums and
  `osc_search()` run against it; the search model is now exported on every search page.
- The post-publish redirect is filterable via `item_post_redirect_url` (passed the new listing's id
  and category).
- `ItemForm::category_select()` accepts an optional `$attributes` array (values escaped) for
  `required` / `data-*` without string-injecting core markup.

### Breaking

- Removed the unused `INSTANT` alert frequency — core never dispatched it. Its mail builder, the
  `hook_alert_email_instant` hook and the `alert_email_instant` template are gone (an upgrade
  deletes the dead template); `osc_runAlert('INSTANT')` is now a no-op.

### Security

- Core HTTP fetches now verify the peer's TLS certificate by default. `osc_file_get_contents()` had
  forced `verify_ssl=false`, so every core fetch — including `install_locations()`, which runs the
  SQL it downloads — ran unauthenticated. It now defaults to `true`, caps redirects, pins to
  HTTP(S), and aborts stalled transfers.
- The saved-search alert endpoint no longer trusts a caller-supplied `userid`. An anonymous request
  could activate a recurring alert on any user's account, skipping confirmation; the owner now comes
  from the session only.

### Performance

- Auto-cron self-requests are throttled to at most once per 5 minutes instead of firing on every
  page view, so a busy site no longer spawns an FPM worker per hit.

### Fixed

- `Plugins::hasHook()` now reports whether a hook still has a listener, not merely whether one was
  ever registered — an emptied priority bucket used to read as true forever.
- Saved-search alerts no longer stop firing mid-cron. The reused `Search` instance never cleared its
  keyword pattern, so the first keyword alert poisoned every keyword-less alert after it; restore now
  resets the pattern state.
- The alert cron isolates each saved search in a try/catch. `d_last_exec` is advanced before alerts
  are sent, so an uncaught error mid-run used to drop every remaining subscriber permanently; one bad
  search now costs one alert, not the rest of the run.
- Saved-search alerts match the same listings on replay as when saved — `toJson()` double-escaped the
  pattern, drifting short-keyword results. Alerts saved before the fix are repaired on replay.
- `osc_route_url()` emits `page=route` (not `page=custom`) for controller routes, so a fileless route
  no longer 404s when rewrite is disabled.
- Renewing a listing with no location row no longer warns and mis-moves the counters —
  `Item::updateExpirationDate()`'s null guard now covers the whole block.

Source: https://github.com/mindstellar/shopclass
