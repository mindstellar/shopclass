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

use mindstellar\database\Db;

/**
 * Listing photos: the `item` rows of t_resource, read and written in the old t_item_resource
 * row shape (pk_i_id, fk_i_item_id, s_name, s_extension, s_content_type, s_path, s_storage).
 *
 * @package    Shopclass
 * @subpackage Model
 */
class ItemResource
{
    /** The owner type listing photos have in t_resource. */
    public const OWNER = \mindstellar\model\Resource::OWNER_ITEM;

    /** The old column names, in the order rows come back. */
    private const FIELDS = array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage');

    /** The old columns as t_resource holds them. */
    private const SELECT = 'pk_i_id, i_owner_id AS fk_i_item_id, s_name, s_extension, s_content_type, s_path, s_storage';

    /**
     * The select list that reads t_resource in the old row shape, with an optional table alias.
     */
    public static function columns(string $alias = ''): string
    {
        return $alias === '' ? self::SELECT : preg_replace('/(^|, )/', '$1' . $alias . '.', self::SELECT);
    }

    /**
     * @var \ItemResource
     */
    private static $instance;

    /**
     * Return the shared ItemResource model instance, creating it on first use.
     *
     * @return \ItemResource
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * The table listing photos live in, with its prefix.
     *
     * @return string
     */
    public function getTableName()
    {
        return DB_TABLE_PREFIX . 't_resource';
    }

    /**
     * @return string[] the old column names
     */
    public function getFields()
    {
        return self::FIELDS;
    }

    /**
     * Every photo, each with its listing's dt_pub_date.
     *
     * @return array<int,array<string,string|null>>
     */
    public function getAllResources()
    {
        return $this->select(
            'SELECT ' . self::columns('r') . ', c.dt_pub_date'
            . ' FROM ' . $this->getTableName() . ' r INNER JOIN ' . $this->getTableItemName() . ' c ON c.pk_i_id = r.i_owner_id'
            . ' WHERE r.s_owner_type = ?',
            array(self::OWNER)
        );
    }

    /**
     * @return string
     */
    public function getTableItemName()
    {
        return DB_TABLE_PREFIX . 't_item';
    }

    /**
     * @return string
     */
    public function getTableItemDescription()
    {
        return DB_TABLE_PREFIX . 't_item_description';
    }

    /**
     * A listing's photos, in upload order. Cached.
     *
     * @param int $itemId
     *
     * @return array<int,array<string,string|null>>
     */
    public function getAllResourcesFromItem($itemId)
    {
        $key   = self::cacheKey((int) $itemId);
        $found = false;
        $cache = osc_cache_get($key, $found);
        if ($cache !== false) {
            return $cache;
        }
        try {
            $rows = Db::stringifyRows($this->rows('i_owner_id = ?', array((int) $itemId)));
        } catch (\mindstellar\database\DbException $e) {
            // A failed read is not memoized, so the next call retries.
            return array();
        }
        osc_cache_set($key, $rows, OSC_CACHE_TTL);

        return $rows;
    }

    /**
     * Read the photos of many listings in one query and cache each listing's list.
     *
     * @param int[] $itemIds
     *
     * @return void
     */
    public function primeResourcesCache($itemIds)
    {
        $itemIds = array_values(array_unique(array_map('intval', (array) $itemIds)));
        if (empty($itemIds)) {
            return;
        }
        try {
            $rows = Db::stringifyRows($this->rows(
                'i_owner_id IN (' . implode(', ', array_fill(0, count($itemIds), '?')) . ')',
                $itemIds
            ));
        } catch (\mindstellar\database\DbException $e) {
            // A failed read still seeds every id with an empty list, so the memo
            // reports "no resources" rather than retrying.
            $rows = array();
        }
        $byItem = array_fill_keys($itemIds, array());
        foreach ($rows as $row) {
            $byItem[(int) $row['fk_i_item_id']][] = $row;
        }
        foreach ($byItem as $id => $resources) {
            osc_cache_set(self::cacheKey((int) $id), $resources, OSC_CACHE_TTL);
        }
    }

