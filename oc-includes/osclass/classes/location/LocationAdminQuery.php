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

use InvalidArgumentException;
use mindstellar\database\DbException;

/**
 * Read side of the location admin screen: one page of one level, a search across all
 * levels, the record behind a row, and what a delete would take with it.
 *
 * Lists are bounded pages backed by the (parent, s_name) indexes, and the counts shown
 * beside a page are read for the ids on that page only, so no call scales with the size
 * of a level.
 */
final class LocationAdminQuery
{
    public const LEVELS = array('country', 'region', 'city');

    public const DEFAULT_PER = 50;
    public const MAX_PER     = 200;

    /** Most ids one impact() call accepts; bulk actions never span more than a page. */
    public const MAX_IDS = self::MAX_PER;

    /** InvalidArgumentException codes. */
    public const ERR_LEVEL    = 1;
    public const ERR_BAD_ID   = 2;
    public const ERR_TOO_MANY = 3;

    /**
     * One page of countries, with listing and region counts.
     *
     * @param string $q    name prefix; blank lists all
     * @param int    $page 1-based
     * @param int    $per
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per:int}
     * @throws DbException
     */
    public function countries(string $q, int $page, int $per): array
    {
        [$countSql, $pageSql, $params, $page, $per] = $this->listQuery('country', null, $q, $page, $per);

        $total = (int) osc_db_scalar($countSql, $params);
        $rows  = $total > 0 ? osc_db_select($pageSql, $params) : array();
        $codes = array_column($rows, 'pk_c_code');

        $listings = $this->keyedCounts(
            'SELECT fk_c_country_code AS k, i_num_items AS n FROM ' . $this->table('t_country_stats')
            . ' WHERE fk_c_country_code IN (%s)',
            $codes
        );
        $regions  = $this->keyedCounts(
            'SELECT fk_c_country_code AS k, COUNT(*) AS n FROM ' . $this->table('t_region')
            . ' WHERE fk_c_country_code IN (%s) GROUP BY fk_c_country_code',
            $codes
        );

        $out = array();
        foreach ($rows as $row) {
            $code  = (string) $row['pk_c_code'];
            $key   = strtoupper($code);
            $out[] = array(
                'code'     => $code,
                'name'     => (string) $row['s_name'],
                'slug'     => (string) $row['s_slug'],
                'listings' => $listings[$key] ?? 0,
                'regions'  => $regions[$key] ?? 0,
            );
        }

        return array('rows' => $out, 'total' => $total, 'page' => $page, 'per' => $per, 'parent' => null);
    }

    /**
     * One page of a country's regions, with listing and city counts.
     *
     * @param string $country country code
     * @param string $q       name prefix; blank lists all
     * @param int    $page    1-based
     * @param int    $per
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per:int,parent:array<string,mixed>|null}
     *               parent is null, with no rows, when the country does not exist
     * @throws DbException
     */
    public function regions(string $country, string $q, int $page, int $per): array
    {
        [$countSql, $pageSql, $params, $page, $per] = $this->listQuery('region', $country, $q, $page, $per);

        $found = $this->record('country', $country, false);
        if ($found === null) {
            return array('rows' => array(), 'total' => 0, 'page' => $page, 'per' => $per, 'parent' => null);
        }
        $parent = array('level' => 'country', 'id' => $found['id'], 'name' => $found['name']);

        $total = (int) osc_db_scalar($countSql, $params);
        $rows  = $total > 0 ? osc_db_select($pageSql, $params) : array();
        $ids   = array_map('intval', array_column($rows, 'pk_i_id'));

        $listings = $this->keyedCounts(
            'SELECT fk_i_region_id AS k, i_num_items AS n FROM ' . $this->table('t_region_stats')
            . ' WHERE fk_i_region_id IN (%s)',
            $ids
        );
        $cities   = $this->keyedCounts(
            'SELECT fk_i_region_id AS k, COUNT(*) AS n FROM ' . $this->table('t_city')
            . ' WHERE fk_i_region_id IN (%s) GROUP BY fk_i_region_id',
            $ids
        );

        $out = array();
        foreach ($rows as $row) {
            $id    = (int) $row['pk_i_id'];
            $out[] = array(
                'id'       => $id,
                // The importer stores some rows lowercase; every code leaving this class is upper.
                'country'  => strtoupper((string) $row['fk_c_country_code']),
                'name'     => (string) $row['s_name'],
                'slug'     => (string) $row['s_slug'],
                'active'   => (int) $row['b_active'] === 1,
                'listings' => $listings[$id] ?? 0,
                'cities'   => $cities[$id] ?? 0,
            );
        }

        return array('rows' => $out, 'total' => $total, 'page' => $page, 'per' => $per, 'parent' => $parent);
    }

