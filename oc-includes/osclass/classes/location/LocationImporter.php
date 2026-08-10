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
        $this->resetReport($data['s_country_code'] ?? '');

        if (!isset($data['s_country_code'], $data['regions']) || !is_array($data['regions'])) {
            $this->report['error'] = 'malformed country file';

            return $this->report;
        }

        // One transaction for the whole country. A half-imported country is worse than a
        // long transaction: it leaves regions whose cities never arrived, and the admin
        // has no way to tell how far it got.
        $run = function () use ($data) {
            $this->importCountryRow($data);
            foreach ($data['regions'] as $region) {
                $regionId = $this->importRegion($data['s_country_code'], $region);
                if ($regionId !== null) {
                    $this->importCities($regionId, $region['cities'] ?? array());
                }
            }
            $this->deactivateVanishedRegions($data['s_country_code']);
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
        $code    = (string) $data['s_country_code'];
        $current = osc_db_select_one(
            'SELECT pk_c_code, s_name, s_slug FROM ' . $table . ' WHERE pk_c_code = ?',
            array($code)
        );

        if ($current === null) {
            $this->report['country_inserted'] = true;
            osc_db_execute(
                'INSERT INTO ' . $table . ' (pk_c_code, s_name, s_slug) VALUES (?, ?, ?)',
                array($code, $data['s_country_name'], $data['s_country_slug'])
            );

            return;
        }

        // Country names do drift upstream ("Korea North" became "North Korea"), and
        // pk_c_code is the ISO code, so the rename is safe to apply.
        if ($current['s_name'] !== $data['s_country_name'] || $current['s_slug'] !== $data['s_country_slug']) {
            $this->report['country_renamed'] = true;
            osc_db_execute(
                'UPDATE ' . $table . ' SET s_name = ?, s_slug = ? WHERE pk_c_code = ?',
                array($data['s_country_name'], $data['s_country_slug'], $code)
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
            $this->regionClaimed
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
            $incoming
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
    private function importCities(int $regionId, array $incomingCities): void
    {
        if ($incomingCities === array()) {
            return;
        }

        // Looked up by source id across the whole table, not just this region: upstream
        // re-parents cities between regions, and finding the row wherever it currently
        // sits turns that into an UPDATE of fk_i_region_id instead of a duplicate.
        $bySource = $this->citiesBySourceId($incomingCities);

        $rows    = osc_db_select(
            'SELECT pk_i_id, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active'
            . ' FROM ' . DB_TABLE_PREFIX . 't_city WHERE fk_i_region_id = ?',
            array($regionId)
        );
        $inRegion = $bySlug = $byName = $indexed = array();
        foreach ($rows as $row) {
            $this->indexRow($row, $indexed, $inRegion, $bySlug, $byName);
        }

        $claimed = array();
        $pending = array();
        foreach ($incomingCities as $incoming) {
            $sourceId = isset($incoming['i_source_id']) ? (int) $incoming['i_source_id'] : null;
            $name     = (string) $incoming['s_city_name'];
            $slug     = (string) $incoming['s_city_slug'];

            $match = $this->matchRow($sourceId, $slug, $name, $bySource, $bySlug, $byName, $indexed, $claimed);
            if ($match === null) {
                $this->report['cities']['inserted']++;
                $pending[] = array(
                    $regionId,
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
                $regionId
            );
        }

        $this->insertCities($pending);
        $this->deactivateVanishedCities($indexed, $claimed);
    }

    /**
     * Existing rows for the incoming source ids, wherever in the table they currently live.
     *
     * @param array<int, array> $incomingCities
     *
     * @return array<int, array> i_source_id => row
     */
    private function citiesBySourceId(array $incomingCities): array
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
                'SELECT pk_i_id, fk_i_region_id, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active'
                . ' FROM ' . DB_TABLE_PREFIX . 't_city'
                . ' WHERE i_source_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
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
            osc_db_execute(
                'INSERT INTO ' . DB_TABLE_PREFIX . 't_city'
                . ' (fk_i_region_id, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active)'
                . ' VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, 1)')),
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
        array $claimed
    ): ?array {
        if ($sourceId !== null && isset($bySource[$sourceId])) {
            $row = $bySource[$sourceId];
            if (!isset($claimed[(int) $row['pk_i_id']])) {
                return array($row, self::MATCH_SOURCE);
            }
        }

        foreach (array(array($bySlug, $slug, self::MATCH_SLUG), array($byName, mb_strtolower($name), self::MATCH_NAME)) as $attempt) {
            [$index, $key, $how] = $attempt;
            if ($key === '' || !isset($index[$key])) {
                continue;
            }
            $id = $index[$key];
            if (isset($claimed[$id]) || !isset($rows[$id])) {
                continue;
            }
            // Never steal a row that already answers to a different upstream id — that is
            // two distinct places sharing a slug, and the incoming one needs its own row.
            // An incoming row with no id at all makes no competing claim, so it may still
            // match: that is a catalog file published before source ids existed, and it
            // must update the row it describes rather than duplicate it.
            if ($rows[$id]['i_source_id'] !== null && $sourceId !== null) {
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
        ?int $newParentId = null
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
        if ($sourceId !== null && $row['i_source_id'] === null) {
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
        // First row wins on a duplicate slug or name; the loser stays unclaimed and is
        // evaluated for deactivation like any other row upstream no longer lists.
        $slug = (string) $row['s_slug'];
        if ($slug !== '' && !isset($bySlug[$slug])) {
            $bySlug[$slug] = $id;
        }
        $name = mb_strtolower((string) $row['s_name']);
        if ($name !== '' && !isset($byName[$name])) {
            $byName[$name] = $id;
        }
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
