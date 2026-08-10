# Geo search

Core owns **coordinates and distance**. Plugins own **providers and engines**.

Shopclass stores `d_coord_lat` / `d_coord_long` on every listing today, but nothing reads
them: no radius filter, no distance sort, not even an index. They are populated only when
an admin picks a map provider, by a blocking HTTP call inside the publish request, and the
only consumer is a pin on a theme's item page.

This document specifies turning that into a real feature: *listings within N km of a
place*. The hard part is not the query — it is that a radius search silently hides every
listing without coordinates, so **coverage is the feature**. Everything below is arranged
around getting coverage to ~100% on day one, on an existing install, with no API key.

---

## 1. The model

Three coordinate sources, in descending precision. Every listing resolves to exactly one:

| Precision | Source | Typical accuracy |
|---|---|---|
| `exact` | Geocoded from the listing's street address | 10–100 m |
| `city` | Centroid of the listing's `fk_i_city_id` | 1–20 km |
| `region` | Centroid of `fk_i_region_id`, when no city is set | 20–200 km |

The resolved coordinate is **denormalised onto `t_item_location`** together with the
precision that produced it. It is not resolved at query time.

That is a deliberate trade. A `COALESCE` across a join to `t_city` cannot use an index, so
every radius search would scan the whole location table. Denormalising costs one enum
column and a re-stamp when a listing's city changes; it buys an ordinary indexed range
scan.

The consequence that matters: **radius search works before any provider is configured.**
City centroids come from the location data an install already downloads (§4), so a fresh
install and an upgraded one both get usable coverage immediately. Address-level geocoding
is then an accuracy upgrade that can be rolled out slowly, per site, within a free tier —
not a prerequisite that blocks the feature behind an API key and a backfill.

---

## 2. Schema

One new table, five columns, one index. Migration `0023_geo_search.php`.

### `t_city` and `t_region` — centroids

```sql
ALTER TABLE t_city
    ADD COLUMN d_coord_lat  DECIMAL(10,6) NULL,
    ADD COLUMN d_coord_long DECIMAL(10,6) NULL;
-- same two columns on t_region
```

Nullable: an install whose location data predates centroids keeps working, at `region`
precision or with no coordinate at all, until it re-syncs (§4).

### `t_item_location` — resolved coordinate and its provenance

```sql
ALTER TABLE t_item_location
    ADD COLUMN e_coord_precision ENUM('exact','city','region') NULL,
    ADD COLUMN dt_geocoded DATETIME NULL,
    ADD COLUMN i_geocode_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD INDEX idx_coords (d_coord_lat, d_coord_long);
```

`e_coord_precision IS NULL` means the row has no usable coordinate — the only state a
radius search may legitimately exclude.

`i_geocode_attempts` exists so an address that no provider can resolve is retried a bounded
number of times and then left alone. Without it the backfill queue never drains and burns a
free tier forever on the same unresolvable rows.

`idx_coords` is the whole performance story. `(lat, lng)` in that order, because latitude is
the more selective of the two for the box shape a radius produces at most populated
latitudes.

### `t_geocode_cache`

```sql
CREATE TABLE t_geocode_cache (
    s_hash        CHAR(64) NOT NULL,     -- sha256 of the normalised query + provider
    s_provider    VARCHAR(32) NOT NULL,
    s_query       VARCHAR(255) NOT NULL, -- normalised, for debugging and eviction
    d_coord_lat   DECIMAL(10,6) NULL,    -- NULL = provider returned no match
    d_coord_long  DECIMAL(10,6) NULL,
    dt_created    DATETIME NOT NULL,
    dt_expires    DATETIME NULL,         -- per-provider TTL; NULL = no expiry
    PRIMARY KEY (s_hash),
    INDEX idx_expires (dt_expires)
) ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci';
```

Classifieds addresses repeat enormously — the same city, the same few neighbourhoods, over
and over. On the search side the repetition is worse: a handful of place names account for
most queries. This table is what makes a 5,000/day free tier viable for a real site, and it
is the single highest-leverage piece of the design.

