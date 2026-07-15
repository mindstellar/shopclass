<?php

/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
        $this->dao->select('pk_i_id as id, CONCAT(s_name, \' (\', s_email , \')\') as label, s_name as value');
        $this->dao->from($this->getTableName());
        $this->dao->like('s_name', $query, 'after');
        $this->dao->orLike('s_email', $query, 'after');
        $this->dao->limit(0, 10);

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
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

        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where($this->getPrimaryKey(), $id);
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        if ($result->numRows() != 1) {
            return array();
        }

        $user = $this->extendData($result->row(), $locale);
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
        $this->dao->select();
        $this->dao->from(DB_TABLE_PREFIX . 't_user_description');
        $this->dao->where('fk_i_user_id', $user['pk_i_id']);
        if (null !== $locale) {
            $this->dao->where('fk_c_locale_code', $locale);
        }
        $result       = $this->dao->get();
        $descriptions = $result->result();

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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_username', $username);
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() == 1) {
            return $this->extendData($result->row(), $locale);
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('s_email', $email);
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() == 1) {
            return $this->extendData($result->row(), $locale);
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $conditions = array(
            'pk_i_id'  => $id,
            's_secret' => $secret
        );
        $this->dao->where($conditions);
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() == 1) {
            return $this->extendData($result->row(), $locale);
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
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $conditions = array(
            'pk_i_id'     => $id,
            's_pass_code' => $secret
        );
        $this->dao->where($conditions);
        $this->dao->where("s_pass_date >= '$date'");
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() == 1) {
            return $this->extendData($result->row(), $locale);
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

            $this->dao->select('pk_i_id, fk_i_category_id');
            $this->dao->from(DB_TABLE_PREFIX . 't_item');
            $this->dao->where('fk_i_user_id', $id);
            $result = $this->dao->get();
            $items  = $result->result();

            $itemManager = Item::newInstance();
            foreach ($items as $item) {
                $itemManager->deleteByPrimaryKey($item['pk_i_id']);
            }

            ItemComment::newInstance()->delete(array('fk_i_user_id' => $id));

            $this->dao->delete(DB_TABLE_PREFIX . 't_user_email_tmp', array('fk_i_user_id' => $id));
            $this->dao->delete(DB_TABLE_PREFIX . 't_user_description', array('fk_i_user_id' => $id));
            $this->dao->delete(DB_TABLE_PREFIX . 't_alerts', array('fk_i_user_id' => $id));
            $deleted = $this->dao->delete($this->getTableName(), array('pk_i_id' => $id));
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

        return $this->dao->update(DB_TABLE_PREFIX . 't_user_description', array('s_info' => $info), $array_where);
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
        $this->dao->select();
        $this->dao->from(DB_TABLE_PREFIX . 't_user_description');
        $this->dao->where($conditions);

        $result = $this->dao->get();

        return !($result == false || $result->numRows() == 0);
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
        $array_set = array(
            'fk_i_user_id'     => $id,
            'fk_c_locale_code' => $locale,
            's_info'           => $info
        );

        return $this->dao->insert(DB_TABLE_PREFIX . 't_user_description', $array_set);
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

        $this->dao->select('SQL_CALC_FOUND_ROWS *');
        $this->dao->from($this->getTableName());
        $this->dao->orderBy($order_column, $order_direction);
        $this->dao->limit($start, $end);

        foreach ($fields as $k => $v) {
            $this->dao->where($k, $v);
        }

        $rs = $this->dao->get();

        if (!$rs) {
            return $users;
        }

        $users['users'] = $rs->result();

        $rsRows = $this->dao->query('SELECT FOUND_ROWS() as total');
        $data   = $rsRows->row();
        if ($data['total']) {
            $users['total_results'] = $data['total'];
        }

        $rsTotal = $this->dao->query('SELECT COUNT(*) as total FROM ' . $this->getTableName());
        $data    = $rsTotal->row();
        if ($data['total']) {
            $users['rows'] = $data['total'];
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
        $this->dao->select('COUNT(*) as i_total');
        $this->dao->from(DB_TABLE_PREFIX . 't_user');
        $this->dao->where($condition);

        $result = $this->dao->get();

        if ($result == false || $result->numRows() == 0) {
            return 0;
        }

        $row = $result->row();

        return $row['i_total'];
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
            $this->dao->select('dt_access_date, s_access_ip');
            $this->dao->from(DB_TABLE_PREFIX . 't_user');
            $this->dao->where('pk_i_id', $userId);
            $this->dao->where("dt_access_date <= '" . date('Y-m-d H:i:s', time() - $time) . "'");
            $result = $this->dao->get();
            if ($result == false || $result->numRows() == 0) {
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
     * @param int $id user id
     *
     * @return bool|\DBRecordsetClass number of affected rows, id error occurred return false
     * @since  unknown
     */
    public function increaseNumItems($id)
    {
        if (!is_numeric($id)) {
            return false;
        }

        $sql = sprintf('UPDATE %s SET i_items = i_items + 1 WHERE pk_i_id = %d', $this->getTableName(), $id);

        return $this->dao->query($sql);
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

        $sql = sprintf('UPDATE %s SET i_items = IF(i_items > 0, i_items - 1, i_items) WHERE pk_i_id = %d', $this->getTableName(), $id);

        return $this->dao->query($sql);
    }
}

/* file end: ./oc-includes/osclass/model/User.php */
