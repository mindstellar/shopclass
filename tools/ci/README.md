# Package CI

Shared CI harness for the `shopclass-plugins` and `shopclass-themes` registry
repositories (docs/MARKET.md §6-§7). Everything in this directory is published
as a release asset — both standalone and bundled together — so a registry
workflow can download exactly one thing and run it against a package
directory, with no checkout of this repository.

The package contract these tools enforce is `docs/PACKAGE-SPEC.md`. Read that
first if you are wondering *why* a check exists; this file only covers how
the tools fit together.

Two families live here. §6's pull-request gate (`package-lint.php` and
friends, below) validates one changed package on every PR. `build-catalog.php`
is §7's release-time job — it does not gate anything, it turns an entire
registry into the static catalog core actually fetches. See
[Building the catalog](#building-the-catalog) further down.

## The pieces

| File | What it does | Fails a build? |
|---|---|---|
| `package-lint.php` *(sibling, not in this dir — see below)* | Structure, manifest, header parsing, versioning, compatibility fields, dangerous-construct scan | Yes, on error-level findings |
| `Compatibility.php` *(sibling)* | The compatibility evaluator `package-lint.php` and `build-catalog.php` need beside them | — |
| `deprecated-api.json` *(generated, not in this dir)* | The inventory `deprecation-scan.php` scans against | — |
| `deprecation-scan.php` | Static scan for calls to deprecated core functions/hooks/filters | Never (§6.3) |
| `smoke-install.sh` | Installs the package in a real core container and exercises it | Yes, on a fatal or a failed lifecycle step |
| `deprecation-collector/` | A tiny plugin `smoke-install.sh` installs alongside the package under test, to catch deprecated calls the static scan can't see | — |
| `annotate.php` | Merges the three JSON outputs above into GitHub annotations + one sticky PR comment | Yes, if any error-level finding exists |
| `build-catalog.php` | Builds the v1/ catalog tree from an entire registry checkout (§7) — not part of the PR gate | Yes, if any package fails to resolve |

`package-lint.php`, `Compatibility.php`, and `build-catalog.php` are owned by
core (`tools/`, `oc-includes/osclass/classes/market/`, and `tools/ci/`
respectively) — this directory does not duplicate them, it only assumes they
travel alongside each other in the release bundle described below.

## The release bundle

`build.yml` attaches a `shopclass-package-ci.tar.gz` asset to every release,
alongside the existing standalone files (which keep shipping too — nothing
here replaces them). It extracts to one flat directory:

```
package-ci/
  package-lint.php
  Compatibility.php
  deprecated-api.json
  deprecation-scan.php
  annotate.php
  smoke-install.sh
  build-catalog.php
  deprecation-collector/
    index.php
```

Flat is load-bearing: `package-lint.php` and `build-catalog.php` both look for
`Compatibility.php` beside themselves first (falling back to `--compat=` /
the core source tree only when it isn't there). A registry workflow that just
extracts the tarball and runs `php package-ci/package-lint.php` or
`php package-ci/build-catalog.php` gets that for free.

## Running the PR gate

All three commands are dependency-free — no bootstrap, no autoloader, no
database — and designed to run from an extracted bundle with nothing else
present.

```bash
# Deprecated-API scan (always exits 0; warnings only)
php package-ci/deprecation-scan.php \
  --inventory=package-ci/deprecated-api.json --json <package-dir>

# Smoke install (needs Docker; never needs secrets)
bash package-ci/smoke-install.sh \
  --type=plugin --slug=<slug> --path=<package-dir> \
  --image=ghcr.io/mindstellar/shopclass:edge --out=result.json

# Merge into annotations + a sticky-comment body
php package-ci/annotate.php \
  --slug=<slug> --path-prefix=plugins/<slug> \
  --lint=lint.json --deprecations=deprecations.json --smoke=result.json \
  --comment-out=comment.md
```

`--path-prefix` matters once these run inside a registry workflow: `lint`,
`deprecation-scan`, and `smoke-install` all report file paths relative to the
package directory they were pointed at (e.g. `index.php`), but a GitHub
annotation needs a path relative to the checkout root the workflow diff is
against (e.g. `plugins/<slug>/index.php`) to render inline. `annotate.php`
applies the prefix once, centrally, so the other three tools never need to
know where in the registry repo they are being run from.

## How `smoke-install.sh` drives the container

There is no headless "install a plugin" CLI verb in core — only the admin web
UI does it (`Plugins::install()`/`activate()`/`deactivate()`/`uninstall()`,
wired to `?page=plugins&action=...` behind `osc_csrf_check()`). So the script
drives the real thing over HTTP with `curl`, the same way a browser would:

1. Boot a MariaDB sidecar and the core image (`--image`), with `WEB_PATH` set
   to the host port the container actually publishes — core echoes that value
   verbatim into every redirect it sends, and those are followed.
2. The image's own entrypoint self-installs from the environment
   (`oc-cli.php install --unattended`); the script just waits for it to start
   answering requests.
3. Copy the package into `oc-content/plugins/<slug>` or `themes/<slug>`
   (`docker cp`), plus `deprecation-collector/`.
4. Log in to `/oc-admin/` and scrape the page's CSRF token pair. Every token
   `mindstellar\Csrf` issues on one authenticated page load is valid for every
   other admin request in the same session (2h lifetime, no per-request
   nonce), so it is scraped once and reused for install/enable/configure/
   disable/uninstall — no re-scraping between steps.
5. Install and enable the deprecation collector the same way, before touching
   the package under test, so its hooks are registered for the whole run.
6. Snapshot `SHOW TABLES` and the preference table, run the package's
   lifecycle (§6.5), snapshot again, and diff.
7. Pull the collector's JSONL log back out and fold it into `deprecations`.

### A production quirk this had to work around

The prod image ships `output_buffering = 0`. `Plugins::install()`'s own
unexpected-output detector (`ob_get_length() > 0`) needs an active output
buffer to measure, so with buffering off it can never fire — not a bug in
core, just not what this harness needs. `smoke-install.sh` bind-mounts a
one-line `conf.d` override (`output_buffering = 4096`) into its own throwaway
container only; the image and every real deployment are untouched.

Similarly, the prod image never shows a raw PHP error to a client —
`OsclassErrors` catches every fatal and uncaught exception and renders a
branded error page instead — so response bodies never contain the words
"Fatal error". What does happen unconditionally is an `error_log()` call with
a fixed prefix (`Shopclass error: ` for an uncaught exception, `Shopclass
fatal: ` for a true shutdown-time fatal), which lands in the container's
stdout. `smoke-install.sh` greps container logs for those two prefixes (with
the literal `Fatal error`/`Parse error` strings kept as a fallback, in case
`OSC_DEBUG` or a different error-handling configuration is in play).

### Known limitation: `tables_left` / `prefs_left` are session-wide, not package-scoped

The before/after diff brackets the *entire* package lifecycle, not just the
plugin's own `install()`/`uninstall()` calls — anything else the request
cycle causes (a lazily-generated preference from an unrelated core feature
touched by simply rendering a page, for instance) will show up too. This
matches the imprecision `docs/MARKET.md` §6.5 already accepts for this check
("a plugin that adds a table and does not drop it is a **warning**, not a
failure") — it is not a rejection gate, so a false positive here costs a PR
author nothing but a moment's confusion, never a failed build. A tighter,
package-scoped diff would need core to expose which preferences/tables a
specific plugin's `install()` call touched, which nothing does today.

## Building the catalog

```bash
php package-ci/build-catalog.php --type=plugin --root=/path/to/shopclass-plugins \
    --out=catalog --core-version=6.1.0 [--max-versions=20] [--offline]
```

Run once per registry (plugin and theme catalogs are always built separately —
each registry publishes its own `v1/` tree). Writes `<out>/v1/index.json`,
`updates.json`, `categories.json`, `manifest.json`, and one
`<out>/v1/packages/<slug>.json` per package. Exit 0 on success, 1 if any
package failed to resolve (a bad download URL, a corrupt zip, a header/slug
mismatch — never just "this package has no releases yet", which is a normal
state, not a failure), 2 on a usage error. One summary line per package goes
to stderr.

Auth is via `GITHUB_TOKEN` in the environment when set (the unauthenticated
GitHub API budget is 60 req/h, easily exhausted rebuilding a catalog with
several packages' worth of release history); every path also works with no
token, at a lower budget.

**Both source models resolve to one shape.** An in-repo package
(`plugins/<slug>/`) and an `external/<slug>.json` registration both end up
calling the same per-release resolver, so core genuinely cannot tell which
one produced a given catalog entry (docs/MARKET.md §3). Per-version
`requires`/`requires_php`/`tested` always come from *that version's own*
downloaded artifact, never the newest one — this is what lets
`Compatibility::pickBestVersion()` hand an old core a package's last
compatible release instead of its newest.

**The host allowlist is enforced per download URL, not per package.** A
release whose asset URL fails the check (`github.com`,
`objects.githubusercontent.com`, `raw.githubusercontent.com`, `*.github.io`,
HTTPS only) is refused and reported on stderr — that one version is dropped
from the output, the rest of the package's versions are unaffected, and the
run exits 1.

**README rendering has no raw-HTML passthrough at all.** The whole Markdown
source is HTML-escaped before any block/inline pattern is applied, so every
tag in the rendered output is one the renderer built itself from a recognised
Markdown construct — there is no way for a `<script>` or an `onclick="..."` in
a README to reach the output as anything but inert escaped text. It is a
small, deliberately non-exhaustive subset of Markdown (headings, nested
lists, task-list markers rendered as inert symbols, GFM pipe tables with
optional column alignment, blockquotes, fenced code, bold/italic, and links
and images with a scheme allowlist) — enough for a real package README, not a
CommonMark implementation. Table alignment is emitted as `text-center` /
`text-end` classes rather than inline styles, because the admin purifies this
HTML a second time before rendering it and drops `style`.

**A local build cache makes `--offline` possible.** Every GitHub API response
and downloaded asset is cached under `<out>/.build-cache/` (not part of the
published `v1/` tree). `--offline` reads that cache only and never touches the
network — useful for iterating on output formatting without spending API
budget, and for CI to warm the cache once and rebuild from it. A package with
no cached data available offline resolves to zero versions, same as one with
no releases at all.

**Determinism.** Every JSON object's keys are sorted, and every "set-like"
list (categories, tags) is sorted where it is built, so a rebuild against
unchanged upstream data produces a byte-identical tree — the `catalog` branch
should not churn on a no-op rebuild. The one field that looks like it should
break this, `manifest.json`'s `generated_at`, is deliberately **not**
wall-clock time: it is the newest `published_at` among the versions actually
resolved, which only advances when there is new upstream content to reflect.

**In-repo artwork is copied, not linked, and referenced relative to `v1/`.**
An in-repo package's icon/screenshots are extracted from its newest resolved
release zip into `<out>/v1/assets/<slug>/...` and referenced by a path
relative to `v1/` (e.g. `assets/bender/icon.svg`), not an absolute URL — the
catalog is mirrored at two different bases (GitHub Pages and
raw.githubusercontent.com, docs/MARKET.md §5) and a relative path resolves
against whichever one core is currently using, without this tool having to
know which. An external package's `icon`/`screenshots` are already absolute
URLs in its manifest (the artwork lives in the package's own repository) and
are passed through as-is after the same allowlist check used for downloads.