    /**
     * One page of a region's cities, with listing counts.
     *
     * @param int    $regionId
     * @param string $q        name prefix; blank lists all
     * @param int    $page     1-based
     * @param int    $per
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per:int,parent:array<string,mixed>|null}
     *               parent is null, with no rows, when the region does not exist
     * @throws DbException
     */
    public function cities(int $regionId, string $q, int $page, int $per): array
    {
        [$countSql, $pageSql, $params, $page, $per] = $this->listQuery('city', $regionId, $q, $page, $per);

        $found = $this->record('region', $regionId, false);
        if ($found === null) {
            return array('rows' => array(), 'total' => 0, 'page' => $page, 'per' => $per, 'parent' => null);
        }
        $parent = array('level' => 'region', 'id' => $found['id'], 'name' => $found['name'], 'country' => $found['country']);

        $total = (int) osc_db_scalar($countSql, $params);
        $rows  = $total > 0 ? osc_db_select($pageSql, $params) : array();
        $ids   = array_map('intval', array_column($rows, 'pk_i_id'));

        $listings = $this->keyedCounts(
            'SELECT fk_i_city_id AS k, i_num_items AS n FROM ' . $this->table('t_city_stats')
            . ' WHERE fk_i_city_id IN (%s)',
            $ids
        );

        $out = array();
        foreach ($rows as $row) {
            $id    = (int) $row['pk_i_id'];
            $out[] = array(
                'id'       => $id,
                'region'   => (int) $row['fk_i_region_id'],
                // The region's country wins, as in searchAll() and record(); old city rows may carry none.
                'country'  => $parent['country']['code'] ?? self::upperNullable($row['fk_c_country_code']),
                'name'     => (string) $row['s_name'],
                'slug'     => (string) $row['s_slug'],
                'active'   => (int) $row['b_active'] === 1,
                'listings' => $listings[$id] ?? 0,
            );
        }

        return array('rows' => $out, 'total' => $total, 'page' => $page, 'per' => $per, 'parent' => $parent);
    }

    /**
     * The distinct first letters and digits of the names at one level, upper-cased and in
     * collation order, for a jump strip. Null when there are more than $max, or none.
     *
     * @param string          $level  country|region|city
     * @param string|int|null $parent country code for regions, region id for cities
     * @param int             $max
     *
     * @return array<int,string>|null
     * @throws InvalidArgumentException on an unknown level
     * @throws DbException
     */
    public function initials(string $level, string|int|null $parent, int $max = 36): ?array
    {
        self::assertLevel($level);
        switch ($level) {
            case 'country':
                $sql    = 'SELECT DISTINCT UPPER(LEFT(s_name, 1)) AS i FROM ' . $this->table('t_country');
                $params = array();
                break;
            case 'region':
                $sql    = 'SELECT DISTINCT UPPER(LEFT(s_name, 1)) AS i FROM ' . $this->table('t_region') . ' WHERE fk_c_country_code = ?';
                $params = array((string) $parent);
                break;
            default:
                $sql    = 'SELECT DISTINCT UPPER(LEFT(s_name, 1)) AS i FROM ' . $this->table('t_city') . ' WHERE fk_i_region_id = ?';
                $params = array((int) $parent);
                break;
        }
        $limit = max(1, $max) + 2;
        $out   = array();
        foreach (osc_db_select($sql . ' ORDER BY i LIMIT ' . $limit, $params) as $row) {
            $char = trim((string) $row['i']);
            // Only letters and digits make a useful jump; names starting otherwise stay searchable.
            if (preg_match('/^[\p{L}\p{N}]$/u', $char) === 1 && !in_array($char, $out, true)) {
                $out[] = $char;
            }
        }

        return $out === array() || count($out) > $max ? null : $out;
    }

