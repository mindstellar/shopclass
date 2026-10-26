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

use mindstellar\location\LocationNameStore;

/**
 * City area, city, region and country filters on t_item_location. A number is an id,
 * text is matched with LIKE; a two-letter country is a country code. A city, region or
 * country name also matches the places that carry that name in any language.
 *
 * Each value is kept twice: as the SQL text toJson() has always shown, and as a
 * condition with the value bound.
 */
final class LocationFilter
{
    public const LEVELS = array('city_areas', 'cities', 'regions', 'countries');

    /** @var array<string,array<int,array{text:string,sql:string,param:mixed,by:string,ids?:array<int,int|string>}>> */
    private array $entries = array('city_areas' => array(), 'cities' => array(), 'regions' => array(), 'countries' => array());

    /** Set once any location was asked for; the join then stays, as it always did. */
    private bool $used = false;

    /** Typed names per level looked up in other languages; any more match the stored name only. */
    private const NAMED_LOOKUPS = 10;

    private readonly LocationNameStore $names;

    public function __construct(?LocationNameStore $names = null)
    {
        $this->names = $names ?? new LocationNameStore();
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function addCityArea($value): void
    {
        $this->addEach($value, 'city_areas', 'fk_i_city_area_id', 's_city_area', null);
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function addCity($value): void
    {
        $this->addEach($value, 'cities', 'fk_i_city_id', 's_city', LocationNameStore::CITY);
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function addRegion($value): void
    {
        $this->addEach($value, 'regions', 'fk_i_region_id', 's_region', LocationNameStore::REGION);
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
                    'by'    => 'code',
                );
            } else {
                $codes = $this->namedIds(LocationNameStore::COUNTRY, 'countries', $country);
                $this->entries['countries'][] = $this->byName($column, 's_country', 'fk_c_country_code', SqlValue::bind($country), $codes);
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
     * Each value of a level: an id, a two-letter country code, or a name matched with LIKE.
     *
     * @param string $level one of LEVELS
     *
     * @return array<int,array{by:string,value:int|string}>
     */
    public function values(string $level): array
    {
        $values = array();
        foreach ($this->entries[$level] as $entry) {
            $values[] = array('by' => $entry['by'], 'value' => $entry['param']);
        }

        return $values;
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
                $params = array();
                foreach ($this->entries[$level] as $entry) {
                    $params[] = $entry['param'];
                    array_push($params, ...($entry['ids'] ?? array()));
                }
                $statement->where(
                    '( ' . implode(' || ', array_column($this->entries[$level], 'sql')) . ' )',
                    $params
                );
            }
        }
    }

    /**
     * @param mixed       $value      one value or a list
     * @param string      $level
     * @param string      $idColumn
     * @param string      $nameColumn
     * @param string|null $type       the place type whose names in other languages also match
     *
     * @return void
     */
    private function addEach($value, string $level, string $idColumn, string $nameColumn, ?string $type): void
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
                    'by'    => 'id',
                );
            } else {
                $ids = $type === null ? array() : array_map('intval', $this->namedIds($type, $level, $one));
                $this->entries[$level][] = $this->byName($column, $nameColumn, $idColumn, $one, $ids);
            }
        }
    }

    /**
     * The ids of the places with this exact name in another language, for the first few names
     * of a level.
     *
     * @return string[]
     */
    private function namedIds(string $type, string $level, string $name): array
    {
        $looked = count(array_filter($this->entries[$level], static fn (array $e): bool => $e['by'] === 'name'));

        return $looked < self::NAMED_LOOKUPS ? $this->names->idsNamed($type, $name) : array();
    }

    /**
     * A name matched with LIKE on the stored copy, or, when places carry that name in
     * another language, on their ids too. The ids are part of the recorded text.
     *
     * @param mixed                 $name
     * @param array<int,int|string> $ids
     *
     * @return array{text:string,sql:string,param:mixed,by:string,ids?:array<int,int|string>}
     */
    private function byName(string $column, string $nameColumn, string $idColumn, $name, array $ids): array
    {
        $text = sprintf('%s%s LIKE %s ', $column, $nameColumn, SqlValue::literal($name));
        $sql  = $column . $nameColumn . ' LIKE ? ';
        if ($ids === array()) {
            return array('text' => $text, 'sql' => $sql, 'param' => $name, 'by' => 'name');
        }
        $literals = implode(', ', array_map(static fn ($id) => is_int($id) ? (string) $id : (string) SqlValue::literal($id), $ids));

        return array(
            'text'  => sprintf('( %sOR %s%s IN (%s) ) ', $text, $column, $idColumn, $literals),
            'sql'   => '( ' . $sql . 'OR ' . $column . $idColumn . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ) ',
            'param' => $name,
            'by'    => 'name',
            'ids'   => $ids,
        );
    }
}
