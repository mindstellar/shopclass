# Market — a GitHub-native plugin & theme ecosystem

Status: **Phase 0 in progress.** Phases 1-6 not started.
Scope: two new GitHub repositories (`mindstellar/shopclass-plugins`,
`mindstellar/shopclass-themes`), the static catalog they publish, and the core
code that browses, installs, and updates from it.

The author-facing contract — header fields, compatibility semantics, manifest
schema, artwork, security requirements, deprecation policy — is specified in
**`docs/PACKAGE-SPEC.md`**. This document covers the system; that one covers what
a package must look like. Where they overlap, PACKAGE-SPEC is authoritative.

The goal is an ecosystem where **GitHub is the whole backend** — no market
server, no API keys, no accounts, no database anywhere but the site's own. Every
moving part is a repository, a pull request, a release asset, or a static JSON
file on a CDN.

---

## 1. Current state (grounded)

The market layer that shipped with Osclass 3.x is present but **completely
dead**, and two of the three code paths that would replace it are broken.

**The legacy market is hard-disabled.**
`_get_market_url()` (`oc-includes/osclass/utils.php:1319`) takes `$disable = true`
and returns `false` on the first line; so does `_need_update()`
(`utils.php:1361`). Every caller — `osc_check_plugin_update()`,
`osc_check_theme_update()`, `osc_check_language_update()` — therefore always
returns `false`. `_osc_check_plugins_update()` (`functions.php:595`) and
`_osc_check_themes_update()` (`functions.php:664`) loop over installed packages,
call those functions, and unconditionally write `plugins_to_update = []` /
`themes_to_update = []` into preferences. The admin toolbar badge and the
"There's a new update available" link in `CAdminPlugins.php:338` can never fire.

**The per-package GitHub path is broken.**
`mindstellar\upgrade\Plugin::getPackageInfo()` and `Theme::getPackageInfo()`
build a `$package_info` array and **never return it** — the functions fall off
the end returning `null`. Both also branch on
`stripos($json_url, 'api.github.com') === true` (`Plugin.php:75`,
`Theme.php:75`), and `stripos()` returns `int|false` — never `true` — so the
GitHub arm is unreachable even if the return existed. Nothing in the tree calls
either method today.

**Only the core self-updater actually works.**
`upgrade\Osclass::getPackageInfo()` polls the GitHub Releases API directly,
scans all releases for the highest version, caches the payload in
`update_core_json`, and honours a 24h clock with a 1h retry on failure
(`CAdminAjax.php:843`). `Upgrade` + `UpgradePackage` then download, unzip, sync
over the target directory behind a `.maintenance` flag, and run
`afterProcessUpgrade()`. That machinery is sound and is the substrate to build
on — but it has no checksum verification, and its compatibility check is
`in_array($this->osclass_version, $this->a_compatible)`
(`UpgradePackage.php:203`): an **exact string match** against a CSV, so a
package declaring `6.0.2` is judged incompatible with 6.0.3.

**Installation is upload-only.** `plugins/add.php` and `appearance/add.php` are
a single `<input type="file">` posting a zip to `add_post`, which calls
`osc_unzip_file()` straight into `oc-content/plugins|themes`. There is no
browse, no search, no discovery of any kind.

**Screenshots assume a file that may not exist.**
`appearance/index.php:71` and `:103` hardcode
`oc-content/themes/<slug>/screenshot.png` into an `<img src>`. A theme without
that file renders a broken image. Plugins have no icon concept at all.

**No compatibility metadata exists.** Plugin headers parse
`Plugin Name / Plugin URI / Plugin update URI / Support URI / Description /
Version / Author / Author URI / Short Name` (`Plugins.php:225-278`); theme
headers parse an equivalent set (`WebThemes.php:104-160`). Neither carries a
minimum or tested core version, or a PHP floor.

**There is a deprecation-reporting substrate already.**
`mindstellar\utility\Deprecate` fires `d_function_run`, `d_hook_run`, and
`d_file_included` hooks and raises `E_USER_DEPRECATED` under `OSC_DEBUG`. This
is exactly what the PR-time "warn, don't fail" requirement needs — no new
instrumentation.