    /**
     * Name-prefix search across all three levels, each with the names of its parents.
     *
     * @param string $q
     * @param int    $perLevel hits returned per level, 1-50; below 1 means 10
     *
     * @return array{countries:array<int,array<string,mixed>>,regions:array<int,array<string,mixed>>,cities:array<int,array<string,mixed>>}
     * @throws DbException
     */
    public function searchAll(string $q, int $perLevel = 10): array
    {
        $result = array('countries' => array(), 'regions' => array(), 'cities' => array());
        $q      = trim($q);
        if ($q === '') {
            return $result;
        }
        $limit   = $perLevel < 1 ? 10 : min(50, $perLevel);
        $pattern = self::prefixPattern($q);

        $rows = osc_db_select(
            'SELECT pk_c_code, s_name, s_slug FROM ' . $this->table('t_country')
            . ' WHERE s_name LIKE ? ORDER BY s_name, pk_c_code LIMIT ' . $limit,
            array($pattern)
        );
        foreach ($rows as $row) {
            $result['countries'][] = array(
                'code' => (string) $row['pk_c_code'],
                'name' => (string) $row['s_name'],
                'slug' => (string) $row['s_slug'],
            );
        }

        $rows = osc_db_select(
            'SELECT r.pk_i_id, r.s_name, r.s_slug, r.b_active, r.fk_c_country_code, co.s_name AS country_name'
            . ' FROM ' . $this->table('t_region') . ' r'
            . ' LEFT JOIN ' . $this->table('t_country') . ' co ON co.pk_c_code = r.fk_c_country_code'
            . ' WHERE r.s_name LIKE ? ORDER BY r.s_name, r.pk_i_id LIMIT ' . $limit,
            array($pattern)
        );
        foreach ($rows as $row) {
            $result['regions'][] = array(
                'id'           => (int) $row['pk_i_id'],
                'name'         => (string) $row['s_name'],
                'slug'         => (string) $row['s_slug'],
                'active'       => (int) $row['b_active'] === 1,
                'country'      => strtoupper((string) $row['fk_c_country_code']),
                'country_name' => $row['country_name'] === null ? null : (string) $row['country_name'],
            );
        }

        $rows = osc_db_select(
            'SELECT c.pk_i_id, c.s_name, c.s_slug, c.b_active, c.fk_i_region_id, r.s_name AS region_name,'
            . ' COALESCE(r.fk_c_country_code, c.fk_c_country_code) AS country_code, co.s_name AS country_name'
            . ' FROM ' . $this->table('t_city') . ' c'
            . ' LEFT JOIN ' . $this->table('t_region') . ' r ON r.pk_i_id = c.fk_i_region_id'
            . ' LEFT JOIN ' . $this->table('t_country') . ' co'
            . ' ON co.pk_c_code = COALESCE(r.fk_c_country_code, c.fk_c_country_code)'
            . ' WHERE c.s_name LIKE ? ORDER BY c.s_name, c.pk_i_id LIMIT ' . $limit,
            array($pattern)
        );
        foreach ($rows as $row) {
            $result['cities'][] = array(
                'id'           => (int) $row['pk_i_id'],
                'name'         => (string) $row['s_name'],
                'slug'         => (string) $row['s_slug'],
                'active'       => (int) $row['b_active'] === 1,
                'region'       => (int) $row['fk_i_region_id'],
                'region_name'  => $row['region_name'] === null ? null : (string) $row['region_name'],
                'country'      => self::upperNullable($row['country_code']),
                'country_name' => $row['country_name'] === null ? null : (string) $row['country_name'],
            );
        }

        return $result;
    }

