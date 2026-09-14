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
 * Model database for Admin table
 *
 * @package    Shopclass
 * @subpackage Model
 */
class Admin extends DAO
{
    /**
     * It references to self object: Admin.
     * It is used as a singleton
     *
     * @var Admin
     */
    private static $instance;

    /**
     * array for save currencies
     *
     * @var array
     */
    private $cachedAdmin;

    /**
     * Set data from t_admin table
     *
     * @throws mysqli_sql_exception when the column probe fails
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_admin');
        $this->setPrimaryKey('pk_i_id');

        // SHOW COLUMNS cannot go through the query builder (it is not a
        // SELECT/INSERT/UPDATE/DELETE statement). $this->getTableName() is fixed
        // by setTableName() immediately above and is never runtime input.
        try {
            $columns = osc_db_select(
                'SHOW COLUMNS FROM ' . $this->getTableName() . ' where Field = "b_moderator" '
            );
        } catch (\mindstellar\database\DbException $e) {
            throw new mysqli_sql_exception($e->getMessage(), (int)$e->getCode(), $e);
        }

        if (count($columns) > 0) {
            $this->setFields(array(
                'pk_i_id',
                's_name',
                's_username',
                's_password',
                's_email',
                's_secret',
                'b_moderator'
            ));
        } else {
            $this->setFields(array('pk_i_id', 's_name', 's_username', 's_password', 's_email', 's_secret'));
        }
    }

    /**
     * Return the shared Admin model instance, creating it on first use.
     *
     * @return \Admin
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Find an admin by its id, memoising the row for the request.
     *
     * @param int|string  $id
     * @param string|null $locale Accepted for signature compatibility; unused
     *
     * @return array<string,string|null>|string|false '' for an empty $id, false when unknown
     */
    public function findByPrimaryKey($id, $locale = null)
    {
        if ($id == '') {
            return '';
        }
        if (isset($this->cachedAdmin[$id])) {
            return $this->cachedAdmin[$id];
        }
        $this->cachedAdmin[$id] = parent::findByPrimaryKey($id);

        return $this->cachedAdmin[$id];
    }

    /**
     * Searches for admin information, given an email address.
     * If email not exist return false.
     *
     * @param string $email
     *
     * @return array<string,string|null>|false
     */
    public function findByEmail($email)
    {
        try {
            $row = osc_db_table($this->getTableName())->where('s_email', $email)->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Searches for admin information, given a username and password
     * If credential don't match return false.
     *
     * @param string $userName
     * @param string $password
     *
     * @return array<string,string|null>|false
     */
    public function findByCredentials($userName, $password)
    {
        $user = $this->findByUsername($userName);
        if ($user !== false && isset($user['s_password']) && osc_verify_password($password, $user['s_password'])) {
            return $user;
        }

        return false;
    }

    /**
     * Searches for admin information, given a username.
     * If admin not exist return false.
     *
     * @param string $username
     *
     * @return array<string,string|null>|false
     */
    public function findByUsername($username)
    {
        try {
            $row = osc_db_table($this->getTableName())->where('s_username', $username)->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Searches for admin information, given a admin id and secret.
     * If credential don't match return false.
     *
     * @param int    $id
     * @param string $secret
     *
     * @return array<string,string|null>|false
     */
    public function findByIdSecret($id, $secret)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->where('s_secret', $secret)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Searches for admin information, given a admin id and password.
     * If credential don't match return false.
     *
     * @param int    $id
     * @param string $password
     *
     * @return array<string,string|null>|false
     */
    public function findByIdPassword($id, $password)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->where('s_password', $password)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Perform a batch delete (for more than one admin ID)
     *
     * @param array<int,int|string>|int|string $id
     *
     * @return int|false Rows deleted, or false for an empty list or a query failure
     * @since  2.3.4
     */
    public function deleteBatch($id)
    {
        $ids = is_array($id) ? array_values($id) : array($id);

        // Deleting with an empty id list reports false, not 0. QueryBuilder's
        // whereIn() turns an empty array into a harmless `1 = 0` and would run a
        // no-op delete returning int 0; callers test this result loosely, so
        // return the false they expect rather than a value that differs in kind.
        if ($ids === array()) {
            return false;
        }

        try {
            return osc_db_table($this->getTableName())->whereIn('pk_i_id', $ids)->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/Admin.php */
