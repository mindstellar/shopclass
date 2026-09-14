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
     * Set data related to t_locale table
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
     * Return the shared OSCLocale model instance, creating it on first use.
     *
     * @return \OSCLocale
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Return the code of every installed locale, enabled or not.
     *
     * @return array<int,string> Empty when the query failed
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
     * @param bool $isBo         Read b_enabled_bo (the admin) rather than b_enabled
     * @param bool $indexedByPk   Key the result by locale code instead of position
     *
     * @return array<int|string,array<string,string|null>> Empty when the query failed
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
     * @param string $code
     *
     * @return array<int,array<string,string|null>> Empty when the code is unknown
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
     * @param string $locale
     *
     * @return int|false Rows deleted from t_locale, or false for a null code or a query failure
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
            $deleted = osc_db_table($this->getTableName())->where('pk_c_code', $locale)->delete();
        } catch (\mindstellar\database\DbException $e) {
            $deleted = false;
        }

        // The enabled-locale list is memoised per request, so anything drawn after this
        // would still offer the locale that has just gone.
        if (function_exists('osc_invalidate_locale_cache')) {
            osc_invalidate_locale_cache();
        }

        return $deleted;
    }
    /**
     * Insert or update location info in database
     *
     * @param array<string,mixed> $aLocale Keyed by the manifest field names (locale_code, name, …)
     * @param string              $localeCode pk_c_code
     *
     * @return int|bool Affected rows from the update, true/false from the insert,
     *                  or false when $aLocale is not an array
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
            // findByCode() returns a LIST of rows, so take the first before
            // merging; merging the list itself injected a numeric key that
            // checkFieldKeys() rejected, which is why the update branch never
            // ran. array_merge keeps the existing values (they win on a key
            // clash) and lets only the new s_version through, since it is unset
            // from the existing row.
            $existing = $this->findByCode($localeCode);
            if (!empty($existing)) {
                $existingRow = $existing[0];
                unset($existingRow['s_version']);
                $values = array_merge($values, $existingRow);
                $result = $this->update($values, ['pk_c_code' => $localeCode]);
            } else {
                $result = $this->insert($values);
            }

            // As deleteLocale(): the memoised enabled-locale list predates this write.
            if (function_exists('osc_invalidate_locale_cache')) {
                osc_invalidate_locale_cache();
            }

            return $result;
        }
        return false;
    }
}

/* file end: ./oc-includes/osclass/model/OSCLocale.php */
