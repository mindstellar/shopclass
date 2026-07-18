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
 * Model database for Currency table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      unknown
 */
class Currency extends DAO
{
    /**
     * It references to self object: Currency.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var Currency
     */
    private static $instance;
    private static $_currencies;

    /**
     * Set data related to t_currency table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_currency');
        $this->setPrimaryKey('pk_c_code');
        $this->setFields(array('pk_c_code', 's_name', 's_description', 'b_enabled'));
    }

    /**
     * It creates a new Currency object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return Currency
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
     * @param string $value
     *
     * @return bool|mixed
     */
    public function findByPrimaryKey($value)
    {
        if (isset(self::$_currencies[$value])) {
            return self::$_currencies[$value];
        }

        $this->dao->select($this->fields);
        $this->dao->from($this->getTableName());
        $this->dao->where($this->getPrimaryKey(), $value);
        $result = $this->dao->get();

        if ($result === false) {
            return false;
        }

        if ($result->numRows() !== 1) {
            return false;
        }

        self::$_currencies[$value] = $result->row();

        return self::$_currencies[$value];
    }
}

/* file end: ./oc-includes/osclass/model/Currency.php */
