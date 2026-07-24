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
 * Log DAO
 */
class Log extends DAO
{
    /**
     *
     * @var \Log
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_log');
        $array_fields = array(
            'dt_date',
            's_section',
            's_action',
            'fk_i_id',
            's_data',
            's_ip',
            's_who',
            'fk_i_who_id'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \Log
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Insert a log row.
     *
     * @access public
     *
     * @param string  $section
     * @param string  $action
     * @param integer $id
     * @param string  $data
     * @param string  $who
     * @param         $whoId
     *
     * @return boolean
     * @since  unknown
     *
     */
    public function insertLog($section, $action, $id, $data, $who, $whoId)
    {
        $ip = Params::getServerParam('REMOTE_ADDR');
        if (!$ip) {
            // No request address (e.g. a cron run): record the loopback address,
            // and expose it on $_SERVER for anything later in the same request.
            // The row now stores this value directly rather than re-reading the
            // Params snapshot, which was taken before the assignment and so left
            // s_ip empty on every cron-path log.
            $ip                     = '127.0.0.1';
            $_SERVER['REMOTE_ADDR'] = $ip;
        }

        $array_set = array(
            'dt_date'     => date('Y-m-d H:i:s'),
            's_section'   => $section,
            's_action'    => $action,
            'fk_i_id'     => $id,
            's_data'      => $data,
            's_ip'        => $ip,
            's_who'       => $who,
            'fk_i_who_id' => $whoId
        );

        try {
            osc_db_table($this->getTableName())->insert($array_set);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }
}

/* file end: ./oc-includes/osclass/model/Log.php */