---

## 2. Shape of the ecosystem

```
 mindstellar/shopclass-plugins ──┐                     ┌── PR: validate changed package only
 mindstellar/shopclass-themes  ──┤   GitHub Actions ───┤   merge: build zip + tag + Release
 mindstellar/theme-bender      ──┘                     └── cron/merge: rebuild catalog
 (+ any external author repo)
                                          │
                                          ▼
                    Static catalog on GitHub Pages (v1/*.json)
                    mirror: raw.githubusercontent.com/<repo>/catalog/v1/*.json
                                          │
                          conditional GET, once/day, ETag
                                          ▼
                    Shopclass core ── mindstellar\market\Catalog
                                   ── Browse / Install / Update UI
```

Two source models, one output. A package either lives **inside** the registry
monorepo (`plugins/<slug>/`) or **outside** it in the author's own repo,
registered by a one-file manifest. The catalog builder resolves both to the same
entry shape — `download_url` + `version` + `sha256` — so **core never learns the
difference**. That escape hatch matters: `theme-bender` is already its own
upstream repo with its own release cadence, and third-party authors will not
hand over their source tree.

### Why static JSON and not the GitHub API

Core must not call `api.github.com` per installed package. Unauthenticated
GitHub API is 60 req/h/IP — on shared hosting that is a shared budget, and a
site with 15 plugins burns it on one update check. The catalog collapses **all**
update checks into a single conditional GET of one gzipped file served from a
CDN, with no token, no rate limit, and a 304 on the common path. The GitHub API
is called only by CI, which has a token and a generous budget.

---

## 3. Repository layout

Both repos share a structure; `shopclass-themes` swaps `plugins/` for `themes/`
and adds a mandatory `screenshot.png`.

```
shopclass-plugins/
  plugins/
    <slug>/                  # exactly as it deploys to oc-content/plugins/<slug>
      index.php              # header block — the source of truth for name/version
      shopclass.json         # catalog metadata the header block cannot carry
      assets/
        icon.svg | icon-256.png
        screenshot-1.png … screenshot-4.png   (optional for plugins)
      README.md              # long description, rendered on the detail screen
      CHANGELOG.md           # parsed into per-version notes
      LICENSE
      .distignore            # excluded from the built zip
  external/
    <slug>.json              # registration for a package hosted elsewhere
  schema/
    package.schema.json      # JSON Schema for shopclass.json
    external.schema.json
  tools/                     # validators, catalog builder (PHP + node)
  .github/workflows/
    pr-validate.yml
    release.yml
    catalog.yml
  CONTRIBUTING.md
```

### 3.1 `shopclass.json` (in-repo package manifest)

The header block in `index.php` stays authoritative for what **core** reads at
runtime (name, version, URIs) — it must, because that is what an installed
package exposes on disk. `shopclass.json` carries what a header line cannot: the
catalog-facing metadata.

```jsonc
{
  "$schema": "../../schema/package.schema.json",
  "slug": "better-s3",                 // MUST equal the directory name
  "type": "plugin",
  "categories": ["storage", "media"],  // from a fixed vocabulary
  "tags": ["s3", "cdn", "offload"],
  "short_description": "Offload uploaded images to any S3-compatible bucket.",
  "icon": "assets/icon.svg",
  "screenshots": [
    { "src": "assets/screenshot-1.png", "caption": "Bucket settings" }
  ],
  "support": { "issues": "https://github.com/…/issues", "docs": "https://…" },
  "funding": "https://github.com/sponsors/…",
  "license": "GPL-3.0-or-later"
}
```

### 3.2 `external/<slug>.json` (package hosted in its own repo)

```jsonc
{
  "slug": "bender",
  "type": "theme",
  "source": { "kind": "github-release", "repo": "mindstellar/theme-bender" },
  "asset_pattern": "^bender_.*\\.zip$",
  "categories": ["general"],
  "short_description": "The bundled default theme.",
  "icon": "https://raw.githubusercontent.com/mindstellar/theme-bender/main/screenshot.png"
}
```

