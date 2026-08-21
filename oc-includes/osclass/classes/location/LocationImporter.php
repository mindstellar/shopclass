<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\location;

/**
 * Imports one country's regions and cities, updating what is already there instead of
 * inserting beside it.
 *
 * The data source renames administrative divisions constantly — between two snapshots a
 * year apart only about a third of surviving regions still carried the same name, while
 * roughly 90% of the source ids were unchanged. So identity is `i_source_id` and nothing
 * else: a rename is an UPDATE, not a delete plus an insert.
 *
 * Two invariants hold no matter what the incoming file says:
 *
 *   - `pk_i_id` is never reassigned. Every `t_item_location.fk_i_city_id` points at it.
 *   - No row is ever deleted. A division that vanished upstream is deactivated only when
 *     it holds no listings; one that still holds listings stays fully active, because a
 *     seller's location did not stop existing when a boundary was redrawn.
 */
final class LocationImporter
{
    /** How an incoming row was matched to an existing one. */
    public const MATCH_SOURCE = 'source_id';
    public const MATCH_SLUG   = 'slug';
    public const MATCH_NAME   = 'name';

    /** Rows per multi-row INSERT. Large enough to matter, small enough for max_allowed_packet. */
    private const INSERT_CHUNK = 500;

    /** Placeholders per IN () lookup. */
    private const SELECT_CHUNK = 500;

    /** Renames and unmatched rows recorded in the report before it stops collecting. */
    private const SAMPLE_LIMIT = 50;

    /**
     * The longest place name this install will store.
     *
     * A name past this length is not a place a seller picks out of a list -- upstream it
     * is a heritage listing enumerating street addresses, or a village whose name needed
     * a parenthesised administrative division to tell it from its namesakes. Neither
     * reads as a location in a dropdown, so the row is not imported at all.
     *
     * It also keeps every stored value inside the 60-character columns. Longer names were
     * silently truncated by the database, which made the next import see a difference
     * between what it sent and what came back, write the row again, and file another
     * slug-history entry -- on every run, for ever.
     */
    private const MAX_NAME = 55;

    /** @var bool When true, everything is computed and counted but nothing is written. */
    private $dryRun;

    /** @var array<string, mixed> */
    private $report;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Import one decoded country file.
     *
     * @param array $data as published: s_country_code, s_country_name, s_country_slug, regions[]
     *
     * @return array<string, mixed> the report, see resetReport()
     */
    public function import(array $data): array
    {
        $this->resetReport((string) ($data['code'] ?? $data['s_country_code'] ?? ''));

        // `code` in the published catalog, `s_country_code` in the older one.
        if (!isset($data['regions']) || !is_array($data['regions'])
            || (!isset($data['code']) && !isset($data['s_country_code']))
        ) {
            $this->report['error'] = 'malformed country file';

            return $this->report;
        }

        $this->noteIncomingRegions(array_map(
            function ($region) {
                return $this->normalizeRow($region, 'region');
            },
            $data['regions']
        ));

        $regions = (function () use ($data) {
            foreach ($data['regions'] as $region) {
                $cities = array();
                foreach ($region['settlements'] ?? $region['cities'] ?? array() as $city) {
                    $cities[] = $this->normalizeRow($city, 'city');
                }
                yield array($this->normalizeRow($region, 'region'), $cities);
            }
        })();

        return $this->runImport($data, $regions);
    }

    /**
     * Import one country from the catalog, streaming it where the catalog allows.
     *
     * The choice of format lives here rather than in each caller: a catalog that
     * publishes ndjson is read a line at a time, and one that does not falls back to
     * decoding the whole country. The older published catalog offers only the latter, so
     * both paths stay live.
     *
     * @param LocationCatalog $catalog
     * @param array           $entry   a row from LocationCatalog::status()
     *
     * @return array the report, carrying 'error' when the country could not be fetched
     */
    public function importCountry(LocationCatalog $catalog, array $entry): array
    {
        if (($entry['ndjson'] ?? '') !== '') {
            $path = $catalog->countryNdjsonFile((string) $entry['ndjson'], (string) ($entry['ndjson_sha'] ?? ''));
            if ($path !== null) {
                try {
                    return $this->importNdjson($path);
                } finally {
                    @unlink($path);
                }
            }
            // Fall through: a catalog that lists ndjson but cannot serve it should still
            // import, rather than failing when the whole-file form is sitting right there.
            $fellBack = true;
        }

        $data = $catalog->countryFile((string) $entry['file']);
        if ($data === null) {
            $this->resetReport((string) ($entry['code'] ?? ''));
            $this->report['error'] = 'could not download ' . (string) $entry['file'];

            return $this->report;
        }

        $report = $this->import($data);

        // Said out loud, because the two published forms of a country are not always the
        // same data: one catalog release described Great Britain as 259 regions in ndjson
        // and 5 in the whole-file form. Falling back on a checksum failure then imports a
        // different shape of the same country, and without this nothing says so.
        if (isset($fellBack)) {
            $report['fell_back'] = (string) $entry['ndjson'];
        }

        return $report;
    }

