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
 * Country, region and city names per language (t_location_name). A region or city is keyed by
 * its id as digits, a country by its code in lower case.
 */
final class LocationNameStore
{
    public const COUNTRY = 'country';
    public const REGION  = 'region';
    public const CITY    = 'city';

    private const TYPES = [self::COUNTRY, self::REGION, self::CITY];

    /**
     * Names in one language, in one query.
     *
     * @param array<int,int|string> $ids
     *
     * @return array<string,string> id => name; places with no row are left out
     */
    public function names(string $type, array $ids, string $locale): array
    {
        $keys = [];
        foreach ($ids as $id) {
            $keys[$this->key($type, $id)] = true;
        }
        if ($keys === []) {
            return [];
        }
        $keys   = array_keys($keys);
        $marks  = implode(',', array_fill(0, count($keys), '?'));
        $rows   = Db::select(
            'SELECT s_place_id, s_name FROM ' . self::table() . ' WHERE e_place_type = ? AND s_place_id IN (' . $marks . ') AND fk_c_locale_code = ?',
            array_merge([$type], array_map('strval', $keys), [$locale])
        );
        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['s_place_id']] = (string) $row['s_name'];
        }

        return $names;
    }

    /**
     * @return array<string,string> locale => name
     */
    public function all(string $type, int|string $id): array
    {
        $rows  = Db::select(
            'SELECT fk_c_locale_code, s_name FROM ' . self::table() . ' WHERE e_place_type = ? AND s_place_id = ? ORDER BY fk_c_locale_code',
            [$this->checkedType($type), $this->key($type, $id)]
        );
        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['fk_c_locale_code']] = (string) $row['s_name'];
        }

        return $names;
    }

    /**
     * Save a name; a blank name removes it. An unknown locale throws a DbException.
     *
     * @throws \mindstellar\database\DbException
     */
    public function set(string $type, int|string $id, string $locale, string $name): void
    {
        $key  = $this->key($type, $id);
        $name = trim($name);
        $rows = Db::table(self::table());
        if ($name === '') {
            $rows->where('e_place_type', $type)->where('s_place_id', $key)->where('fk_c_locale_code', $locale)->delete();

            return;
        }
        $rows->upsert([
            'e_place_type'     => $type,
            's_place_id'       => $key,
            'fk_c_locale_code' => $locale,
            's_name'           => $name,
        ], ['s_name']);
    }

    /**
     * Remove every language of one place.
     */
    public function forget(string $type, int|string $id): void
    {
        Db::table(self::table())->where('e_place_type', $this->checkedType($type))->where('s_place_id', $this->key($type, $id))->delete();
    }

    /**
     * Ids of the places that have this name in any language.
     *
     * @return string[]
     */
    public function idsNamed(string $type, string $name, int $limit = 100): array
    {
        return $this->ids($type, $name, $limit);
    }

    /**
     * Ids of the places with a name that starts with $prefix in any language.
     *
     * @return string[]
     */
    public function idsStartingWith(string $type, string $prefix, int $limit = 100): array
    {
        return $this->ids($type, QueryBuilder::escapeLike($prefix) . '%', $limit);
    }

    /**
     * @return string[]
     */
    private function ids(string $type, string $pattern, int $limit): array
    {
        if ($pattern === '' || $pattern === '%') {
            return [];
        }
        $rows = Db::select(
            'SELECT s_place_id FROM ' . self::table() . ' WHERE e_place_type = ? AND s_name LIKE ? LIMIT ' . max(1, $limit),
            [$this->checkedType($type), $pattern]
        );

        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['s_place_id'], $rows)));
    }

    private function key(string $type, int|string $id): string
    {
        $key = (string) $id;

        return $this->checkedType($type) === self::COUNTRY ? strtolower($key) : $key;
    }

    private function checkedType(string $type): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown place type: ' . $type);
        }

        return $type;
    }

    private static function table(): string
    {
        return DB_TABLE_PREFIX . 't_location_name';
    }
}
