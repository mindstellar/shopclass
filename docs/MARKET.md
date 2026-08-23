# Market — a GitHub-native plugin & theme ecosystem

Status: **Live**, shipped in Shopclass 6.1.0. Both registries are public, their catalogs
are served from GitHub Pages, and core browses, installs, and updates packages from them.
Catalog signing (§9) is scoped but not built.

Scope: two GitHub repositories (`mindstellar/shopclass-plugins`,
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

## 1. Background: what this replaced

Skip this section if you only want the design — §2 onward is self-contained. It is here
because several classes exist specifically to repair a named defect below, and the dead
code they replaced is still in the tree; without this, that code looks inexplicable.

Everything in this section describes the state the market subsystem was in before this
system was built. It explains why the code looks as it does today — several of the classes
below exist specifically to repair a named defect here — but none of it is the live path
any more; the sections that follow are.

**The legacy market was hard-disabled, and still is.**
`_get_market_url()` (`oc-includes/osclass/utils.php:1338`) takes `$disable = true`
and returns `false` on the first line; so does `_need_update()`
(`utils.php:1372`). Both remain exactly this way — `@deprecated since 4.0.0`,
never re-enabled — and every caller (`osc_check_plugin_update()`,
`osc_check_theme_update()`, `osc_check_language_update()`, `utils.php:1265-1319`)
still always returns `false`. This path was not repaired; it was replaced.
`_osc_check_plugins_update()` (`functions.php:595`) and
`_osc_check_themes_update()` (`functions.php:674`) no longer depend on it —
they now resolve against `mindstellar\market\PackageIndex::pendingUpdates()`
(§8) instead, wrapped in a `try`/`catch` so a catalog outage degrades to "no
updates" rather than a fatal error. The admin toolbar badge and the "There's a
new update available" link (`CAdminPlugins.php:353`) fire from that catalog
data now, alongside the still-dead legacy CSV check kept only for a package
that supplies its own `Plugin update URI`.

**The per-package GitHub path was broken, and is now repaired but superseded.**
`mindstellar\upgrade\Plugin::getPackageInfo()` and `Theme::getPackageInfo()`
used to build a `$package_info` array and never return it — the functions fell
off the end returning `null` — and both branched on
`stripos($json_url, 'api.github.com') === true`, which `stripos()` (returning
`int|false`) can never satisfy, so the GitHub arm was unreachable even with the
return in place. Both are fixed now (`Plugin.php:63-134`, `Theme.php:63-…`):
they return the array, the `stripos()` check reads `!== false`, and they carry
the three new compatibility fields (`s_requires`, `s_tested_up_to`,
`s_requires_php`) through to `UpgradePackage::isCompatible()`. But this is the
**legacy self-hosted `Plugin update URI` fallback**, retained for packages
outside the catalog entirely — a catalog package never touches it; `Installer`
(§8) is its own, separately-verified download path.

**Only the core self-updater worked, and its compatibility check was still the
CSV.** `upgrade\Osclass::getPackageInfo()` polls the GitHub Releases API
directly, scans all releases for the highest version, caches the payload in
`update_core_json`, and honours a 24h clock with a 1h retry on failure
(`CAdminAjax.php`). `Upgrade` + `UpgradePackage` then download, unzip, sync
over the target directory behind a `.maintenance` flag, and run
`afterProcessUpgrade()`. That machinery was sound and became the substrate
`Installer` wraps — but its compatibility check was
`in_array($this->osclass_version, $this->a_compatible)`: an **exact string
match** against a CSV, so a package declaring `6.0.2` was judged incompatible
with 6.0.3. `UpgradePackage::isCompatible()` (`UpgradePackage.php:228-249`) now
delegates to `Compatibility::evaluate()` first and only falls back to the CSV
`in_array()` check when a package declares none of the three new fields — see §4.

**Installation used to be upload-only.** `plugins/add.php` and
`appearance/add.php` still exist as a single `<input type="file">` posting a
zip to `add_post`, and still work — nothing about them was removed. Browse,
search, and catalog-driven install/update (§8.2) are new tabs alongside that
screen, not a replacement for it; a site owner who prefers to upload a zip by
hand still can.

**Screenshots used to assume a file that may not exist; fixed.**
`appearance/index.php` no longer hardcodes `oc-content/themes/<slug>/screenshot.png`
into an `<img src>`. `osc_theme_screenshot_url()` / `osc_theme_has_screenshot()`
(`oc-includes/osclass/helpers/hTheme.php:225,247`) and `osc_plugin_icon_url()` /
`osc_plugin_has_icon()` (`hPlugins.php:357,375`) resolve real artwork or fall
back to a bundled placeholder — see §8.1.

**No compatibility metadata existed; it does now.** Plugin headers parse
`Plugin Name / Plugin URI / Plugin update URI / Support URI / Description /
Version / Author / Author URI / Short Name` plus, since this shipped,
`Requires Shopclass` / `Tested up to` / `Requires PHP`
(`Plugins.php:218-295`); theme headers parse the equivalent set
(`WebThemes.php:98-186`). See §4.

**The deprecation-reporting substrate that already existed is now the thing PR
validation reads.** `mindstellar\utility\Deprecate` fires `d_function_run`,
`d_hook_run`, and `d_file_included` hooks and raises `E_USER_DEPRECATED` under
`OSC_DEBUG` — unchanged. `scripts/gen-deprecated-api.mjs` turns it into a
published inventory, `tools/ci/deprecation-scan.php` greps a package against
that inventory (statically, always exits 0), and
`tools/ci/deprecation-collector/index.php` hooks the same three hooks at
runtime during the smoke install. No new instrumentation was needed — see §6.3.

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
catalog-facing metadata. Note what it deliberately omits: there is no `version`
key. The catalog builder reads the version from the `index.php` header and the
release tag, the same way it does for an external registration — a package's
version has exactly one source of truth, never a manifest copy that could drift
from it.

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
  "short_description": "The legacy Osclass theme, kept for compatibility.",
  "icon": "https://raw.githubusercontent.com/mindstellar/theme-bender/master/screenshot.png"
}
```

The catalog builder fetches that repo's releases, picks the matching asset,
downloads it, reads the header block **out of the zip** (so name/version/
compatibility come from the real artifact, never from a hand-edited claim),
computes the sha256, and emits an entry identical in shape to a monorepo one.
`shopclass-themes` registers `bender` and `storefront` this way today — neither
theme's source lives in the registry; both are `external/<slug>.json` pointers
at `mindstellar/theme-bender` and `mindstellar/theme-storefront`.

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
| `tested_up_to` < core, compared at **minor** precision | `6.0` vs `6.1` | **Untested** — installs, a muted note reads "Not tested with your Shopclass version yet" — informational, never a warning, since nothing is disabled |
| `tested_up_to` ≥ core minor | — | **OK** |
| nothing declared | — | Muted "not declared" |

`evaluate()` is always run **locally**, against whatever core version and PHP version are
actually running — never trusted from a precomputed value in the catalog (see §5: the
catalog stopped publishing one). This is what decides whether an action is actually
blocked; `Compatibility::badgeLabel()` renders that per-install verdict as a short string
("Compatible with 6.0.x", "Not tested with 6.1 yet", "Requires 6.2+") for the per-version
table in the detail dialog. The card and detail-dialog badge shown *before* that — "does
this package support my 6.x at all?" — is a different, install-independent string:
`Compatibility::rangeLabel()` formats the catalog's own published `requires_min` /
`tested_max` (§5) as "Works with 6.0 – 6.1" or "6.0 and newer", with no version_compare
against the running core at all, because a static catalog file is read by sites on many
different core versions and a single baked verdict can only ever be right for one of them.

The important consequence is in **update resolution**: the catalog lists every
released version of a package with its own `requires`, and
`Compatibility::pickBestVersion()` returns the highest version whose `requires`
≤ the installed core and whose `requires_php` ≤ the running PHP. A 6.0.x site is
therefore offered the last 6.x-compatible release of a plugin that has since
moved to 7.0 — and is never offered an update that would fatal on boot. This is
the single most valuable thing the catalog does, and the legacy
`in_array()`-on-a-CSV check it replaces (kept only as a fallback, §1) could
never express it — an exact string match has no notion of "highest qualifying
version."

`UpgradePackage::isCompatible()` is rewritten to delegate to `Compatibility`,
keeping the legacy `s_compatible` CSV as a fallback when the new fields are
absent so old third-party update endpoints keep working
(`UpgradePackage.php:228-249`).

`requires`/`requires_php` are compared against
`Compatibility::releaseVersion($coreVersion)` (`Compatibility.php:173-176`),
which strips a trailing `.beta`/`-rc`/`.alpha`/`.dev` suffix and its number
before the comparison: `6.1.0.beta2` evaluates as `6.1.0`. A site running the
6.1 beta already has 6.1's code, so a package declaring `Requires Shopclass:
6.1.0` installs there rather than being refused for the whole prerelease
series — this landed as a follow-up fix once the beta cycle exposed the case.

The admin package list and every catalog card render a badge built from these two
sources — the published range for "does this support my 6.x?", the local verdict for
"can I actually click the button?" — so both questions are answerable at a glance.

---

## 5. The catalog contract

Published to GitHub Pages from a `catalog` branch; mirrored at
`raw.githubusercontent.com/mindstellar/shopclass-plugins/catalog/v1/…` for the
case where Pages is unreachable. Path-versioned (`v1/`) so the schema can break
later without breaking old installs.

| File | Purpose | Fetched by core |
|---|---|---|
| `v1/updates.json` | `{slug: [{version, requires, requires_php, tested, url, sha256, size, published_at, downloads}]}` for **every** package, versions newest-first | Once per 24h, conditional GET |
| `v1/index.json` | Slim browse rows — slug, name, short description, author, latest version, icon URL, categories, tags, `updated_at`, `downloads`, `requires_min`, `tested_max` — published as a **JSON array**, not an object; `Catalog::index()` re-keys it by slug on read so nothing downstream has to | On first open of Browse, then cached 24h |
| `v1/packages/<slug>.json` | Full detail: rendered README (`description_html`), screenshots, `versions[]` with per-version `requires`/`requires_php`/`tested`/`downloads`, a package-level `downloads` total, `requires_min`/`tested_max`, `links` (homepage/repo/issues) | Lazily, when a detail view opens |
| `v1/categories.json` | Category vocabulary + counts, as a JSON array of `{id, label, description, count}` | With `index.json` |
| `v1/manifest.json` | Catalog-level build metadata — `core_version` the build was validated against, `generated_at`, `package_count`, `resolved_version_count`, `schema_version` | Not fetched by core; an operator/debugging artifact of the catalog build |

**`requires_min` / `tested_max` are a supported *range*, never a verdict.** Each version in
`updates.json` and `packages/<slug>.json` already carries its own raw `requires` /
`requires_php` / `tested` — exactly what that release's header declared, nothing computed
against them. `requires_min` and `tested_max` are a package-level summary the builder
derives from those: the lowest `requires` and the highest `tested` among the versions the
build resolved, so a browse row can show "works with 6.0 – 6.1" without fetching every
version. Neither field says anything about whether *this* install can use the package —
only `Compatibility::evaluate()`, run locally by the site reading the catalog, decides
that (§4). A catalog built before this shipped carries a per-version `compat` object
instead (a verdict baked against whatever core version the build happened to resolve
against — wrong by construction for every site not on that exact version); `Catalog`
reads that shape too, but ignores the `compat` key entirely rather than trusting it.

**`downloads` is GitHub's own count of release-asset fetches, nothing more.** The catalog
builder reads `assets[].download_count` from the GitHub Releases API response for the
asset it resolves for each version (§7) — the same response it already uses to pick that
asset and compute its sha256, so no extra API call is spent on it. Per-version `downloads`
in `updates.json` and `packages/<slug>.json` is that one asset's count; the package-level
`downloads` in `index.json` and `packages/<slug>.json` sums it across every version the
build resolved. This resolves §11's former open question: it is free, requires no
telemetry or call-home, and needs no scraping beyond an API core CI already calls. It is
**not an install count** — it also counts CI jobs, mirrors, bots, and the same person
re-downloading a release, so treat it as a popularity signal for ranking/sorting, never as
a measure of how many sites run a package. A catalog published before this field existed
has none; `Catalog` reads that as `0`, not as missing data (§8 below).

Efficiency rules core follows:

- **One request per surface, per day.** `updates.json` covers all installed
  packages at once; the per-package `plugin_update_uri` poll (N requests, N
  failure modes) is retained only as a fallback for packages absent from the
  catalog.
- **Conditional GET.** `Catalog::key()` (`Catalog.php:341-344`) namespaces every
  preference by surface and resource — `market_plugins_updates_etag`,
  `market_themes_index_last_modified`, and so on — and `requestFromSources()`
  sends `If-None-Match` / `If-Modified-Since` only to whichever source (Pages
  or the mirror) produced them last. A 304 costs ~200 bytes and short-circuits
  everything.
- **Failure never resets the clock.** `Catalog::fail()` (`Catalog.php:255-262`)
  follows the same pattern `CAdminAjax::scheduleUpdateCheckRetry()` proved for
  the core self-updater — back-date `checked_at` by `DAY_SECONDS -
  RETRY_SECONDS` on a failed fetch, so the next check is due in ~1h and the
  cached payload and its badge are untouched, rather than marking the day
  checked.
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
the registry repos — a divergence would pass CI and fail on real installs. Core's
self-contained, dependency-free validator (`tools/package-lint.php`, no
bootstrap, no DB) reads a package directory and prints JSON: parsed header,
manifest errors, compatibility verdict, size/security findings. It looks for
`Compatibility.php` beside itself first, so the compatibility verdict a PR sees
is produced by the same class core evaluates at install time, not a
reimplementation of its rules.

Both files, `deprecated-api.json`, and the rest of the CI scripts are attached
individually to every core GitHub Release **and** bundled together as
`shopclass-package-ci.tar.gz` (`.github/workflows/build.yml`, the `release` job's
"Build package-ci bundle" step) — the registry workflows download the bundle
rather than pulling files one at a time. One implementation, versioned with core.

### 6.5 Smoke install

`tools/ci/smoke-install.sh` boots MariaDB and the prod core Docker image, lets
the image's own entrypoint self-provision (the same unattended installer path,
`oc-cli.php install --unattended`, that backs a headless deploy), drops the PR's
package into place alongside the deprecation-collector instrumentation plugin,
and drives the real admin HTTP flow — no repository checkout required, since it
ships from an extracted release bundle and shells out only to `docker`, `curl`,
and POSIX tools.

1. Boot MariaDB + the core image; wait for the entrypoint's self-provisioning to
   finish.
2. Copy the PR's package into `oc-content/plugins/<slug>` (themes:
   `oc-content/themes/<slug>`), plus the deprecation collector.
3. Plugin: `install` → assert no fatal **and no unexpected output** (core already
   classifies that as `error_output` in `Plugins::install()`); `enable`; load the
   admin dashboard, the plugin's configure route if it declares one, the public
   home page and one item page; `disable`; `uninstall`.
4. Theme: activate; render home, search, item, contact, and one user page; assert
   HTTP 200 and no `Fatal error` / `Parse error` in the PHP log.
5. Assert `uninstall` left no orphan `t_*` tables and no orphan preferences under
   the package's own key namespace.
6. Diff the DB schema before/after install+uninstall — a plugin that adds a table
   and does not drop it is a **warning**, not a failure (some deliberately retain
   data), but it must be visible.

Any fatal, any 500, any unexpected output fails the PR. The script always writes
its `--out` result JSON, even on failure, so the caller has a report either way.

### 6.6 External registrations get a narrower gate

`shopclass-themes` (and any future all-external registry) validates an
`external/<slug>.json` registration differently from an in-repo package,
because a registrant does not control the code they are pointing at.
`tools/validate-external.sh` draws the line explicitly: manifest facts the
registrant wrote themselves — slug, `source.kind`, `source.repo`,
`asset_pattern`, category vocabulary — are **blocking**, the same as any other
manifest error. Facts about the artifact the upstream repo currently
publishes — a missing `LICENSE`, a `package-lint.php` finding inside the
released zip, a `Version:` header that disagrees with its own release tag —
are **warnings only**. A registrant who does not control the upstream repo
cannot fix those from inside this PR, so they are surfaced loudly on the sticky
comment but never block the registration. Both live registrations (`bender`,
`storefront`, §3.2) validate under this split.

---

## 7. Release and catalog build

`release.yml` exists wherever a repo hosts in-repo packages — `shopclass-plugins`
today (`sample-forms`, `sample-widgets`); `shopclass-themes` currently carries
only external registrations (§3.2) and so has no `release.yml` of its own —
its packages are released from their own repos, and `catalog.yml`'s daily cron
is what notices. If an in-repo theme is ever added, it needs this exact
workflow, not a second implementation.

On push to `main`:

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

Namespace `mindstellar\market`, `oc-includes/osclass/classes/market/`:

| Class | Responsibility |
|---|---|
| `Catalog` | Fetch + cache + ETag + mirror fallback; `forPlugins()`/`forThemes()`, `index()`, `detail($slug)`, `updates()`, `lastChecked()`, `lastError()`; every write goes to preferences, every failure is non-fatal (`Catalog.php`) |
| `Compatibility` | §4 — `evaluate($info, $coreVersion=null, $phpVersion=null)`, `pickBestVersion($versions, …)`, `badgeLabel($info, …)` — static-only, private constructor (`Compatibility.php`) |
| `PackageIndex` | Joins `Catalog` entries with installed state from `Plugins::listAll()`/`getInfo()` or `WebThemes::getListThemes()`/`loadThemeInfo()`; `installed()`, `available()`, `pendingUpdates()` — the rows the Installed, Browse, and Updates screens render (`PackageIndex.php`) |
| `Installer` | Download → **verify sha256** → extract to a temp dir → validate structure, slug, and compatibility → back up the existing directory → atomic move → restore on any failure; `install()`, `update()`, `rollback($slug)` (`Installer.php`) |

`Installer` wraps rather than replaces `Upgrade`/`UpgradePackage`; the three
things it brought that were missing before are all in place: checksum
verification (`FileSystem::downloadFile()`, `FileSystem.php:840-…`, now takes an
`$expectedSha256` and deletes the file on a mismatch via `hash_file()` +
`hash_equals()`), staged extraction validated before anything touches the live
directory (`validateStagedPackage()`, `Installer.php:261`), and a rollback
backup at `oc-content/downloads/backups/<slug>-<version>.zip`
(`backupExisting()`, `Installer.php:366`, restored by `restoreBackup()` /
the public `rollback()` method).

Repairs made to existing code, all landed:

- `upgrade\Plugin::getPackageInfo()` / `Theme::getPackageInfo()`
  (`Plugin.php:63-134`, `Theme.php:63-…`) — return the built array (the
  function signature is now `: array`; it throws `RuntimeException` rather than
  falling through to `null` on any failure), and the GitHub-asset branch now
  reads `stripos($json_url, 'api.github.com') !== false`. Both carry
  `s_requires`/`s_tested_up_to`/`s_requires_php` through from the header. This
  is the legacy self-hosted `Plugin update URI` fallback, not the catalog path —
  see §1.
- `_osc_check_plugins_update()` / `_osc_check_themes_update()`
  (`functions.php:595`, `:674`) — resolve against
  `PackageIndex::forPlugins()/forThemes()->pendingUpdates()` inside a
  `try`/`catch`, instead of the disabled `osc_check_*_update()` helpers. Those
  helpers stay in place, still returning `false`; they are `@deprecated` public
  API and removing them breaks third-party callers.
- `UpgradePackage::isCompatible()` (`UpgradePackage.php:228-249`) — delegates to
  `Compatibility::evaluate()` when any of the three fields are declared, falls
  back to the legacy CSV `in_array()` check otherwise (§4).
- `Zip` hardening (`Zip.php`) — the old `isPathValid()` only inspected a `../`
  prefix and never actually rejected anything (a bug the changelog calls out:
  its condition evaluated false for every ordinary path). The rewrite resolves
  every entry against the destination realpath (`resolveEntryTarget()`,
  `Zip.php:126`) before extraction, rejects symlink entries by unix mode
  (`isZipArchiveEntrySymlink()`, `Zip.php:205`), and caps entry count
  (`MAX_ENTRIES = 20000`), per-entry size (`MAX_ENTRY_UNCOMPRESSED_BYTES` =
  100 MiB), total size (`MAX_TOTAL_UNCOMPRESSED_BYTES` = 300 MiB), and
  compression ratio (`MAX_COMPRESSION_RATIO = 200`, exempting small entries) —
  all validated in a metadata-only first pass before any file is written, so one
  unsafe entry rejects the whole archive rather than partially extracting.

### 8.1 Placeholder thumbnails

Four helpers, and no template builds an image path by string concatenation:

```php
osc_theme_screenshot_url($theme = null);   // hTheme.php:225 — screenshot.png/.jpg/.webp if present
osc_theme_has_screenshot($theme = null);   // hTheme.php:247
osc_plugin_icon_url($plugin = null);       // hPlugins.php:357 — assets/icon.svg/.png/-256.png if present
osc_plugin_has_icon($plugin = null);       // hPlugins.php:375
```

Both URL helpers fall back to a core asset —
`oc-admin/themes/modern/images/placeholder-theme.svg` and
`placeholder-plugin.svg`, both shipped in the zip and filterable
(`theme_screenshot_url` / `plugin_icon_url`) — so a package with no artwork
still renders a deliberate tile rather than a broken image. The placeholder is a
neutral mark on a surface driven by `var(--osc-*)` / `var(--bs-*)` tokens so it
flips with `data-bs-theme="dark"`, with the package's initial rendered as a text
overlay in the wrapper (not baked into the SVG) and its tint chosen from a hash
of the slug — so a grid of unillustrated packages still reads as distinct tiles.
Catalog cards use the same helpers, so pre-install and post-install artwork
behave identically.

This also fixed the Appearance grid's long-standing broken image for a theme shipping
no `screenshot.png`, which used to build the `<img src>` by string concatenation.

### 8.2 Admin UI

Not a new top-level page — tabs on the two screens that already own these
objects, so nothing about the existing information architecture moves:

- **Plugins** → `Installed` (today's table) · `Browse` · `Updates`
- **Appearance** → `Themes` (today's grid) · `Browse` · `Updates`

Both controllers (`CAdminPlugins.php`, `CAdminAppearance.php`) build the market
view data and hand it to shared partials in
`oc-admin/themes/modern/parts/market.php`: `osc_market_render_browse()`,
`osc_market_render_updates()`, `osc_market_render_detail_dialog()`. `Browse` is
a card grid rendered from the cached slim index: thumbnail, name, author, short
description, a badge for the package's published supported range (§4), and one
primary action (`Install` / `Update to 1.4.0` / `Installed`, or a disabled button
with the reason when blocked by §4). A package that hasn't been tested with the
running core yet gets a muted note under the badge — informational, since
untested never blocks the action. Search, category filter, and sort run
client-side over the cached JSON, with **"Recently updated" (`updated_at`
descending) pre-selected** as the default sort — an abandoned package sinks to
the bottom of Browse rather than sitting wherever the catalog happened to list
it. A detail dialog — a native `<dialog>`, consistent with the modernised admin
— shows screenshots, the sanitised README, a per-version table (`requires` /
`requires_php` / `tested`, each with its own locally-evaluated badge), and links
to the repo and its issue tracker.

Install, update, refresh, and detail fetch are POSTs/GETs through
`CAdminAjax` (`market_install`, `market_update`, `market_refresh`,
`market_detail`) with `osc_csrf_check()`, the `DEMO` guard, and
`osc_self_update_disabled()` all honoured — container deployments that update
by image must not grow a second, divergent update path. Directory writability
is checked before the button renders, reusing the messaging already in
`add.php`.

---

## 9. Security posture

| Risk | Mitigation |
|---|---|
| Malicious package merged | PR gate §6, human review required, no auto-merge; org members only for `external/*.json` registrations pointing outside `mindstellar` |
| Contributor code exfiltrating CI secrets | `pull_request` (not `pull_request_target`); no secrets in the validate workflow; runtime jobs in a container with a throwaway DB |
| Tampered download | sha256 in the catalog, verified before extraction (`Installer.php:147`); host allowlist on download URLs (`FileSystem::isAllowedPackageHost()`); HTTPS with peer verification enforced |
| Poisoned catalog | Catalog is only writable by CI on a protected branch, and every field is type-checked and re-validated against the host allowlist on read (`Catalog::sanitize*()`). **Not built**: a minisign/cosign signature over `updates.json` verified against a public key shipped in core, so a compromised Pages deploy is still rejected even if the branch protection itself were bypassed — scoped but never built |
| Zip slip / zip bomb | `Zip` rewrite (§8) — realpath containment, symlink rejection, entry-count and size caps |
| Half-written install | Staged extraction, atomic move, rollback backup |
| Supply-chain drift between CI and runtime | One shared validator downloaded from core releases (§6.4) |

---

## 10. Documentation

The registry's whole value proposition is that a stranger can ship a package
without asking anyone how, so the documentation is part of the product rather
than a description of it.

`docs/PACKAGE-SPEC.md` is the package contract; this file is the system design.
Each registry carries a `README.md`, a `CONTRIBUTING.md`, JSON Schemas for its
manifests, and pull-request and issue templates. `tools/ci/README.md` documents
the shared CI harness. Core's `CHANGELOG.md` records what changed and when.

Two rules keep this from rotting:

1. **PACKAGE-SPEC is the single source.** Each registry's `CONTRIBUTING.md`
   links to it rather than restating its rules, and a validator that disagrees
   with it is a bug in the validator. Same discipline as the shared
   `package-lint.php` in §6.4 — one implementation, one specification.
2. **A described feature that does not exist says so.** Catalog signing (§9) and
   a rendered author-facing docs site are both scoped and unbuilt; neither is
   implied to work anywhere in these documents.

## 11. Open questions

1. **Do bundled plugins leave core? — resolved: mirrored, not moved.**
   `sample-forms` and `sample-widgets` are registered in `shopclass-plugins`
   (both released, currently at `1.0.1`) and still ship in the core zip —
   neither was removed from `oc-content/plugins/`; the `.gitignore` allowlist
   there (`!oc-content/plugins/sample-widgets/`, `!…/sample-forms/`) still
   tracks them explicitly. No other bundled plugin was mirrored. Revisit moving
   more of them at 7.0, as originally proposed.
2. **Review capacity — resolved as proposed.** `external/*.json` registration
   is the pattern actually used for both live themes (`bender`, `storefront`,
   §3.2); neither theme's source lives in the registry, so review is "does this
   manifest point at a real repo and release," not a codebase review.
3. **Download counts — resolved: built on GitHub's asset download count.** Shipped in
   6.1.0.beta3, as the recommended source concluded here: no call-home, no telemetry,
   no scraping beyond an API the catalog build already calls. `downloads` is a frozen
   field, per-version and per-package, in `updates.json`, `index.json`, and
   `packages/<slug>.json` — see §5 for the exact shape and honest limits (it is GitHub's
   raw asset-fetch count, not an install count).
4. **Paid plugins.** Still out of scope; still no commerce in this system. Not
   verified whether the manifest schema reserves a `price` field — treat that
   detail as unconfirmed rather than repeat the earlier draft's claim about it.
