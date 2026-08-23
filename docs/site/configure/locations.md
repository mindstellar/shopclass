---
title: Install location data
description: Add countries, regions and cities to ShopClass so visitors can filter listings by place — from the admin panel or the command line.
sidebar:
  order: 3
---

Location is half of what makes a classifieds site useful: *for sale near me*
only works if the site knows what "near me" contains. ShopClass ships with no
location data — a site serving one country has no business carrying 1.6 million
place names — so you install the countries you actually serve.

## From the admin panel

**Admin → Listings → Locations** lists every country in the published catalog
alongside what this install currently holds. Pick a country and install it; the
regions and cities come with it.

Installing a large country moves a lot of rows. If the request times out
half-way, use the command line instead — it has no web-server timeout to hit.

## From the command line

```bash
php oc-cli.php location:status                    # what is installed, and whether it is current
php oc-cli.php location:update --country=IN       # install or update one country
php oc-cli.php location:update --all              # update every country already installed
php oc-cli.php location:update --country=IN --dry-run
```

:::note[`--all` does not mean "all countries"]
It updates **every country already installed on this site**. The command
refreshes what you have; it does not decide what you should have. Adding a new
country is always an explicit `--country=`.
:::

`--dry-run` computes every change and then rolls it back, so you can see the
size of an update before committing to it.

## Keeping it current

Place names change — councils merge, cities are renamed, spellings are
corrected. The catalog carries a content-derived version and a per-country
checksum, so ShopClass answers "is my data current?" with one small request
rather than by re-downloading anything. `location:status` shows you the answer,
and the admin screen surfaces it as an update prompt.

Nothing is downloaded until you ask for it, and a routine upstream rebuild that
finds no changes never produces a prompt.

## Where the data comes from

The dataset is [**mindstellar/location-data**](https://github.com/mindstellar/location-data)
— countries, administrative divisions and 1.6M+ settlements built from Wikidata
and published **CC0**. No attribution or share-alike condition travels with the
data your site imports.

The catalog is published at `https://geo.mindstellar.com/releases/latest.json`.
Core follows that pointer rather than pinning a release, so a corrected place
name reaches installs without waiting for a ShopClass release.

### Pointing somewhere else

To use a local mirror, a staging copy or a pinned release, set an environment
variable:

```bash
OSC_LOCATIONS_JSON_URL=https://mirror.example.com/locations/latest.json
```

Or filter it from a plugin:

```php
osc_add_filter('locations_json_url', function () {
    return 'https://mirror.example.com/locations/latest.json';
});
```

For safety, a pointer may only resolve to a manifest on its own origin — whoever
serves the pointer cannot redirect an install somewhere else.

## Importing your own data

If you have location data of your own — a country the catalog does not cover
well, or a custom set of service areas — you can import SQL directly through
**Admin → Tools → Import**, or with any MySQL client.

Two rules:

- Replace the `/*TABLE_PREFIX*/` placeholder with your actual prefix (`oc_` by
  default) unless you are importing through the admin panel, which substitutes
  it for you.
- Do not install a country or region twice. Re-importing over existing rows
  creates duplicates rather than updating them; remove the old rows first.
