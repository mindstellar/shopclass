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
 * OSCLocale DAO
 */
class OSCLocale extends DAO
{
    /**
     *
     * @var \OSCLocale
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_locale');
        $this->setPrimaryKey('pk_c_code');
        $array_fields = array(
            'pk_c_code',
            's_name',
            's_short_name',
            's_description',
            's_version',
            's_direction',
            's_author_name',
            's_author_url',
            's_currency_format',
            's_dec_point',
            's_thousands_sep',
            'i_num_dec',
            's_date_format',
            's_stop_words',
            'b_enabled',
            'b_enabled_bo'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \OSCLocale
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Return all locales enabled.
     *
     * @access public
     *
     * @param bool $isBo
     * @param bool $indexedByPk
     *
     * @return array
     * @since  unknown
     *
     */
    public function listAllCodes()
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->select('pk_c_code')
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $rows   = osc_db_stringify_rows($rows);
        $aCodes = array();

        foreach ($rows as $row) {
            $aCodes[] = $row['pk_c_code'];
        }

        return $aCodes;
    }

    /**
     * Return all locales enabled.
     *
     * @access public
     *
     * @param bool $isBo
     * @param bool $indexedByPk
     *
     * @return array
     * @since  unknown
     *
     */
    public function listAllEnabled($isBo = false, $indexedByPk = false)
    {
        $query = osc_db_table($this->getTableName())
            ->select(...$this->getFields())
            ->where($isBo ? 'b_enabled_bo' : 'b_enabled', 1)
            ->orderBy('s_name', 'ASC');

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        $rows = osc_db_stringify_rows($rows);

        if ($indexedByPk) {
            $aTmp = array();
            foreach ($rows as $row) {
                $aTmp[(string)$row[$this->getPrimaryKey()]] = $row;
            }
            $rows = $aTmp;
        }

        return $rows;
    }

    /**
     * Return all locales by code
     *
     * @access public
     *
     * @param string $code
     *
     * @return array
     * @since  2.3
     */
    public function findByCode($code)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('pk_c_code', $code)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Delete all related to locale code.
     *
     * @access public
     *
     * @param string $locale
     *
     * @return bool
     * @since  unknown
     */
    public function deleteLocale($locale)
    {
        osc_run_hook('delete_locale', $locale);

        if ($locale === null) {
            // A null value used to build a bare comparison with no
            // right-hand side, a SQL error every delete below absorbed into
            // a false return. A real code that simply matches no rows
            // succeeds and reports 0, so the two outcomes are not
            // interchangeable and null keeps its own explicit branch.
            return false;
        }

        // Each cascading delete's own outcome was discarded here even
        // before this conversion, so a failure on any one of them must not
        // stop the rest from running.
        foreach (
            array(
                DB_TABLE_PREFIX . 't_category_description',
                DB_TABLE_PREFIX . 't_item_description',
                DB_TABLE_PREFIX . 't_keywords',
                DB_TABLE_PREFIX . 't_user_description',
                DB_TABLE_PREFIX . 't_pages_description',
            ) as $table
        ) {
            try {
                osc_db_table($table)->where('fk_c_locale_code', $locale)->delete();
            } catch (\mindstellar\database\DbException $e) {
                // Discarded, as above.
            }
        }

        try {
            return osc_db_table($this->getTableName())->where('pk_c_code', $locale)->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
    /**
     * Insert or update location info in database
     *
     * @param array  $aLocale
     * @param string $localeCode pk_c_code
     */
    public function insertLocaleInfo($aLocale, $localeCode = '')
    {
        if (is_array($aLocale)) {
            if ($localeCode === '') {
                $localeCode = $aLocale['locale_code'];
            }
            $values         = array(
                'pk_c_code'         => $aLocale['locale_code'],
                's_name'            => $aLocale['name'],
                's_short_name'      => $aLocale['short_name'],
                's_description'     => $aLocale['description'],
                's_version'         => $aLocale['version'],
                's_direction'       => $aLocale['direction'],
                's_author_name'     => $aLocale['author_name'],
                's_author_url'      => $aLocale['author_url'],
                's_currency_format' => $aLocale['currency_format'],
                's_date_format'     => $aLocale['date_format'],
                'b_enabled'         => 0,
                'b_enabled_bo'      => 1
            );
            $existingLocale = $this->findByCode($localeCode);
            if ($existingLocale) {
                // don't overwrite existing values use array_merge
                unset($existingLocale['s_version']);
                $values = array_merge($values, $existingLocale);
                return $this->update($values, ['pk_c_code' => $localeCode]);
            }
            return $this->insert($values);
        }
        return false;
    }
}

/* file end: ./oc-includes/osclass/model/OSCLocale.php */