The catalog builder fetches that repo's releases, picks the matching asset,
downloads it, reads the header block **out of the zip** (so name/version/
compatibility come from the real artifact, never from a hand-edited claim),
computes the sha256, and emits an entry identical in shape to a monorepo one.

---

## 4. Compatibility metadata

New header lines, WordPress-shaped because plugin authors already know them.
Parsed by `Plugins::getInfo()` and `WebThemes::loadThemeInfo()` with the same
`preg_match` style as the existing fields, so nothing about header parsing
changes structurally:

```
Requires Shopclass: 6.0.0     # minimum core version — hard gate
Tested up to: 6.1             # highest core version verified — soft warning
Requires PHP: 8.0             # hard gate
```

All three are optional. Absent `Requires Shopclass` means "unknown" — treated as
compatible with a muted "compatibility not declared" note, never as a block, so
the thousands of existing Osclass-era packages keep installing.

New class `mindstellar\market\Compatibility`:

| Check | Rule | Result |
|---|---|---|
| `requires` > core version | `version_compare` | **Blocked** — Install/Update disabled, "Requires Shopclass 6.2 or newer (you have 6.0.3)" |
| `requires_php` > `PHP_VERSION` | `version_compare` | **Blocked** — "Requires PHP 8.2 (you have 8.0.30)" |
| `tested_up_to` < core, compared at **minor** precision | `6.0` vs `6.1` | **Warning** — installs, badge reads "Untested with 6.1" |
| `tested_up_to` ≥ core minor | — | **OK** — badge reads "Compatible with 6.0.x" |
| nothing declared | — | Muted "not declared" |

The important consequence is in **update resolution**: the catalog lists every
released version of a package with its own `requires`, and
`Compatibility::pickBestVersion()` returns the highest version whose `requires`
≤ the installed core and whose `requires_php` ≤ the running PHP. A 6.0.x site is
therefore offered the last 6.x-compatible release of a plugin that has since
moved to 7.0 — and is never offered an update that would fatal on boot. This is
the single most valuable thing the catalog does, and the current
`in_array()`-on-a-CSV check (`UpgradePackage.php:203`) cannot express it.

`UpgradePackage::isCompatible()` is rewritten to delegate to `Compatibility`,
keeping the legacy `s_compatible` CSV as a fallback when the new fields are
absent so old third-party update endpoints keep working.

The admin package list and every catalog card render a compatibility badge from
the same evaluation, so "does this support my 6.x?" is answerable at a glance
before install and after.

---

## 5. The catalog contract

Published to GitHub Pages from a `catalog` branch; mirrored at
`raw.githubusercontent.com/mindstellar/shopclass-plugins/catalog/v1/…` for the
case where Pages is unreachable. Path-versioned (`v1/`) so the schema can break
later without breaking old installs.

| File | Purpose | Fetched by core |
|---|---|---|
| `v1/updates.json` | `{slug: [{version, requires, requires_php, tested, url, sha256, size}]}` for **every** package, versions newest-first | Once per 24h, conditional GET |
| `v1/index.json` | Slim browse list: slug, name, short description, author, latest version, icon URL, categories, tags, updated_at | On first open of Browse, then cached 24h |
| `v1/packages/<slug>.json` | Full detail: long description (rendered README), screenshots, per-version changelog, links | Lazily, when a detail view opens |
| `v1/categories.json` | Category vocabulary + counts | With `index.json` |

Efficiency rules core follows:

- **One request per surface, per day.** `updates.json` covers all installed
  packages at once; the per-package `plugin_update_uri` poll (N requests, N
  failure modes) is retained only as a fallback for packages absent from the
  catalog.
- **Conditional GET.** Store the `ETag` and `Last-Modified` in preferences
  (`market_updates_etag`, …) and send `If-None-Match`. A 304 costs ~200 bytes
  and short-circuits everything.
