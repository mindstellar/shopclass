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
 * User DAO
 */
class User extends DAO
{
    /**
     *
     * @var \User
     */
    private static $instance;

    /**
     * User constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_user');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            'dt_reg_date',
            'dt_mod_date',
            's_name',
            's_password',
            's_secret',
            's_username',
            's_email',
            's_website',
            's_phone_land',
            's_phone_mobile',
            'b_enabled',
            'b_active',
            's_pass_code',
            's_pass_date',
            's_pass_ip',
            'fk_c_country_code',
            's_country',
            's_address',
            's_zip',
            'fk_i_region_id',
            's_region',
            'fk_i_city_id',
            's_city',
            'fk_i_city_area_id',
            's_city_area',
            'd_coord_lat',
            'd_coord_long',
            'b_company',
            'i_items',
            'i_comments',
            'dt_access_date',
            's_access_ip'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \User
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Find an user by its primary key
     *
     * @access public
     *
     * @param string $query
     *
     * @return array
     * @since  2.3.2
     *
     */
    public function ajax($query = '')
    {
        // Aliased projection and an OR of two prefix LIKEs are beyond the
        // builder's identifier allowlist, so this is hand-written. The search
        // term is bound; legacy like(..., 'after') matched a prefix and escaped
        // %/_ in the payload, reproduced here. LIMIT 0, 10 is offset 0, count 10.
        $pattern = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string)$query) . '%';
        $sql     = 'SELECT pk_i_id as id, CONCAT(s_name, \' (\', s_email, \')\') as label, s_name as value'
            . ' FROM ' . $this->getTableName()
            . ' WHERE s_name LIKE ? OR s_email LIKE ? LIMIT 10';

        try {
            $rows = osc_db_select($sql, array($pattern, $pattern));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }


    /**
     * Find an user by its primary key
     *
     * @access public
     *
     * @param int    $id
     * @param string $locale
     *
     * @return array
     * @since  unknown
     */
    public function findByPrimaryKey($id, $locale = null)
    {
        $key   = md5(osc_base_url() . 'User:findByPrimaryKey:' . $id . $locale);
        $found = null;
        $cache = osc_cache_get($key, $found);
        if ($cache !== false) {
            return $cache;
        }

        try {
            $rows = osc_db_table($this->getTableName())
                ->where($this->getPrimaryKey(), $id)
                ->limit(2)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if (count($rows) != 1) {
            return array();
        }

        $user = $this->extendData(osc_db_stringify_row($rows[0]), $locale);
        osc_cache_set($key, $user, OSC_CACHE_TTL);

        return $user;
    }

    /**
     * Add description to user array
     *
     * @param      $user
     * @param null $locale
     *
     * @return array
     * @since 3.1.1
     */
    private function extendData($user, $locale = null)
    {
        $query = osc_db_table(DB_TABLE_PREFIX . 't_user_description')
            ->where('fk_i_user_id', $user['pk_i_id']);
        if (null !== $locale) {
            $query = $query->where('fk_c_locale_code', $locale);
        }
        $descriptions = osc_db_stringify_rows($query->get());

        $user['locale'] = array();
        foreach ($descriptions as $sub_row) {
            $user['locale'][$sub_row['fk_c_locale_code']] = $sub_row;
        }

        return $user;
    }

    /**
     * Find an user by its username
     *
     * @access public
     *
     * @param string $username
     * @param null   $locale
     *
     * @return array|bool
     * @since  3.1
     *
     */
    public function findByUsername($username, $locale = null)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->where('s_username', $username)
                ->limit(2)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if (count($rows) == 1) {
            return $this->extendData(osc_db_stringify_row($rows[0]), $locale);
        }

        return array();
    }

    /**
     * Find an user by its email and password
     *
     * @access public
     *
     * @param        $email
     * @param string $password
     * @param null   $locale
     *
     * @return array
     * @since  unknown
     */
    public function findByCredentials($email, $password, $locale = null)
    {
        $user = $this->findByEmail($email);
        if (isset($user['s_password']) && osc_verify_password($password, $user['s_password'])) {
            return $this->extendData($user, $locale);
        }

        return array();
    }

    /**
     * Find an user by its email
     *
     * @access public
     *
     * @param string $email
     * @param null   $locale
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function findByEmail($email, $locale = null)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->where('s_email', $email)
                ->limit(2)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if (count($rows) == 1) {
            return $this->extendData(osc_db_stringify_row($rows[0]), $locale);
        }

        return array();
    }

    /**
     * Find an user by its id and secret
     *
     * @access public
     *
     * @param string $id
     * @param string $secret
     *
     * @param null   $locale
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function findByIdSecret($id, $secret, $locale = null)
    {
        // The secret must be compared as a string. Passed through the legacy
        // escape(), a value such as "0" reached MySQL unquoted, which turned this
        // into a numeric comparison against a VARCHAR column -- and any secret
        // beginning with a letter evaluates to 0, so the cookie value "0" matched
        // almost every account.
        try {
            $row = osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->where('s_secret', (string)$secret)
                ->limit(2)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if (count($row) === 1) {
            return $this->extendData(osc_db_stringify_row($row[0]), $locale);
        }

        return array();
    }

    /**
     *
     *
     * @access public
     *
     * @param string $id
     * @param string $secret
     * @param null   $locale
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function findByIdPasswordSecret($id, $secret, $locale = null)
    {
        if ($secret == '') {
            return null;
        }
        $date = date('Y-m-d H:i:s', time() - (24 * 3600));

        // Same string comparison as findByIdSecret: the reset code is a VARCHAR,
        // and comparing it numerically let "0" match any code beginning with a
        // letter. The cut-off date is bound rather than interpolated.
        try {
            $row = osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->where('s_pass_code', (string)$secret)
                ->where('s_pass_date', '>=', $date)
                ->limit(2)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if (count($row) === 1) {
            return $this->extendData(osc_db_stringify_row($row[0]), $locale);
        }

        return array();
    }

    /**
     * Delete an user given its id
     *
     * @access public
     *
     * @param int $id
     *
     * @return bool
     * @since  unknown
     *
     */
    public function deleteUser($id = null)
    {
        if ($id != null) {
            osc_run_hook('delete_user', $id);

            try {
                $items = osc_db_table(DB_TABLE_PREFIX . 't_item')
                    ->select('pk_i_id', 'fk_i_category_id')
                    ->where('fk_i_user_id', $id)
                    ->get();
            } catch (\mindstellar\database\DbException $e) {
                $items = array();
            }

            $itemManager = Item::newInstance();
            foreach ($items as $item) {
                $itemManager->deleteByPrimaryKey($item['pk_i_id']);
            }

            ItemComment::newInstance()->delete(array('fk_i_user_id' => $id));

            // The dependent deletes discarded their own result before, so a
            // failure on any one must not stop the rest; only the final user
            // delete's count decides the return.
            foreach (
                array('t_user_email_tmp', 't_user_description', 't_alerts') as $depTable
            ) {
                try {
                    osc_db_table(DB_TABLE_PREFIX . $depTable)->where('fk_i_user_id', $id)->delete();
                } catch (\mindstellar\database\DbException $e) {
                    // discarded, as before
                }
            }

            try {
                $deleted = osc_db_table($this->getTableName())->where('pk_i_id', $id)->delete();
            } catch (\mindstellar\database\DbException $e) {
                $deleted = 0;
            }
            if ($deleted === 1) {
                osc_run_hook('after_delete_user', $id);

                return true;
            }
        }

        return false;
    }

    /**
     * Update users' description
     *
     * @access public
     *
     * @param int    $id
     * @param string $locale
     * @param string $info
     *
     * @return bool
     * @since  unknown
     */
    public function updateDescription($id, $locale, $info)
    {
        $conditions = array('fk_c_locale_code' => $locale, 'fk_i_user_id' => $id);
        $exist      = $this->existDescription($conditions);

        if (!$exist) {
            return $this->insertDescription($id, $locale, $info);
        }

        $array_where = array(
            'fk_c_locale_code' => $locale,
            'fk_i_user_id'     => $id
        );

        try {
            return osc_db_table(DB_TABLE_PREFIX . 't_user_description')
                ->where('fk_c_locale_code', $locale)
                ->where('fk_i_user_id', $id)
                ->update(array('s_info' => $info));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Check if a description exists
     *
     * @access private
     *
     * @param array $conditions
     *
     * @return bool
     * @since  unknown
     */
    private function existDescription($conditions)
    {
        $query = osc_db_table(DB_TABLE_PREFIX . 't_user_description');
        foreach ($conditions as $column => $value) {
            $query = $query->where($column, $value);
        }

        try {
            return $query->count() > 0;
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Insert users' description
     *
     * @access private
     *
     * @param int    $id
     * @param string $locale
     * @param string $info
     *
     * @return bool
     * @since  unknown
     */
    private function insertDescription($id, $locale, $info)
    {
        try {
            osc_db_table(DB_TABLE_PREFIX . 't_user_description')->insert(array(
                'fk_i_user_id'     => $id,
                'fk_c_locale_code' => $locale,
                's_info'           => $info
            ));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Return list of users
     *
     * @access public
     *
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     * @param null   $conditions
     *
     * @return array
     * @parma  array $conditions
     * @since  2.4
     */
    public function search(
        $start = 0,
        $end = 10,
        $order_column = 'pk_i_id',
        $order_direction = 'DESC',
        $conditions = null
    ) {
        return $this->_search($conditions, $start, $end, $order_column, $order_direction);
    }

    /**
     * @param        $fields
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     *
     * @return array
     */
    private function _search($fields, $start = 0, $end = 10, $order_column = 'pk_i_id', $order_direction = 'DESC')
    {
        // SET data, so we always return a valid object
        $users                  = array();
        $users['rows']          = 0;
        $users['total_results'] = 0;
        $users['users']         = array();

        // $order_column is allowlisted; $order_direction reproduces the legacy
        // ASC/DESC/RAND() handling. The where values are bound. LIMIT $start,$end
        // is offset $start, count $end (the emitted comma form). SQL_CALC_FOUND_ROWS
        // and the aliased select keep this hand-written.
        if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$order_column)) {
            $order_column = 'pk_i_id';
        }
        $direction = (string)$order_direction;
        if (strtolower($direction) === 'random') {
            $orderSql = $order_column . ' RAND()';
        } elseif (trim($direction) !== '' && trim($direction) !== '0') {
            $orderSql = $order_column
                . (in_array(strtoupper(trim($direction)), array('ASC', 'DESC'), true) ? ' ' . $direction : ' ASC');
        } else {
            $orderSql = $order_column . $direction;
        }

        $params = array();
        $sql    = 'SELECT SQL_CALC_FOUND_ROWS * FROM ' . $this->getTableName();
        if (is_array($fields) && count($fields) > 0) {
            $clauses = array();
            foreach ($fields as $k => $v) {
                // Each key is a fixed column name supplied by the wrapper methods,
                // validated against the same allowlist as the sort column; each
                // value is bound.
                if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$k)) {
                    continue;
                }
                $clauses[] = $k . ' = ?';
                $params[]  = $v;
            }
            if (count($clauses) > 0) {
                $sql .= ' WHERE ' . implode(' AND ', $clauses);
            }
        }
        $sql .= ' ORDER BY ' . $orderSql;
        $sql .= ' LIMIT ' . (int)$start . ', ' . (int)$end;

        try {
            $users['users'] = osc_db_stringify_rows(osc_db_select($sql, $params));
        } catch (\mindstellar\database\DbException $e) {
            return $users;
        }

        $total = osc_db_scalar('SELECT FOUND_ROWS() as total');
        if ($total) {
            $users['total_results'] = (string)$total;
        }

        $rows = osc_db_scalar('SELECT COUNT(*) as total FROM ' . $this->getTableName());
        if ($rows) {
            $users['rows'] = (string)$rows;
        }

        return $users;
    }

    /**
     * Return list of users
     *
     * @access public
     *
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     * @param string $name
     *
     * @return array
     * @parma  string $name
     * @since  2.4
     */
    public function searchByName(
        $start = 0,
        $end = 10,
        $order_column = 'pk_i_id',
        $order_direction = 'DESC',
        $name = ''
    ) {
        return $this->_search(array('s_name' => $name), $start, $end, $order_column, $order_direction);
    }

    /**
     * Return list of users by email
     *
     * @access public
     *
     * @param int    $start
     * @param int    $end
     * @param string $order_column
     * @param string $order_direction
     * @param string $email
     *
     * @return array
     * @parma  string $email
     * @since  2.4
     */
    public function searchByEmail(
        $start = 0,
        $end = 10,
        $order_column = 'pk_i_id',
        $order_direction = 'DESC',
        $email = ''
    ) {
        return $this->_search(array('s_email' => $email), $start, $end, $order_column, $order_direction);
    }

    /**
     * Return number of users
     *
     * @param string $condition
     *
     * @return int
     * @since 2.3.6
     */
    public function countUsers($condition = 'b_enabled = 1 AND b_active = 1')
    {
        // $condition is a raw SQL fragment: this is a public API and every caller
        // passes a fixed literal such as 'b_enabled = 1 AND b_active = 1'. It is
        // trusted SQL the caller owns, so it goes through whereRaw unchanged --
        // parameterizing an arbitrary fragment is not possible without breaking
        // the contract. The count is cast back to a string, as the row value was.
        try {
            return (string)osc_db_table(DB_TABLE_PREFIX . 't_user')
                ->whereRaw($condition)
                ->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * Insert last access data
     *
     * @param int    $userId
     * @param string $date
     * @param string $ip
     *
     * @param null   $time
     *
     * @return boolean on success
     */
    public function lastAccess($userId, $date, $ip, $time = null)
    {
        if ($time != null) {
            try {
                $row = osc_db_table(DB_TABLE_PREFIX . 't_user')
                    ->select('dt_access_date', 's_access_ip')
                    ->where('pk_i_id', $userId)
                    ->where('dt_access_date', '<=', date('Y-m-d H:i:s', time() - $time))
                    ->first();
            } catch (\mindstellar\database\DbException $e) {
                return false;
            }
            if ($row === null) {
                return false;
            }
        }

        return $this->update(array('dt_access_date' => $date, 's_access_ip' => $ip), array('pk_i_id' => $userId));
    }

    /**
     * Increase number of items, given a user id
     *
     * @access public
     *
     * @param int $id    user id
     * @param int $items number of items to add (default 1)
     *
     * @return bool|\DBRecordsetClass number of affected rows, id error occurred return false
     * @since  unknown
     */
    public function increaseNumItems($id, $items = 1)
    {
        if (!is_numeric($id)) {
            return false;
        }

        // Self-referential SET; the amount and id were %d-formatted, so they are
        // int-cast before binding to reproduce that truncation. Returns the
        // affected-row count, as the legacy write did.
        try {
            return osc_db_execute(
                'UPDATE ' . $this->getTableName() . ' SET i_items = i_items + ? WHERE pk_i_id = ?',
                array((int)$items, (int)$id)
            );
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Decrease number of items, given a user id
     *
     * @access public
     *
     * @param int $id user id
     *
     * @return bool|\DBRecordsetClass number of affected rows, id error occurred return false
     * @since  unknown
     */
    public function decreaseNumItems($id)
    {
        if (!is_numeric($id)) {
            return false;
        }

        try {
            return osc_db_execute(
                'UPDATE ' . $this->getTableName()
                . ' SET i_items = IF(i_items > 0, i_items - 1, i_items) WHERE pk_i_id = ?',
                array((int)$id)
            );
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }
}

/* file end: ./oc-includes/osclass/model/User.php */
