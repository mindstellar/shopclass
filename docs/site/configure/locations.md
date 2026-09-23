---
title: Install location data
description: Add countries, regions and cities to ShopClass so visitors can find listings near them — from the admin panel or the command line.
sidebar:
  order: 3
---

"For sale near me" only works if your site knows the places near your visitors.
ShopClass ships with no places at all. A site that serves one country does not
need 1.6 million place names, so you install only the countries you serve.

## From the admin panel

**Admin → Listings → Locations** has two tabs.

### Data — install and update countries

**Data** lists all 255 countries in the published catalog. Filter them by **All**,
**Installed**, **Updates** or **Not installed**. Install a country, and its
regions and cities come with it.

An import never deletes a place that has listings.

Large countries are fine. Germany's 91,385 places import in well under a minute.
The import works in small batches, so no single request runs long enough to time
out.

The tab also shows the date of the catalog, and a **Check for updates** button.

#### Listing counts

The number of listings shown for each place can go wrong after an import or a
bulk change. **Recalculate** counts them again. It shows its progress, for
example *27% counted: 24,198 of 88,301 locations*. The site stays online while it
runs.

### Browse — edit what you have

**Browse** shows your installed places: countries, then regions, then cities. The
breadcrumb at the top takes you back up a level.

Each row shows the name, the slug (the name as it appears in web addresses), how
many places sit under it, how many listings it has, and whether it is shown or
hidden.

| Tool | What it does |
|---|---|
| **Search** | Type a name. Choose **This level**, or **Everywhere** to search all places. |
| **A–Z strip** | Jump to a letter. It appears when a level has many entries. |
| **Paging** | 50 rows a page, for example *Showing 1–50 of 17,505 cities*. |
| **Edit** | Opens a panel beside the list. Rename, change the slug, hide or show. |
| **Add** | **Add country**, **Add region** or **Add city**, depending on the level. |
| **Bulk actions** | Tick rows, then apply one action to all of them. |

### Deleting a location

Before you delete, ShopClass shows what goes with it: the places under it, how
many listings it deletes, and how many users keep their account but lose their
location.

If any listing will be deleted, you must type something before the button
works. For one place, type its name. For several, type the number of listings.
If no listing is affected, you only confirm.

You cannot undo a delete.

:::caution[Editing locations affects your statistics]
The listing counts do not change until you recalculate them on the **Data** tab.
:::

## From the command line

```bash
php oc-cli.php location:status                    # what is installed, and whether it is current
php oc-cli.php location:update --country=IN       # install or update one country
php oc-cli.php location:update --all              # update every country already installed
php oc-cli.php location:update --country=IN --dry-run
```

:::note[`--all` does not mean "all countries"]
It updates **every country already installed on this site**. It never adds a
country. To add one, always name it with `--country=`.
:::

`--dry-run` works out every change and then undoes it. Use it to see how big an
update is before you run it for real.

## Keeping it current

Place names change. Towns merge, cities get new names, spellings get fixed.

ShopClass checks whether your data is current with one small request. It does not
download anything to find out. `location:status` shows the answer, and the admin
screen shows an update prompt.

Nothing downloads until you ask. If the catalog is rebuilt but nothing in it
changed, you get no prompt.

## Where the data comes from

The data is [**mindstellar/location-data**](https://github.com/mindstellar/location-data):
countries, regions and more than 1.6 million towns and cities, built from
Wikidata. It is published under **CC0**, so you owe no credit and no conditions
for the data your site imports.

The catalog lives at `https://geo.mindstellar.com/releases/latest.json`.
ShopClass always reads the latest release. So a fixed place name reaches your site
without waiting for a new ShopClass version.

### Using another source

To use a local mirror, a test copy or one fixed release, set this environment
variable:

```bash
OSC_LOCATIONS_JSON_URL=https://mirror.example.com/locations/latest.json
```

Or set it from a plugin:

```php
osc_add_filter('locations_json_url', function () {
    return 'https://mirror.example.com/locations/latest.json';
});
```

For safety, the address can only point to a catalog on the same site. Whoever
serves it cannot send your install to another site.

## Importing your own data

You may have places of your own: a country the catalog covers badly, or your own
service areas. Import them as SQL through **Admin → Tools → Import**, or with any
MySQL program.

Two rules:

- Replace `/*TABLE_PREFIX*/` with your table prefix (`oc_` by default). The admin
  import does this for you.
- Do not import a country or region twice. A second import adds duplicates. It
  does not update the rows you have. Delete the old rows first.
