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
 * Model database for AlertsStats table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      3.1
 */
class AlertsStats extends DAO
{
    /**
     * It references to self object: AlertsStats.
     * It is used as a singleton
     *
     * @access private
     * @since  3.1
     * @var AlertsStats
     */
    private static $instance;

    /**
     * Set data related to t_alerts_sent table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_alerts_sent');
        $this->setPrimaryKey('d_date');
        $this->setFields(array('d_date', 'i_num_alerts_sent'));
    }

    /**
     * It creates a new AlertsStats object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return AlertsStats
     * @since  3.1
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
     * @param string $date
     *
     * @return bool
     * @since  3.1
     */
    public function increase($date)
    {
        // check the date it's ok
        if (!preg_match('|^[0-9]{4}-[0-9]{2}-[0-9]{2}$|', $date)) {
            return false;
        }

        // One statement covers both the first alert of the day and every one
        // after it, so there is no failed insert to interpret. The date is
        // bound rather than interpolated, and the format guard above already
        // constrains it.
        $sql = 'INSERT INTO ' . $this->getTableName() . ' (d_date, i_num_alerts_sent)
                VALUES (?, 1)
                ON DUPLICATE KEY UPDATE i_num_alerts_sent = i_num_alerts_sent + 1';

        try {
            osc_db_execute($sql, array($date));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }
}

/* file end: ./oc-includes/osclass/model/AlertsStats.php */
