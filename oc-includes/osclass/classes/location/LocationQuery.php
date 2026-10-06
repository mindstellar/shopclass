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

        return $row === null ? null : osc_db_stringify_row($row);
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

        return osc_db_stringify_rows($query->limit($limit)->offset($offset)->get());
    }

    private function table(string $level): QueryBuilder
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException('Unknown location level ' . $level . '.');
        }

        return osc_db_table(DB_TABLE_PREFIX . self::LEVELS[$level][0]);
    }
}
