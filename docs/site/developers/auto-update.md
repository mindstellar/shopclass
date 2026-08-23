---
title: Self-hosted updates
description: The legacy Update URI mechanism for ShopClass plugins and themes hosted outside the registry, and why the market replaced it.
sidebar:
  order: 8
---

Before the registry existed, a plugin or theme advertised its own updates: you
put an `Update URI` in the header block, and core polled that URL for a JSON
document describing the latest version.

**That mechanism still works**, and existing packages relying on it keep
updating. For anything new, [the market](/docs/developers/market/) is the better
route — including for code you host in your own repository, which can be
registered with a one-file pointer without moving your source anywhere.

## Why the market replaced it

Self-hosted update URLs put one HTTP request per installed package into every
update check. A site with fifteen plugins made fifteen outbound calls, each to a
different author's server, each able to be slow, down, or gone. The catalog
answers for every package in one cached request, and it verifies a `sha256`
against the real artifact — the update URL mechanism verifies nothing.

## The legacy contract

Declare the endpoint in your header block:

```php
Plugin update URI: https://example.com/updates/myplugin.json
```

It must return JSON in this shape:

```json
{
  "s_title": "My Plugin",
  "s_description": "What it does. HTML accepted.",
  "s_version": "2.1.0",
  "e_type": "PLUGIN",
  "s_source_file": "https://example.com/downloads/myplugin-2.1.0.zip",
  "s_update_url": "https://example.com/updates/myplugin.json",
  "s_compatible": "6.0.0,6.1.0,6.2.0",
  "s_contact_name": "Your name",
  "s_banner": "banner.jpg",
  "s_banner_path": "https://example.com/banners/",
  "i_total_downloads": "1234",
  "dt_mod_date": "2026-01-15 10:00:00",
  "dt_pub_date": "2025-06-01 09:00:00"
}
```

| Field | Notes |
|---|---|
| `s_version` | Any alphanumeric string is accepted, but use `MAJOR.MINOR.PATCH` — core has to decide whether it is *newer* than what is installed, and only a sortable version answers that. |
| `e_type` | One of `PLUGIN`, `THEME` or `LANGUAGE`. |
| `s_source_file` | Direct link to the zip. Must be reachable without authentication. |
| `s_update_url` | The endpoint itself, so it can be re-checked after installation. |
| `s_compatible` | Comma-separated core versions you support. |

The `Update URI` must be unique per package.

:::caution[Serve it over HTTPS]
This endpoint decides what code gets downloaded and executed on somebody else's
site. Over plain HTTP, anyone on the path can rewrite `s_source_file`.
:::

## Moving to the market

You do not have to give up your own repository or release process. Register an
`external/<slug>.json` pointer in the registry; the catalog builder reads your
GitHub releases and publishes entries from the real artifact. See
[the market](/docs/developers/market/).
