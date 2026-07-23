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
 * Model database for CategoryStats table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class CategoryStats extends DAO
{
    /**
     * It references to self object: CategotyStats.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var CategoryStats
     */
    private static $instance;

    /**
     * Set data related to t_category_stats table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_category_stats');
        $this->setPrimaryKey('fk_i_category_id');
        $this->setFields(array('fk_i_category_id', 'i_num_items'));
    }

    /**
     * It creates a new CategoryStats object class if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return CategoryStats
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
     * Increase number of category items, given a category id
     *
     * @access public
     *
     * @param int $categoryId Category id
     *
     * @return int number of affected rows, id error occurred return false
     *
     * @since  unknown
     *
     */
    public function increaseNumItems($categoryId)
    {
        if (!is_numeric($categoryId)) {
            return false;
        }

        // Legacy built this SQL with sprintf('%d', $categoryId), truncating to
        // an integer before the query ever reached the database. A bound
        // parameter has to reproduce that same truncation explicitly: MySQL's
        // foreign-key check on a prepared value does not coerce a fractional
        // numeric string the way a plain integer literal does, so an uncast
        // bind of e.g. "5.7" fails where the legacy, already-truncated SQL
        // text succeeds.
        $categoryId = (int)$categoryId;

        $sql = 'INSERT INTO ' . $this->getTableName()
            . ' (fk_i_category_id, i_num_items) VALUES (?, 1)
               ON DUPLICATE KEY UPDATE i_num_items = i_num_items + 1';

        try {
            osc_db_execute($sql, array($categoryId));
            $return = true;
        } catch (\mindstellar\database\DbException $e) {
            $return = false;
        }

        // Runs unconditionally, exactly as legacy did: the && below only
        // short-circuits the recursive add, not this lookup.
        $result = Category::newInstance()->findByPrimaryKey($categoryId);
        if (($return !== false) && $result['fk_i_parent_id'] != null) {
            $parent_res = $this->increaseNumItems($result['fk_i_parent_id']);
            if ($parent_res !== false) {
                $return += $parent_res;
            } else {
                $return = false;
            }
        }

        return $return;
    }

    /**
     * Increase number of category items, given a category id
     *
     * @access public
     *
     * @param int $categoryId Category id
     *
     * @return int number of affected rows, id error occurred return false
     *
     * @since  unknown
     *
     */
    public function decreaseNumItems($categoryId)
    {
        if ($categoryId === null) {
            // Legacy where('fk_i_category_id', null) only appends a value when
            // it is non-null, so it emits a bare "fk_i_category_id =" with no
            // right-hand side -- a SQL syntax error that fails the SELECT and
            // returns bool false after exactly one query, before ever reaching
            // a write. A bound null is valid SQL matching zero rows, so a
            // naive conversion would fall through to the insert branch and
            // fail there instead (NULL into a NOT NULL column) -- reaching
            // the same false, but at the cost of a second, wasted query.
            // Guarded explicitly so the cost matches, not just the return
            // value.
            return false;
        }

        try {
            $row = osc_db_table($this->getTableName())
                ->select('i_num_items')
                ->where($this->getPrimaryKey(), $categoryId)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        $categoryStat = $row !== null ? osc_db_stringify_row($row) : array();
        $return       = 0;

        if (isset($categoryStat['i_num_items'])) {
            try {
                $return = osc_db_execute(
                    'UPDATE ' . $this->getTableName()
                    . ' SET i_num_items = i_num_items - 1 WHERE i_num_items > 0 AND fk_i_category_id = ?',
                    array($categoryId)
                );
            } catch (\mindstellar\database\DbException $e) {
                $return = false;
            }
        } else {
            try {
                osc_db_table($this->getTableName())->insert(array(
                    'fk_i_category_id' => $categoryId,
                    'i_num_items'      => 0,
                ));
            } catch (\mindstellar\database\DbException $e) {
                $return = false;
            }
        }

        if ($return !== false) {
            $result = Category::newInstance()->findByPrimaryKey($categoryId);
            if ($result['fk_i_parent_id'] != null) {
                $parent_res = $this->decreaseNumItems($result['fk_i_parent_id']);
                if ($parent_res !== false) {
                    $return += $parent_res;
                } else {
                    $return = false;
                }
            }
        }

        return $return;
    }

    /**
     * @param $categoryID
     * @param $numItems
     *
     * @return bool|\DBRecordsetClass
     */
    public function setNumItems($categoryID, $numItems)
    {
        $categoryID = (int)$categoryID;
        $numItems   = (int)$numItems;

        $sql = 'INSERT INTO ' . $this->getTableName()
            . ' (fk_i_category_id, i_num_items) VALUES (?, ?) ON DUPLICATE KEY UPDATE i_num_items = ?';

        try {
            osc_db_execute($sql, array($categoryID, $numItems, $numItems));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Find stats by category id
     *
     * @access public
     *
     * @param int $categoryId Category id
     *
     * @return array CategoryStats
     * @since  unknown
     */
    public function findByCategoryId($categoryId)
    {
        return $this->findByPrimaryKey($categoryId);
    }

    /**
     * Count items,  given a category id
     *
     * @access public
     *
     * @param $categoryId Category id
     *
     * @return int number of items into category
     * @since  unknown
     */
    public function countItemsFromCategory($categoryId)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->select('i_num_items')
                ->where('fk_i_category_id', $categoryId)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        if ($row === null) {
            return 0;
        }

        $row = osc_db_stringify_row($row);

        return $row['i_num_items'];
    }

    /**
     * Get number of items
     *
     * @access    public
     *
     * @param array $cat category array
     *
     * @return int
     *
     * @since     unknown
     * @staticvar string $numItemsMap
     *
     */
    public function getNumItems($cat)
    {
        static $numItemsMap = null;
        if (null === $numItemsMap) {
            $numItemsMap = $this->toNumItemsMap();
        }
        if (isset($numItemsMap['parent'][$cat['pk_i_id']])) {
            return $numItemsMap['parent'][$cat['pk_i_id']]['numItems'];
        }

        if (isset($numItemsMap['subcategories'][$cat['pk_i_id']])) {
            return $numItemsMap['subcategories'][$cat['pk_i_id']]['numItems'];
        }

        return 0;
    }

    /**
     *
     * @access public
     * @return array
     * @since  unknown
     */
    public function toNumItemsMap()
    {
        $map = array();
        $all = $this->listAll();

        if (empty($all)) {
            return array();
        }

        $roots = Category::newInstance()->findRootCategories();

        foreach ($all as $a) {
            $map[$a['fk_i_category_id']] = $a['i_num_items'];
        }

        $new_map = array();
        foreach ($roots as $root) {
            $root_description                    = Category::newInstance()->findByPrimaryKey($root['pk_i_id']);
            $new_map['parent'][$root['pk_i_id']] =
                array('numItems' => @$map[$root['pk_i_id']], 's_name' => @$root_description['s_name']);
            $subcategories                       = Category::newInstance()->findSubcategories($root['pk_i_id']);
            $aux                                 = array();
            foreach ($subcategories as $sub) {
                $sub_description      = Category::newInstance()->findByPrimaryKey($sub['pk_i_id']);
                $aux[$sub['pk_i_id']] =
                    array('numItems' => $map[$sub['pk_i_id']], 's_name' => $sub_description['s_name']);
            }
            $new_map['subcategories'][$root['pk_i_id']] = $aux;
        }

        return $new_map;
    }
}

/* file end: ./oc-includes/osclass/model/CategoryStats.php */
