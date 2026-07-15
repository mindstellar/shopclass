## Update changelog for Osclass 5.3.0 Release Notes {#release-notes-5-3-0}
* New: Admin frontend modernisation — the "Workshop Bench" design system on Bootstrap 5.3, a rebuilt collapsible sidebar app shell, a unified content header, and restyled settings, footer, tables, callouts and flash messages.
* New: Per-admin dark/light theme toggle, persisted server-side, with colour routed through design tokens so the theme can move.
* New: Versioned database migration system — an ordered, ledger-backed (`t_migration`) runner for schema and data changes the existing struct.sql reconciler cannot express (column/table drops, renames, data backfills). Runs forward-only and fail-fast, after the reconcile, on upgrade.
* New: Schema-drift CI check that proves a fresh install and an upgraded install converge to the same database schema.
* Changed: Build toolchain moved from Grunt to `sass-embedded` + `esbuild` (`npm run build`).
* Changed: Minimum PHP raised to 8.0.
* Fixed: Added a missing CSRF check to the `upgrade_db` admin action.
* Fixed: Item title sanitisation (HTML-entity handling and allowed punctuation) and locale handling in the Item model.
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