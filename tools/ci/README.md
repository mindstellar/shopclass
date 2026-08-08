# Package CI

Shared CI harness for the `shopclass-plugins` and `shopclass-themes` registry
repositories (docs/MARKET.md §6-§7). Everything in this directory is published
as a release asset — both standalone and bundled together — so a registry
workflow can download exactly one thing and run it against a package
directory, with no checkout of this repository.

The package contract these tools enforce is `docs/PACKAGE-SPEC.md`. Read that
first if you are wondering *why* a check exists; this file only covers how
the tools fit together.

## The pieces

| File | What it does | Fails a build? |
|---|---|---|
| `package-lint.php` *(sibling, not in this dir — see below)* | Structure, manifest, header parsing, versioning, compatibility fields, dangerous-construct scan | Yes, on error-level findings |
| `Compatibility.php` *(sibling)* | The compatibility evaluator `package-lint.php` needs beside it | — |
| `deprecated-api.json` *(generated, not in this dir)* | The inventory `deprecation-scan.php` scans against | — |
| `deprecation-scan.php` | Static scan for calls to deprecated core functions/hooks/filters | Never (§6.3) |
| `smoke-install.sh` | Installs the package in a real core container and exercises it | Yes, on a fatal or a failed lifecycle step |
| `deprecation-collector/` | A tiny plugin `smoke-install.sh` installs alongside the package under test, to catch deprecated calls the static scan can't see | — |
| `annotate.php` | Merges the three JSON outputs above into GitHub annotations + one sticky PR comment | Yes, if any error-level finding exists |

`package-lint.php` and `Compatibility.php` are owned by core (`tools/` and
`oc-includes/osclass/classes/market/`, respectively) — this directory does not
duplicate them, it only assumes they travel alongside it in the release
bundle described below.

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
  deprecation-collector/
    index.php
```

Flat is load-bearing: `package-lint.php` looks for `Compatibility.php` beside
itself first, before falling back to `--compat=`. A registry workflow that
just extracts the tarball and runs `php package-ci/package-lint.php` gets that
for free.

## Running it

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
