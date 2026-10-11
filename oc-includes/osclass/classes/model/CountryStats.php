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
use mindstellar\listing\ListingCountStore;

/**
 * The live listing count of each country, kept in t_country.i_num_items
 *
 * @package    Shopclass
 * @subpackage Model
 * @since      2.4
 */
class CountryStats extends DAO
{
    use \mindstellar\base\SharedInstance;

    /**
     * No table is behind this DAO: the counts are in t_country.i_num_items, through ListingCountStore.
     * The DAO names a table that no longer exists, so the inherited DAO methods fail on purpose.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_country_stats');
        $this->setPrimaryKey('fk_c_country_code');
        $this->setFields(array('fk_c_country_code', 'i_num_items'));
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Add one to a country's count. A listing with no country has no count.
     *
     * @param string $countryCode
     *
     * @return bool false for an empty or too long code, no such country, or a failed write
     */
    public function increaseNumItems($countryCode)
    {
        $length = strlen((string)$countryCode);

        try {
            return $length > 0 && $length <= 2 && ListingCountStore::countries()->add(array((string)$countryCode), 1) === 1;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Take one from a country's count, never below zero.
     *
     * @param string $countryCode
     *
     * @return int|false rows changed, or false for an empty or too long code or a failed write
     */
    public function decreaseNumItems($countryCode)
    {
        $length = strlen((string)$countryCode);

        try {
            return $length > 0 && $length <= 2 ? ListingCountStore::countries()->add(array((string)$countryCode), -1) : false;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Set a country's count.
     *
     * @param string $countryCode
     * @param int    $numItems
     *
     * @return bool false when the write fails
     */
    public function setNumItems($countryCode, $numItems)
    {
        try {
            return ListingCountStore::countries()->set(array((string)$countryCode => (int)$numItems));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * A country's count, as the old stats row: fk_c_country_code and i_num_items.
     *
     * @param string $countryCode
     *
     * @return array<string,string>|false False when there is no such country
     */
    public function findByCountryCode($countryCode)
    {
        try {
            return (ListingCountStore::countries()->row((string)$countryCode, 'fk_c_country_code') ?? false);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Return a list of countries and counter items.
     * Can be filtered by num_items,
     * and ordered by country_name or items counter.
     * $order = 'country_name ASC' OR $oder = 'items DESC'
     *
     * @param string $zero  Comparison operator applied to i_num_items
     * @param string $order  '<column> ASC|DESC'
     *
     * @return array<int,array{country_code:string,items:string,country_name:string,country_slug:string}>
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

        // Column aliases put this beyond the builder's identifier allowlist. It carries no caller
        // values: $zero and $order were checked above.
        $sql = 'SELECT pk_c_code as country_code, i_num_items as items, s_name as country_name, s_slug as country_slug'
            . ' FROM ' . ListingCountStore::countries()->table() . ' WHERE i_num_items ' . $zero . ' 0 ORDER BY ' . $order;

        try {
            $rows = Db::select($sql);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return Db::stringifyRows($rows);
    }

    /**
     * Calculate the total items that belong to countryCode
     *
     * @param string $countryCode
     *
     * @return int|string Item count as a string, or int 0 when the query fails
     * @since  2.4
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
            $row = Db::selectOne($sql, array($countryCode, date('Y-m-d H:i:s')));
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }

        if ($row !== null) {
            $row = Db::stringifyRow($row);

            return $row['total'];
        }

        return 0;
    }

}

/* file end: ./oc-includes/osclass/model/CountryStats.php */
