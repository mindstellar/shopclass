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
 * @since      unknown
 */
class Admin extends DAO
{
    /**
     * It references to self object: Admin.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
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
            throw new mysqli_sql_exception($this->dao->errorDesc);
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
     * @return \Admin
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * @param string $id
     * @param null   $locale
     *
     * @return mixed|string
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
     * @access public
     *
     * @param string $email
     *
     * @return array|bool
     * @since  unknown
     *
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
     * @access public
     *
     * @param string $userName
     * @param string $password
     *
     * @return array|bool
     * @since  unknown
     *
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
     * @access public
     *
     * @param string $username
     *
     * @return array|bool
     * @since  unknown
     *
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
     * @access public
     *
     * @param integer $id
     * @param string  $secret
     *
     * @return array|bool
     * @since  unknown
     *
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
     * @access public
     *
     * @param integer $id
     * @param string  $password
     *
     * @return array|bool
     * @since  unknown
     *
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
     * @access public
     *
     * @param array $id
     *
     * @return boolean
     * @since  2.3.4
     */
    public function deleteBatch($id)
    {
        $ids = is_array($id) ? array_values($id) : array($id);

        // An empty id list is the write-side sibling of the null-where
        // correction: the legacy dao->whereIn() would emit "pk_i_id IN ()", a
        // SQL syntax error, and dao->delete() absorbs that into bool false.
        // QueryBuilder::whereIn() emits a valid (harmless) `1 = 0` for an empty
        // array instead, which would return int 0 here -- a different value,
        // not just a different type. Reproduce the legacy false explicitly.
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
