---
title: Self-hosted updates
description: The legacy Update URI mechanism for ShopClass plugins and themes hosted outside the registry, and why the market replaced it.
sidebar:
  order: 10
---

Before the market existed, a plugin or theme advertised its own updates: you
put an `Update URI` in the header block, and a JSON document at that URL told
core the latest version.

**Core still parses the header field**, and the code that reads it
(`mindstellar\upgrade\Plugin::getPackageInfo()`,
`oc-includes/osclass/classes/upgrade/Plugin.php:68`; `Theme::getPackageInfo()`,
`oc-includes/osclass/classes/upgrade/Theme.php:68`) still works if something
calls it. **Nothing in the admin panel or the CLI calls it.** A package that
relies only on `Update URI` gets no "update available" notice and no one-click
install in this version — the admin's update badge and install flow come from
`mindstellar\market\PackageIndex`, which only knows about packages in the
catalog. For anything new, [the market](/docs/developers/market/) is the route
that actually shows up in the admin — including for code you host in your own
repository, registered with a one-file pointer, no source move required.

## Why the market replaced it

A self-hosted update URL is one more server that has to answer, for every
plugin and theme, on every update check — slow, down, or gone all count against
your package. The catalog answers for every package in one cached request per
site, and it verifies a `sha256` against the real artifact before installing —
the `Update URI` fields above give core no way to verify anything.

## The legacy contract

Declare the endpoint in your header block:

```php
Plugin update URI: https://example.com/updates/myplugin.json
```

`getPackageInfo()` reads that URL two different ways, depending on what it is.

### A GitHub Releases API URL

If the URL contains `api.github.com`, it is read as a real GitHub Releases API
response (the JSON GitHub itself returns) — not a custom shape. Three fields
are used: `tag_name` (the version — a leading `v` is stripped),
`assets[0].browser_download_url` (the zip to download), and `prerelease`
(skip this release unless the admin turned on pre-releases).

### Any other URL

It must return JSON with these fields. Everything else in the payload is
ignored:

```json
{
  "s_source_file": "https://example.com/downloads/myplugin-2.1.0.zip",
  "s_version": "2.1.0",
  "s_compatible": "6.2.0,6.3.0,6.4.0"
}
```

| Field | Notes |
|---|---|
| `s_source_file` | Required. Direct link to the zip, reachable without authentication. Core throws an error if this is missing. |
| `s_version` | Required. Any alphanumeric string is accepted, but use `MAJOR.MINOR.PATCH` — core has to decide whether it is *newer* than what is installed, and only a sortable version answers that. |
| `s_compatible` | Optional, comma-separated core versions you support. Core only looks at this when your header block declares none of `Requires Shopclass`, `Tested up to` or `Requires PHP` — declare those instead and this field is never read. |

:::caution[Serve it over HTTPS]
This endpoint decides what code gets downloaded and executed on somebody else's
site. Over plain HTTP, anyone on the path can rewrite `s_source_file`.
:::

## Moving to the market

You do not have to give up your own repository or release process. Register an
`external/<slug>.json` pointer in the registry; the catalog builder reads your
GitHub releases and publishes entries from the real artifact. See
[the market](/docs/developers/market/).
