# Package specification — plugins and themes

Status: **Shipped, in force.** The header fields in §3 parse in core as of Shopclass 6.1.0;
`mindstellar/shopclass-plugins` and `mindstellar/shopclass-themes` are live, and §6-§8 describe
their actual PR validation, not a plan for it. See `docs/MARKET.md` for the ecosystem design
and what each phase delivered.

Audience: anyone writing a Shopclass plugin or theme, and anyone implementing tooling
that reads one. This is the contract. Core parses packages according to it, the registry
CI validates against it, and the catalog is generated from it — so a change here is a
change to all three, and none of them may diverge.

---

## 1. What a package is

A **plugin** is a directory under `oc-content/plugins/` containing an `index.php` whose
opening comment block declares it. A **theme** is a directory under
`oc-content/themes/` with the same arrangement. Nothing registers a package; it is
discovered by scanning those directories.

The directory name is the package's **slug** and is its permanent identity. It must match
`^[a-z0-9][a-z0-9-]{1,40}$` — lowercase, digits, hyphens, no leading hyphen. The slug
appears in the download URL, the release tag, the catalog key, and (for many plugins) the
option keys and table names the package owns. Renaming it is a new package, not a rename.

```
oc-content/plugins/better-s3/          oc-content/themes/bender/
  index.php            required          index.php            required
  shopclass.json       registry only     shopclass.json       registry only
  README.md            recommended       screenshot.png       required
  CHANGELOG.md         recommended       README.md            recommended
  LICENSE              required          CHANGELOG.md         recommended
  assets/icon.svg      recommended       LICENSE              required
  assets/screenshot-1.png  optional      assets/screenshot-*.png  optional
```

`shopclass.json` is only consulted by the registry and the catalog builder. **Core never
reads it.** Everything core needs at runtime lives in the header block, because that is
the only thing guaranteed to exist on an installed package — including one installed by
unzipping a tarball by hand in 2014.

---

## 2. Distribution shape

A released package is a zip whose **single top-level directory is the slug**:

```
better-s3_1.2.0.zip
└── better-s3/
    ├── index.php
    └── …
```