    /**
     * Import a country from ndjson: one JSON object per line, the country first, then
     * each region followed by its own cities.
     *
     * Read a line at a time so memory stays flat whatever the country's size — the whole
     * reason this format is published. Only one region's cities are held at once, since
     * a region's cities arrive together and are written when the next region begins.
     *
     * @param string $path a local file, as written by LocationCatalog::countryNdjsonFile()
     *
     * @return array the same report import() returns
     */
    public function importNdjson(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->resetReport('');
            $this->report['error'] = 'could not read the downloaded country file';

            return $this->report;
        }

        // The country is the first line, and everything after it needs it, so it is read
        // before the transaction opens rather than streamed with the rest.
        $country = null;
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true);
            if (is_array($row) && ($row['type'] ?? '') === 'country') {
                $country = $row;
                break;
            }
        }

        if ($country === null) {
            fclose($handle);
            $this->resetReport('');
            $this->report['error'] = 'malformed country file';

            return $this->report;
        }

        // The regions are read once on their own before the import starts. Deciding whether
        // a stored row is about to be claimed by an incoming id, or is an orphan from a
        // source this catalog no longer speaks, needs the whole set of incoming ids — and
        // the first region is imported long before the last one has been streamed. Only
        // region lines are decoded, so this costs a pass over the file and no memory worth
        // counting: a country has tens of regions and hundreds of thousands of settlements.
        $this->noteIncomingRegions($this->scanRegions($path));

        $regions = (function () use ($handle) {
            $region = null;
            $cities = array();

            while (($line = fgets($handle)) !== false) {
                $row = json_decode(trim($line), true);
                if (!is_array($row)) {
                    continue; // a blank or unreadable line is skipped, not fatal
                }

                if (($row['type'] ?? '') === 'region') {
                    if ($region !== null) {
                        yield array($region, $cities);
                    }
                    $region = $this->normalizeRow($row, 'region');
                    $cities = array();
                    continue;
                }

                // 'settlement' in the published catalog, 'city' in the older one.
                $type = $row['type'] ?? '';
                if (($type === 'settlement' || $type === 'city') && $region !== null) {
                    // Reduced here rather than after the region is complete: the published
                    // rows carry some twenty fields apiece — alternative names, sitelinks,
                    // elevation — and a large region holds tens of thousands of them, so
                    // buffering them whole is what the streaming format exists to avoid.
                    $cities[] = $this->normalizeRow($row, 'city');
                }
            }

            if ($region !== null) {
                yield array($region, $cities); // the last region has no successor to flush it
            }
        })();

        try {
            return $this->runImport($country, $regions);
        } finally {
            fclose($handle);
        }
    }

    /**
     * The region rows of an ndjson country file, without reading its settlements.
     *
     * @return array<int, array> normalised region rows
     */
    private function scanRegions(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return array();
        }

        $regions = array();
        try {
            while (($line = fgets($handle)) !== false) {
                // Decoding every settlement to discover it is not a region is the whole
                // cost of this pass, and a cheap string test skips almost all of it.
                if (strpos($line, '"region"') === false) {
                    continue;
                }
                $row = json_decode(trim($line), true);
                if (is_array($row) && ($row['type'] ?? '') === 'region') {
                    $regions[] = $this->normalizeRow($row, 'region');
                }
            }
        } finally {
            fclose($handle);
        }

        return $regions;
    }

    /**
     * Record which region ids this import brings and which region names it repeats.
     *
     * @param array<int, array> $regions normalised region rows
     */
    private function noteIncomingRegions(array $regions): void
    {
        $this->incomingRegionIds = $this->regionAmbiguous = array();

        $seen = array();
        foreach ($regions as $region) {
            if (isset($region['i_source_id'])) {
                $this->incomingRegionIds[(int) $region['i_source_id']] = true;
            }
            foreach (self::comparisonKeys($region['s_region_name'], $region['s_region_slug']) as $key) {
                if (isset($seen[$key])) {
                    $this->regionAmbiguous[$key] = true;
                }
                $seen[$key] = true;
            }
        }
    }

    /**
     * The distinct keys one row answers to, from its name and its slug.
     *
     * Deduplicated, because a row's slug is usually its own name with the spaces replaced
     * and both reduce to the same key. Counting that as the name being used twice makes
     * every row ambiguous with itself, and nothing matches anything.
     *
     * @return array<int, string>
     */
    private static function comparisonKeys($name, $slug): array
    {
        $keys = array();
        foreach (array((string) $name, (string) $slug) as $value) {
            $key = self::normalizeKey($value);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Put an upstream row into the field names the rest of this class works in.
     *
     * The published catalog names a place `name`/`slug`/`id`/`latitude`/`longitude`; an
     * earlier one used the column names themselves. Both are read here so a mirror of the
     * older catalog keeps importing, and so the mapping lives in one place rather than
     * being spread across the region and city paths.
     *
     * `source` names the upstream a row's id came from. It is one value today, and the
     * reason it exists is that an id only identifies a row within its own source — which
     * is what made ids from two datasets overwrite each other's rows. Carried here so
     * that a second source can be told apart without revisiting every call site.
     *
     * @param array  $row
     * @param string $kind 'region' or 'city'
     *
     * @return array
     */
    private function normalizeRow(array $row, string $kind): array
    {
        $nameKey = $kind === 'region' ? 's_region_name' : 's_city_name';
        $slugKey = $kind === 'region' ? 's_region_slug' : 's_city_slug';

        return array(
            $nameKey       => (string) ($row['name'] ?? $row[$nameKey] ?? ''),
            $slugKey       => (string) ($row['slug'] ?? $row[$slugKey] ?? ''),
            'i_source_id'  => isset($row['id']) ? (int) $row['id']
                : (isset($row['i_source_id']) ? (int) $row['i_source_id'] : null),
            'd_coord_lat'  => $row['latitude'] ?? $row['d_coord_lat'] ?? null,
            'd_coord_long' => $row['longitude'] ?? $row['d_coord_long'] ?? null,
            's_source'     => (string) ($row['source'] ?? ''),
        );
    }

    /**
     * The body both entry points share: one country, then regions with their cities.
     *
     * @param array    $country the country row, in either format's field names
     * @param iterable $regions yields [regionRow, citiesRows]
     *
     * @return array
     */
    private function runImport(array $country, iterable $regions): array
    {
        // `code` in the published catalog, `s_country_code` in the older one.
        $countryCode = (string) ($country['code'] ?? $country['s_country_code'] ?? '');
        if ($countryCode === '') {
            $this->resetReport('');
            $this->report['error'] = 'malformed country file';

            return $this->report;
        }

        // Reset here rather than only in the callers, so the counts belong to this run
        // whichever entry point started it.
        $this->resetReport($countryCode);

        // One transaction for the whole country. A half-imported country is worse than a
        // long transaction: it leaves regions whose cities never arrived, and the admin
        // has no way to tell how far it got.
        $run = function () use ($country, $regions, $countryCode) {
            $this->importCountryRow($country);
            foreach ($regions as [$region, $cities]) {
                // A region with nothing in it is a dead end: picking it offers an empty
                // list of cities. They are not places the catalog forgot to fill — they
                // are abolished councils and overlapping tiers of the hierarchy, whose
                // settlements sit correctly under the units that replaced them. Skipped
                // rather than imported, and any already stored is left unclaimed, so it
                // is retired by the same pass that retires anything else upstream dropped.
                if ($cities === array()) {
                    $this->report['regions']['skipped_empty']++;
                    continue;
                }
                // A region skipped for its name takes its cities with it, so it is decided
                // before they are imported rather than leaving them without a parent.
                if (self::nameTooLong((string) $region['s_region_name'])) {
                    $this->report['regions']['skipped_long']++;
                    continue;
                }
                $regionId = $this->importRegion($countryCode, $region);
                if ($regionId !== null) {
                    $this->importCities($regionId, $cities, $countryCode);
                }
            }
            $this->deactivateVanishedRegions($countryCode);
        };

        if ($this->dryRun) {
            // A dry run executes every statement for real and then rolls the whole thing
            // back, rather than estimating alongside a separate code path. It is the same
            // code, so the counts are exact, ids of newly inserted regions are real enough
            // to hang their cities off, and a constraint a real run would violate is
            // violated here too — where it is harmless.
            osc_db_begin();
            try {
                $run();
            } finally {
                osc_db_rollback();
            }
        } else {
            osc_db_transaction($run);
        }

        return $this->report;
    }

    /* ------------------------------------------------------------------ *
     * Country
     * ------------------------------------------------------------------ */

    private function importCountryRow(array $data): void
    {
        $table = DB_TABLE_PREFIX . 't_country';
        // Stored with the casing the catalog publishes (upper), which is what every
        // pre-existing row uses. The region/city foreign keys are lowercased, as they
        // always have been; the column collation is case-insensitive, so they still join.
        // `code`/`name`/`slug` in the published catalog, the column names themselves in
        // the older one — same fallback the region and city rows go through.
        $code    = (string) ($data['code'] ?? $data['s_country_code'] ?? '');
        $name    = (string) ($data['name'] ?? $data['s_country_name'] ?? '');
        $slug    = (string) ($data['slug'] ?? $data['s_country_slug'] ?? '');
        $current = osc_db_select_one(
            'SELECT pk_c_code, s_name, s_slug FROM ' . $table . ' WHERE pk_c_code = ?',
            array($code)
        );

        if ($current === null) {
            $this->report['country_inserted'] = true;
            osc_db_execute(
                'INSERT INTO ' . $table . ' (pk_c_code, s_name, s_slug) VALUES (?, ?, ?)',
                array($code, $name, $slug)
            );

            return;
        }

        // Country names do drift upstream ("Korea North" became "North Korea"), and
        // pk_c_code is the ISO code, so the rename is safe to apply.
        if ($current['s_name'] !== $name || $current['s_slug'] !== $slug) {
            $this->report['country_renamed'] = true;
            osc_db_execute(
                'UPDATE ' . $table . ' SET s_name = ?, s_slug = ? WHERE pk_c_code = ?',
                array($name, $slug, $code)
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Regions
     * ------------------------------------------------------------------ */

    /** @var array<int, array> pk_i_id => row, for regions of the country being imported */
    private $regionRows = array();

    /** @var array<int, bool> pk_i_id of regions an incoming row has already claimed */
    private $regionClaimed = array();

    /** @var array<string, int>|null lazily built lookups over $regionRows */
    private $regionBySource;
    private $regionBySlug;
    private $regionByName;

    /** @var array<int, true> source ids this import brings, for regions */
    private $incomingRegionIds = array();

    /** @var array<string, true> region names or slugs this import uses more than once */
    private $regionAmbiguous = array();

    private function importRegion(string $countryCode, array $incoming): ?int
    {
        $this->loadRegions($countryCode);

        $sourceId = isset($incoming['i_source_id']) ? (int) $incoming['i_source_id'] : null;
        $name     = (string) $incoming['s_region_name'];
        $slug     = (string) $incoming['s_region_slug'];

        $match = $this->matchRow(
            $sourceId,
            $slug,
            $name,
            $this->regionBySource,
            $this->regionBySlug,
            $this->regionByName,
            $this->regionRows,
            $this->regionClaimed,
            $this->incomingRegionIds,
            $this->regionAmbiguous
        );

        if ($match === null) {
            return $this->insertRegion($countryCode, $sourceId, $name, $slug, $incoming);
        }

        [$row, $how] = $match;
        $this->regionClaimed[(int) $row['pk_i_id']] = true;
        $this->countMatch('regions', $how);
        $this->updateRow(
            'REGION',
            DB_TABLE_PREFIX . 't_region',
            'pk_i_id',
            $row,
            $sourceId,
            $name,
            $slug,
            $incoming,
            null,
            $how
        );

        return (int) $row['pk_i_id'];
    }

    private function loadRegions(string $countryCode): void
    {
        if ($this->regionBySource !== null) {
            return;
        }

        $rows = osc_db_select(
            'SELECT pk_i_id, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active'
            . ' FROM ' . DB_TABLE_PREFIX . 't_region WHERE fk_c_country_code = ?',
            array(strtolower($countryCode))
        );

        $this->regionRows     = array();
        $this->regionBySource = array();
        $this->regionBySlug   = array();
        $this->regionByName   = array();
        foreach ($rows as $row) {
            $this->indexRow($row, $this->regionRows, $this->regionBySource, $this->regionBySlug, $this->regionByName);
        }
    }

    private function insertRegion(string $countryCode, ?int $sourceId, string $name, string $slug, array $incoming): ?int
    {
        $this->report['regions']['inserted']++;

        return osc_db_insert_id(
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_region'
            . ' (fk_c_country_code, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active)'
            . ' VALUES (?, ?, ?, ?, ?, ?, 1)',
            array(
                strtolower($countryCode),
                $sourceId,
                $name,
                $slug,
                $incoming['d_coord_lat'] ?? null,
                $incoming['d_coord_long'] ?? null,
            )
        );
    }

    private function deactivateVanishedRegions(string $countryCode): void
    {
        $this->loadRegions($countryCode);
        $orphans = array_values(array_diff(array_keys($this->regionRows), array_keys($this->regionClaimed)));
        if ($orphans === array()) {
            return;
        }

        $held = $this->idsHoldingListings('fk_i_region_id', $orphans);
        foreach ($orphans as $id) {
            if (isset($held[$id])) {
                $this->report['regions']['kept_stale']++;
                continue;
            }
            // Already deactivated by an earlier import: re-issuing the UPDATE would make
            // every subsequent run report work it did not do.
            if ((int) $this->regionRows[$id]['b_active'] === 0) {
                continue;
            }
            $this->report['regions']['deactivated']++;
            osc_db_execute(
                'UPDATE ' . DB_TABLE_PREFIX . 't_region SET b_active = 0 WHERE pk_i_id = ?',
                array($id)
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Cities
     * ------------------------------------------------------------------ */

    /**
     * @param array<int, array> $incomingCities
     */
    private function importCities(int $regionId, array $incomingCities, string $countryCode): void
    {
        if ($incomingCities === array()) {
            return;
        }

        // Looked up by source id across this country rather than just this region:
        // upstream re-parents cities between regions, and finding the row wherever it
        // currently sits turns that into an UPDATE of fk_i_region_id instead of a
        // duplicate. Country-wide and not table-wide — see citiesBySourceId().
        $bySource = $this->citiesBySourceId($incomingCities, $countryCode);

        $rows    = osc_db_select(
            'SELECT pk_i_id, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active'
            . ' FROM ' . DB_TABLE_PREFIX . 't_city WHERE fk_i_region_id = ?',
            array($regionId)
        );
        $inRegion = $bySlug = $byName = $indexed = array();
        foreach ($rows as $row) {
            $this->indexRow($row, $indexed, $inRegion, $bySlug, $byName);
        }

        // Names too long to be picked out of a list are dropped before anything else, so
        // they are absent from the id and ambiguity indexes too: a row that will not be
        // imported must not reserve a name or claim a stored row on its way past.
        $tooLong = 0;
        $incomingCities = array_values(array_filter(
            $incomingCities,
            static function (array $incoming) use (&$tooLong): bool {
                if (self::nameTooLong((string) $incoming['s_city_name'])) {
                    $tooLong++;

                    return false;
                }

                return true;
            }
        ));
        $this->report['cities']['skipped_long'] += $tooLong;

        if ($incomingCities === array()) {
            return;
        }

        // Which ids this import is bringing, and which names it brings more than once.
        // A name the catalog itself uses twice inside one region cannot identify a stored
        // row: 117 of India's villages are called Gopalpur and they share a region.
        $incomingIds = $ambiguous = $seen = array();
        foreach ($incomingCities as $incoming) {
            if (isset($incoming['i_source_id'])) {
                $incomingIds[(int) $incoming['i_source_id']] = true;
            }
            foreach (self::comparisonKeys($incoming['s_city_name'], $incoming['s_city_slug']) as $key) {
                if (isset($seen[$key])) {
                    $ambiguous[$key] = true;
                }
                $seen[$key] = true;
            }
        }

        $claimed = array();
        $pending = array();
        foreach ($incomingCities as $incoming) {
            $sourceId = isset($incoming['i_source_id']) ? (int) $incoming['i_source_id'] : null;
            $name     = (string) $incoming['s_city_name'];
            $slug     = (string) $incoming['s_city_slug'];

            $match = $this->matchRow(
                $sourceId,
                $slug,
                $name,
                $bySource,
                $bySlug,
                $byName,
                $indexed,
                $claimed,
                $incomingIds,
                $ambiguous
            );
            if ($match === null) {
                $this->report['cities']['inserted']++;
                $pending[] = array(
                    $regionId,
                    $countryCode,
                    $sourceId,
                    $name,
                    $slug,
                    $incoming['d_coord_lat'] ?? null,
                    $incoming['d_coord_long'] ?? null,
                );
                continue;
            }

            [$row, $how] = $match;
            $claimed[(int) $row['pk_i_id']] = true;
            $this->countMatch('cities', $how);
            $this->updateRow(
                'CITY',
                DB_TABLE_PREFIX . 't_city',
                'pk_i_id',
                $row,
                $sourceId,
                $name,
                $slug,
                $incoming,
                $regionId,
                $how
            );
        }

        $this->insertCities($pending);
        $this->deactivateVanishedCities($indexed, $claimed);
    }

    /**
     * Existing rows for the incoming source ids, anywhere in THIS country.
     *
     * Scoped to the country, and this is load-bearing rather than tidiness. A source id
     * only identifies a row within the source that issued it, and this table has held
     * ids from more than one: the previous dataset numbered cities 57,584–147,939 while
     * Wikidata QIDs run from 1 upwards, so the two ranges overlap almost entirely.
     * Searching the whole table matched an Indian city against a Northern Irish one that
     * merely shared the integer, renamed it and moved it to a British region — silent
     * corruption of data the import was not supposed to touch.
     *
     * The country comes from the region rather than t_city.fk_c_country_code, because
     * rows this importer inserted before it began setting that column have it NULL and
     * would otherwise look like they belong to no country, be missed here, and be
     * re-inserted as duplicates.
     *
     * Scoping this way keeps what the table-wide search was for — a city re-parented
     * between regions is still found and updated rather than duplicated — and gives up
     * only cross-country moves, which upstream does not do and which should not happen
     * silently if it ever did.
     *
     * @param array<int, array> $incomingCities
     *
     * @return array<int, array> i_source_id => row
     */
    private function citiesBySourceId(array $incomingCities, string $countryCode): array
    {
        $ids = array();
        foreach ($incomingCities as $incoming) {
            if (isset($incoming['i_source_id'])) {
                $ids[] = (int) $incoming['i_source_id'];
            }
        }
        if ($ids === array()) {
            return array();
        }

        $found = array();
        foreach (array_chunk(array_unique($ids), self::SELECT_CHUNK) as $chunk) {
            $rows = osc_db_select(
                'SELECT c.pk_i_id, c.fk_i_region_id, c.i_source_id, c.s_name, c.s_slug,'
                . ' c.d_coord_lat, c.d_coord_long, c.b_active'
                . ' FROM ' . DB_TABLE_PREFIX . 't_city c'
                . ' JOIN ' . DB_TABLE_PREFIX . 't_region r ON r.pk_i_id = c.fk_i_region_id'
                . ' WHERE c.i_source_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
                . ' AND r.fk_c_country_code = ?',
                array_merge($chunk, array($countryCode))
            );
            foreach ($rows as $row) {
                $found[(int) $row['i_source_id']] = $row;
            }
        }

        return $found;
    }

    /**
     * @param array<int, array<int, mixed>> $pending
     */
    private function insertCities(array $pending): void
    {
        if ($pending === array()) {
            return;
        }

        foreach (array_chunk($pending, self::INSERT_CHUNK) as $chunk) {
            $params = array();
            foreach ($chunk as $row) {
                foreach ($row as $value) {
                    $params[] = $value;
                }
            }
            // fk_c_country_code is written rather than left to default: the column exists
            // on t_city, themes and search read it, and rows inserted without it were
            // arriving NULL — a city belonging to no country.
            osc_db_execute(
                'INSERT INTO ' . DB_TABLE_PREFIX . 't_city'
                . ' (fk_i_region_id, fk_c_country_code, i_source_id, s_name, s_slug,'
                . ' d_coord_lat, d_coord_long, b_active)'
                . ' VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, 1)')),
                $params
            );
        }
    }

    /**
     * @param array<int, array> $indexed
     * @param array<int, bool>  $claimed
     */
    private function deactivateVanishedCities(array $indexed, array $claimed): void
    {
        $orphans = array_values(array_diff(array_keys($indexed), array_keys($claimed)));
        if ($orphans === array()) {
            return;
        }

        $held = $this->idsHoldingListings('fk_i_city_id', $orphans);
        foreach ($orphans as $id) {
            if (isset($held[$id])) {
                $this->report['cities']['kept_stale']++;
                continue;
            }
            if ((int) $indexed[$id]['b_active'] === 0) {
                continue;
            }
            $this->report['cities']['deactivated']++;
            osc_db_execute(
                'UPDATE ' . DB_TABLE_PREFIX . 't_city SET b_active = 0 WHERE pk_i_id = ?',
                array($id)
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Shared matching and writing
     * ------------------------------------------------------------------ */

    /**
     * Resolve an incoming row to an existing one.
     *
     * Source id first, always. Slug and name are only consulted for rows that carry no
     * source id yet — the one-time adoption pass on an install that predates them. Once a
     * row has an id, a name collision must never be allowed to steal it.
     *
     * @return array{0: array, 1: string}|null the matched row and how it matched
     */
    private function matchRow(
        ?int $sourceId,
        string $slug,
        string $name,
        array $bySource,
        array $bySlug,
        array $byName,
        array $rows,
        array $claimed,
        array $incomingIds = array(),
        array $ambiguous = array()
    ): ?array {
        if ($sourceId !== null && isset($bySource[$sourceId])) {
            $row = $bySource[$sourceId];
            if (!isset($claimed[(int) $row['pk_i_id']])) {
                return array($row, self::MATCH_SOURCE);
            }
        }

        foreach (array(
            array($bySlug, self::normalizeKey($slug), self::MATCH_SLUG),
            array($byName, self::normalizeKey($name), self::MATCH_NAME),
        ) as $attempt) {
            [$index, $key, $how] = $attempt;
            if ($key === '' || !isset($index[$key])) {
                continue;
            }
            // false means the key is held by more than one stored row, or by more than one
            // incoming row: either way it names no particular place and cannot match.
            $id = $index[$key];
            if ($id === false || isset($ambiguous[$key]) || isset($claimed[$id]) || !isset($rows[$id])) {
                continue;
            }
            // Never steal a row an incoming id is going to claim in its own right — that is
            // two distinct places sharing a name, and the incoming one needs its own row.
            //
            // A stored id that appears nowhere in what is being imported is a different
            // matter: it was issued by a source this catalog no longer speaks, so nothing
            // will ever claim it and the row would be retired and replaced by an identical
            // one. Adopting it instead is what carries a place across a change of source,
            // and is why the previous dataset's ids do not strand every row that held one.
            $storedId = $rows[$id]['i_source_id'];
            if ($storedId !== null && $sourceId !== null && isset($incomingIds[(int) $storedId])) {
                continue;
            }

            return array($rows[$id], $how);
        }

        return null;
    }

    /**
     * Update one row in place, recording a slug change so old URLs keep resolving.
     */
    private function updateRow(
        string $type,
        string $table,
        string $pk,
        array $row,
        ?int $sourceId,
        string $name,
        string $slug,
        array $incoming,
        ?int $newParentId = null,
        string $how = self::MATCH_SOURCE
    ): void {
        $id  = (int) $row[$pk];
        $lat = $incoming['d_coord_lat'] ?? null;
        $lng = $incoming['d_coord_long'] ?? null;

        $set    = array();
        $params = array();
        if ((string) $row['s_name'] !== $name) {
            $set[]    = 's_name = ?';
            $params[] = $name;
        }
        if ((string) $row['s_slug'] !== $slug) {
            $set[]    = 's_slug = ?';
            $params[] = $slug;
            $this->recordSlugChange($type, $id, (string) $row['s_slug'], $slug);
            $this->sample('renames', array('type' => $type, 'id' => $id, 'from' => $row['s_slug'], 'to' => $slug));
            $this->report[$type === 'REGION' ? 'regions' : 'cities']['renamed']++;
        }
        // The id is taken over on adoption, not only when the row has none.
        //
        // A row matched by name rather than by id is one whose id came from a source this
        // catalog no longer speaks, and leaving that id in place means the row is found by
        // its name again on every future import — a match this deliberately treats as the
        // last resort, standing in permanently for the one that should be first. Writing
        // the new id is what actually completes the move to a new source; the row is
        // identified by id from then on. No other row can hold that id, because the id
        // lookup is what failed to find one just now.
        $adopting = $sourceId !== null
            && ($row['i_source_id'] === null || ($how !== self::MATCH_SOURCE && (int) $row['i_source_id'] !== $sourceId));
        if ($adopting) {
            $set[]    = 'i_source_id = ?';
            $params[] = $sourceId;
        }
        if ($this->coordDiffers($row['d_coord_lat'], $lat) || $this->coordDiffers($row['d_coord_long'], $lng)) {
            $set[]    = 'd_coord_lat = ?';
            $params[] = $lat;
            $set[]    = 'd_coord_long = ?';
            $params[] = $lng;
        }
        if ($newParentId !== null && isset($row['fk_i_region_id']) && (int) $row['fk_i_region_id'] !== $newParentId) {
            $set[]    = 'fk_i_region_id = ?';
            $params[] = $newParentId;
            $this->report['cities']['reparented']++;
        }
        // A division that came back upstream is live again.
        if ((int) $row['b_active'] === 0) {
            $set[] = 'b_active = 1';
        }

        if ($set === array()) {
            $this->report[$type === 'REGION' ? 'regions' : 'cities']['unchanged']++;

            return;
        }

        $this->report[$type === 'REGION' ? 'regions' : 'cities']['updated']++;
        $params[] = $id;
        osc_db_execute('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . $pk . ' = ?', $params);
    }

    /**
     * Coordinates arrive as strings and are stored as DECIMAL(10,6); comparing them as
     * strings would rewrite every row on every import over "20.18" versus "20.180000".
     */
    private function coordDiffers($stored, $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored !== $incoming;
        }

        return abs((float) $stored - (float) $incoming) > 0.0000005;
    }

    private function recordSlugChange(string $type, int $id, string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === '' || $oldSlug === $newSlug) {
            return;
        }

        $table = DB_TABLE_PREFIX . 't_location_slug_history';
        // A slug that is now live must never redirect, so drop any history row claiming it.
        osc_db_execute('DELETE FROM ' . $table . ' WHERE e_type = ? AND s_slug = ?', array($type, $newSlug));
        osc_db_execute(
            'INSERT INTO ' . $table . ' (e_type, s_slug, fk_i_id, dt_date) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE fk_i_id = VALUES(fk_i_id), dt_date = VALUES(dt_date)',
            array($type, $oldSlug, $id, date('Y-m-d H:i:s'))
        );
    }

    /**
     * Which of $ids are referenced by at least one listing.
     *
     * @param array<int, int> $ids
     *
     * @return array<int, bool>
     */
    private function idsHoldingListings(string $column, array $ids): array
    {
        $held = array();
        foreach (array_chunk($ids, self::SELECT_CHUNK) as $chunk) {
            $rows = osc_db_select(
                'SELECT DISTINCT ' . $column . ' AS id FROM ' . DB_TABLE_PREFIX . 't_item_location'
                . ' WHERE ' . $column . ' IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            );
            foreach ($rows as $row) {
                $held[(int) $row['id']] = true;
            }
        }

        return $held;
    }

    /* ------------------------------------------------------------------ *
     * Bookkeeping
     * ------------------------------------------------------------------ */

    /**
     * @param array<int, array>  $rows
     * @param array<int, array>  $bySource
     * @param array<string, int> $bySlug
     * @param array<string, int> $byName
     */
    private function indexRow(array $row, array &$rows, array &$bySource, array &$bySlug, array &$byName): void
    {
        $id        = (int) $row['pk_i_id'];
        $rows[$id] = $row;
        if ($row['i_source_id'] !== null) {
            $bySource[(int) $row['i_source_id']] = $row;
        }

        // A name or slug held by more than one row identifies nothing, so the key is
        // poisoned rather than won by whichever row was read first. India publishes 117
        // distinct villages called Gopalpur inside a single region; letting the first of
        // them answer for the name would update one row and retire the other 116.
        $slug = self::normalizeKey((string) $row['s_slug']);
        if ($slug !== '') {
            $bySlug[$slug] = array_key_exists($slug, $bySlug) ? false : $id;
        }

        $name = self::normalizeKey((string) $row['s_name']);
        if ($name !== '') {
            $byName[$name] = array_key_exists($name, $byName) ? false : $id;
        }
    }

    /**
     * Whether a row names something this install will not store.
     *
     * Measured in characters rather than bytes, so a name is judged by its length as read
     * and not by how many bytes its accents happen to occupy.
     */
    private static function nameTooLong(string $name): bool
    {
        return mb_strlen($name) > self::MAX_NAME;
    }

    /**
     * The form a name or slug is compared in, which is not the form it is stored in.
     *
     * Stored names keep the capitalisation and the accents the catalog publishes, because
     * that is what a visitor reads. Matching them has to ignore both, along with the
     * spacing and punctuation that move between snapshots: "Villeneuve-d'Ascq" and
     * "Villeneuve d Ascq" are one place written twice, and a comparison that says
     * otherwise imports it twice.
     */
    private static function normalizeKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Accents fold to their base letter, so "Málaga" and "Malaga" compare equal.
        if (function_exists('transliterator_transliterate')) {
            $folded = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
            if (is_string($folded) && $folded !== '') {
                $value = $folded;
            }
        } elseif (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
            if (is_string($folded) && $folded !== '') {
                $value = $folded;
            }
        }

        $value = mb_strtolower($value);
        // Hyphens, apostrophes and the like are separators here, not characters: what is
        // left is the run of letters and digits, joined by single spaces.
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) $value);
    }

    private function countMatch(string $bucket, string $how): void
    {
        if ($how !== self::MATCH_SOURCE) {
            $this->report[$bucket]['adopted']++;
        }
    }

    private function sample(string $key, array $entry): void
    {
        if (count($this->report[$key]) < self::SAMPLE_LIMIT) {
            $this->report[$key][] = $entry;
        }
    }

    private function resetReport(string $countryCode): void
    {
        $counters = array(
            'inserted'    => 0,
            'updated'     => 0,
            'unchanged'   => 0,
            'renamed'     => 0,
            'adopted'     => 0,
            'reparented'  => 0,
            'deactivated' => 0,
            'kept_stale'  => 0,
            // Regions only: offered by the catalog with no settlements under them.
            'skipped_empty' => 0,
            // Offered with a name too long to be picked out of a list.
            'skipped_long'  => 0,
        );

        $this->report = array(
            'country'          => $countryCode,
            'dry_run'          => $this->dryRun,
            'country_inserted' => false,
            'country_renamed'  => false,
            'regions'          => $counters,
            'cities'           => $counters,
            'renames'          => array(),
        );

        $this->regionRows     = array();
        $this->regionClaimed  = array();
        $this->regionBySource = null;
        $this->regionBySlug   = null;
        $this->regionByName   = null;
    }
}