    /**
     * A listing's first photo, or an empty array.
     *
     * @param int $itemId
     *
     * @return array<string,string|null>
     */
    public function getResource($itemId)
    {
        try {
            $rows = $this->rows('i_owner_id = ?', array((int) $itemId), ' ORDER BY pk_i_id LIMIT 1');
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $rows === array() ? array() : Db::stringifyRow($rows[0]);
    }

    /**
     * @param int    $resourceId
     * @param string $code s_name
     *
     * @return string|int see existResource()
     */
    public function getResourceSecure($resourceId, $code)
    {
        return $this->existResource($resourceId, $code);
    }

    /**
     * How many photos have this id and code: "1" or "0", or int 0 when either is null.
     *
     * @param int    $resourceId
     * @param string $code s_name
     *
     * @return string|int
     */
    public function existResource($resourceId, $code)
    {
        if ($resourceId === null || $code === null) {
            return 0;
        }
        try {
            $count = Db::table($this->getTableName())
                ->where('s_owner_type', self::OWNER)
                ->where('pk_i_id', $resourceId)
                ->where('s_name', $code)
                ->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        return (string) $count;
    }

    /**
     * How many photos one listing has, or all listings when $itemId is null.
     *
     * @param int|null $itemId
     *
     * @return string|int
     */
    public function countResources($itemId = null)
    {
        try {
            $query = Db::table($this->getTableName())->where('s_owner_type', self::OWNER);
            if (null !== $itemId && is_numeric($itemId)) {
                $query = $query->where('i_owner_id', $itemId);
            }
            $count = $query->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        return (string) $count;
    }

    /**
     * A page of photos with their listing's dt_pub_date, for the admin.
     *
     * @param int|null $itemId
     * @param int      $start  offset
     * @param int      $length
     * @param string   $order  r.pk_i_id, r.fk_i_item_id or c.dt_pub_date
     * @param string   $type   ASC or DESC
     *
     * @return array<int,array<string,string|null>>
     */
    public function getResources($itemId = null, $start = 0, $length = 10, $order = 'r.pk_i_id', $type = 'DESC')
    {
        $columns = array('r.pk_i_id' => 'r.pk_i_id', 'r.fk_i_item_id' => 'r.i_owner_id', 'c.dt_pub_date' => 'c.dt_pub_date');
        if (!isset($columns[$order]) || !in_array(strtoupper((string) $type), array('DESC', 'ASC'), true)) {
            return array();
        }

        $sql = 'SELECT ' . self::columns('r') . ', c.dt_pub_date'
            . ' FROM ' . $this->getTableName() . ' r INNER JOIN ' . $this->getTableItemName() . ' c ON c.pk_i_id = r.i_owner_id'
            . ' WHERE r.s_owner_type = ?';
        $params = array(self::OWNER);
        if (null !== $itemId && is_numeric($itemId)) {
            $sql     .= ' AND r.i_owner_id = ?';
            $params[] = $itemId;
        }
        $sql .= ' ORDER BY ' . $columns[$order] . ' ' . strtoupper((string) $type);
        // $start is the offset; a non-numeric $start returns every row, and a $length of
        // zero or less makes $start the row count.
        if (is_numeric($start)) {
            $sql .= ' LIMIT ' . (int) $start;
            if (is_numeric($length) && (int) $length > 0) {
                $sql .= ', ' . (int) $length;
            }
        }

        return $this->select($sql, $params);
    }

    /**
     * Photo ids in id order, a page at a time.
     *
     * @return int[]
     */
    public function getResourceIdsBatch(int $offset, int $limit): array
    {
        if ($offset < 0) {
            return array();
        }
        try {
            $rows = Db::select(
                'SELECT pk_i_id FROM ' . $this->getTableName() . ' WHERE s_owner_type = ? ORDER BY pk_i_id ASC' . self::limit($offset, $limit),
                array(self::OWNER)
            );
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return array_map('intval', array_column($rows, 'pk_i_id'));
    }

    /**
     * Photos on one storage, in id order, a page at a time.
     *
     * @return array<int,array<string,string|null>>
     */
    public function getResourcesBatchByStorage(string $storage, int $offset, int $limit): array
    {
        if ($offset < 0) {
            return array();
        }
        try {
            return Db::stringifyRows($this->rows('s_storage = ?', array($storage), ' ORDER BY pk_i_id ASC' . self::limit($offset, $limit)));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
    }

    /**
     * One photo, or false.
     *
     * @param int|string $id
     *
     * @return array<string,string|null>|false
     */
    public function findByPrimaryKey($id)
    {
        try {
            $rows = $this->rows('pk_i_id = ?', array($id));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return count($rows) === 1 ? Db::stringifyRow($rows[0]) : false;
    }

    /**
     * Add a photo row.
     *
     * @param array<string,mixed> $values old column names; fk_i_item_id is required
     *
     * @return int the new id, or 0 when refused or the write failed
     */
    public function insertGetId($values)
    {
        $data = self::toColumns(is_array($values) ? $values : array());
        if ($data === null || empty($data['i_owner_id'])) {
            return 0;
        }
        $data['s_owner_type'] = self::OWNER;
        $data['dt_created']   = date('Y-m-d H:i:s');
        try {
            return Db::table($this->getTableName())->insert($data);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * Change photo rows.
     *
     * @param array<string,mixed> $values old column names
     * @param array<string,mixed> $where  old column names; an empty one is refused
     *
     * @return int|false rows changed, or false when refused or the write failed
     */
    public function update($values, $where)
    {
        $data  = self::toColumns(is_array($values) ? $values : array());
        $match = self::toColumns(is_array($where) ? $where : array());
        if ($data === null || $data === array() || $match === null || $match === array()) {
            return false;
        }
        try {
            $query = Db::table($this->getTableName())->where('s_owner_type', self::OWNER);
            foreach ($match as $column => $value) {
                $query = $query->where($column, $value);
            }

            return $query->update($data);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $values old column names
     * @param int                 $key
     *
     * @return int|false
     */
    public function updateByPrimaryKey($values, $key)
    {
        return $this->update($values, array('pk_i_id' => $key));
    }

    /**
     * Delete photo rows by id. Rows of other owner types are left alone.
     *
     * @param int|int[] $ids
     *
     * @return int|false rows removed, or false for an empty list or a failed write
     */
    public function deleteResourcesIds($ids)
    {
        $values = is_array($ids) ? $ids : array($ids);
        if ($values === array()) {
            return false;
        }
        try {
            return Db::table($this->getTableName())
                ->where('s_owner_type', self::OWNER)
                ->whereIn('pk_i_id', $values)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * The cache key of one listing's photo list.
     */
    public static function cacheKey(int $itemId): string
    {
        return md5(osc_base_url() . 'ItemResource:getAllResourcesFromItem:' . $itemId);
    }

    /**
     * @param array<int,mixed> $params
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    private function rows(string $where, array $params, string $tail = ''): array
    {
        return Db::select(
            'SELECT ' . self::SELECT . ' FROM ' . $this->getTableName() . ' WHERE s_owner_type = ? AND ' . $where
            . ($tail === '' ? ' ORDER BY pk_i_id' : $tail),
            array_merge(array(self::OWNER), $params)
        );
    }

    /**
     * @param array<int,mixed> $params
     *
     * @return array<int,array<string,string|null>>
     */
    private function select(string $sql, array $params): array
    {
        try {
            return Db::stringifyRows(Db::select($sql, $params));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
    }

    /**
     * The old paging: $offset is the offset, and a $limit of zero or less makes it the row count.
     */
    private static function limit(int $offset, int $limit): string
    {
        return $limit > 0 ? ' LIMIT ' . $limit . ' OFFSET ' . $offset : ' LIMIT ' . $offset;
    }

    /**
     * Old column names to t_resource ones, or null when one is unknown.
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>|null
     */
    private static function toColumns(array $values): ?array
    {
        $out = array();
        foreach ($values as $column => $value) {
            if (!in_array($column, self::FIELDS, true)) {
                return null;
            }
            $out[$column === 'fk_i_item_id' ? 'i_owner_id' : $column] = $value;
        }

        return $out;
    }
}

/* file end: ./oc-includes/osclass/model/ItemResource.php */