- **Failure never resets the clock.** Reuse the pattern already proven in
  `CAdminAjax::scheduleUpdateCheckRetry()` — on a failed fetch, schedule a 1h
  retry and leave the cached payload and its badge untouched, rather than
  marking the day checked.
- **Never fetch on a front-end request.** Catalog traffic happens only from the
  admin footer poll or the CLI, never on a public page render — the HTTP caching
  contract in `docs/CACHING.md` depends on public pages doing no egress.
- **`index.json` is filtered client-side.** The browse grid searches, tags, and
  sorts in the browser over the cached slim index; there is no server round-trip
  per keystroke and no search endpoint to build.

New CLI verbs on `oc-cli.php`, so headless and container installs get the same
capability: `market:refresh`, `market:search <q>`, `market:install <slug>`,
`market:update [slug|--all]`, `market:info <slug>`.

---

## 6. Pull-request validation (the contribution gate)

`pr-validate.yml`, triggered on `pull_request`. **Not** `pull_request_target` —
that would run untrusted contributor code with repository secrets in scope. The
workflow gets no secrets; the runtime jobs run in a container with none.

### 6.1 Only the changed package

```yaml
jobs:
  changed:
    outputs:
      packages: ${{ steps.detect.outputs.packages }}   # JSON array of slugs
    steps:
      - uses: actions/checkout@v7
        with: { fetch-depth: 0 }
      - id: detect
        run: tools/changed-packages.sh "origin/${{ github.base_ref }}"
  validate:
    needs: changed
    if: needs.changed.outputs.packages != '[]'
    strategy:
      fail-fast: false
      matrix:
        package: ${{ fromJSON(needs.changed.outputs.packages) }}
```

`changed-packages.sh` diffs `origin/<base>...HEAD`, maps each path to its
`plugins/<slug>` (or `themes/<slug>`) prefix, dedupes, and emits a JSON array.
A PR touching only `README.md` at the repo root produces `[]` and the matrix is
skipped — green, with a note, not a false pass on a real change.
`concurrency: pr-${{ github.event.number }}` with `cancel-in-progress` so a
force-push does not queue duplicate runs.

### 6.2 What each package is checked for

Ordered cheapest-first so an obvious mistake fails in seconds.

| # | Gate | Failing? | What it does |
|---|---|---|---|
| 1 | **Structure** | ❌ fail | Slug matches directory and `^[a-z0-9][a-z0-9-]{1,40}$`; required files present (`index.php`, `shopclass.json`, `README.md`, `LICENSE`; themes also `screenshot.png` ≥ 1200×900); no symlinks, no `.git`, no `node_modules`, no `.exe/.dll/.so`; unpacked size ≤ 15 MB; single file ≤ 5 MB |
| 2 | **Manifest** | ❌ fail | `shopclass.json` validates against `schema/package.schema.json`; categories drawn from the fixed vocabulary; icon/screenshot paths resolve |
| 3 | **Header** | ❌ fail | Parsed with the **same regexes core uses** (see §6.4); `Version:` is semver-ish, is **greater than** the version currently in the catalog, and matches the changelog's newest entry |
| 4 | **Compatibility** | ❌ fail / ⚠️ warn | `Requires Shopclass` ≤ latest released core (fail if it names a version that does not exist); `Tested up to` must be a real released core version; **warn** if `Tested up to` is more than one minor behind current core |
| 5 | **Parse** | ❌ fail | `php -l` across 8.0 – 8.5, mirroring `tests/php-lint.sh` |
| 6 | **PHP floor** | ❌ fail | PHPCompatibility `--runtime-set testVersion 8.0-` |
| 7 | **Deprecated API** | ⚠️ **warn only** | §6.3 |
| 8 | **Security scan** | ❌ fail / ⚠️ warn | Fail on `eval(`, `assert(` on a string, `system/exec/shell_exec/passthru/proc_open`, `base64_decode` of a >200-char literal, `create_function`. Warn on raw `$_GET/$_POST/$_REQUEST` (should use `Params`), state-changing POST handlers with no `osc_csrf_check()`, `echo` of un-escaped request data |
| 9 | **Smoke install** | ❌ fail | §6.5 |
| 10 | **Style** | ⚠️ warn | php-cs-fixer PSR-12 dry-run as annotations. Third-party house style must not block a contribution |

