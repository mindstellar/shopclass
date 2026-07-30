<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Model database for ItemResource table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class ItemResource extends DAO
{
    /**
     * It references to self object: ItemResource.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var ItemResource
     */
    private static $instance;

    /**
     * Set data related to t_item_resource table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_resource');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage'));
    }

    /**
     * It creates a new ItemResource object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return ItemResource
     * @since  unknown
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Get all resources
     *
     * @access public
     * @return array of resources
     * @since  unknown
     */
    public function getAllResources()
    {
        // The query builder has no notion of a table alias, and neither `r.*` nor the
        // aliased join can be expressed through its identifier allowlist, so this is
        // hand-written SQL with the same columns, the same join and no values to bind.
        $sql = 'SELECT r.*, c.dt_pub_date'
            . ' FROM ' . $this->getTableName() . ' r'
            . ' INNER JOIN ' . $this->getTableItemName() . ' c ON c.pk_i_id = r.fk_i_item_id';

        try {
            $rows = osc_db_select($sql);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Return table item name
     *
     * @access public
     * @return string table name
     * @since  unknown
     */
    public function getTableItemName()
    {
        return $this->getTablePrefix() . 't_item';
    }

    /**
     * Get all resources belong to an item given its id
     *
     * @access public
     *
     * @param int $itemId Item id
     *
     * @return array of resources
     *
     * @since  2.3.7
     *
     */
    public function getAllResourcesFromItem($itemId)
    {
        $key   = md5(osc_base_url() . 'ItemResource:getAllResourcesFromItem:' . $itemId);
        $found = null;
        $cache = osc_cache_get($key, $found);
        if ($cache === false) {
            try {
                $rows = osc_db_table($this->getTableName())
                    ->where('fk_i_item_id', (int)$itemId)
                    ->get();
            } catch (\mindstellar\database\DbException $e) {
                // A failed read is not memoized, so the next call retries.
                return array();
            }

            $return = osc_db_stringify_rows($rows);
            osc_cache_set($key, $return, OSC_CACHE_TTL);

            return $return;
        }

        return $cache;
    }

    /**
     * Prime the per-item resource cache for a set of items in a single query.
     *
     * Listing loops call getAllResourcesFromItem() once per item, which is an N+1.
     * Calling this first (e.g. from Item::extendData) fetches every listed item's
     * resources in one query and seeds the exact cache keys getAllResourcesFromItem
     * reads, so those per-item calls become cache hits. Items with no resources are
     * seeded with an empty array so they don't fall through to their own query.
     *
     * @param int[] $itemIds
     *
     * @return void
     * @since  5.3.0
     */
    public function primeResourcesCache($itemIds)
    {
        $itemIds = array_values(array_unique(array_map('intval', (array)$itemIds)));
        if (empty($itemIds)) {
            return;
        }

        try {
            $rows = osc_db_stringify_rows(
                osc_db_table($this->getTableName())
                    ->whereIn('fk_i_item_id', $itemIds)
                    ->get()
            );
        } catch (\mindstellar\database\DbException $e) {
            // A failed read still seeds every id with an empty list, so the memo
            // reports "no resources" rather than retrying. Long-standing behaviour.
            $rows = array();
        }

        $byItem = array_fill_keys($itemIds, array());
        foreach ($rows as $row) {
            $byItem[(int)$row['fk_i_item_id']][] = $row;
        }

        foreach ($byItem as $id => $resources) {
            $key = md5(osc_base_url() . 'ItemResource:getAllResourcesFromItem:' . $id);
            osc_cache_set($key, $resources, OSC_CACHE_TTL);
        }
    }

    /**
     * Get first resource belong to an item given it id
     *
     * @access public
     *
     * @param int $itemId Item id
     *
     * @return array resource
     * @since  unknown
     */
    public function getResource($itemId)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('fk_i_item_id', $itemId)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Check if resource id and name exist
     *
     * @param int    $resourceId
     * @param string $code
     *
     * @return bool
     * @deprecated since 2.3
     */
    public function getResourceSecure($resourceId, $code)
    {
        return $this->existResource($resourceId, $code);
    }

    /**
     * Check if resource id and name exist
     *
     * @access public
     *
     * @param int    $resourceId
     * @param string $code
     *
     * @return bool
     * @since  unknown
     */
    public function existResource($resourceId, $code)
    {
        if ($resourceId === null || $code === null) {
            // A null left the comparison without a right-hand side, so the query
            // failed and the caller got the INT zero rather than the string a
            // genuine no-match returns. Both are falsy; the type is still visible.
            return 0;
        }

        try {
            $count = osc_db_table($this->getTableName())
                ->where('pk_i_id', $resourceId)
                ->where('s_name', $code)
                ->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        // An aggregate with no GROUP BY always yields exactly one row, so the
        // "not exactly one row" branch this method used to have was unreachable.
        return (string)$count;
    }

    /**
     * Count resouces belong to item given its id
     *
     * @access public
     *
     * @param int $itemId Item id
     *
     * @return int
     * @since  unknown
     */
    public function countResources($itemId = null)
    {
        try {
            $query = osc_db_table($this->getTableName());
            if (null !== $itemId && is_numeric($itemId)) {
                $query = $query->where('fk_i_item_id', $itemId);
            }
            $count = $query->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        // As in existResource(), the aggregate always returns one row, so the
        // "not exactly one row" branch was unreachable.
        return (string)$count;
    }

    /**
     * Get resources, if $itemId is set return resources belong to an item given its id,
     * can be filtered by $start/$end and ordered by column.
     *
     * @access public
     *
     * @param int    $itemId Item id
     * @param int    $start  beginig
     * @param int    $length ending
     * @param string $order  column order default='pk_i_id'
     * @param string $type   order type [DESC|ASC]
     *
     * @return array of resources
     * @since  unknown
     */
    public function getResources($itemId = null, $start = 0, $length = 10, $order = 'r.pk_i_id', $type = 'DESC')
    {
        if (!in_array($order, array(
            0 => 'r.pk_i_id',
            1 => 'r.pk_i_id',
            2 => 'r.pk_i_id',
            3 => 'r.fk_i_item_id',
            4 => 'c.dt_pub_date'
        ))
        ) {
            // order by is incorrect
            return array();
        }

        if (!in_array(strtoupper($type), array('DESC', 'ASC'))) {
            // order type is incorrect
            return array();
        }

        // Aliases, `r.*` and the aliased join are all outside the query builder's
        // identifier allowlist, so this stays hand-written SQL. Every value is a
        // placeholder; $order is one of the five literals checked above and $type is
        // DESC or ASC, checked above as well.
        $sql = 'SELECT r.*, c.dt_pub_date'
            . ' FROM ' . $this->getTableName() . ' r'
            . ' INNER JOIN ' . $this->getTableItemName() . ' c ON c.pk_i_id = r.fk_i_item_id';

        $params = array();
        if (null !== $itemId && is_numeric($itemId)) {
            $sql     .= ' WHERE r.fk_i_item_id = ?';
            $params[] = $itemId;
        }

        $sql .= ' ORDER BY ' . $order . ' ' . strtoupper($type);

        // Legacy compiled "LIMIT <start>, <length>", i.e. $start is the OFFSET, with
        // two gates worth keeping: a non-numeric $start dropped the clause entirely
        // and returned every row, and a $length of zero or less dropped the offset,
        // turning $start into the row count.
        if (is_numeric($start)) {
            $sql .= ' LIMIT ' . (int)$start;
            if (is_numeric($length) && (int)$length > 0) {
                $sql .= ', ' . (int)$length;
            }
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Get a page of resource ids, ordered by pk_i_id, without loading the
     * full rows. Used by low-memory batch operations — e.g. queuing images
     * for background regeneration — that need to walk the whole table.
     *
     * @param int $offset
     * @param int $limit
     *
     * @return int[]
     * @since  5.3.0
     */
    public function getResourceIdsBatch(int $offset, int $limit): array
    {
        if ($offset < 0) {
            // Legacy emitted "LIMIT -n", an invalid clause whose failure landed here.
            return array();
        }

        try {
            $query = osc_db_table($this->getTableName())
                ->select('pk_i_id')
                ->orderBy('pk_i_id', 'ASC');
            // Legacy compiled "LIMIT <offset>, <limit>": the first argument is the
            // OFFSET, and a $limit of zero or less dropped the offset, turning
            // $offset into the row count instead.
            $query = $limit > 0 ? $query->limit($limit)->offset($offset) : $query->limit($offset);
            $rows  = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return array_map('intval', array_column($rows, 'pk_i_id'));
    }

    /**
     * Get a page of full resource rows for a given storage adapter id,
     * ordered by pk_i_id. Used by the admin migration actions to walk every
     * resource currently on one storage backend (e.g. 'local') and enqueue
     * a job per row without loading the whole table into memory at once.
     *
     * @param string $storage
     * @param int    $offset
     * @param int    $limit
     *
     * @return array
     * @since  5.3.0
     */
    public function getResourcesBatchByStorage(string $storage, int $offset, int $limit): array
    {
        if ($offset < 0) {
            // Legacy emitted "LIMIT -n", an invalid clause whose failure landed here.
            return array();
        }

        try {
            $query = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('s_storage', $storage)
                ->orderBy('pk_i_id', 'ASC');
            // Same legacy paging shape as getResourceIdsBatch(): $offset is the
            // offset, and a $limit of zero or less turns it into the row count.
            $query = $limit > 0 ? $query->limit($limit)->offset($offset) : $query->limit($offset);
            $rows  = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Delete all resources where id is in $ids
     *
     * @param array $ids
     *
     * @return bool|int
     */
    public function deleteResourcesIds($ids)
    {
        $values = is_array($ids) ? $ids : array($ids);
        if ($values === array()) {
            // Legacy compiled "IN ()" here, an invalid clause, and the failed delete
            // returned false — which callers can tell apart from a clean run that
            // matched nothing.
            return false;
        }

        try {
            return osc_db_table($this->getTableName())
                ->whereIn('pk_i_id', $values)
                ->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Return table description name
     *
     * @access public
     * @return string table description name
     * @since  unknown
     */
    public function getTableItemDescription()
    {
        return $this->getTablePrefix() . 't_item_description';
    }
}

/* file end: ./oc-includes/osclass/model/ItemResource.php */
