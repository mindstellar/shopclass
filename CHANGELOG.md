## Update changelog for Osclass 5.3.0 Release Notes {#release-notes-5-3-0}
* New: Admin frontend modernisation — the "Workshop Bench" design system on Bootstrap 5.3, a rebuilt collapsible sidebar app shell, a unified content header, and restyled settings, footer, tables, callouts and flash messages.
* Breaking: The admin frontend is now vanilla JavaScript and no longer loads jQuery, jQuery-UI, jQuery-validate, jQuery-treeview or jQuery-uniform. Every widget was rewritten with native browser APIs — tabs (ARIA), modals (native `<dialog>`), autocomplete (ARIA combobox), the datepicker (`<input type="date">`), the category treeview, tooltips and form validation. These scripts stay *registered*, so a plugin that still relies on them must now enqueue them itself (e.g. `osc_enqueue_script('jquery')`); previously the admin loaded jQuery on every page. (The bundled item add/edit and listing pages still enqueue jQuery because the core `ItemForm` category/location/photo JavaScript has not been migrated yet.)
* New: Rebuilt the admin Categories manager — a proper tree with drag-to-reorder and drag-to-nest, inline subcategory and listing counts, an Enabled/Disabled status pill, a slide-over drawer editor, and a delete confirmation that spells out how many subcategories and listings will be removed. Rewritten in modern vanilla JS (no jQuery).
* Changed: Listings row actions restyled — the "Show more" link became a compact overflow (meatball) menu, and the "opens in a new tab" indicator now reveals on hover or focus.
* Changed: The statistics charts and the login screen are now on-brand and theme-aware, following dark mode.
* Fixed: Friendly URLs (permalinks) — item, category, contact and search URLs no longer fall through to the home page.
* Security: Escaped remaining user-controlled output across the admin datatables (listings, users, comments, ban rules), the statistics widgets and the comment editor, and markup is stripped when saving an edited comment.
* New: Per-admin dark/light theme toggle, persisted server-side, with colour routed through design tokens so the theme can move. Dark mode is now complete — every admin component reads a token and flips with `data-bs-theme`.
* New: The admin mirrors under `dir="rtl"` — all component CSS uses logical properties (`inline-start/end`, logical corner radii) and Bootstrap's directional utilities are redefined logically, so RTL locales get a correct layout without a separate stylesheet.
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