This is what `Upgrade::processUpgrade()` expects; it accepts either an `index.php` at the
zip root or one inside a directory named for the package's short name, and the latter is
the form to produce. A zip that expands to loose files, or to a directory named
`better-s3-main/` (what GitHub's "Download ZIP" button produces), will not install.

Exclude development files from the zip via `.distignore` — at minimum `.git`, `.github`,
`node_modules`, tests, build sources, `*.map`, and the `.distignore` itself.

Limits enforced by registry CI: 5 MB for any single file, no symlinks, no
`.exe`/`.dll`/`.so`, no `.git` or `node_modules` directory. Unpacked size is graduated —
over **15 MB** warns, over **50 MB** fails. A plugin bundling a real SDK legitimately runs
to tens of megabytes, so the recommended ceiling informs rather than blocks; the hard cap
exists because every install downloads and unpacks this on shared hosting.

---

## 3. The header block

### 3.1 How it is parsed

Core reads the raw bytes of `index.php` and runs one case-insensitive regex per field,
shaped `|Field Name:([^\r\t\n]*)|i` — see `Plugins::getInfo()` and
`WebThemes::loadThemeInfo()`. Three consequences authors must know:

- **The match is a substring search over the whole file, not a parse of the comment
  block.** The first occurrence anywhere in `index.php` wins.
- **Therefore a field name that appears earlier in the file hijacks the real one.** A
  block containing `API Version: 2` above `Version: 1.4.0` yields a parsed version of
  `2`, because `Version:` matches inside `API Version:`. Keep the declaration block at the
  very top of the file and avoid any line elsewhere in `index.php` that contains a field
  name followed by a colon.
- **A value ends at the end of the line.** Values cannot wrap. Tabs terminate them too.

The block is a plain PHP comment immediately after `<?php`:

```php
<?php
/*
Plugin Name: Better S3
Plugin URI: https://github.com/mindstellar/better-s3
Description: Offload uploaded images to any S3-compatible bucket.
Version: 1.2.0
Author: Mindstellar
Author URI: https://github.com/mindstellar
Short Name: better-s3
Requires Shopclass: 6.0.0
Tested up to: 6.1
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/better-s3/issues
*/
```

### 3.2 Plugin fields

| Field | Required | Parsed key | Notes |
|---|---|---|---|
| `Plugin Name` | yes | `plugin_name` | Human-readable. Defaults to the filename when absent |
| `Description` | yes | `description` | One line. Long-form prose goes in `README.md` |
| `Version` | yes | `version` | §5 |
| `Author` | yes | `author` | |
| `Short Name` | recommended | `short_name` | Defaults to `plugin_name`. Should equal the slug |
| `Plugin URI` | recommended | `plugin_uri` | Homepage |
| `Author URI` | optional | `author_uri` | |
| `Support URI` | recommended | `support_uri` | Rendered as the support icon in the admin list |
| `Plugin update URI` | optional | `plugin_update_uri` | Legacy self-hosted update endpoint. Redundant for catalog packages; see §7 |
| `Requires Shopclass` | recommended | `requires` | §4 |
| `Tested up to` | recommended | `tested_up_to` | §4 |
| `Requires PHP` | recommended | `requires_php` | §4 |

### 3.3 Theme fields

Identical in spirit; the names differ because theme parsing is a separate function.

| Field | Required | Parsed key |
|---|---|---|
| `Theme Name` | yes | `theme_name`, `name` |
| `Description` | yes | `description` |
| `Version` | yes | `version` |
| `Author` | yes | `author_name` |
| `Theme URI` | recommended | `theme_uri` |
| `Author URI` | optional | `author_url` |
| `Parent Theme` | optional | `template` — the slug of the theme this one extends |
| `Widgets` | optional | `locations` — comma-separated widget location ids |
| `Theme update URI` | optional | `theme_update_uri` |
| `Requires Shopclass` | recommended | `requires` |
| `Tested up to` | recommended | `tested_up_to` |
| `Requires PHP` | recommended | `requires_php` |

---

## 4. Compatibility

The three compatibility fields are the answer to "does this work on my 6.x?", and they
are the only reason core can offer a *safe* update rather than the newest one.

```
Requires Shopclass: 6.0.0     minimum core version — a hard gate
Tested up to: 6.1             highest core version verified — a soft warning
Requires PHP: 8.0             minimum PHP — a hard gate
```

All three are optional, and omitting them is not an error: an absent field means
**undeclared**, which core treats as installable with a muted note. It never blocks.
Osclass-era packages predate these fields entirely and must keep working.

Evaluation, implemented once in `mindstellar\market\Compatibility` and used by both the
admin UI and the updater:

| Condition | Verdict | Effect |
|---|---|---|
| `Requires Shopclass` > running core | **Incompatible** | Install/Update disabled. "Requires Shopclass 6.2 or newer (you have 6.0.3)" |
| `Requires PHP` > running PHP | **Incompatible** | Install/Update disabled |
| `Tested up to` < running core, compared at **minor precision** | **Untested** | Installs. Badge reads "Untested with 6.1" |
| `Tested up to` ≥ running core minor | **OK** | Badge reads "Compatible with 6.0.x" |
| Nothing declared | **Undeclared** | Installs. Muted "compatibility not declared" |

`Tested up to` is compared at minor precision on purpose. A package tested against 6.0.1
is not meaningfully untested on 6.0.3 — patch releases do not break plugin API — so
declaring `6.0` is sufficient and correct, and demanding a bump every patch would train
authors to lie.

**Version resolution.** The catalog carries every released version of a package with its
own `Requires Shopclass` and `Requires PHP`. Core offers the *highest version that
qualifies*, not the newest that exists. A site on 6.0.3 running a plugin that has since
moved to a 7.0-only release is offered the plugin's last 6.x-compatible version, and is
never offered an update that would fatal on boot. This is why per-version compatibility
metadata matters more than per-package.

Consequently: **when you drop support for a core series, raise `Requires Shopclass` on the
new release rather than deleting the old one.** Deleting old releases strands users; a
correct `Requires` line routes them to the right version automatically.

---

## 5. Versioning

Versions are compared with PHP's `version_compare()`. Semantic versioning is expected —
`MAJOR.MINOR.PATCH`, optionally with a `-beta1`/`-rc1` suffix that `version_compare()`
orders below the release.

- Every release must increase the version. Registry CI rejects a package whose `Version:`
  is not greater than the one already in the catalog.
- The `Version:` header, the newest `CHANGELOG.md` entry, and the release tag must agree.
- Do not prefix the header value with `v`. Tags may (`better-s3-v1.2.0`); the header may
  not.

---

## 6. Artwork

| Asset | Plugin | Theme | Spec |
|---|---|---|---|
| Icon | `assets/icon.svg` or `assets/icon.png` | — | Square. SVG preferred; PNG at 256×256 |
| Screenshot | `assets/screenshot-1.png` … | `screenshot.png` **at the package root** | 4:3, minimum 1200×900 |

The theme screenshot lives at the package root rather than under `assets/` because that
is where a decade of themes already put it and where core looks.

**Artwork is optional.** Core renders a built-in placeholder for any package with no icon
or screenshot — a neutral, theme-aware tile tinted from a hash of the slug, so a grid of
unillustrated packages still reads as distinct tiles rather than as broken images. The
same placeholder is used before install (catalog card) and after (admin list), so a
package's appearance does not change on install. Do not ship a blank or a "no image"
graphic of your own; the fallback is better than one.

Screenshots must depict the package's real UI. No marketing copy, no logos-on-gradient,
no before/after collages.

---

## 7. `shopclass.json` (registry only)

Required for a package hosted **inside** `shopclass-plugins` / `shopclass-themes`. It
carries what a one-line header field cannot; it never duplicates the header. Validated in
CI against `schema/package.schema.json` in the registry repo.

```jsonc
{
  "$schema": "../../schema/package.schema.json",
  "slug": "better-s3",                 // MUST equal the directory name
  "type": "plugin",                    // "plugin" | "theme"
  "categories": ["storage", "media"],  // from the registry's fixed vocabulary
  "tags": ["s3", "cdn", "offload"],    // free-form, max 8
  "short_description": "Offload uploaded images to any S3-compatible bucket.",
  "icon": "assets/icon.svg",
  "screenshots": [
    { "src": "assets/screenshot-1.png", "caption": "Bucket settings" }
  ],
  "support": { "issues": "https://…/issues", "docs": "https://…" },
  "funding": "https://github.com/sponsors/…",
  "license": "GPL-3.0-or-later"
}
```

A package hosted in its **own repository** instead registers a single
`external/<slug>.json` in the registry, pointing at that repo; the catalog builder reads
the real header block out of the released zip, so metadata cannot drift from the artifact.
Both forms produce identical catalog entries — core cannot tell them apart. Note what
`shopclass.json` never carries: a `version`. The catalog reads that from your `index.php`
header and your release tag, the same way it reads an external package's — there is exactly
one place a version can be declared.

`Plugin update URI` / `Theme update URI` remain supported for packages distributed outside
the registry entirely. They are the fallback path, polled per-package; catalog packages
get a single batched update check instead and should leave the field off.

**`updated_at` drives the default browse order, and it is not something you set.** The
catalog's `index.json` carries an `updated_at` per package, taken from your newest
release's publish timestamp — not from anything in `shopclass.json` or the header. The
admin Browse screen pre-selects "Recently updated" as its sort. Ship no releases for a long
time and your package sinks toward the bottom of that list regardless of how good it is;
the only way to stay near the top is to keep cutting releases.

**`downloads` is also not something you set.** The catalog builder reads it from GitHub's
own per-asset download count on each release, summed across every version for the
package-level figure in `index.json` and `packages/<slug>.json`. It is a raw count of
release-asset fetches — it includes CI runs, mirrors, and repeat downloads — not a
measure of active installs, so do not advertise it as one.

---

## 8. What CI checks

Registry pull requests validate **only the packages the PR changed**. Full gate list and
rationale are in `docs/MARKET.md` §6; the split that matters to an author:

**Blocking** — structure and slug, manifest schema, header parse, version increment,
compatibility fields naming real core versions, `php -l` across 8.0–8.5, PHP 8.0 floor via
PHPCompatibility, dangerous-construct scan (§9), and a smoke install in a real container
(install → enable → load admin and public pages → disable → uninstall, with no fatal and
no unexpected output).

**Non-blocking warnings** — use of deprecated core APIs (§10), code style, missing
compatibility declarations, an uninstall that leaves tables behind, and `Tested up to`
more than one minor behind current core. A pull request can be merged with warnings
outstanding; they exist to inform, not to gate.

**Registering a package from your own repository gets a narrower gate.** An
`external/<slug>.json` registration (§7) is checked by `tools/validate-external.sh`, which
draws a line an in-repo package doesn't need: manifest facts you wrote yourself — slug,
`source.repo`, `asset_pattern`, category vocabulary — are blocking, same as any other
manifest error. Facts about what your upstream repository's release **currently**
publishes — a missing `LICENSE`, a `package-lint.php` finding inside your own released
zip, a `Version:` header that disagrees with your own release tag — are warnings only.
The registration PR is reviewing your manifest, not fixing your other repository from
inside it, so a defect in what you've already released is surfaced on the sticky comment
but does not block the registration.

---

## 9. Security requirements

These are enforced, not advisory. The first group fails CI outright:

- No `eval()`, `assert()` on a string, `create_function()`, `system()`, `exec()`,
  `shell_exec()`, `passthru()`, `proc_open()`, or `base64_decode()` of a long literal.

**Vendored code is exempt from blocking.** A hit inside `vendor/`, `third-party/`, or
`thirdparty/` is reported as a warning rather than an error, and the second group's
heuristics are not run there at all. An author cannot patch a dependency before merging,
the fix belongs upstream, and half the PHP ecosystem's SDKs shell out somewhere — treating
that as a rejection would block every package that uses one while telling the author
nothing actionable. The finding is still surfaced, because knowing what your dependencies
do is the point.

The second group warns in CI and will be treated as a bug report against your package:

- **`Params::getParam()` returns raw request data — it is not sanitisation.** Use the typed
  accessors (`getParamInt`, `getParamString`, `getParamArray`) and `osc_sanitize_*()` on
  input; `osc_esc_html()` / `osc_esc_js()` on output.
- Every state-changing admin action needs `osc_csrf_check()`.
- Never read `$_GET` / `$_POST` / `$_REQUEST` directly.
- Never interpolate request data into SQL. Use the parameterised query API
  (`osc_db_*` / the query builder), not string-built queries.
- Validate and constrain file uploads by MIME and extension; never trust the client name.

An uninstall must remove what the package created: its tables, its preferences, its
scheduled tasks, its uploaded working files. Retaining user data deliberately is
legitimate — say so in the README, and expect the CI warning.

---

## 10. Deprecation policy

Core marks removals in advance through `mindstellar\utility\Deprecate`, which fires the
`d_function_run`, `d_hook_run`, and `d_file_included` hooks and raises `E_USER_DEPRECATED`
under `OSC_DEBUG`. Core publishes the full inventory as `deprecated-api.json` with every
release: symbol, the version that deprecated it, its replacement, and its scheduled
removal version.

Registry CI consumes that inventory two ways — `tools/ci/deprecation-scan.php`'s static
scan of your source (function/method calls and hook/filter-name string literals) and a
runtime capture from a collector plugin during the smoke install — and reports every hit
as a **warning on your pull request**, annotated on the offending line:

> ⚠️ `osc_check_plugin_update()` is deprecated since 6.0 and scheduled for removal in 7.0.
> No replacement.

The static scan skips anything under `vendor/`, `third-party/`, or `thirdparty/` — the
same carve-out §9 gives the security scan, for the same reason: a warning about a
dependency you didn't write isn't something you can act on from inside your own PR.

Deprecations never fail a build — `deprecation-scan.php` always exits `0`. They are a
schedule, not a rejection: a symbol deprecated in 6.x is removed no earlier than the next
major, so a warning is a note that you have a release cycle to act, not that your package
is broken.

The reciprocal obligation on core is the compatibility contract in the project README:
admin CSS class names, `osc_*` helper signatures, hook names, and asset paths under
`oc-includes/assets/` are public API even though nothing declares them so. Restyling is
safe; renaming and deleting are not.

---

## 11. Licensing

Shopclass is GPL-3.0-or-later. A package distributed through the registry must carry a
`LICENSE` file with a GPL-compatible licence and a matching SPDX identifier in
`shopclass.json`.

Original code must not carry an Osclass copyright notice. Files derived from Osclass keep
their original Apache-2.0 attribution alongside the GPL notice — see any core file's
header for the exact form.