    /**
     * What deleting these rows would remove, counted live rather than from the stats
     * tables. Follows the model cascades: a country takes its regions, a region its cities,
     * a city its city areas; every listing placed in any of them is deleted and every user
     * placed in any of them is unlinked.
     *
     * @param string                $level country|region|city
     * @param array<int,int|string> $ids   country codes or numeric ids; at most MAX_IDS
     *
     * @return array{level:string,requested:int,found:int,regions:int,cities:int,children:int,listings:int,users:int}
     *               requested counts distinct ids; found below it means some no longer exist
     * @throws InvalidArgumentException on an unknown level, a malformed id or more than MAX_IDS ids
     * @throws DbException
     */
    public function impact(string $level, array $ids): array
    {
        self::assertLevel($level);
        $ids    = self::normaliseIds($level, $ids);
        $result = array(
            'level'     => $level,
            'requested' => count($ids),
            'found'    => 0,
            'regions'  => 0,
            'cities'   => 0,
            'children' => 0,
            'listings' => 0,
            'users'    => 0,
        );
        if ($ids === array()) {
            return $result;
        }

        $in    = implode(', ', array_fill(0, count($ids), '?'));
        $sets  = $this->placeSets($level, $in);
        $count = static function (string $sql, int $times) use ($ids): int {
            return (int) osc_db_scalar($sql, array_merge(...array_fill(0, $times, $ids)));
        };

        switch ($level) {
            case 'country':
                $result['found']    = $count('SELECT COUNT(*) FROM ' . $this->table('t_country') . " WHERE pk_c_code IN ($in)", 1);
                $result['regions']  = $count('SELECT COUNT(*) FROM ' . $this->table('t_region') . " WHERE fk_c_country_code IN ($in)", 1);
                $result['cities']   = $count(
                    'SELECT COUNT(*) FROM ' . $this->table('t_city') . ' c'
                    . ' JOIN ' . $this->table('t_region') . ' r ON r.pk_i_id = c.fk_i_region_id'
                    . " WHERE r.fk_c_country_code IN ($in)",
                    1
                );
                $result['children'] = $result['regions'];
                break;
            case 'region':
                $result['found']    = $count('SELECT COUNT(*) FROM ' . $this->table('t_region') . " WHERE pk_i_id IN ($in)", 1);
                $result['cities']   = $count('SELECT COUNT(*) FROM ' . $this->table('t_city') . " WHERE fk_i_region_id IN ($in)", 1);
                $result['children'] = $result['cities'];
                break;
            default:
                $result['found'] = $count('SELECT COUNT(*) FROM ' . $this->table('t_city') . " WHERE pk_i_id IN ($in)", 1);
                break;
        }

        foreach (array('listings' => array('t_item_location', 'fk_i_item_id'), 'users' => array('t_user', 'pk_i_id')) as $key => [$table, $pk]) {
            $branches = array();
            foreach ($sets as [$column, $filter]) {
                $branches[] = 'SELECT ' . $pk . ' FROM ' . $this->table($table) . ' WHERE ' . $column . ' IN (' . $filter . ')';
            }
            // UNION de-duplicates, so a listing placed by both city and region counts once.
            $result[$key] = $count('SELECT COUNT(*) FROM (' . implode(' UNION ', $branches) . ') x', count($branches));
        }

        return $result;
    }

    /**
     * One location with its parents and, unless $withCounts is false, its live counts.
     *
     * @param string     $level      country|region|city
     * @param string|int $id         country code or numeric id
     * @param bool       $withCounts false skips the impact queries; counts is then null
     *
     * @return array<string,mixed>|null null when there is no such row or the id is malformed
     * @throws InvalidArgumentException on an unknown level
     * @throws DbException
     */
    public function record(string $level, string|int $id, bool $withCounts = true): ?array
    {
        self::assertLevel($level);
        try {
            $id = self::normaliseIds($level, array($id))[0];
        } catch (InvalidArgumentException $e) {
            return null;
        }

        switch ($level) {
            case 'country':
                $row = osc_db_select_one(
                    'SELECT pk_c_code, s_name, s_slug FROM ' . $this->table('t_country') . ' WHERE pk_c_code = ?',
                    array($id)
                );
                if ($row === null) {
                    return null;
                }
                $record = array(
                    'level'     => 'country',
                    'id'        => (string) $row['pk_c_code'],
                    'name'      => (string) $row['s_name'],
                    'slug'      => (string) $row['s_slug'],
                    'active'    => true,
                    'source_id' => null,
                    'lat'       => null,
                    'long'      => null,
                    'country'   => null,
                    'region'    => null,
                );
                break;
            case 'region':
                $row = osc_db_select_one(
                    'SELECT r.*, co.s_name AS country_name FROM ' . $this->table('t_region') . ' r'
                    . ' LEFT JOIN ' . $this->table('t_country') . ' co ON co.pk_c_code = r.fk_c_country_code'
                    . ' WHERE r.pk_i_id = ?',
                    array($id)
                );
                if ($row === null) {
                    return null;
                }
                $record = self::placeRecord('region', $row) + array(
                    'country' => array('code' => strtoupper((string) $row['fk_c_country_code']), 'name' => self::nullableString($row['country_name'])),
                    'region'  => null,
                );
                break;
            default:
                $row = osc_db_select_one(
                    'SELECT c.*, r.s_name AS region_name,'
                    . ' COALESCE(r.fk_c_country_code, c.fk_c_country_code) AS country_code, co.s_name AS country_name'
                    . ' FROM ' . $this->table('t_city') . ' c'
                    . ' LEFT JOIN ' . $this->table('t_region') . ' r ON r.pk_i_id = c.fk_i_region_id'
                    . ' LEFT JOIN ' . $this->table('t_country') . ' co'
                    . ' ON co.pk_c_code = COALESCE(r.fk_c_country_code, c.fk_c_country_code)'
                    . ' WHERE c.pk_i_id = ?',
                    array($id)
                );
                if ($row === null) {
                    return null;
                }
                $record = self::placeRecord('city', $row) + array(
                    'country' => $row['country_code'] === null
                        ? null
                        : array('code' => strtoupper((string) $row['country_code']), 'name' => self::nullableString($row['country_name'])),
                    'region'  => array('id' => (int) $row['fk_i_region_id'], 'name' => self::nullableString($row['region_name'])),
                );
                break;
        }

        if (!$withCounts) {
            $record['counts'] = null;

            return $record;
        }

        $impact           = $this->impact($level, array($id));
        $record['counts'] = array(
            'children' => $impact['children'],
            'regions'  => $impact['regions'],
            'cities'   => $impact['cities'],
            'listings' => $impact['listings'],
            'users'    => $impact['users'],
        );

        return $record;
    }

