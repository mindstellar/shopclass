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
 * Model database for Currency table
 *
 * @package    Shopclass
 * @subpackage Model
 */
class Currency extends DAO
{
    use \mindstellar\base\SharedInstance;

    protected $cacheGroup = 'currency';

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

        $all = $this->all();

        // A miss is left out of the map, so a currency added later in the same request is found.
        $code = strtoupper((string)$value);
        if (!isset($all[$code])) {
            return false;
        }
        self::$_currencies[$value] = $all[$code];

        return self::$_currencies[$value];
    }

    /**
     * Every currency row, from the cached table.
     *
     * @return array<int,array<string,string|null>>
     */
    public function listAll()
    {
        return array_values($this->all());
    }

    /**
     * The whole table by upper-cased code. It holds a handful of rows, so it is cached whole.
     *
     * @return array<string,array<string,string|null>>
     */
    private function all(): array
    {
        return \mindstellar\cache\CacheGroup::remember('currency', 'all', function () {
            try {
                $rows = Db::table($this->getTableName())->select(...$this->getFields())->get();
            } catch (\mindstellar\database\DbException $e) {
                return null;
            }

            // Upper-cased keys: the old lookup went through a case-insensitive collation.
            return array_change_key_case(array_column(Db::stringifyRows($rows), null, $this->getPrimaryKey()), CASE_UPPER);
        }) ?? array();
    }
}

/* file end: ./oc-includes/osclass/model/Currency.php */
