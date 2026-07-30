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
 * Model database for CountryStats table
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.4
 */
class CountryStats extends DAO
{
    /**
     * It references to self object: CountryStats.
     * It is used as a singleton
     *
     * @access private
     * @since  2.4
     * @var CountryStats
     */
    private static $instance;

    /**
     * Set data related to t_country_stats table
     *
     * @access public
     * @since  2.4
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_country_stats');
        $this->setPrimaryKey('fk_c_country_code');
        $this->setFields(array('fk_c_country_code', 'i_num_items'));
    }

    /**
     * It creates a new CountryStats object class if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return CountryStats
     * @since  2.4
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Increase number of country items, given a country id
     *
     * @access public
     *
     * @param int $countryCode Country code
     *
     * @return bool True once the counter is written, false when the code is
     *              rejected or the write fails
     * @since  2.4
     */
    public function increaseNumItems($countryCode)
    {
        $lenght = strlen($countryCode);
        if ($lenght > 2 || $lenght == '') {
            return false;
        }

        // The only caller value is the bound placeholder; the table name is the
        // one this model set on itself in the constructor.
        $sql =
            sprintf(
                'INSERT INTO %s (fk_c_country_code, i_num_items) VALUES (?, 1) ON DUPLICATE KEY UPDATE i_num_items = i_num_items + 1',
                $this->getTableName()
            );

        try {
            osc_db_execute($sql, array($countryCode));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Increase number of country items, given a Country code
     *
     * @access public
     *
     * @param $countryCode
     *
     * @return bool|int Number of affected rows, or false when there is no
     *                  counter row for that country
     * @since  2.4
     *
     */
    public function decreaseNumItems($countryCode)
    {
        $length = strlen($countryCode);
        if ($length > 2 || !$length) {
            return false;
        }

        try {
            $countryStat = osc_db_table($this->getTableName())
                ->select('i_num_items')
                ->where($this->getPrimaryKey(), $countryCode)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            $countryStat = null;
        }

        if (isset($countryStat['i_num_items'])) {
            // The counter is assigned from itself, which no builder update can
            // express; the country code is the single bound value and the table
            // name is the model's own.
            $sql = 'UPDATE ' . $this->getTableName()
                . ' SET i_num_items = i_num_items - 1 WHERE i_num_items > 0 AND fk_c_country_code = ?';

            try {
                return osc_db_execute($sql, array($countryCode));
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Set i_num_items, given a country code
     *
     * @access public
     *
     * @param string $countryCode
     * @param int    $numItems
     *
     * @return bool True once the counter is written, false when the write fails
     * @since  2.4
     *
     */
    public function setNumItems($countryCode, $numItems)
    {
        $numItems = (int)$numItems;

        // Both caller values are bound; the count is bound twice rather than
        // read back with VALUES(), which MySQL 8.0.20 deprecates and whose
        // replacement syntax MariaDB does not accept.
        $sql = 'INSERT INTO ' . $this->getTableName()
            . ' (fk_c_country_code, i_num_items) VALUES (?, ?) ON DUPLICATE KEY UPDATE i_num_items = ?';

        try {
            osc_db_execute($sql, array($countryCode, $numItems, $numItems));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Find stats by country code
     *
     * @access public
     *
     * @param int $countryCode country id
     *
     * @return array
     * @since  2.4
     */
    public function findByCountryCode($countryCode)
    {
        return $this->findByPrimaryKey($countryCode);
    }


    /**
     * Return a list of countries and counter items.
     * Can be filtered by num_items,
     * and ordered by country_name or items counter.
     * $order = 'country_name ASC' OR $oder = 'items DESC'
     *
     * @access public
     *
     * @param string $zero
     * @param string $order
     *
     * @return array
     * @since  2.4
     */
    public function listCountries($zero = '>', $order = 'country_name ASC')
    {
        if (!in_array($zero, array('>', '>=', '<', '<=', '=', '<>', '!='), true)) {
            $zero = '>';
        }
        if (!preg_match('/^[A-Za-z0-9_.]+ (ASC|DESC)$/i', (string)$order)) {
            $order = 'country_name ASC';
        }

        // Column aliases and a join put this beyond the builder's identifier
        // allowlist. It carries no caller values at all: $zero is one of the
        // seven operators the in_array() above accepts, $order matched the
        // identifier-plus-direction pattern above, and both table names are
        // fixed (the model's own and t_country).
        $sql = 'SELECT ' . $this->getTableName() . '.fk_c_country_code as country_code, ' . $this->getTableName()
            . '.i_num_items as items, ' . DB_TABLE_PREFIX . 't_country.s_name as country_name, ' . DB_TABLE_PREFIX
            . 't_country.s_slug as country_slug'
            . ' FROM ' . $this->getTableName()
            . ' JOIN ' . DB_TABLE_PREFIX . 't_country ON ' . $this->getTableName()
            . '.fk_c_country_code = ' . DB_TABLE_PREFIX . 't_country.pk_c_code'
            . ' WHERE i_num_items ' . $zero . ' 0'
            . ' ORDER BY ' . $order;

        try {
            $rows = osc_db_select($sql);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Calculate the total items that belong to countryCode
     *
     * @access public
     *
     * @param string $countryCode
     *
     * @return int|string Item count as a string, or int 0 when the query fails
     * @since  2.4
     *
     */
    public function calculateNumItems($countryCode)
    {
        // Three fixed core tables and an aggregate, so the builder cannot carry
        // it; the country code and the expiry cut-off are the only caller
        // values and both are bound. The cut-off stays on PHP's clock, as it
        // has always been, rather than moving to the server's NOW().
        $sql = 'SELECT count(*) as total FROM ' . DB_TABLE_PREFIX . 't_item_location, ' . DB_TABLE_PREFIX . 't_item, '
               . DB_TABLE_PREFIX . 't_category ';
        $sql .= 'WHERE ' . DB_TABLE_PREFIX . 't_item_location.fk_c_country_code = ? AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.pk_i_id = ' . DB_TABLE_PREFIX . 't_item_location.fk_i_item_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.pk_i_id = ' . DB_TABLE_PREFIX . 't_item.fk_i_category_id AND ';
        $sql .= DB_TABLE_PREFIX . 't_item.b_active = 1 AND ' . DB_TABLE_PREFIX . 't_item.b_enabled = 1 AND '
                . DB_TABLE_PREFIX . 't_item.b_spam = 0 AND ';
        $sql .= '(' . DB_TABLE_PREFIX . 't_item.b_premium = 1 || ' . DB_TABLE_PREFIX . 't_item.dt_expiration >= ? ) AND ';
        $sql .= DB_TABLE_PREFIX . 't_category.b_enabled = 1 ';

        try {
            $row = osc_db_select_one($sql, array($countryCode, date('Y-m-d H:i:s')));
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        if ($row !== null) {
            $row = osc_db_stringify_row($row);

            return $row['total'];
        }

        return 0;
    }

}

/* file end: ./oc-includes/osclass/model/CountryStats.php */