Negative results are cached too (`d_coord_lat IS NULL`). A typo'd address that resolves to
nothing must not cost a provider call on every retry.

`dt_expires` is set by the provider, not by core — see §3.

**No queue table.** Pending work is `t_item_location WHERE e_coord_precision <> 'exact' AND
i_geocode_attempts < 3`, which the existing index answers well enough at cron cadence. A
queue table would be a second source of truth for the same fact.

---

## 3. Providers

### The contract

```php
interface GeocodeProvider
{
    /** Stable slug, e.g. 'locationiq'. */
    public function id(): string;

    /** Human label for the admin picker. */
    public function label(): string;

    /** True when the provider has everything it needs to run (usually a key). */
    public function isConfigured(): bool;

    /** Forward geocode. Returns [lat, lng] or null when there is no match. */
    public function geocode(string $address, ?string $countryCode = null): ?array;

    /** Seconds a result may be cached, or null for indefinitely. */
    public function cacheTtl(): ?int;

    /** Max requests per second core will issue. */
    public function rateLimit(): float;

    /** Markup required on any page showing derived data, or '' if none. */
    public function attribution(): string;
}
```

`GeocodeProviderRegistry` mirrors `PaymentGatewayRegistry` in [BILLING.md](BILLING.md):
plugins register on a hook, core picks the one named in preferences, and an unconfigured or
missing provider degrades to centroid precision rather than failing.

### What core ships

| Provider | Key | Free tier | Notes |
|---|---|---|---|
| **LocationIQ** *(recommended default)* | yes | 5,000/day, 2 req/s, 60/min | OSM-derived, commercial use permitted with attribution, has a real autocomplete endpoint |
| **Google** | yes | pay-as-you-go with monthly credit | Best coverage for messy free-text addresses; strictest terms |
| **Nominatim** | no | 1 req/s, community-funded | For self-hosted instances. Pointing this at the public OSMF endpoint is not a supported configuration |

**MapQuest is removed.** It survives as a working endpoint but its open APIs were sunset in
2022 and it has no path forward; it becomes a plugin if anyone wants it. The current
`openstreet_api_key` setting — labelled "OpenStreetMaps key" while holding a MapQuest
consumer key, and used by nothing the visitor ever sees — is dropped in the same migration.

### Storage terms — read this before choosing a default

Radius search requires storing a coordinate on the listing **permanently**. Provider terms
vary on whether that is allowed, and core cannot decide it for a site owner:

- **LocationIQ free** permits caching request/response pairs for **48 hours**; paid plans
  permit caching for the life of the subscription.
- **Google** restricts caching and storage of geocoding results.
- **Self-hosted Nominatim or Photon** has no such restriction — the site owns the instance.

Core's position: `t_geocode_cache` honours `cacheTtl()` strictly, so the *cache* is always
within terms. The listing coordinate is a stored attribute of the seller's own listing and
is kept indefinitely, because the feature cannot exist otherwise. The admin provider picker
states each provider's storage terms next to it, and the default configuration — centroids
only, no provider — has no terms question at all.

A site that wants exact geocoding at scale, permanently stored, wants LocationIQ paid or a
self-hosted instance. That should be said plainly in the admin, not buried here.

### Attribution

LocationIQ's free tier requires a visible link. Core exposes:

```php
osc_geocode_attribution();   // echoes the active provider's required markup, or nothing
```

Themes call it near the map or the results footer. Core also emits it automatically in the
search results footer when the active theme does not, so a site cannot silently fall out of
compliance by using a theme that forgot.

---

## 4. Where centroids come from

