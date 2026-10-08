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
use mindstellar\database\QueryBuilder;

/**
 * Location reads straight from their tables: countries, a country's active regions, a
 * region's active cities and a city's areas, by name with an optional name prefix, and one
 * place by id.
 */
final class LocationQuery
{
    public const COUNTRY = 'country';
    public const REGION  = 'region';
    public const CITY    = 'city';
    public const AREA    = 'area';

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
     * In one read: whether the country exists, the region's country and the city's region and
     * country. A member is null when its place is not stored.
     *
     * @return array{country:?bool,regionCountry:?string,cityRegion:?int,cityCountry:?string}
     */
    public function lineage(string $countryCode, int $regionId, int $cityId): array
    {
        $city = self::tableName(self::CITY);
        $row  = Db::selectOne(
            'SELECT (SELECT 1 FROM ' . self::tableName(self::COUNTRY) . ' WHERE pk_c_code = ?) AS country,'
            . ' (SELECT fk_c_country_code FROM ' . self::tableName(self::REGION) . ' WHERE pk_i_id = ?) AS region_country,'
            . ' (SELECT fk_i_region_id FROM ' . $city . ' WHERE pk_i_id = ?) AS city_region,'
            . " (SELECT IFNULL(fk_c_country_code, '') FROM " . $city . ' WHERE pk_i_id = ?) AS city_country',
            [$countryCode, $regionId, $cityId, $cityId]
        ) ?? [];

        return [
            'country'       => isset($row['country']) ? true : null,
            'regionCountry' => isset($row['region_country']) ? (string) $row['region_country'] : null,
            'cityRegion'    => isset($row['city_region']) ? (int) $row['city_region'] : null,
            'cityCountry'   => isset($row['city_country']) ? (string) $row['city_country'] : null,
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
        $query = $query->orderBy('s_name')->orderBy(self::LEVELS[$level][1]);
        if ($prefix !== '') {
            $query = $query->like('s_name', $prefix, 'after');
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
