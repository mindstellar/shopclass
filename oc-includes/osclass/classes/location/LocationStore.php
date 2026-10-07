<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\location;

use mindstellar\database\Db;

/**
 * Location writes, and the reads the catalog importer matches against. LocationQuery holds
 * the plain reads; the legacy Country/Region/City models keep their own methods.
 */
final class LocationStore
{
    /** Importer type => [table, primary key]. */
    private const PLACES = [
        'REGION' => ['t_region', 'pk_i_id'],
        'CITY'   => ['t_city', 'pk_i_id'],
    ];

    /**
     * @return int the new area id
     * @throws \mindstellar\database\DbException
     */
    public static function addArea(int $cityId, string $name): int
    {
        return (int) Db::table(DB_TABLE_PREFIX . 't_city_area')->insert(['fk_i_city_id' => $cityId, 's_name' => $name]);
    }

    /**
     * @return array<string,mixed>|null pk_c_code, s_name, s_slug
     * @throws \mindstellar\database\DbException
     */
    public static function country(string $code): ?array
    {
        return Db::selectOne('SELECT pk_c_code, s_name, s_slug FROM ' . DB_TABLE_PREFIX . 't_country WHERE pk_c_code = ?', array($code));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function addCountry(string $code, string $name, string $slug): void
    {
        Db::execute('INSERT INTO ' . DB_TABLE_PREFIX . 't_country (pk_c_code, s_name, s_slug) VALUES (?, ?, ?)', array($code, $name, $slug));
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function renameCountry(string $code, string $name, string $slug): void
    {
        Db::execute('UPDATE ' . DB_TABLE_PREFIX . 't_country SET s_name = ?, s_slug = ? WHERE pk_c_code = ?', array($name, $slug, $code));
    }

    /**
     * A country's regions with the columns the importer matches on.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function regionsOf(string $countryCode): array
    {
        return Db::select(
            'SELECT pk_i_id, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active'
            . ' FROM ' . DB_TABLE_PREFIX . 't_region WHERE fk_c_country_code = ?',
            array($countryCode)
        );
    }

    /**
     * @return int the new region id
     * @throws \mindstellar\database\DbException
     */
    public static function addRegion(string $countryCode, ?int $sourceId, string $name, string $slug, mixed $lat, mixed $lng): int
    {
        return Db::insertGetId(
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_region'
            . ' (fk_c_country_code, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active)'
            . ' VALUES (?, ?, ?, ?, ?, ?, 1)',
            array($countryCode, $sourceId, $name, $slug, $lat, $lng)
        );
    }

    /**
     * A region's cities with the columns the importer matches on.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function citiesOf(int $regionId): array
    {
        return Db::select(
            'SELECT pk_i_id, i_source_id, s_name, s_slug, d_coord_lat, d_coord_long, b_active'
            . ' FROM ' . DB_TABLE_PREFIX . 't_city WHERE fk_i_region_id = ?',
            array($regionId)
        );
    }

    /**
     * Cities in this country with these source ids.
     *
     * @param int[] $sourceIds
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function citiesBySource(array $sourceIds, string $countryCode): array
    {
        return Db::select(
            'SELECT c.pk_i_id, c.fk_i_region_id, c.i_source_id, c.s_name, c.s_slug,'
            . ' c.d_coord_lat, c.d_coord_long, c.b_active'
            . ' FROM ' . DB_TABLE_PREFIX . 't_city c'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_region r ON r.pk_i_id = c.fk_i_region_id'
            . ' WHERE c.i_source_id IN (' . implode(',', array_fill(0, count($sourceIds), '?')) . ')'
            . ' AND r.fk_c_country_code = ?',
            array_merge($sourceIds, array($countryCode))
        );
    }

    /**
     * Insert cities in one statement.
     *
     * @param array<int,array<int,mixed>> $rows region id, country code, source id, name, slug, lat, lng
     *
     * @throws \mindstellar\database\DbException
     */
    public static function addCities(array $rows): void
    {
        $params = array();
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }
        Db::execute(
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_city'
            . ' (fk_i_region_id, fk_c_country_code, i_source_id, s_name, s_slug,'
            . ' d_coord_lat, d_coord_long, b_active)'
            . ' VALUES ' . implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?, 1)')),
            $params
        );
    }

    /**
     * Update one region or city.
     *
     * @param string            $type   'REGION' or 'CITY'
     * @param string[]          $set    trusted "column = ?" fragments
     * @param array<int,mixed>  $params one value per placeholder in $set
     *
     * @throws \mindstellar\database\DbException
     */
    public static function updatePlace(string $type, int $id, array $set, array $params): void
    {
        [$table, $pk] = self::PLACES[$type];
        $params[]     = $id;
        Db::execute('UPDATE ' . DB_TABLE_PREFIX . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . $pk . ' = ?', $params);
    }

    /**
     * @param string $type 'REGION' or 'CITY'
     *
     * @throws \mindstellar\database\DbException
     */
    public static function deactivate(string $type, int $id): void
    {
        self::updatePlace($type, $id, array('b_active = 0'), array());
    }

    /**
     * Keep an old slug redirecting to the place, and never redirect a slug that is live.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function recordSlugChange(string $type, int $id, string $oldSlug, string $newSlug, string $at): void
    {
        $table = DB_TABLE_PREFIX . 't_location_slug_history';
        Db::execute('DELETE FROM ' . $table . ' WHERE e_type = ? AND s_slug = ?', array($type, $newSlug));
        Db::execute(
            'INSERT INTO ' . $table . ' (e_type, s_slug, fk_i_id, dt_date) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE fk_i_id = VALUES(fk_i_id), dt_date = VALUES(dt_date)',
            array($type, $oldSlug, $id, $at)
        );
    }

    /**
     * The id of the place an old slug belonged to, or null.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function idForOldSlug(string $type, string $slug): ?int
    {
        $row = Db::selectOne(
            'SELECT fk_i_id FROM ' . DB_TABLE_PREFIX . 't_location_slug_history WHERE e_type = ? AND s_slug = ?',
            array($type, $slug)
        );

        return $row === null ? null : (int) $row['fk_i_id'];
    }

    /**
     * Which of these region or city ids at least one listing uses.
     *
     * @param string $column 'fk_i_region_id' or 'fk_i_city_id'
     * @param int[]  $ids
     *
     * @return array<int,bool>
     * @throws \mindstellar\database\DbException
     */
    public static function usedByListings(string $column, array $ids): array
    {
        if (!in_array($column, array('fk_i_region_id', 'fk_i_city_id'), true)) {
            throw new \InvalidArgumentException('Unknown location column ' . $column . '.');
        }
        $rows = Db::select(
            'SELECT DISTINCT ' . $column . ' AS id FROM ' . DB_TABLE_PREFIX . 't_item_location'
            . ' WHERE ' . $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $held = array();
        foreach ($rows as $row) {
            $held[(int) $row['id']] = true;
        }

        return $held;
    }
}