Every gate writes GitHub annotations (`::error file=…,line=…` /
`::warning file=…,line=…`) so findings land inline on the diff, and a final job
posts one **sticky** PR comment (updated in place, never a new comment per push)
summarising ✅ / ⚠️ / ❌ per gate with counts.

### 6.3 Deprecated-function detection — warn, never fail

Two layers, both reporting as warnings.

**Static.** Core publishes a machine-readable inventory of its own deprecations.
A new script in *this* repo (`scripts/gen-deprecated-api.mjs`, wired into
`build.yml`) scans for `Deprecate::deprecatedFunction(…)`,
`deprecatedRunHook(…)`, `deprecatedApplyFilter(…)`, `deprecatedFile(…)` calls
and `@deprecated since` docblocks, and emits `deprecated-api.json`:

```jsonc
{ "core_version": "6.0.3",
  "functions": [ { "name": "osc_check_plugin_update", "since": "4.0.0",
                   "replacement": null, "removal": "7.0.0" } ],
  "hooks":     [ … ], "filters": [ … ], "classes": [ … ] }
```

It ships as a release asset and on Pages. The PR workflow downloads the inventory
for the **latest core release**, greps the changed package for each symbol, and
annotates each hit:

> ⚠️ `osc_check_plugin_update()` is deprecated since 6.0 and scheduled for
> removal in 7.0. No replacement.

This makes the warning list self-maintaining: deprecating something in core
automatically starts warning contributors, with no second list to update.

**Runtime.** The smoke-install job (§6.5) loads a tiny collector plugin that
hooks `d_function_run`, `d_hook_run`, and `d_file_included`, and writes every
firing (symbol, replacement, version, backtrace file/line) to a JSON log. After
exercising the plugin, the log is folded into the same warning annotations. This
catches dynamic calls that grep cannot see, and it is free — the hooks already
exist in `Deprecate`.

Both layers are `continue-on-error`-equivalent: they set no failing exit code,
and the sticky comment renders them under a "Warnings — not blocking" heading
with an explicit note that a PR may be merged with them outstanding.

### 6.4 One parser, no drift

The header regexes and the compatibility comparison must not be reimplemented in
the registry repos — a divergence would pass CI and fail on real installs. Core
grows a self-contained, dependency-free validator
(`tools/package-lint.php`, no bootstrap, no DB) that reads a package directory
and prints JSON: parsed header, manifest errors, compatibility verdict. It is
committed here, published as a release asset alongside `deprecated-api.json`,
and **downloaded** by the registry workflows. One implementation, versioned with
core.

### 6.5 Smoke install

Uses the prod Docker image plus the headless installer already in the tree
(`oc-cli.php install --unattended`):

1. `docker compose up` — MariaDB + the `:edge` core image.
2. `oc-cli.php install --unattended` against a throwaway database.
3. Copy the PR's package into `oc-content/plugins/<slug>` (themes:
   `oc-content/themes/<slug>`), plus the deprecation collector.
4. Plugin: `install` → assert no fatal **and no unexpected output** (core already
   classifies that as `error_output` in `Plugins::install()`); `enable`; load the
   admin dashboard, the plugin's configure route if it declares one, the public
   home page and one item page; `disable`; `uninstall`.
5. Theme: activate; render home, search, item, contact, and one user page; assert
   HTTP 200 and no `Fatal error` / `Parse error` in the PHP log.
6. Assert `uninstall` left no orphan `t_*` tables and no orphan preferences under
   the package's own key namespace.
7. Diff the DB schema before/after install+uninstall — a plugin that adds a table
   and does not drop it is a **warning**, not a failure (some deliberately retain
   data), but it must be visible.

