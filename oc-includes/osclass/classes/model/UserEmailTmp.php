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

        $status = $this->dao->insert($this->getTableName(), array(
            'fk_i_user_id' => $userEmailTmp['fk_i_user_id'],
            's_new_email'  => $userEmailTmp['s_new_email'],
            'dt_date'      => date('Y-m-d H:i:s')
        ));
        if (!$status) {
            return $this->dao->update(
                $this->getTableName(),
                array('s_new_email' => $userEmailTmp['s_new_email'], 'dt_date' => date('Y-m-d H:i:s')),
                array('fk_i_user_id' => $userEmailTmp['fk_i_user_id'])
            );
        }

        return false;
    }
}

/* file end: ./oc-includes/osclass/model/UserEmailTmp.php */
