## Update changelog for Osclass 5.3.0 Release Notes {#release-notes-5-3-0}
* New: Admin frontend modernisation — the "Workshop Bench" design system on Bootstrap 5.3, a rebuilt collapsible sidebar app shell, a unified content header, and restyled settings, footer, tables, callouts and flash messages.
* New: Per-admin dark/light theme toggle, persisted server-side, with colour routed through design tokens so the theme can move.
* New: Versioned database migration system — an ordered, ledger-backed (`t_migration`) runner for schema and data changes the existing struct.sql reconciler cannot express (column/table drops, renames, data backfills). Runs forward-only and fail-fast, after the reconcile, on upgrade.
* New: Schema-drift CI check that proves a fresh install and an upgraded install converge to the same database schema.
* Changed: Build toolchain moved from Grunt to `sass-embedded` + `esbuild` (`npm run build`).
* Changed: Minimum PHP raised to 8.0.
* Fixed: Added a missing CSRF check to the `upgrade_db` admin action.
* Fixed: Item title sanitisation (HTML-entity handling and allowed punctuation) and locale handling in the Item model.
* Performance: Anonymous browse and search pages are now cacheable — sessions and CSRF tokens are created lazily (only on the first write, or when a page renders a protected form), so a first-time visitor receives no session cookie and no no-cache headers. Search alerts were made stateless (a persistent per-install key) so search-result pages no longer need a session either.
* Performance: Listing search uses an exact, cheap `COUNT(*)`; the per-item resource N+1 on listing pages is batched away; `User::findByPrimaryKey` is cached and query logging is gated behind `OSC_DEBUG_DB`.
* New: `memcached` object-cache driver (the legacy `memcache` driver is now deprecated); item caches are invalidated on edits, uploads and deletes.
* New: Item reporting is gate-able — an `item_mark` filter can veto a report (for per-reporter dedup, rate-limiting or a captcha) and an `item_marked` action fires after it counts. The stale user-agent allowlist that silently dropped reports from browsers such as Firefox was removed. Bulk enable/disable-by-category and location-cascade deletes now fire item-lifecycle hooks.
* New: `osc_csrf_token_form()` helper to emit CSRF token fields explicitly, complementing `osc_csrf_token_url()`.
* Security: Hardened CSRF — cryptographically secure token generation (`random_bytes` / `hash_equals`) and `SameSite=Lax` on the session cookie.
* Security: Fixed a stored XSS in the admin search-alerts list — the attacker-controlled report email and search pattern are now escaped.
* Security: Watermark uploads are validated by content (`getimagesize` MIME) rather than a client-supplied filename.
* Security: Search-alert subscription can optionally require a logged-in user, to curb anonymous e-mail harvesting.
* For more details, please check the commit history
Source: https://github.com/mindstellar/Osclass

## Update changelog for Osclass 5.2.0 Release Notes {#release-notes-5-2-0}
* New: Added support for PHP 8.0+ to 8.3
* New: Mysql8 support 
* Fixed: [#462](https://github.com/mindstellar/Osclass/issues/462)
* Fixed: Multiple security issues as reported
* Fixed: Other Multiple bug fixes
* Fixed: Multiple performance improvements
* For more details, please check the commit history
Source: https://github.com/mindstellar/Osclass