    /**
     * Count and page SQL for one level, with their shared bindings and the clamped paging.
     *
     * @param string          $level
     * @param string|int|null $parent country code for regions, region id for cities
     * @param string          $q
     * @param int             $page
     * @param int             $per
     *
     * @return array{0:string,1:string,2:array<int,mixed>,3:int,4:int}
     */
    private function listQuery(string $level, string|int|null $parent, string $q, int $page, int $per): array
    {
        $per  = $per < 1 ? self::DEFAULT_PER : min($per, self::MAX_PER);
        $page = max(1, min($page, 1000000));

        $where  = array();
        $params = array();
        switch ($level) {
            case 'country':
                $table  = $this->table('t_country');
                $select = 'pk_c_code, s_name, s_slug';
                $order  = 's_name, pk_c_code';
                break;
            case 'region':
                $table    = $this->table('t_region');
                $select   = 'pk_i_id, fk_c_country_code, s_name, s_slug, b_active';
                $order    = 's_name, pk_i_id';
                $where[]  = 'fk_c_country_code = ?';
                $params[] = (string) $parent;
                break;
            default:
                $table    = $this->table('t_city');
                $select   = 'pk_i_id, fk_i_region_id, fk_c_country_code, s_name, s_slug, b_active';
                $order    = 's_name, pk_i_id';
                $where[]  = 'fk_i_region_id = ?';
                $params[] = (int) $parent;
                break;
        }

        $q = trim($q);
        if ($q !== '') {
            $where[]  = 's_name LIKE ?';
            $params[] = self::prefixPattern($q);
        }
        $whereSql = $where === array() ? '' : ' WHERE ' . implode(' AND ', $where);

        return array(
            'SELECT COUNT(*) FROM ' . $table . $whereSql,
            'SELECT ' . $select . ' FROM ' . $table . $whereSql
            . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params,
            $page,
            $per,
        );
    }

