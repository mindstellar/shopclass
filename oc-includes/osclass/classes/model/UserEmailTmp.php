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
 *
 */
class UserEmailTmp extends DAO
{
    /**
     *
     * @var \UserEmailTmp
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_user_email_tmp');
        $this->setPrimaryKey('fk_i_user_id');
        $this->setFields(array('fk_i_user_id', 's_new_email', 'dt_date'));
    }

    /**
     * @return \UserEmailTmp
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     *
     * @access public
     *
     * @param $userEmailTmp
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function insertOrUpdate($userEmailTmp)
    {
        $now = date('Y-m-d H:i:s');

        // One statement instead of an insert that is expected to fail followed by
        // an update. The assignments are spelled out rather than using VALUES(),
        // which MySQL 8 deprecates and whose replacement syntax MariaDB rejects.
        $sql = 'INSERT INTO ' . $this->getTableName() . ' (fk_i_user_id, s_new_email, dt_date)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE s_new_email = ?, dt_date = ?';

        try {
            $affected = osc_db_execute($sql, array(
                $userEmailTmp['fk_i_user_id'],
                $userEmailTmp['s_new_email'],
                $now,
                $userEmailTmp['s_new_email'],
                $now
            ));
        } catch (\mindstellar\database\DbException $e) {
            // An unknown user id trips the foreign key. That used to fail the
            // insert and then match no rows on the update, reporting 0.
            return 0;
        }

        // MySQL reports 1 for a fresh insert, 2 when it overwrote an existing row
        // and 0 when that row already held these values. Callers have always been
        // told false for a new row, and the affected-row count otherwise.
        if ($affected === 1) {
            return false;
        }

        return $affected === 2 ? 1 : 0;
    }
}

/* file end: ./oc-includes/osclass/model/UserEmailTmp.php */