Any fatal, any 500, any unexpected output fails the PR.

---

## 7. Release and catalog build

`release.yml`, on push to `main`:

1. Detect packages whose `Version:` header changed versus the previous commit.
2. Build `<slug>_<version>.zip` from the package directory, honouring
   `.distignore` (drops `.github`, tests, sources, `*.map`, `.editorconfig`).
   The zip's single top-level directory is `<slug>/` — the shape
   `Upgrade::processUpgrade()` already expects.
3. Compute sha256; create tag `<slug>-v<version>` and a GitHub Release named
   `<Name> <version>` with the zip and a `.sha256` attached, body taken from the
   package's `CHANGELOG.md` section for that version.
4. Trigger `catalog.yml`.

`catalog.yml`, on release-published and on a daily cron (the cron is what picks
up new releases in **external** repos):

1. Enumerate in-repo packages and `external/*.json` registrations.
2. For each, resolve the newest N releases (GitHub API with the workflow token),
   download each asset, read the header block out of the zip, verify slug and
   version agreement, compute sha256.
3. Render `README.md` to sanitised HTML and split `CHANGELOG.md` per version.
4. Emit `v1/updates.json`, `v1/index.json`, `v1/packages/<slug>.json`,
   `v1/categories.json`; write `v1/manifest.json` with a build timestamp and the
   core version the catalog was validated against.
5. Commit to the `catalog` branch and deploy Pages.

The download URL an install ultimately hits is a GitHub release asset. Core
enforces a **host allowlist** — `github.com`, `objects.githubusercontent.com`,
`*.github.io`, `raw.githubusercontent.com` — so even a compromised catalog
cannot redirect an install to arbitrary infrastructure.

---

## 8. Core implementation

New namespace `mindstellar\market` under `oc-includes/osclass/classes/market/`:

| Class | Responsibility |
|---|---|
| `Catalog` | Fetch + cache + ETag + mirror fallback; `index()`, `detail($slug)`, `updates()`; all writes go to preferences, all failures are non-fatal |
| `Compatibility` | §4 — `evaluate($pkg)`, `pickBestVersion($versions)` |
| `PackageIndex` | Joins catalog entries with installed state from `Plugins::listAll()` / `WebThemes::getListThemes()`; produces the rows both the Installed and Browse screens render |
| `Installer` | Download → **verify sha256** → extract to a temp dir → validate structure and slug → back up the existing directory → atomic move → restore on any failure |

`Installer` wraps rather than replaces `Upgrade`/`UpgradePackage`; the additions
it brings are the three things missing today: checksum verification (nothing
verifies a downloaded zip now — `FileSystem::downloadFile()` at
`FileSystem.php:737` returns the path whatever arrived), staged extraction with
validation before anything touches the live directory, and a rollback backup in
`oc-content/downloads/backups/<slug>-<version>.zip`.

Repairs to existing code, all small and all required:

- `upgrade\Plugin::getPackageInfo()` / `Theme::getPackageInfo()` — add the
  missing `return $package_info;` and delete the unreachable
  `stripos(…) === true` branch, repointing both at `Catalog` with the legacy
  `Plugin update URI` JSON endpoint as fallback.
- `_osc_check_plugins_update()` / `_osc_check_themes_update()`
  (`functions.php:595`, `:664`) — resolve against `Catalog::updates()` instead of
  the disabled `osc_check_*_update()` helpers. Keep those helpers in place,
  returning `false`; they are `@deprecated` public API and removing them breaks
  third-party callers.
- `UpgradePackage::isCompatible()` — delegate to `Compatibility` (§4).
- `Zip` hardening — `isPathValid()` (`Zip.php:76`) only inspects a `../` prefix,
  entries are filtered by substring rather than by resolved real path, symlink
  entries are not rejected, and there is no entry-count or total-size cap
  (zip-bomb). Rewrite to resolve each entry against the destination realpath.

### 8.1 Placeholder thumbnails

