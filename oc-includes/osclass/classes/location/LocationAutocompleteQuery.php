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
 * The location boxes' suggestions. A place matches when its stored name or its name in any
 * language starts with the typed text; labels come out in the given language.
 */
final class LocationAutocompleteQuery
{
    public function __construct(
        private readonly string $locale,
        private readonly LocationNameStore $store,
        private readonly LocationNames $names
    ) {
    }

    /**
     * Suggestions in $locale, sharing the request's name memo.
     */
    public static function inLocale(string $locale): self
    {
        return new self($locale, new LocationNameStore(), Container::root()->get(LocationNames::class));
    }

    /**
     * @return array<int,array<string,string|null>> rows with id, label and value
     */
    public function countries(string $term): array
    {
        $term  = trim($term);
        $codes = $this->store->idsStartingWith(LocationNameStore::COUNTRY, $term);
        [$match, $params] = $this->matching('pk_c_code', $term, $codes);
        $rows = Db::select(
            'SELECT pk_c_code AS id, s_name AS label, s_name AS value FROM ' . self::table('t_country') . ' WHERE ' . $match . ' LIMIT 5',
            $params
        );

        return $this->labelled(Db::stringifyRows($rows), LocationNameStore::COUNTRY);
    }

    /**
     * @param string|null $country a country code, a country name or nothing
     *
     * @return array<int,array<string,string|null>> rows with id, label and value
     */
    public function regions(string $term, ?string $country = null): array
    {
        $term    = trim($term);
        $country = trim((string) $country);
        $ids     = $this->store->idsStartingWith(LocationNameStore::REGION, $term);
        [$match, $params] = $this->matching('a.pk_i_id', $term, array_map('intval', $ids), 'a.s_name');

        $sql = 'SELECT a.pk_i_id AS id, a.s_name AS label, a.s_name AS value FROM ' . self::table('t_region') . ' AS a';
        if ($country === '') {
            $sql .= ' WHERE ' . $match;
        } elseif (strlen($country) === 2) {
            $sql .= ' WHERE a.fk_c_country_code = ? AND ' . $match;
            array_unshift($params, strtolower($country));
        } else {
            $codes  = $this->store->idsNamed(LocationNameStore::COUNTRY, $country);
            $sql   .= ' LEFT JOIN ' . self::table('t_country') . ' AS aux ON aux.pk_c_code = a.fk_c_country_code WHERE '
                . $this->scope('aux.s_name', 'a.fk_c_country_code', $codes) . ' AND ' . $match;
            $params = array_merge(array($country), $codes, $params);
        }
        $sql     .= ' ORDER BY a.s_name = ? DESC, a.i_num_items DESC, a.s_name LIMIT 5';
        $params[] = $term;

        return $this->labelled(Db::stringifyRows(Db::select($sql, $params)), LocationNameStore::REGION);
    }

    /**
     * @param int|string|null $region a region id, a region name or nothing
     *
     * @return array<int,array<string,string|null>> rows with id, label, value and region
     */
    public function cities(string $term, int|string|null $region = null): array
    {
        $term = trim($term);
        // An empty term would match, and load, every city.
        if ($term === '') {
            return array();
        }
        $region = trim((string) $region);
        $ids    = $this->store->idsStartingWith(LocationNameStore::CITY, $term);
        [$match, $params] = $this->matching('a.pk_i_id', $term, array_map('intval', $ids), 'a.s_name');

        $sql = 'SELECT a.pk_i_id AS id, a.s_name AS label, a.s_name AS value, aux.s_name AS region, a.fk_i_region_id AS region_id'
            . ' FROM ' . self::table('t_city') . ' AS a'
            . ' LEFT JOIN ' . self::table('t_region') . ' AS aux ON aux.pk_i_id = a.fk_i_region_id'
            . ' WHERE ' . $match;
        if ($region !== '' && is_numeric($region)) {
            $sql     .= ' AND a.fk_i_region_id = ?';
            $params[] = (int) $region;
        } elseif ($region !== '') {
            $named    = array_map('intval', $this->store->idsNamed(LocationNameStore::REGION, $region));
            $sql     .= ' AND ' . $this->scope('aux.s_name', 'a.fk_i_region_id', $named);
            $params   = array_merge($params, array($region), $named);
        }
        $sql     .= ' ORDER BY a.s_name = ? DESC, a.i_num_items DESC, a.s_name LIMIT 10';
        $params[] = $term;

        $rows = Db::stringifyRows(Db::select($sql, $params));
        $this->names->prime($this->locale, array(
            LocationNameStore::CITY   => array_column($rows, 'id'),
            LocationNameStore::REGION => array_column($rows, 'region_id'),
        ));
        foreach ($rows as $i => $row) {
            if ($row['region'] !== null && $row['region_id'] !== null) {
                $rows[$i]['region'] = $this->names->name(LocationNameStore::REGION, $row['region_id'], $this->locale, $row['region']);
            }
            unset($rows[$i]['region_id']);
        }

        return $this->labelled($rows, LocationNameStore::CITY);
    }

    /**
     * The name prefix match, widened to the places named so in another language.
     *
     * @param array<int,int|string> $ids
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function matching(string $idColumn, string $term, array $ids, string $nameColumn = 's_name'): array
    {
        $params = array(QueryBuilder::escapeLike($term) . '%');
        if ($ids === array()) {
            return array($nameColumn . ' LIKE ?', $params);
        }

        return array(
            '(' . $nameColumn . ' LIKE ? OR ' . $idColumn . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . '))',
            array_merge($params, $ids),
        );
    }

    /**
     * A parent given by name: its stored name, or the ids of the places named so in any language.
     *
     * @param array<int,int|string> $ids
     */
    private function scope(string $nameColumn, string $idColumn, array $ids): string
    {
        if ($ids === array()) {
            return $nameColumn . ' = ?';
        }

        return '(' . $nameColumn . ' = ? OR ' . $idColumn . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . '))';
    }

    /**
     * Label and value in the locale's name, when it has one.
     *
     * @param array<int,array<string,string|null>> $rows
     *
     * @return array<int,array<string,string|null>>
     */
    private function labelled(array $rows, string $type): array
    {
        $this->names->prime($this->locale, array($type => array_column($rows, 'id')));
        foreach ($rows as $i => $row) {
            $name             = $this->names->name($type, (string) $row['id'], $this->locale, (string) $row['label']);
            $rows[$i]['label'] = $name;
            $rows[$i]['value'] = $name;
        }

        return $rows;
    }

    private static function table(string $name): string
    {
        return DB_TABLE_PREFIX . $name;
    }
}
