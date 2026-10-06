<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\search\query;

/**
 * City area, city, region and country filters on t_item_location. A number is an id,
 * text is matched with LIKE; a two-letter country is a country code.
 *
 * Each value is kept twice: as the SQL text toJson() has always shown, and as a
 * condition with the value bound.
 */
final class LocationFilter
{
    public const LEVELS = array('city_areas', 'cities', 'regions', 'countries');

    /** @var array<string,array<int,array{text:string,sql:string,param:mixed}>> */
    private array $entries = array('city_areas' => array(), 'cities' => array(), 'regions' => array(), 'countries' => array());

    /** Set once any location was asked for; the join then stays, as it always did. */
    private bool $used = false;

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function addCityArea($value): void
    {
        $this->addEach($value, 'city_areas', 'fk_i_city_area_id', 's_city_area');
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function addCity($value): void
    {
        $this->addEach($value, 'cities', 'fk_i_city_id', 's_city');
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function addRegion($value): void
    {
        $this->addEach($value, 'regions', 'fk_i_region_id', 's_region');
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function addCountry($value): void
    {
        foreach (is_array($value) ? $value : array($value) as $country) {
            $country = trim((string)$country);
            if (!$country) {
                continue;
            }
            $column = DB_TABLE_PREFIX . 't_item_location.';
            if (strlen($country) === 2) {
                $this->entries['countries'][] = array(
                    'text'  => sprintf('%sfk_c_country_code = %s ', $column, strtolower((string)SqlValue::literal($country))),
                    'sql'   => $column . 'fk_c_country_code = ? ',
                    'param' => SqlValue::bind(is_numeric($country) ? $country : strtolower($country)),
                );
            } else {
                $this->entries['countries'][] = array(
                    'text'  => sprintf('%ss_country LIKE %s ', $column, SqlValue::literal($country)),
                    'sql'   => $column . 's_country LIKE ? ',
                    'param' => SqlValue::bind($country),
                );
            }
        }
    }

    /**
     * Whether a location is set, or was when a statement was last built.
     *
     * @return bool
     */
    public function used(): bool
    {
        foreach ($this->entries as $level) {
            if ($level !== array()) {
                $this->used = true;
            }
        }

        return $this->used;
    }

    /**
     * Each level's conditions as SQL text, for toJson().
     *
     * @param string $level one of LEVELS
     *
     * @return array<int,string>
     */
    public function recorded(string $level): array
    {
        return array_column($this->entries[$level], 'text');
    }

    /**
     * Drop every location value; a join flag already set stays.
     *
     * @return void
     */
    public function clear(): void
    {
        foreach (self::LEVELS as $level) {
            $this->entries[$level] = array();
        }
    }

    /**
     * Add one OR group per level to $statement.
     *
     * @param Statement $statement
     *
     * @return void
     */
    public function apply(Statement $statement): void
    {
        foreach (self::LEVELS as $level) {
            if ($this->entries[$level] !== array()) {
                $statement->where(
                    '( ' . implode(' || ', array_column($this->entries[$level], 'sql')) . ' )',
                    array_column($this->entries[$level], 'param')
                );
            }
        }
    }

    /**
     * @param mixed  $value    one value or a list
     * @param string $level
     * @param string $idColumn
     * @param string $nameColumn
     *
     * @return void
     */
    private function addEach($value, string $level, string $idColumn, string $nameColumn): void
    {
        $column = DB_TABLE_PREFIX . 't_item_location.';
        foreach (is_array($value) ? $value : array($value) as $one) {
            $one = trim((string)$one);
            if (!$one) {
                continue;
            }
            if (is_numeric($one)) {
                $id = SqlValue::intOf($one);
                $this->entries[$level][] = array(
                    'text'  => sprintf('%s%s = %d ', $column, $idColumn, $id),
                    'sql'   => $column . $idColumn . ' = ? ',
                    'param' => $id,
                );
            } else {
                $this->entries[$level][] = array(
                    'text'  => sprintf('%s%s LIKE %s ', $column, $nameColumn, SqlValue::literal($one)),
                    'sql'   => $column . $nameColumn . ' LIKE ? ',
                    'param' => $one,
                );
            }
        }
    }
}
