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

use mindstellar\cache\CacheGroup;
use mindstellar\cache\CacheManager;
use mindstellar\cache\MemoryCache;
use mindstellar\database\Db;

/**
 * Site settings, loaded once per request into a section => name => value map.
 * With Redis, Memcached or APCu the map is shared between requests for up to CACHE_TTL seconds;
 * every write through this class drops it. Code that writes t_preference with raw SQL must call
 * osc_reset_preferences() afterwards. With the default cache the table is read on every request.
 */
class Preference extends DAO
{
    use \mindstellar\base\SharedInstance;

    /** Seconds the shared map lives, so a write that bypasses this class shows within that time. */
    private const CACHE_TTL = 300;

    /** Writes through the inherited DAO methods drop the shared map too. */
    protected $cacheGroup = 'preference';

    /**
     * array for save preferences
     *
     * @var array
     */
    private $pref;

    /**
     * Set data related to t_preference table and load the cached preference map.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_preference');
        /* $this->set_primary_key($key); // no primary key in preference table */
        $this->setFields(array('s_section', 's_name', 's_value', 'e_type'));
        $shared = $this->loadShared();
        if ($shared === null) {
            $this->toArray();
        } else {
            $this->merge($shared);
        }
    }

    /**
     * Load every preference row into the in-memory section => name => value map.
     *
     * @return bool False when the table is empty or the query failed
     */
    public function toArray()
    {
        $map = $this->readTable();
        if (!$map) {
            return false;
        }

        $this->merge($map);
        $this->share($map);

        return true;
    }

    /**
     * Every row as section => name => value, or null when the query failed.
     *
     * @return array<string,array<string,string>>|null
     */
    private function readTable(): ?array
    {
        try {
            $rows = Db::table($this->getTableName())->get();
        } catch (\mindstellar\database\DbException $e) {
            return null;
        }

        // A plain query returns text already, so the rows need no conversion.
        $map = array();
        foreach ($rows as $row) {
            $map[$row['s_section']][$row['s_name']] = $row['s_value'];
        }

        return $map;
    }

    /**
     * @param array<string,array<string,string>> $map
     */
    private function merge(array $map): void
    {
        // Merges into the existing map rather than replacing it, so a key
        // removed from the table since the last load stays cached until it is
        // overwritten by name.
        foreach ($map as $section => $values) {
            foreach ($values as $name => $value) {
                $this->pref[$section][$name] = $value;
            }
        }
    }

    /**
     * Whether the object cache outlives the request. The in-request cache would only hold a
     * second copy of the map, so it is skipped.
     */
    private static function sharedCache(): bool
    {
        try {
            return !(CacheManager::getInstance() instanceof MemoryCache);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A map from a finished install. Anything else is never cached, so an install in progress
     * cannot keep the site looking uninstalled.
     *
     * @param array<string,array<string,string>>|null $map
     */
    private static function shareable(?array $map): bool
    {
        return !empty($map['osclass']['osclass_installed']);
    }

    /**
     * The map from the object cache, read from the table on a miss.
     *
     * @return array<string,array<string,string>>|null null when there is no shared cache to use
     */
    private function loadShared(): ?array
    {
        if (!self::sharedCache()) {
            return null;
        }
        $read = null;
        try {
            $map = CacheGroup::remember('preference', 'map', function () use (&$read): ?array {
                $read = $this->readTable() ?? array();

                return self::shareable($read) ? $read : null;
            }, self::CACHE_TTL);
        } catch (\Throwable $e) {
            return $read;
        }

        return is_array($map) ? $map : $read;
    }

    /**
     * Replace the cached map when it differs from $map, a fresh read of the table.
     *
     * @param array<string,array<string,string>> $map
     */
    private function share(array $map): void
    {
        if (!self::shareable($map) || !self::sharedCache()) {
            return;
        }
        try {
            $store = static fn (): array => $map;
            if (CacheGroup::remember('preference', 'map', $store, self::CACHE_TTL) !== $map) {
                CacheGroup::invalidate('preference');
                CacheGroup::remember('preference', 'map', $store, self::CACHE_TTL);
            }
        } catch (\Throwable $e) {
            // The next request reads the table.
        }
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Find a value by its name
     *
     * @param string $name
     *
     * @return string|false False when the name is unknown or the query failed
     */
    public function findValueByName($name)
    {
        try {
            $row = Db::table($this->getTableName())
                ->select('s_value')
                ->where('s_name', $name)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        return Db::stringifyRow($row)['s_value'];
    }

    /**
     * Find array preference for a given section
     *
     * @param string $name
     *
     * @return array<int,array<string,string|null>>|false False when the section has no rows,
     *         an empty array when the query failed
     */
    public function findBySection($name)
    {
        if ($name === null) {
            // A null section builds a comparison with no right-hand side under
            // the legacy query layer, which fails outright and lands on the
            // array() branch below rather than the zero-row false branch a
            // valid, merely non-matching section reaches.
            return array();
        }

        try {
            $rows = Db::table($this->getTableName())
                ->where('s_section', $name)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($rows === []) {
            return false;
        }

        return Db::stringifyRows($rows);
    }

    /**
     * Get value, given a preference name and a section name.
     *
     * @param string $key
     * @param string $section
     *
     * @return string
     */
    public function get($key, $section = 'osclass')
    {
        return $this->pref[$section][$key] ?? '';
    }

    /**
     * Get value, given a preference name and a section name.
     *
     * @param string $section
     *
     * @return array<string,string> Empty when the section is not loaded
     */
    public function getSection($section = 'osclass')
    {
        if (isset($this->pref[$section]) && is_array($this->pref[$section])) {
            return $this->pref[$section];
        }

        return array();
    }

    /**
     * Set preference value, given a preference name and a section name.
     *
     * @param string $key
     * @param string $value
     * @param string $section
     *
     * @return void
     */
    public function set($key, $value, $section = 'osclass')
    {
        $this->pref[$section][$key] = $value;
    }

    /**
     * Replace preference value, given preference name, preference section and value.
     *
     * @param string $key
     * @param string $value
     * @param string $section
     * @param string $type
     *
     * @return boolean
     */
    public function replace($key, $value, $section = 'osclass', $type = 'STRING')
    {
        static $aValidEnumTypes = array('STRING', 'INTEGER', 'BOOLEAN');
        $e_type = in_array($type, $aValidEnumTypes) ? $type : 'STRING';

        try {
            // No QueryBuilder equivalent for REPLACE INTO; the unique key on
            // (s_section, s_name) is what makes this an upsert.
            Db::execute(
                'REPLACE INTO ' . $this->getTableName() . ' (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)',
                array($key, $value, $section, $e_type)
            );
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        $this->cacheChanged();

        // The map this class answers reads from is loaded once per request, so without
        // this a value written here stays invisible to the code that wrote it until the
        // next request. That reads as a stale preference rather than a missing write: the
        // location catalog refreshes to a new release, records where that release lives,
        // then builds its download URLs from the previous one and fails every checksum.
        $this->set($key, $value, $section);

        return true;
    }

    /**
     * Write $value unless the preference already holds it, so that of two runs at once only
     * one sees its write land. The row is created first when missing.
     *
     * @param string $key
     * @param string $value
     * @param string $section
     *
     * @return bool whether this call changed the value
     * @throws \mindstellar\database\DbException
     */
    public function claim($key, $value, $section = 'osclass')
    {
        $created = Db::execute(
            'INSERT IGNORE INTO ' . $this->getTableName() . " (s_section, s_name, s_value, e_type) VALUES (?, ?, '', 'STRING')",
            array($section, $key)
        );

        $changed = Db::execute(
            'UPDATE ' . $this->getTableName() . ' SET s_value = ? WHERE s_section = ? AND s_name = ? AND s_value <> ?',
            array($value, $section, $key, $value)
        ) === 1;
        if ($created > 0 || $changed) {
            $this->cacheChanged();
        }

        return $changed;
    }
}