`mindstellar/geodata` derives from
[`dr5hn/countries-states-cities-database`](https://github.com/dr5hn/countries-states-cities-database),
**which already carries `latitude` and `longitude` for every city and state**. The current
normalisation drops those fields. Restoring them is the entire data task.

Three coordinated changes:

1. **`mindstellar/geodata`** — keep `latitude`/`longitude` through normalisation and emit
   them as `d_coord_lat` / `d_coord_long` in each country JSON. Confirm the upstream
   licence and record the attribution the repo owes.
2. **Installer** — `osc_install_json_locations()` reads the two new keys when present and
   ignores them when absent, so an older geodata snapshot still installs.
3. **Existing installs** — `geo:sync-centroids` (§8) re-fetches the country files an install
   already has and fills centroids by slug match, without touching names, slugs or ids.
   Also offered as a one-click action on the admin screen.

Cities that still have no centroid after a sync fall back to their region's; listings in
those cities resolve at `region` precision. Nothing is ever left uncoordinated because of a
data gap alone.

---

## 5. Search

### The API

```php
$search->addRadius(float $lat, float $lng, float $km);   // filter
$search->orderByDistance(float $lat, float $lng);        // sort
```

Both are additive to the existing model: `addRegion()` and `addCity()` keep working
unchanged, and combining them is legal (radius within a region is a valid, if unusual,
query).

### The SQL

Distance is not indexable — neither haversine nor `ST_Distance_Sphere` can use an index,
because both are per-row computations. So the index work is done by a **bounding box**, and
the exact distance only trims the box's corners into a circle over the few rows that
survive:

```sql
WHERE l.d_coord_lat  BETWEEN :latMin  AND :latMax
  AND l.d_coord_long BETWEEN :lngMin  AND :lngMax
  AND 6371 * ACOS(LEAST(1.0,
        COS(RADIANS(:lat)) * COS(RADIANS(l.d_coord_lat))
      * COS(RADIANS(l.d_coord_long) - RADIANS(:lng))
      + SIN(RADIANS(:lat)) * SIN(RADIANS(l.d_coord_lat))
      )) <= :km
```

with

```
latDelta = km / 111.045
lngDelta = km / (111.045 * COS(RADIANS(lat)))
```

Details that are wrong in most implementations of this and must not be wrong here:

- **`LEAST(1.0, …)`** guards the `ACOS` domain. Floating-point rounding pushes the argument
  a hair above 1.0 for a point at zero distance from itself, and `ACOS` then returns `NULL`
  — a listing at the exact search origin silently disappears.
- **Antimeridian.** When `lngMin < -180` or `lngMax > 180` the box must be split into two
  ranges `OR`ed together, or the query returns nothing across the date line.
- **Poles.** `COS(RADIANS(lat))` approaches zero, so `lngDelta` explodes. Clamp to the full
  `-180..180` range above ~85°.
- **`e_coord_precision IS NOT NULL`** is implied by the `BETWEEN`s, since `NULL` fails them.
  Do not add a redundant predicate that defeats the index.

**Why not `ST_Distance_Sphere`.** MariaDB only added it in 10.5.10 (May 2021) and the
documented floor is MariaDB 10.2. Since the bounding box carries the index either way, the
refinement runs over a handful of rows where inline haversine costs nothing — portability
is free. Spatial `POINT` columns are worse still: `NOT NULL` requirements plus SRID
divergence between MySQL 8 and MariaDB.

### URLs and cacheability

Search results are a cacheable public page ([CACHING.md](CACHING.md) §4). A free-form
lat/lng in the URL has unbounded cardinality and would make that cache useless.

So the canonical radius URL carries a **snapped origin and a banded distance**:

```
/search?near=bandra-mumbai&lat=19.06&lng=72.83&within=25
```

- Origin rounded to **2 decimal places** (~1.1 km). Also exactly the privacy rule in §6, so
  the URL never carries a finer coordinate than the page is allowed to display.
- `within` from a fixed set — 5, 10, 25, 50, 100 — interpreted in the site's configured
  distance unit. Anything else redirects to the nearest band.
- `near` is a human-readable slug for the resolved place, carried for display and
  shareability; `lat`/`lng` are authoritative.

That collapses the query space to something a micro-cache can actually hold, and makes
"listings near Bandra" a linkable, indexable page rather than a per-visitor result.

"Use my location" resolves the browser coordinate client-side, snaps it, and navigates to
the canonical URL. It is never a distinct uncacheable request shape.

### Units

One site-wide preference, `distance_unit` = `km` | `mi`. Stored and computed in kilometres
always; converted at display and when interpreting `within`. The URL carries no unit,
because a site does not change units per request and doubling the URL space for a
site-constant is not worth the cache cost.

---

## 6. What the public sees

The stored coordinate is exact. **What leaves the server is not.**

| Surface | Value |
|---|---|
| Radius matching, distance sort | Exact stored coordinate |
| Map pin, item JSON, feeds, sitemap | Rounded to **2 decimal places** (~±0.5–1.1 km) |
| Displayed distance | Rounded to the nearest whole unit — "3 km away", never "2.7 km" |

Metre-precision coordinates plus an exact distance readout let anyone trilaterate a
seller's home address from three queries. Rounding both closes that: the published
coordinate is coarse, and the displayed distance is too coarse to sharpen it by
triangulation.

Exposed through helpers so a theme cannot bypass it by accident:

```php
osc_item_latitude();          // rounded — the public value
osc_item_longitude();         // rounded
osc_item_distance();          // rounded, in the site unit, only within a radius search
```

The exact value has no output helper. Code that needs it reads the model directly, and
there are exactly two such callers: the radius filter and the distance sort.

This is a behaviour change to `osc_item_latitude()` / `osc_item_longitude()`, which
currently return the raw column. It is a rounding of an existing return value, not a
signature or name change, so themes calling them keep working — they just stop leaking
street-level precision. Called out in the release notes as a change, not a break.

---

## 7. Admin surface

The two map settings blocks merge into **one** screen — today the provider lives under
Settings → Listings and the keys under Settings → General, which is how the MapQuest field
went years mislabelled without anyone noticing.

**Settings → Location** carries:

- Geocoding provider — `None (city centroids only)` | LocationIQ | Google | Nominatim,
  each with its free-tier limit and storage terms stated inline
- API key for the selected provider, with a **Test** button that geocodes a known address
  and reports the round trip
- Distance unit
- Map provider and key (rendering, theme-facing — unchanged in behaviour)
- **Coverage**: *"38,204 of 41,910 listings have address-level coordinates. 3,706 pending."*
  with **Run backfill now** and **Sync city centroids**

Coverage is the number that tells an admin whether the feature is working. It is the first
thing on the screen, not a diagnostic buried in Tools.

---

## 8. CLI and cron

```
geo:sync-centroids   Fill city/region centroids from the location data source
geo:backfill         Geocode listings that lack address-level coordinates
                     [--limit=N] [--force] [--dry-run]
geo:stats            Print coverage by precision
```

`geo:backfill` is resumable, respects `rateLimit()`, stops on repeated provider errors
rather than burning the day's quota, and increments `i_geocode_attempts` on every failure.

On `cron_hourly`, core drains a bounded slice of the same queue — enough that a normal site
converges without anyone running a command, small enough that it never becomes the reason
cron times out.

**Publish stops blocking.** Today `ItemActions::getItemCoordinates()` makes a synchronous
HTTP call with no overall timeout — 5 s to connect, then aborting only after 30 s below
1 byte/s. The new path is: resolve the centroid inline (a local lookup, always available),
check `t_geocode_cache` for the address, and if it misses, leave `e_coord_precision` at
`city` and let cron upgrade it. The listing is live and findable immediately; it sharpens
within the hour.

---

## 9. Extension points

Core provides the seams. It does not provide, test, or support what plugs into them.

**External search engines.** The `search_results` filter already exists
(`CWebSearch.php`) and already hands a listener the parsed `Search` model plus the request
params, letting it answer the query itself and optionally return its own model. A
Manticore or Elasticsearch backend implements radius with the engine's own geo primitives
and returns ids; core keeps URL parsing, the view export and the feeds. Nothing new is
needed for this, and **core ships no such backend and makes no promise about any**.

**Providers.** `GeocodeProviderRegistry` takes plugin registrations on `geocode_providers`.
A plugin can add MapQuest, Mapbox, HERE, Photon or a self-hosted instance without touching
core.

**Filters.**

| Hook | Purpose |
|---|---|
| `geocode_providers` | Register additional providers |
| `geocode_address` | Rewrite the address string before it reaches a provider |
| `geocode_result` | Override or reject a provider's answer |
| `search_radius_sql` | Replace the bounding-box/haversine fragment wholesale |
| `item_coord_precision` | Round differently, or not at all, for the public surface |

---

## 10. Out of scope

- **Reverse geocoding** (coordinate → address). Nothing in core needs it.
- **Drawn or polygon search areas.** Circles only.
- **Routing, drive time, isochrones.** Straight-line distance only.
- **Geocoding user profiles.** `t_user.d_coord_lat` exists and stays as it is.
- **Any bundled Manticore or Elasticsearch integration** — §9.
- **Per-listing precision opt-in.** The rounding in §6 is site-wide. A "show my exact
  location" checkbox for business listings is a reasonable later addition; the
  `item_coord_precision` filter is the seam it would use.

---

## 11. Phases

Each phase ships something usable on its own.

| # | Delivers | Depends on |
|---|---|---|
| **1** | Centroid coverage: geodata carries coordinates, schema migration, installer reads them, `geo:sync-centroids`, resolution on publish/edit | geodata repo change |
| **2** | Search: `addRadius()` / `orderByDistance()`, canonical URLs, banded distances, public rounding and helpers | 1 |
| **3** | Search UI: location box with autocomplete, distance selector, "use my location", distance on result cards | 2 |
| **4** | Address-level geocoding: provider contract and registry, LocationIQ + Google + Nominatim, `t_geocode_cache`, cron drain, `geo:backfill`, admin Location screen with coverage | 1 |
| **5** | Cleanup: remove MapQuest and `openstreet_api_key`, merge the map settings blocks, drop `sensor=false`, timeout the remaining outbound calls | 4 |

Phases 1–3 need no provider, no API key and no outbound HTTP. A site gets working radius
search from them alone. Phase 4 is an accuracy upgrade, which is the correct shape for
something that costs money and quota.

---

## 12. Compatibility

Under the rules in [MARKET.md](MARKET.md), the surface a third party can see:

**Kept.** `osc_item_latitude()`, `osc_item_longitude()`, `osc_item_map_type()`,
`osc_google_maps_api_key()` — same names, same signatures. The two coordinate helpers
return a rounded value (§6); the rest are unchanged.

**Deprecated, not removed.** `osc_openstreet_api_key()` and
`osc_openstreet_geocode_url()` — they keep returning what they always did, gain a
`@deprecated` tag and a `Deprecate::deprecatedFunction()` call, and appear in
`deprecated-api.json` so the registry CI can warn package authors.
`osc_google_maps_geocode_url()` is deprecated in favour of the provider contract.

**Removed.** The `openstreet_api_key` preference row, and the MapQuest branch in
`ItemActions::getItemCoordinates()`. No helper disappears; a plugin reading the preference
directly gets an empty string, which is what an unconfigured install already returns.

**Schema.** Additive only — new nullable columns, one new index, one new table. No column
is dropped or retyped, so a rollback is `DROP` and nothing else. Themes selecting `*` from
`t_item_location` see extra columns, which is not a break.

**Themes.** `storefront` should call `osc_geocode_attribution()` and can stop geocoding
client-side, since listings now arrive with coordinates. Neither is required — an untouched
theme keeps working, it just does redundant work. Its `google.maps.Marker` usage
(deprecated since February 2024) and its runtime unpkg load of Leaflet are separate
theme-side issues, tracked with the theme, not here.