Two new helpers, and no template ever builds an image path by string
concatenation again:

```php
osc_theme_screenshot_url($slug);   // themes/<slug>/screenshot.png if it exists on disk
osc_plugin_icon_url($slug);        // plugins/<slug>/assets/icon.(svg|png) if present
```

Both fall back to a core asset — `oc-admin/themes/modern/images/placeholder-theme.svg`
and `placeholder-plugin.svg` — shipped in the zip, so a package with no artwork
still renders a deliberate tile rather than a broken image. The placeholder is a
neutral mark on a surface driven by `var(--osc-*)` / `var(--bs-*)` tokens so it
flips with `data-bs-theme="dark"`, with the package's initial rendered as a text
overlay in the wrapper (not baked into the SVG) and its tint chosen from a hash
of the slug — so a grid of unillustrated packages still reads as distinct tiles.
Catalog cards use the same helpers, so pre-install and post-install artwork
behave identically.

This also fixes the existing defect at `appearance/index.php:71,103`.

### 8.2 Admin UI

Not a new top-level page — tabs on the two screens that already own these
objects, so nothing about the existing information architecture moves:

- **Plugins** → `Installed` (today's table) · `Browse` · `Updates (n)`
- **Appearance** → `Themes` (today's grid) · `Browse` · `Updates (n)`

`Browse` is a card grid rendered from the cached slim index: thumbnail,
name, author, short description, compatibility badge, and one primary action
(`Install` / `Update to 1.4.0` / `Installed`, or a disabled button with the
reason when blocked by §4). Search, category filter, and sort run in the browser
over the cached JSON. A detail dialog — a native `<dialog>`, consistent with the
modernised admin — shows screenshots, the rendered README, the version table
with per-version compatibility, and links to the repo and its issue tracker.

Install and update are POSTs through the existing admin ajax controller with
`osc_csrf_check()`, the `DEMO` guard, and `osc_self_update_disabled()`
(`utils.php:906`) all honoured — container deployments that update by image must
not grow a second, divergent update path. Directory writability is checked
before the button renders, reusing the messaging already in `add.php`.

---

## 9. Security posture

| Risk | Mitigation |
|---|---|
| Malicious package merged | PR gate §6, human review required, no auto-merge; org members only for `external/*.json` registrations pointing outside `mindstellar` |
| Contributor code exfiltrating CI secrets | `pull_request` (not `pull_request_target`); no secrets in the validate workflow; runtime jobs in a container with a throwaway DB |
| Tampered download | sha256 in the catalog, verified before extraction; host allowlist on download URLs; HTTPS with peer verification enforced |
| Poisoned catalog | Catalog is only writable by CI on a protected branch; **Phase 6** adds a minisign/cosign signature over `updates.json` with the public key shipped in core, so a compromised Pages deploy is still rejected |
| Zip slip / zip bomb | `Zip` rewrite (§8) — realpath containment, symlink rejection, entry-count and size caps |
| Half-written install | Staged extraction, atomic move, rollback backup |
| Supply-chain drift between CI and runtime | One shared validator downloaded from core releases (§6.4) |

---

## 10. Phases

Each phase is independently shippable and useful on its own.

**Phase 0 — core prerequisites** (this repo, no ecosystem yet)
`Requires Shopclass` / `Tested up to` / `Requires PHP` headers + parsing;
`Compatibility` class; `isCompatible()` rewrite; placeholder thumbnails + helpers
and the `appearance/index.php` fix; `Zip` hardening; sha256 verification in the
download path; the `Plugin`/`Theme` `getPackageInfo()` repairs; compatibility
badges on the existing Installed screens. **Ships value immediately: a 6.x site
can finally see whether its installed packages claim support for 6.x.**

**Phase 1 — tooling core publishes**
`tools/package-lint.php`; `scripts/gen-deprecated-api.mjs` + `deprecated-api.json`;
both attached to core releases by `build.yml`.

**Phase 2 — repository scaffolding**
Create both repos; schemas, `CONTRIBUTING.md`, category vocabulary, `.distignore`
convention; seed `shopclass-plugins` with the bundled plugins and
`shopclass-themes` with an `external/bender.json` pointing at `theme-bender`.

**Phase 3 — PR validation** (§6) — the gate contributors actually meet.

**Phase 4 — release + catalog build + Pages** (§7) — the catalog goes live and
is browsable by URL before any core code reads it.

**Phase 5 — core catalog client** (§8) — `Catalog`, `PackageIndex`, `Installer`;
update checks repointed; CLI verbs. Update badges start working for the first
time since 3.x.

**Phase 6 — Browse/Install UI** (§8.2), then catalog signing and the
author-facing docs site.

Each phase carries a documentation deliverable and is not complete without it —
see §11.

---

## 11. Documentation deliverables

An ecosystem is only as usable as its documentation: the registry's whole value
proposition is that a stranger can ship a package without asking anyone how. Docs
are therefore a gate on each phase, not a follow-up.

| Document | Lives in | Written in phase | Purpose |
|---|---|---|---|
| `docs/MARKET.md` | core | 0 | This document — system design, kept current as phases land |
| `docs/PACKAGE-SPEC.md` | core | 0 | The package contract. Core's parser, the registry CI, and the catalog builder are all implemented **against this file** |
| `CHANGELOG.md` entries | core | every | Load-bearing format — the release workflow, the admin upgrade screen, and the version tool all parse it |
| Inline API docs | core | 0, 5 | Docblocks on `Compatibility`, `Catalog`, `Installer` and the new `osc_*` helpers |
| `CONTRIBUTING.md` | each registry repo | 2 | Submission walkthrough: fork, add package, what CI runs, how to read the sticky comment, how a release is cut |
| `README.md` | each registry repo | 2 | What the repo is, how to browse it, how to register an externally-hosted package |
| `schema/*.schema.json` | each registry repo | 2 | Machine-readable manifest schema — the enforceable half of PACKAGE-SPEC §7 |
| PR template + issue templates | each registry repo | 3 | Author checklist mirroring the blocking gates |
| `deprecated-api.json` | core release asset | 1 | Generated, not written — the deprecation inventory CI annotates against |
| Migration note for authors | core | 5 | How an existing self-hosted package moves onto the catalog, and why `Plugin update URI` becomes redundant |
| Author guide (rendered) | docs site | 6 | PACKAGE-SPEC and CONTRIBUTING rendered for people who will never read a repo |

Two rules that keep this from rotting:

1. **PACKAGE-SPEC is the single source.** The registry's `CONTRIBUTING.md` links
   to it and does not restate the rules; a validator that disagrees with it is a
   bug in the validator. This is the same discipline as the shared
   `package-lint.php` in §6.4 — one implementation, one specification.
2. **A phase is not done until its row above is written.** Code that ships
   undocumented in this system is code no third party can use, which is the whole
   point of the system.

## 12. Open questions

1. **Do bundled plugins leave core?** Moving `sample-forms`, `sample-widgets`,
   `ghost_fix`, `apiposter`, `better-s3` into `shopclass-plugins` shrinks the
   release zip and dogfoods the pipeline — but they are the reference
   implementations the compatibility contract is measured against, and they are
   currently how a fresh install demonstrates anything. Proposal: mirror them
   into the registry, keep shipping them in the zip through 6.x, revisit at 7.0.
2. **Review capacity.** A PR gate is only as good as the humans behind it. With
   one maintainer, `external/*.json` registration (review a manifest, not a
   codebase) is the sustainable default and monorepo hosting the exception.
3. **Download counts.** Genuinely useful for ranking, and impossible to collect
   without either a call-home from installs or scraping GitHub release
   statistics. The GitHub asset download count is free, imprecise, and requires
   no telemetry — recommend that, and nothing else.
4. **Paid plugins.** Out of scope. The catalog schema leaves room for a
   `"price"` / `"external_purchase_url"` field so a listing can advertise a
   package core does not install, but no commerce belongs in this system.
```
