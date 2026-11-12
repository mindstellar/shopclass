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

use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\database\QueryBuilder;

/**
 * Location reads straight from their tables: countries, a country's active regions, a
 * region's active cities and a city's areas, by name with an optional name prefix, and one
 * place by id. A prefix also matches a name saved in any language.
 */
final class LocationQuery
{
    public const COUNTRY = 'country';
    public const REGION  = 'region';
    public const CITY    = 'city';
    public const AREA    = 'area';

    /** The most other matches a city search counts. */
    public const SEARCH_MORE_CAP = 100;

    /** Shorter typed text finds no city, without a query. */
    public const SEARCH_MIN_CHARS = 2;

    /** level => [table, key column] */
    private const LEVELS = [
        self::COUNTRY => ['t_country', 'pk_c_code'],
        self::REGION  => ['t_region', 'pk_i_id'],
        self::CITY    => ['t_city', 'pk_i_id'],
        self::AREA    => ['t_city_area', 'pk_i_id'],
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function countries(string $prefix, int $limit, int $offset): array
    {
        return $this->list(self::COUNTRY, $this->table(self::COUNTRY), $prefix, $limit, $offset);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function regions(string $countryCode, string $prefix, int $limit, int $offset): array
    {
        $query = $this->table(self::REGION)->where('fk_c_country_code', $countryCode)->where('b_active', 1);

        return $this->list(self::REGION, $query, $prefix, $limit, $offset);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function cities(int $regionId, string $prefix, int $limit, int $offset): array
    {
        $query = $this->table(self::CITY)->where('fk_i_region_id', $regionId)->where('b_active', 1);

        return $this->list(self::CITY, $query, $prefix, $limit, $offset);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function areas(int $cityId, string $prefix, int $limit, int $offset): array
    {
        return $this->list(self::AREA, $this->table(self::AREA)->where('fk_i_city_id', $cityId), $prefix, $limit, $offset);
    }

    /**
     * Every region of a country, or city of a region, by name: the rows the ajax `regions` and
     * `cities` actions list.
     *
     * @param string $parent a country code for regions, a region id for cities
     *
     * @return array<int,array<string,mixed>> pk_i_id, s_name
     */
    public function children(string $level, string $parent): array
    {
        $query = match ($level) {
            self::REGION => $this->table(self::REGION)->where('fk_c_country_code', $parent),
            self::CITY   => $this->table(self::CITY)->where('fk_i_region_id', (int) $parent),
            default      => throw new \InvalidArgumentException('No list of ' . $level . '.'),
        };

        return Db::stringifyRows($query->select('pk_i_id', 's_name')->orderBy('s_name')->orderBy('pk_i_id')->get());
    }

    /**
     * Active cities in active regions whose stored name or a translated name starts with the
     * typed text, named in $locale: exact names first, then by listing count, name and id. Text
     * under SEARCH_MIN_CHARS finds nothing. `more` counts the other matches, up to SEARCH_MORE_CAP.
     * It does not go through list(): that reads one region's cities, and its OR across the names
     * table scans every city when no region narrows it.
     *
     * @param string|null $country an upper-case country code, or null for any
     *
     * @return array{rows:list<array<string,mixed>>,more:int} rows keyed like a listing's location columns
     */
    public function searchCities(string $typed, ?string $country, int $limit, ?string $locale = null): array
    {
        $prefix = trim($typed);
        if (mb_strlen($prefix) < self::SEARCH_MIN_CHARS) {
            return ['rows' => [], 'more' => 0];
        }
        $city    = self::tableName(self::CITY);
        $region  = self::tableName(self::REGION);
        $like    = QueryBuilder::prefixLike($prefix);
        $cap     = $limit + self::SEARCH_MORE_CAP;
        $inScope = ' AND c.b_active = 1 AND r.b_active = 1' . ($country === null ? '' : ' AND r.fk_c_country_code = ?');
        $scope   = $country === null ? [] : [$country];
        $order   = ' DESC, c.i_num_items DESC, c.pk_i_id LIMIT ' . $cap;

        // Two reads that each stay on a name index, instead of one OR across the names table.
        // The straight joins keep that index first when a country filter would put regions first.
        $stored = array_map('intval', array_column(Db::select(
            'SELECT c.pk_i_id FROM ' . $city . ' c STRAIGHT_JOIN ' . $region . ' r ON r.pk_i_id = c.fk_i_region_id'
            . ' WHERE c.s_name LIKE ?' . $inScope . ' ORDER BY c.s_name = ?' . $order,
            [$like, ...$scope, $prefix]
        ), 'pk_i_id'));
        $exact      = [];
        $translated = [];
        foreach (Db::select(
            'SELECT c.pk_i_id, MAX(n.s_name = ?) AS b_exact FROM ' . LocationNameStore::tableName() . ' n'
            . ' STRAIGHT_JOIN ' . $city . ' c ON c.pk_i_id = n.s_place_id STRAIGHT_JOIN ' . $region . ' r ON r.pk_i_id = c.fk_i_region_id'
            . ' WHERE n.e_place_type = ? AND n.s_name LIKE ?' . $inScope
            . ' GROUP BY c.pk_i_id ORDER BY b_exact' . $order,
            [$prefix, LocationNameStore::CITY, $like, ...$scope]
        ) as $row) {
            $id              = (int) $row['pk_i_id'];
            $translated[$id] = $id;
            if ((int) $row['b_exact'] === 1) {
                $exact[$id] = $id;
            }
        }

        $ids = array_values(array_unique([...array_slice($stored, 0, $limit), ...array_values(array_slice($translated, 0, $limit, true))]));
        if ($ids === []) {
            return ['rows' => [], 'more' => 0];
        }
        $marks = static fn (array $list): string => implode(',', array_fill(0, count($list), '?'));
        $rows  = Db::select(
            'SELECT c.pk_i_id AS fk_i_city_id, c.s_name AS s_city, r.pk_i_id AS fk_i_region_id, r.s_name AS s_region,'
            . ' r.fk_c_country_code, co.s_name AS s_country'
            . ' FROM ' . $city . ' c JOIN ' . $region . ' r ON r.pk_i_id = c.fk_i_region_id'
            . ' JOIN ' . self::tableName(self::COUNTRY) . ' co ON co.pk_c_code = r.fk_c_country_code'
            . ' WHERE c.pk_i_id IN (' . $marks($ids) . ')'
            . ' ORDER BY (c.s_name = ?' . ($exact === [] ? '' : ' OR c.pk_i_id IN (' . $marks($exact) . ')') . ') DESC,'
            . ' c.i_num_items DESC, c.s_name, c.pk_i_id',
            [...$ids, $prefix, ...array_values($exact)]
        );
        $shown = array_slice(Db::stringifyRows($rows), 0, $limit);
        $all   = count(array_unique([...$stored, ...array_values($translated)]));
        if ($locale !== null) {
            $shown = array_values(Container::root()->get(LocationNames::class)->translateRows($shown, $locale));
        }

        return ['rows' => $shown, 'more' => min(self::SEARCH_MORE_CAP, max(0, $all - count($shown)))];
    }

    /**
     * Whether the region and the city are active, the same scope searchCities() offers. An id of 0 or less is not checked.
     */
    public function isOffered(int $regionId, int $cityId): bool
    {
        $row = Db::selectOne(
            'SELECT (SELECT b_active FROM ' . self::tableName(self::REGION) . ' WHERE pk_i_id = ?) AS region_active,'
            . ' (SELECT b_active FROM ' . self::tableName(self::CITY) . ' WHERE pk_i_id = ?) AS city_active',
            [$regionId, $cityId]
        ) ?? [];

        return ($regionId <= 0 || (int) ($row['region_active'] ?? 0) === 1) && ($cityId <= 0 || (int) ($row['city_active'] ?? 0) === 1);
    }

    /**
     * @param string $level one of the level constants
     */
    public function exists(string $level, int|string $id): bool
    {
        return $this->table($level)->where(self::LEVELS[$level][1], $id)->count() > 0;
    }

    /**
     * @param string $level one of the level constants
     *
     * @return array<string,mixed>|null the place's row
     */
    public function find(string $level, int|string $id): ?array
    {
        $row = $this->table($level)->where(self::LEVELS[$level][1], $id)->first();

        return $row === null ? null : Db::stringifyRow($row);
    }

    /**
     * A city's or region's centre, found by id or else by name, within a country when given.
     *
     * @param string $level REGION or CITY
     *
     * @return array{0:float,1:float}|null latitude and longitude
     */
    public function centre(string $level, int|string $place, ?string $country = null): ?array
    {
        $query = $this->table($level)->select('d_coord_lat', 'd_coord_long')
            ->whereNotNull('d_coord_lat')->whereNotNull('d_coord_long');
        $query = is_numeric($place) ? $query->where('pk_i_id', (int) $place) : $query->where('s_name', trim((string) $place));
        if ($country !== null && $country !== '') {
            $query = $query->where('fk_c_country_code', strtoupper($country));
        }
        $row   = $query->orderBy('pk_i_id')->first();

        return $row === null ? null : array((float) $row['d_coord_lat'], (float) $row['d_coord_long']);
    }

    /**
     * In one read: whether the country exists, the region's country and the city's region and
     * country, and the stored code and names. A member is null when its place is not stored.
     * A region id of 0 is taken from the city, and an empty country from that region.
     *
     * @return array{country:?bool,regionId:?int,regionCountry:?string,cityRegion:?int,cityCountry:?string,countryCode:?string,countryName:?string,regionName:?string,cityName:?string}
     */
    public function lineage(string $countryCode, int $regionId, int $cityId): array
    {
        $country = self::tableName(self::COUNTRY);
        $region  = self::tableName(self::REGION);
        $city    = self::tableName(self::CITY);
        $row     = Db::selectOne(
            'SELECT k.rid AS region_id,'
            . ' (SELECT pk_c_code FROM ' . $country . ' WHERE pk_c_code = k.cc) AS country_code,'
            . ' (SELECT s_name FROM ' . $country . ' WHERE pk_c_code = k.cc) AS country_name,'
            . ' (SELECT fk_c_country_code FROM ' . $region . ' WHERE pk_i_id = k.rid) AS region_country,'
            . ' (SELECT s_name FROM ' . $region . ' WHERE pk_i_id = k.rid) AS region_name,'
            . ' (SELECT fk_i_region_id FROM ' . $city . ' WHERE pk_i_id = k.cid) AS city_region,'
            . " (SELECT IFNULL(fk_c_country_code, '') FROM " . $city . ' WHERE pk_i_id = k.cid) AS city_country,'
            . ' (SELECT s_name FROM ' . $city . ' WHERE pk_i_id = k.cid) AS city_name'
            . " FROM (SELECT p.cid, p.rid, COALESCE(NULLIF(?, ''), (SELECT fk_c_country_code FROM " . $region . ' WHERE pk_i_id = p.rid)) AS cc'
            . ' FROM (SELECT ? AS cid, COALESCE(NULLIF(?, 0), (SELECT fk_i_region_id FROM ' . $city . ' WHERE pk_i_id = ?)) AS rid) p) k',
            [$countryCode, $cityId, $regionId, $cityId]
        ) ?? [];

        return [
            'country'       => isset($row['country_code']) ? true : null,
            'regionId'      => isset($row['region_name']) ? (int) $row['region_id'] : null,
            'regionCountry' => isset($row['region_country']) ? (string) $row['region_country'] : null,
            'cityRegion'    => isset($row['city_region']) ? (int) $row['city_region'] : null,
            'cityCountry'   => isset($row['city_country']) ? (string) $row['city_country'] : null,
            'countryCode'   => isset($row['country_code']) ? (string) $row['country_code'] : null,
            'countryName'   => isset($row['country_code']) ? (string) $row['country_name'] : null,
            'regionName'    => isset($row['region_name']) ? (string) $row['region_name'] : null,
            'cityName'      => isset($row['city_name']) ? (string) $row['city_name'] : null,
        ];
    }

    /**
     * Names by id.
     *
     * @param string $level one of the level constants
     * @param int[]  $ids
     *
     * @return array<int,string>
     */
    public function names(string $level, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Db::select(
            'SELECT pk_i_id, s_name FROM ' . self::tableName($level)
            . ' WHERE pk_i_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['pk_i_id']] = (string) $row['s_name'];
        }

        return $names;
    }

    /**
     * The next keys of a level after $after, in key order.
     *
     * @param string $level one of the level constants
     * @param string $after '' to start at the first key
     *
     * @return string[]
     */
    public function keysAfter(string $level, string $after, int $limit): array
    {
        $key = self::LEVELS[$level][1];
        $q   = $this->after($level, $after)->select($key)->orderBy($key)->limit($limit);

        return array_map(static fn (array $row): string => (string) $row[$key], $q->get());
    }

    /**
     * How many keys of a level come after $after.
     */
    public function countAfter(string $level, string $after): int
    {
        return $this->after($level, $after)->count();
    }

    /**
     * Lowercase codes of the countries that have region rows.
     *
     * @return array<string,bool>
     */
    public function countriesWithRegions(): array
    {
        $rows = Db::select('SELECT DISTINCT fk_c_country_code AS code FROM ' . self::tableName(self::REGION));
        $out  = [];
        foreach ($rows as $row) {
            $out[strtolower((string) $row['code'])] = true;
        }

        return $out;
    }

    private function after(string $level, string $after): QueryBuilder
    {
        $q = $this->table($level);
        if ($after !== '') {
            $q = $q->where(self::LEVELS[$level][1], '>', $level === self::COUNTRY ? $after : (int) $after);
        }

        return $q;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function list(string $level, QueryBuilder $query, string $prefix, int $limit, int $offset): array
    {
        $key   = self::LEVELS[$level][1];
        $query = $query->orderBy('s_name')->orderBy($key);
        if ($prefix !== '' && $level === self::AREA) {
            $query = $query->like('s_name', $prefix, 'after');
        } elseif ($prefix !== '') {
            [$match, $params] = (new LocationNameStore())->matchClause($level, $key, 's_name', $prefix);
            $query            = $query->whereRaw($match, $params);
        }

        return Db::stringifyRows($query->limit($limit)->offset($offset)->get());
    }

    private function table(string $level): QueryBuilder
    {
        return Db::table(self::tableName($level));
    }

    private static function tableName(string $level): string
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException('Unknown location level ' . $level . '.');
        }

        return DB_TABLE_PREFIX . self::LEVELS[$level][0];
    }
}
