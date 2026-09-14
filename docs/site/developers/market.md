---
title: The market
description: How ShopClass plugins and themes are published — the GitHub-native registries, the static catalog, and how to submit a package.
sidebar:
  order: 3
---

ShopClass browses, installs and updates plugins and themes from two public
registries:

- [**mindstellar/shopclass-plugins**](https://github.com/mindstellar/shopclass-plugins)
- [**mindstellar/shopclass-themes**](https://github.com/mindstellar/shopclass-themes)

Both are ordinary GitHub repositories. Packages are submitted by pull request,
validated by CI, and published as a **static JSON catalog** that every install
reads. There is no marketplace server, no account, and no fee.

Shipped in ShopClass 6.1.0. From an install, it is **Admin → Plugins → Add new**
and **Admin → Appearance**, or the `market:*`
[CLI commands](/docs/cli/#the-market).

## Two ways to publish

A package either lives **inside** the registry repository or **outside** it in
your own.

**In-repo** — your source tree goes in `plugins/<slug>/` (or `themes/<slug>/`)
alongside a small `shopclass.json` manifest:

```jsonc
{
  "slug": "better-s3",                 // must equal the directory name
  "type": "plugin",
  "categories": ["storage", "media"],  // from a fixed vocabulary
  "tags": ["s3", "cdn", "offload"],
  "short_description": "Offload uploaded images to any S3-compatible bucket.",
  "icon": "assets/icon.svg",
  "screenshots": [
    { "src": "assets/screenshot-1.png", "caption": "Bucket settings" }
  ],
  "support": { "issues": "https://github.com/…/issues" },
  "license": "GPL-3.0-or-later"
}
```

Note what it does **not** carry: a `version`. The builder reads the version from
your `index.php` header and the release tag, so a package's version has exactly
one source of truth.

**External** — keep your code in your own repository and register a one-file
pointer at `external/<slug>.json`:

```jsonc
{
  "slug": "bender",
  "type": "theme",
  "source": { "kind": "github-release", "repo": "mindstellar/theme-bender" },
  "asset_pattern": "^bender_.*\\.zip$",
  "categories": ["general"],
  "short_description": "The legacy Osclass theme, kept for compatibility."
}
```

The builder fetches your releases, picks the matching asset, reads the header
block **out of the zip** — so name, version and compatibility come from the real
artifact, never a hand-edited claim — computes its `sha256`, and emits an entry
identical in shape to an in-repo one. Core never learns the difference.

Both `storefront` and `bender` are registered this way today.

## What happens to your pull request

CI validates **only the package your PR changed**, and checks that:

- the header block parses, and its required fields are present
- the slug matches the directory or manifest
- compatibility metadata is sane
- the package actually installs — a smoke install runs against a real core

Deprecated-function use is reported as a **warning, never a failure**. It tells
you what to fix without blocking a release your users are waiting for.

On merge, CI builds the zip, tags it, cuts a GitHub Release, and rebuilds the
catalog.

## How installs read the catalog

The catalog is static JSON on GitHub Pages, with a `raw.githubusercontent.com`
mirror as a fallback. Core fetches it with a conditional `GET` about once a day
and caches the result.

This is deliberate: core must never call `api.github.com` per installed package.
Unauthenticated GitHub API allows 60 requests per hour per IP — a shared budget
on shared hosting — and a site with fifteen plugins would exhaust it on a single
update check. One cached catalog request answers for every package at once.

## Requirements for listing

- **GPL-compatible licence.** ShopClass is GPLv3; the ecosystem is too.
- **A working `index.php` header block** — see the
  [package specification](/docs/developers/package-spec/).
- **A real support URL**, so users have somewhere to go.

Commercial plugins are welcome to list a free or trial edition and sell
elsewhere; what the catalog serves has to be installable and GPL-compatible.

## The full design

[`docs/MARKET.md`](https://github.com/mindstellar/shopclass/blob/master/docs/MARKET.md)
in the repository covers the catalog contract, the validation gate and the
security posture in full.
