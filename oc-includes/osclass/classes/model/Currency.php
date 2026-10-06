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

/**
 * Model database for Currency table
 *
 * @package    Shopclass
 * @subpackage Model
 */
class Currency extends DAO
{
    protected $cacheGroup = 'currency';

    /**
     * It references to self object: Currency.
     * It is used as a singleton
     *
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
     * @return Currency
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
     * Find a currency row by its code, memoising the hit for the request.
     *
     * @param string $value
     *
     * @return array<string,string|null>|false False when the code is unknown
     */
    public function findByPrimaryKey($value)
    {
        if (isset(self::$_currencies[$value])) {
            return self::$_currencies[$value];
        }

        // The table holds a handful of rows, so it is cached whole.
        $all = \mindstellar\cache\CacheGroup::remember('currency', 'all', function () {
            try {
                $rows = osc_db_table($this->getTableName())->select(...$this->getFields())->get();
            } catch (\mindstellar\database\DbException $e) {
                return null;
            }

            // Upper-cased keys: the old lookup went through a case-insensitive collation.
            return array_change_key_case(array_column(osc_db_stringify_rows($rows), null, $this->getPrimaryKey()), CASE_UPPER);
        }) ?? array();

        // A miss is left out of the map, so a currency added later in the same request is found.
        $code = strtoupper((string)$value);
        if (!isset($all[$code])) {
            return false;
        }
        self::$_currencies[$value] = $all[$code];

        return self::$_currencies[$value];
    }
}

/* file end: ./oc-includes/osclass/model/Currency.php */