    /**
     * The id sets a delete at $level reaches, as [column on t_item_location/t_user, IN-list SQL].
     * Every set's SQL binds the same ids once, in order.
     *
     * @param string $level
     * @param string $in    placeholder list for the ids
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function placeSets(string $level, string $in): array
    {
        $region = $this->table('t_region');
        $city   = $this->table('t_city');
        $area   = $this->table('t_city_area');

        switch ($level) {
            case 'country':
                return array(
                    array('fk_c_country_code', $in),
                    array('fk_i_region_id', "SELECT pk_i_id FROM $region WHERE fk_c_country_code IN ($in)"),
                    array(
                        'fk_i_city_id',
                        "SELECT c.pk_i_id FROM $city c JOIN $region r ON r.pk_i_id = c.fk_i_region_id"
                        . " WHERE r.fk_c_country_code IN ($in)",
                    ),
                    array(
                        'fk_i_city_area_id',
                        "SELECT a.pk_i_id FROM $area a JOIN $city c ON c.pk_i_id = a.fk_i_city_id"
                        . " JOIN $region r ON r.pk_i_id = c.fk_i_region_id WHERE r.fk_c_country_code IN ($in)",
                    ),
                );
            case 'region':
                return array(
                    array('fk_i_region_id', $in),
                    array('fk_i_city_id', "SELECT pk_i_id FROM $city WHERE fk_i_region_id IN ($in)"),
                    array(
                        'fk_i_city_area_id',
                        "SELECT a.pk_i_id FROM $area a JOIN $city c ON c.pk_i_id = a.fk_i_city_id"
                        . " WHERE c.fk_i_region_id IN ($in)",
                    ),
                );
            default:
                return array(
                    array('fk_i_city_id', $in),
                    array('fk_i_city_area_id', "SELECT pk_i_id FROM $area WHERE fk_i_city_id IN ($in)"),
                );
        }
    }

    /**
     * Run a `k, n` query over an IN list and key the counts by k.
     *
     * @param string                $sql  with one %s for the placeholder list
     * @param array<int,int|string> $keys
     *
     * @return array<int|string,int>
     * @throws DbException
     */
    private function keyedCounts(string $sql, array $keys): array
    {
        if ($keys === array()) {
            return array();
        }
        $out = array();
        foreach (osc_db_select(sprintf($sql, implode(', ', array_fill(0, count($keys), '?'))), array_values($keys)) as $row) {
            $key       = is_string($row['k']) ? strtoupper($row['k']) : (int) $row['k'];
            $out[$key] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Fields shared by region and city records.
     *
     * @param string              $level
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function placeRecord(string $level, array $row): array
    {
        return array(
            'level'     => $level,
            'id'        => (int) $row['pk_i_id'],
            'name'      => (string) $row['s_name'],
            'slug'      => (string) $row['s_slug'],
            'active'    => (int) $row['b_active'] === 1,
            'source_id' => $row['i_source_id'] === null ? null : (int) $row['i_source_id'],
            'lat'       => $row['d_coord_lat'] === null ? null : (float) $row['d_coord_lat'],
            'long'      => $row['d_coord_long'] === null ? null : (float) $row['d_coord_long'],
        );
    }

    /**
     * Country codes trimmed and upper-cased, numeric ids positive; both de-duplicated.
     *
     * @param string           $level
     * @param array<int,mixed> $ids
     *
     * @return array<int,int|string>
     * @throws InvalidArgumentException on a malformed id (ERR_BAD_ID) or more than MAX_IDS (ERR_TOO_MANY)
     */
    private static function normaliseIds(string $level, array $ids): array
    {
        $out = array();
        foreach ($ids as $id) {
            if ($level === 'country') {
                $code = is_string($id) ? strtoupper(trim($id)) : '';
                if (preg_match('/^[A-Z0-9]{2}$/', $code) !== 1) {
                    throw new InvalidArgumentException('Malformed location id', self::ERR_BAD_ID);
                }
                $out[$code] = $code;
                continue;
            }
            $n = is_int($id) ? $id : (is_string($id) && ctype_digit(trim($id)) ? (int) $id : 0);
            if ($n < 1) {
                throw new InvalidArgumentException('Malformed location id', self::ERR_BAD_ID);
            }
            $out[$n] = $n;
        }
        if (count($out) > self::MAX_IDS) {
            throw new InvalidArgumentException('Too many location ids', self::ERR_TOO_MANY);
        }

        return array_values($out);
    }

    /**
     * A LIKE prefix pattern with the payload's own wildcards escaped.
     *
     * @param string $q
     *
     * @return string
     */
    private static function prefixPattern(string $q): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
    }

    /**
     * @param string $level
     *
     * @throws InvalidArgumentException
     */
    private static function assertLevel(string $level): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('Unknown location level', self::ERR_LEVEL);
        }
    }

    /**
     * @param mixed $value
     *
     * @return string|null
     */
    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * A country code, upper-cased; null stays null. The importer stores some rows lowercase.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function upperNullable(mixed $value): ?string
    {
        return $value === null ? null : strtoupper((string) $value);
    }

    /**
     * @param string $table unprefixed table name
     *
     * @return string
     */
    private function table(string $table): string
    {
        return DB_TABLE_PREFIX . $table;
    }
}
