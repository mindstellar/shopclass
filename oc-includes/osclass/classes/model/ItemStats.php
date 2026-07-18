<?php

/*
 * This file is part of Osclass (Mindstellar).
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
 * Model database for ItemStat table
 *
 * @package    Osclass
 * @subpackage Model
 * @since      unknown
 */
class ItemStats extends DAO
{
    /**
     * It references to self object: ItemStats.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var ItemStats
     */
    private static $instance;

    /**
     * Set data related to t_item_stats table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_stats');
        $this->setPrimaryKey('fk_i_item_id');
        $this->setFields(array(
            'fk_i_item_id',
            'i_num_views',
            'i_num_spam',
            'i_num_repeated',
            'i_num_bad_classified',
            'i_num_offensive',
            'i_num_expired',
            'i_num_premium_views',
            'dt_date'
        ));
    }

    /**
     * It creates a new ItemStats object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return ItemStats
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
     * Increase the stat column given column name and item id
     *
     * @access public
     *
     * @param string $column
     * @param int    $itemId
     *
     * @return bool
     * @since  unknown
     * @todo   OJO query('update ....') cambiar a ->update()
     */
    public function increase($column, $itemId)
    {

        //('INSERT INTO %s (fk_i_item_id, dt_date, %3$s) VALUES (%d, \'%4$s\',1) ON DUPLICATE KEY UPDATE %3$s = %3$s + 1', $this->getTableName(), $id, $column, date('Y-m-d H:i:s'));
        $increaseColumns = array(
            'i_num_views',
            'i_num_spam',
            'i_num_repeated',
            'i_num_bad_classified',
            'i_num_offensive',
            'i_num_expired',
            'i_num_expired',
            'i_num_premium_views'
        );

        if (!in_array($column, $increaseColumns)) {
            return false;
        }

        if (!is_numeric($itemId)) {
            return false;
        }

        $sql = 'INSERT INTO ' . $this->getTableName() . ' (fk_i_item_id, dt_date, ' . $column . ') VALUES (' . $itemId
            . ', \'' . date('Y-m-d H:i:s') . '\',1) ON DUPLICATE KEY UPDATE  ' . $column . ' = ' . $column . ' + 1 ';

        return $this->dao->query($sql);
    }

    /**
     * Insert an empty row into table item stats
     *
     * @access public
     *
     * @param int $itemId Item id
     *
     * @return bool
     * @since  unknown
     */
    public function emptyRow($itemId)
    {
        return $this->insert(array(
            'fk_i_item_id' => $itemId,
            'dt_date'      => date('Y-m-d H:i:s')
        ));
    }

    /**
     * Return number of views of an item
     *
     * @access public
     *
     * @param int $itemId Item id
     *
     * @return int
     * @since  2.3.3
     */
    public function getViews($itemId)
    {
        $this->dao->select('SUM(i_num_views) AS i_num_views');
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_item_id', $itemId);
        $result = $this->dao->get();
        if (!$result) {
            return 0;
        }

        $res = $result->result();

        return $res[0]['i_num_views'];
    }

    /**
     * Return number of views of an item
     *
     * @access public
     * @return int
     * @since  2.3.3
     */
    public function getAllViews()
    {
        $this->dao->select('SUM(i_num_views) AS i_num_views');
        $this->dao->from($this->getTableName());
        $result = $this->dao->get();
        if (!$result) {
            return 0;
        }

        $res = $result->result();

        return $res[0]['i_num_views'];
    }
}

/* file end: ./oc-includes/osclass/model/ItemStats.php */
