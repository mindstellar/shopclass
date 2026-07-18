<?php

/*
 * This file is part of Osclass (Mindstellar).
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
 * Model database for Item table
 *
 * @package    Osclass
 * @subpackage Model
 * @since      unknown
 */
class Item extends DAO
{
    /**
     * It references to self object: Item.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var Item
     */
    private static $instance;

    /**
     * Set data related to t_item table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            'fk_i_user_id',
            'fk_i_category_id',
            'dt_pub_date',
            'dt_mod_date',
            'f_price',
            'i_price',
            'fk_c_currency_code',
            's_contact_name',
            's_contact_email',
            's_contact_phone',
            'b_premium',
            's_ip',
            'b_enabled',
            'b_active',
            'b_spam',
            's_secret',
            'b_show_email',
            'dt_expiration'
        );
        $this->setFields($array_fields);
    }

    /**
     * It creates a new Item object class if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return Item
     * @since  unknown
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * List items ordered by views
     *
     * @access public
     *
     * @param int $limit
     *
     * @return array of items
     * @since  unknown
     */
    public function mostViewed($limit = 10)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName() . ' i, ' . DB_TABLE_PREFIX . 't_item_location l, ' . DB_TABLE_PREFIX
                         . 't_item_stats s');
        $this->dao->where('l.fk_i_item_id = i.pk_i_id AND s.fk_i_item_id = i.pk_i_id');
        $this->dao->groupBy('s.fk_i_item_id');
        $this->dao->orderBy('i_num_views', 'DESC');
        $this->dao->limit($limit);

        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }
        $items = $result->result();

        return $this->extendData($items);
    }

    /**
     * Extends the given array $items with description in available locales
     *
     * @access public
     *
     * @param array $items array set of items
     *
     * @return array with description extended with all available locales
     *
     */
    public function extendData($items, $prefLocale = null)
    {
        if (!empty($items)) {
            if (null === $prefLocale) {
                $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
            }
            $items = $this->extendItemDescription($items, $prefLocale);
            $items = $this->extendCategoryName($items, $prefLocale);
            $itemIds = array_column($items, 'pk_i_id');
            // First get stats and locations data
            $this->dao->select(
                'SUM(s.i_num_views) as i_num_views, ' .
                'SUM(s.i_num_spam) as i_num_spam, ' .
                'SUM(s.i_num_bad_classified) as i_num_bad_classified, ' .
                'SUM(s.i_num_repeated) as i_num_repeated, ' .
                'SUM(s.i_num_offensive) as i_num_offensive, ' .
                'SUM(s.i_num_expired) as i_num_expired, ' .
                'SUM(s.i_num_premium_views) as i_num_premium_views, ' .
                'l.*'
            );
            $this->dao->from(DB_TABLE_PREFIX . 't_item_stats s');
            $this->dao->join(DB_TABLE_PREFIX . 't_item_location l', 's.fk_i_item_id = l.fk_i_item_id');
            $this->dao->whereIn('s.fk_i_item_id', $itemIds);
            $this->dao->groupBy('s.fk_i_item_id');

            $result = $this->dao->get();
            if (!$result) {
                $itemStatsLocations = array();
            } else {
                $itemStatsLocations = $result->result();
            }

            foreach ($items as $k => $aItem) {
                // Add stats and locations data
                if (isset($itemStatsLocations)) {
                    foreach ($itemStatsLocations as $key => $isl) {
                        if ($aItem['pk_i_id'] === $isl['fk_i_item_id']) {
                            $aItem += $isl;
                            unset($itemStatsLocations[$key]);
                        }
                    }
                }
                $items[$k] = $aItem;
            }

            // Batch-prime the resource cache for the whole page so the theme's
            // per-item osc_get_item_resources() calls are cache hits, not an N+1.
            if (count($itemIds) > 1) {
                ItemResource::newInstance()->primeResourcesCache($itemIds);
            }
        }

        return $items;
    }

    /**
     * List Items with category name
     *
     * @access public
     * @return array of items
     * @since  unknown
     */
    public function listAllWithCategories()
    {
        $this->dao->select('i.*, cd.s_name AS s_category_name ');
        $this->dao->from($this->getTableName() . ' i, ' . DB_TABLE_PREFIX . 't_category c, ' . DB_TABLE_PREFIX
                         . 't_category_description cd');
        $this->dao->where('c.pk_i_id = i.fk_i_category_id AND cd.fk_i_category_id = i.fk_i_category_id');
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Find item resources belong to an item given its id
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array of resources
     * @since  unknown
     */
    public function findResourcesByID($id)
    {
        return ItemResource::newInstance()->getResources($id);
    }

    /**
     * Find the item location given a item id
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array of location
     * @since  unknown
     */
    public function findLocationByID($id)
    {
        return ItemLocation::newInstance()->findByPrimaryKey($id);
    }

    /**
     * Find items belong to a category given its id
     *
     * @access public
     *
     * @param int $catId
     *
     * @return array of items
     * @since  unknown
     */
    public function findByCategoryID($catId)
    {
        return $this->listWhere('fk_i_category_id = %d', (int)$catId);
    }

    /**
     * Comodin function to serve multiple queries
     *
     * @access public
     * @return array of items
     * @since  3.x.x
     */
    public function listWhere(...$args)
    {
        $sql = null;
        switch (func_num_args()) {
            case 0:
                return array();
            case 1:
                $sql = $args[0];
                break;
            default:
                $format = array_shift($args);
                foreach ($args as $k => $v) {
                    $args[$k] = $this->dao->escape($v);
                }
                $sql = vsprintf($format, $args);
                break;
        }

        $this->dao->select('l.*, i.*');
        $this->dao->from($this->getTableName() . ' i, ' . DB_TABLE_PREFIX . 't_item_location l');
        $this->dao->where('l.fk_i_item_id = i.pk_i_id');
        $this->dao->where($sql);
        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }
        $items = $result->result();

        return $this->extendData($items);
    }

    /**
     * Find items belong to a phone number
     *
     * @access public
     *
     * @param $phone
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByPhone($phone)
    {
        return $this->listWhere('s_contact_phone = %s', $phone);
    }

    /**
     * Find items belong to an email
     *
     * @access public
     *
     * @param $email
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByEmail($email)
    {
        return $this->listWhere('s_contact_email = %s', $email);
    }

    /**
     * Count all items, or all items belong to a category id, can be filtered
     * by $options  ['ACTIVE|INACTIVE|ENABLED|DISABLED|SPAM|NOTSPAM|EXPIRED|NOTEXPIRED|PREMIUM|TODAY']
     *
     * @access public
     *
     * @param int   $categoryId
     * @param mixed $options could be a string with | separator or an array with the options
     *
     * @return int total items
     * @since  unknown
     */
    public function totalItems($categoryId = null, $options = null)
    {
        $this->dao->select('count(*) as total');
        $this->dao->from($this->getTableName() . ' i');
        if (null !== $categoryId) {
            $this->dao->join(DB_TABLE_PREFIX . 't_category c', 'c.pk_i_id = i.fk_i_category_id');
            $this->dao->where('i.fk_i_category_id', $categoryId);
        }

        $this->addWhereByOptions($options);

        $result = $this->dao->get();
        if ($result == false) {
            return 0;
        }
        $total_ads = $result->row();

        return $total_ads['total'];
    }

    /**
     * Add where condition by options
     * $options  ['ACTIVE|INACTIVE|ENABLED|DISABLED|SPAM|NOTSPAM|EXPIRED|NOTEXPIRED|PREMIUM|TODAY']
     *
     * @access  private
     *
     * @param string | array $options could be a string with | separator or an array with the options
     *
     * @since   4.0.0
     */
    private function addWhereByOptions($options)
    {
        if (!is_array($options)) {
            $options = explode('|', $options);
        }
        foreach ($options as $option) {
            switch ($option) {
                case 'ACTIVE':
                    $this->dao->where('i.b_active', 1);
                    break;
                case 'INACTIVE':
                    $this->dao->where('i.b_active', 0);
                    break;
                case 'ENABLED':
                    $this->dao->where('i.b_enabled', 1);
                    break;
                case 'DISABLED':
                    $this->dao->where('i.b_enabled', 0);
                    break;
                case 'SPAM':
                    $this->dao->where('i.b_spam', 1);
                    break;
                case 'NOTSPAM':
                    $this->dao->where('i.b_spam', 0);
                    break;
                case 'EXPIRED':
                    $this->dao->where('i.b_premium', 0);
                    $this->dao->where('( i.dt_expiration < \'' . date('Y-m-d H:i:s') . '\' )');
                    break;
                case 'NOTEXPIRED':
                    $this->dao->orWhere('i.b_premium', 1);
                    $this->dao->where('( i.dt_expiration >= \'' . date('Y-m-d H:i:s') . '\' )');
                    break;
                case 'PREMIUM':
                    $this->dao->where('i.b_premium', 1);
                    break;
                case 'TODAY':
                    $this->dao->where('DATEDIFF(\'' . date('Y-m-d H:i:s') . '\', i.dt_pub_date) < 1');
                    break;
                default:
            }
        }
    }

    /**
     * LEAVE THIS FOR COMPATIBILITIES ISSUES (ONLY SITEMAP GENERATOR)
     * BUT REMEMBER TO DELETE IN ANYTHING > 2.1.x THANKS
     *
     * @param      $category
     * @param bool $enabled
     * @param bool $active
     *
     * @return int
     */
    public function numItems($category, $enabled = true, $active = true)
    {
        $this->dao->select('COUNT(*) AS total');
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_category_id', (int)$category['pk_i_id']);
        $this->dao->where('b_enabled', $enabled);
        $this->dao->where('b_active', $active);
        $this->dao->where('b_spam', 0);

        $this->dao->where('( b_premium = 1 || dt_expiration >= \'' . date('Y-m-d H:i:s') . '\' )');

        $result = $this->dao->get();

        if ($result == false) {
            return 0;
        }

        if ($result->numRows() == 0) {
            return 0;
        }

        $row = $result->row();

        return $row['total'];
    }

    /**
     * @param int $limit
     *
     * @return array
     */
    public function listLatest($limit = 10)
    {
        return $this->listWhere(' b_active = 1 AND b_enabled = 1 ORDER BY dt_pub_date DESC LIMIT %d', (int)$limit);
    }

    /**
     * Insert title and description for a given locale and item id.
     *
     * @access public
     *
     * @param string $id Item id
     * @param string $locale
     * @param string $title
     * @param string $description
     *
     * @return boolean
     * @since  unknown
     */
    public function insertLocale($id, $locale, $title, $description)
    {
        $array_set = array(
            'fk_i_item_id'     => $id,
            'fk_c_locale_code' => $locale,
            's_title'          => $title,
            's_description'    => $description
        );

        return $this->dao->insert(DB_TABLE_PREFIX . 't_item_description', $array_set);
    }

    /**
     * Find items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     * @param int $start  begining
     * @param int $end    ending
     *
     * @return array of items
     * @since  unknown
     */
    public function findByUserID($userId, $start = 0, $end = null)
    {
        $condition = "fk_i_user_id = $userId";

        return $this->findItemByTypes($condition, 'all', false, $start, $end);
    }

    /**
     * Find enabled items or count of items by types with given where condition
     *
     * @access public
     *
     * @param string | array $conditions Where condition on t_item table i.e "pk_i_id = 3"
     * @param int            $limit      beginning from $start
     * @param int            $offset     ending
     * @param bool           $itemType   item(active, expired, pending, pending validate, premium, all, enabled,
     *                                   blocked)
     *
     * @return array | int array of items or count of item
     * @since  unknown
     *
     */
    public function findItemByTypes($conditions = null, $itemType = false, $count = false, $limit = 0, $offset = null)
    {
        $this->dao->from($this->getTableName() . ' i');
        if ($conditions !== null) {
            if (is_array($conditions)) {
                foreach ($conditions as $condition) {
                    $this->dao->where($condition);
                }
            } else {
                $this->dao->where($conditions);
            }
        }

        $this->addWhereByType($itemType);

        if ($count === true) {
            $this->dao->select('count(pk_i_id) as total');
            $result = $this->dao->get();
            if ($result === false) {
                return 0;
            }
            $items = $result->row();

            return $items['total'];
        }

        $this->dao->orderBy('dt_pub_date', 'DESC');

        if ($offset !== null) {
            $this->dao->limit($limit, $offset);
        } elseif ($limit > 0) {
            $this->dao->limit($limit);
        }

        $result = $this->dao->get();
        if ($result === false) {
            return array();
        }
        $items = $result->result();

        return $this->extendData($items);
    }

    /**
     * add conditions by type
     *
     * @param $itemType
     */
    private function addWhereByType($itemType)
    {
        switch ($itemType) {
            case 'blocked':
                $this->addWhereByOptions(['DISABLED']);

                return;
            case 'active':
                $this->addWhereByOptions(['ACTIVE', 'ENABLED', 'NOTEXPIRED']);

                return;
            case 'nospam':
                $this->addWhereByOptions(['ACTIVE', 'NOSPAM', 'NOTEXPIRED']);

                return;
            case 'expired':
                $this->addWhereByOptions(['EXPIRED']);

                return;
            case 'pending':
            case 'pending_validate':
                $this->addWhereByOptions(['INACTIVE']);

                return;
            case 'premium':
                $this->addWhereByOptions(['PREMIUM']);

                return;
            case 'all':
                return;
            default:
                $this->addWhereByOptions(['ENABLED', 'ACTIVE', 'NOTEXPIRED', 'NOTSPAM']);
        }
    }

    /**
     * Count items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     *
     * @return int number of items
     * @since  unknown
     */
    public function countByUserID($userId)
    {
        return $this->countItemTypesByUserID($userId, 'all');
    }

    /**
     * Count items by User Id according the
     *
     * @access public
     *
     * @param int    $userId   User id
     * @param bool   $itemType (active, expired, pending validate, premium, all, enabled, blocked)
     * @param string $cond
     *
     * @return int number of items
     * @since  unknown
     */
    public function countItemTypesByUserID($userId, $itemType = false, $cond = '')
    {
        $condition[] = "fk_i_user_id = $userId";
        if ($cond) {
            $condition[] = $cond;
        }

        return $this->findItemByTypes($condition, $itemType, true);
    }

    /**
     * Find enabled items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     * @param int $start  beginning from $start
     * @param int $end    ending
     *
     * @return array of items
     * @since  unknown
     */
    public function findByUserIDEnabled($userId, $start = 0, $end = null)
    {
        $condition = "fk_i_user_id = $userId";

        return $this->findItemByTypes($condition, false, false, $start, $end);
    }

    /**
     * Find enabled items which are going to expired
     *
     * @access public
     *
     * @param int $hours
     *
     * @return array of items
     * @since  3.2
     */
    public function findByHourExpiration($hours = 24)
    {
        $conditions = ['TIMESTAMPDIFF(HOUR, NOW(), dt_expiration) = ' . $hours, 'b_active = 1', 'b_spam = 0'];

        return $this->findItemByTypes($conditions);
    }

    /**
     * Find enabled items which are going to expired
     *
     * @access public
     *
     * @param int $days
     *
     * @return array of items
     * @since  3.2
     */
    public function findByDayExpiration($days = 1)
    {
        $conditions = ['TIMESTAMPDIFF(DAY, NOW(), dt_expiration) = ' . $days, 'b_active = 1', 'b_spam = 0'];

        return $this->findItemByTypes($conditions);
    }

    /**
     * Count enabled items belong to an user given its id
     *
     * @access public
     *
     * @param int $userId User id
     *
     * @return int number of items
     * @since  unknown
     */
    public function countByUserIDEnabled($userId)
    {
        return $this->countItemTypesByUserID($userId, 'enabled');
    }

    /**
     * Find enable items according the
     *
     * @access public
     *
     * @param int  $userId   User id
     * @param int  $start    beginning from $start
     * @param int  $end      ending
     * @param bool $itemType item(active, expired, pending, premium, all, enabled, blocked)
     *
     * @return array of items
     * @since  unknown
     *
     */
    public function findItemTypesByUserID($userId, $start = 0, $end = null, $itemType = false)
    {
        return $this->findItemByTypes("fk_i_user_id = $userId", $itemType, false, $start, $end);
    }

    /**
     * Count items by Email according the
     * Useful for counting item that posted by unregistered user
     *
     * @access public
     *
     * @param int    $email    Email
     * @param bool   $itemType (active, expired, pending validate, premium, all, enabled, blocked)
     * @param string $cond
     *
     * @return int number of items
     * @since  unknown
     */
    public function countItemTypesByEmail($email, $itemType = false, $cond = '')
    {
        $where_email = "s_contact_email = " . $this->dao->escape((string)$email);
        if ($cond) {
            $conditions = array($where_email, $cond);
        } else {
            $conditions = $where_email;
        }

        return $this->findItemByTypes($conditions, $itemType, true);
    }

    /**
     * Clear item stat given item id and stat to clear
     * $stat array('spam', 'duplicated', 'bad', 'offensive', 'expired', 'all')
     *
     * @access public
     *
     * @param int    $id
     * @param string $stat
     *
     * @return mixed int if updated correctly or false when error occurs
     * @since  unknown
     */
    public function clearStat($id, $stat)
    {
        switch ($stat) {
            case 'spam':
                $array_set = array('i_num_spam' => 0);
                break;
            case 'duplicated':
                $array_set = array('i_num_repeated' => 0);
                break;
            case 'bad':
                $array_set = array('i_num_bad_classified' => 0);
                break;
            case 'offensive':
                $array_set = array('i_num_offensive' => 0);
                break;
            case 'expired':
                $array_set = array('i_num_expired' => 0);
                break;
            case 'all':
                $array_set = array(
                    'i_num_spam'           => 0,
                    'i_num_repeated'       => 0,
                    'i_num_bad_classified' => 0,
                    'i_num_offensive'      => 0,
                    'i_num_expired'        => 0
                );
                break;
            default:
                break;
        }
        $array_conditions = array('fk_i_item_id' => $id);

        if (isset($array_set)) {
            return $this->dao->update(DB_TABLE_PREFIX . 't_item_stats', $array_set, $array_conditions);
        }
    }

    /**
     * Update title and description given a item id and locale.
     *
     * @access public
     *
     * @param int    $id
     * @param string $locale
     * @param string $title
     * @param string $text
     *
     * @return bool
     * @since  unknown
     */
    public function updateLocaleForce($id, $locale, $title, $text)
    {
        $array_replace = array(
            's_title'          => $title,
            's_description'    => $text,
            'fk_c_locale_code' => $locale,
            'fk_i_item_id'     => $id
        );

        return $this->dao->replace(DB_TABLE_PREFIX . 't_item_description', $array_replace);
    }

    /**
     * Update dt_expiration field, using $expiration_time
     *
     * @param       $id
     * @param mixed $expiration_time could be interget (number of days) or directly a date
     * @param bool  $do_stats
     *
     * @return string new date expiration, false if error occurs
     *
     */
    public function updateExpirationDate($id, $expiration_time, $do_stats = true)
    {
        if (!$expiration_time) {
            return false;
        }

        $this->dao->select('dt_expiration');
        $this->dao->from($this->getTableName());
        $this->dao->where('pk_i_id', $id);
        $result = $this->dao->get();

        if ($result !== false) {
            $item        = $result->row();
            $expired_old = osc_isExpired($item['dt_expiration']);
            if (ctype_digit($expiration_time)) {
                if ($expiration_time > 0) {
                    $dt_expiration = sprintf(
                        'DATE_ADD(%s.dt_pub_date, INTERVAL %d DAY)',
                        $this->getTableName(),
                        $expiration_time
                    );
                } else {
                    $dt_expiration = '9999-12-31 23:59:59';
                }
            } else {
                $dt_expiration = $expiration_time;
            }
            $result = $this->dao->update(
                $this->getTableName(),
                sprintf('dt_expiration = %s', $dt_expiration),
                ['pk_i_id', $id]
            );
            if ($result && $result > 0) {
                $this->dao->select('i.dt_expiration, i.fk_i_user_id, i.fk_i_category_id, l.fk_c_country_code');
                $this->dao->select('l.fk_i_region_id, l.fk_i_city_id');
                $this->dao->from($this->getTableName() . ' i, ' . DB_TABLE_PREFIX . 't_item_location l');
                $this->dao->where('i.pk_i_id = l.fk_i_item_id');
                $this->dao->where('i.pk_i_id', $id);
                $result = $this->dao->get();
                $_item  = $result->row();

                if (!$do_stats) {
                    return $_item['dt_expiration'];
                }

                $expired = osc_isExpired($_item['dt_expiration']);
                if ($expired !== $expired_old) {
                    if ($expired) {
                        if ($_item['fk_i_user_id'] != null) {
                            User::newInstance()->decreaseNumItems($_item['fk_i_user_id']);
                        }
                        CategoryStats::newInstance()->decreaseNumItems($_item['fk_i_category_id']);
                        CountryStats::newInstance()->decreaseNumItems($_item['fk_c_country_code']);
                        RegionStats::newInstance()->decreaseNumItems($_item['fk_i_region_id']);
                        CityStats::newInstance()->decreaseNumItems($_item['fk_i_city_id']);
                    } else {
                        if ($_item['fk_i_user_id'] != null) {
                            User::newInstance()->increaseNumItems($_item['fk_i_user_id']);
                        }
                        CategoryStats::newInstance()->increaseNumItems($_item['fk_i_category_id']);
                        CountryStats::newInstance()->increaseNumItems($_item['fk_c_country_code']);
                        RegionStats::newInstance()->increaseNumItems($_item['fk_i_region_id']);
                        CityStats::newInstance()->increaseNumItems($_item['fk_i_city_id']);
                    }
                }

                return $_item['dt_expiration'];
            }
        }

        return false;
    }

    /**
     * Enable all items by given category ids
     *
     * @param int 0|1 $enable
     * @param array $aIds
     *
     * @return \DBRecordsetClass
     */
    public function enableByCategory($enable, $aIds)
    {
        $sql = sprintf('UPDATE %st_item SET b_enabled = %d WHERE ', DB_TABLE_PREFIX, $enable);
        $sql .= sprintf('%st_item.fk_i_category_id IN (%s)', DB_TABLE_PREFIX, implode(',', $aIds));

        $result = $this->dao->query($sql);

        // The model fires no lifecycle event for this bulk change, so search indexes,
        // caches and audit listeners would never see it (core only fires item hooks
        // for single-item actions). Announce it so they can reconcile the affected
        // items — $aIds are category ids, $enable is the new b_enabled value.
        if ($result !== false) {
            osc_run_hook('items_bulk_enabled_by_category', $aIds, $enable);
        }

        return $result;
    }

    /**
     * Return the number of items marked as $type
     *
     * @param string $type spam, repeated, bad_classified, offensive, expired
     *
     * @return int
     */
    public function countByMarkas($type)
    {
        $this->dao->select('count(*) as total');
        $this->dao->from($this->getTableName() . ' i');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_stats s');

        $this->dao->where('i.pk_i_id = s.fk_i_item_id');
        // i_num_spam, i_num_repeated, i_num_bad_classified, i_num_offensive, i_num_expired
        if (null !== $type) {
            switch ($type) {
                case 'spam':
                    $this->dao->where('s.i_num_spam > 0 AND i.b_spam = 0');
                    break;
                case 'repeated':
                    $this->dao->where('s.i_num_repeated > 0');
                    break;
                case 'bad_classified':
                    $this->dao->where('s.i_num_bad_classified > 0');
                    break;
                case 'offensive':
                    $this->dao->where('s.i_num_offensive > 0');
                    break;
                case 'expired':
                    $this->dao->where('s.i_num_expired > 0');
                    break;
                default:
            }
        } else {
            return 0;
        }

        $result = $this->dao->get();
        if ($result === false) {
            return 0;
        }
        $total_ads = $result->row();

        return $total_ads['total'];
    }

    /**
     * Return meta fields for a given item
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array meta fields array
     * @since  unknown
     */
    public function metaFields($id)
    {
        $metaFields = Field::newInstance()->findByItem($id);
        if (empty($metaFields)) {
            return [];
        }
        $aTemp = $metaFields;

        $array = array();
        // prepare data - date interval - from <-> to
        foreach ($aTemp as $value) {
            if ($value['e_type'] === 'DATEINTERVAL') {
                $aValue = array();
                if (isset($array[$value['pk_i_id']])) {
                    $aValue = $array[$value['pk_i_id']]['s_value'];
                }
                $aValue[$value['s_multi']] = $value['s_value'];
                $value['s_value']          = $aValue;
            }
            $array[$value['pk_i_id']] = $value;
        }

        return $array;
    }

    /**
     * Delete by city area
     *
     * @access public
     *
     * @param int $cityAreaId city area id
     *
     * @return bool
     *
     * @since  3.1
     *
     */
    public function deleteByCityArea($cityAreaId)
    {
        $this->dao->select('fk_i_item_id');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_location');
        $this->dao->where('fk_i_city_area_id', $cityAreaId);
        $result = $this->dao->get();
        $items  = $result->result();

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by primary key, delete dependencies too
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByPrimaryKey($id)
    {
        $item = $this->findByPrimaryKey($id);

        if (null === $item) {
            return false;
        }

        if ($item['b_active'] == 1 && $item['b_enabled'] == 1 && $item['b_spam'] == 0
            && !osc_isExpired($item['dt_expiration'])
        ) {
            if ($item['fk_i_user_id'] != null) {
                User::newInstance()->decreaseNumItems($item['fk_i_user_id']);
            }
            CategoryStats::newInstance()->decreaseNumItems($item['fk_i_category_id']);
            CountryStats::newInstance()->decreaseNumItems($item['fk_c_country_code']);
            RegionStats::newInstance()->decreaseNumItems($item['fk_i_region_id']);
            CityStats::newInstance()->decreaseNumItems($item['fk_i_city_id']);
        }
        $isAdmin = false;
        if (defined('OC_ADMIN') && OC_ADMIN) {
            $isAdmin = true;
        }
        ItemActions::deleteResourcesFromHD($id, $isAdmin);

        $this->dao->delete(DB_TABLE_PREFIX . 't_item_description', "fk_i_item_id = $id");
        $this->dao->delete(DB_TABLE_PREFIX . 't_item_comment', "fk_i_item_id = $id");
        $this->dao->delete(DB_TABLE_PREFIX . 't_item_resource', "fk_i_item_id = $id");
        $this->dao->delete(DB_TABLE_PREFIX . 't_item_location', "fk_i_item_id = $id");
        $this->dao->delete(DB_TABLE_PREFIX . 't_item_stats', "fk_i_item_id = $id");
        $this->dao->delete(DB_TABLE_PREFIX . 't_item_meta', "fk_i_item_id = $id");

        Plugins::runHook('delete_item', $id);

        return parent::deleteByPrimaryKey($id);
    }

    /**
     * Delete each of the given items by primary key, firing the standard
     * item-lifecycle hooks (before_delete_item / after_delete_item) around
     * every deletion. Cascade deletes triggered by removing a location then
     * emit the same signals a direct item delete does, so listeners that keep
     * external indexes or caches in sync do not need to special-case them.
     *
     * @access private
     *
     * @param array $items rows containing an fk_i_item_id column
     *
     * @return int number of affected rows
     */
    private function deleteItemsFiringHooks($items)
    {
        $arows = 0;
        foreach ($items as $i) {
            $itemId = $i['fk_i_item_id'];
            $item   = $this->findByPrimaryKey($itemId);
            osc_run_hook('before_delete_item', $itemId);
            $deleted = $this->deleteByPrimaryKey($itemId);
            if ($deleted !== false) {
                $arows += $deleted;
                osc_run_hook('after_delete_item', $itemId, $item);
            }
        }

        return $arows;
    }

    /**
     * Get the result match of the primary key passed by parameter, extended with
     * location information and number of views.
     *
     * @access public
     *
     * @param int $id Item id
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function findByPrimaryKey($id)
    {
        if (!is_numeric($id) || $id === null) {
            return array();
        }
        $this->dao->select('i.*');
        $this->dao->from($this->getTableName() . ' i');
        $this->dao->where('i.pk_i_id', $id);
        $result = $this->dao->get();

        if ($result === false) {
            return false;
        }

        if ($result->numRows() === 0) {
            return array();
        }

        $item = $result->row();

        if (null !== $item) {
            return $this->extendDataSingle($item);
        }

        return array();
    }

    /**
     * Extends the given array $item with description in available locales
     *
     * @access public
     *
     * @param array $item
     *
     * @return array item array with description in available locales
     *
     * @since  unknown
     *
     */
    public function extendDataSingle($item)
    {
        return $this->extendData(array($item))[0];
    }

    /**
     * Delete by city
     *
     * @access public
     *
     * @param int $cityId city id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByCity($cityId)
    {
        $this->dao->select('fk_i_item_id');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_location');
        $this->dao->where('fk_i_city_id', $cityId);
        $result = $this->dao->get();
        $items  = $result->result();

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by region
     *
     * @access public
     *
     * @param int $regionId region id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByRegion($regionId)
    {
        $this->dao->select('fk_i_item_id');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_location');
        $this->dao->where('fk_i_region_id', $regionId);
        $result = $this->dao->get();
        $items  = $result->result();

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Delete by country
     *
     * @access public
     *
     * @param int $countryId country id
     *
     * @return bool
     *
     * @since  unknown
     */
    public function deleteByCountry($countryId)
    {
        $this->dao->select('fk_i_item_id');
        $this->dao->from(DB_TABLE_PREFIX . 't_item_location');
        $this->dao->where('fk_c_country_code', $countryId);
        $result = $this->dao->get();
        $items  = $result->result();

        return $this->deleteItemsFiringHooks($items);
    }

    /**
     * Extends the given array $items with category name , and description in available locales
     *
     * @access public
     *
     * @param array $items array with items
     *
     * @return array with category name
     * @since  unknown
     */
    public function extendCategoryName($items, $prefLocale = null)
    {
        if (null === $prefLocale) {
            $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
        }
        $results = array();
        // get categoryIds from items
        $categoryIds = array_column($items, 'fk_i_category_id');
        $categoryIds = array_unique($categoryIds);

        $this->dao->select('fk_i_category_id, fk_c_locale_code, s_name');
        $this->dao->from(DB_TABLE_PREFIX . 't_category_description');
        if (count($categoryIds) > 0) {
            $this->dao->whereIn('fk_i_category_id', $categoryIds);
        }
        $this->dao->where('s_name!=', '');

        $result = $this->dao->get();
        if ($result === false) {
            return $items;
        }
        $categories = $result->result();
        $aCategories = array();
        foreach ($categories as $c) {
            // if category name is not empty
            if ($c['s_name'] != '') {
                $aCategories[$c['fk_i_category_id']]['locale'][$c['fk_c_locale_code']]['s_category_name'] = $c['s_name'];
            }
        }

        foreach ($items as $item) {
            if (isset($item['fk_i_category_id'], $aCategories[$item['fk_i_category_id']])) {
                if (isset($item['locale']) && is_array($item['locale'])) {
                    foreach ($item['locale'] as $localeCode => $itemLocale) {
                        if (isset($aCategories[$item['fk_i_category_id']]['locale'][$localeCode])) {
                            $item['locale'][$localeCode]['s_category_name'] = $aCategories[$item['fk_i_category_id']]['locale'][$localeCode]['s_category_name'];
                        }
                    }
                }
            }
            if (isset($aCategories[$item['fk_i_category_id']]['locale'][$prefLocale]['s_category_name'])) {
                $item['s_category_name'] = $aCategories[$item['fk_i_category_id']]['locale'][$prefLocale]['s_category_name'];
            } else {
                // check each locale until we find one that has a name
                $item['s_category_name'] = '';
                foreach ($aCategories[$item['fk_i_category_id']]['locale'] as $locale => $data) {
                    if ($data['s_category_name'] != '') {
                        $item['s_category_name'] = $data['s_category_name'];
                        break;
                    }
                }
            }
            $results[] = $item;
        }
        return $results;
    }

    /**
     * Extends the given array $items with description in available locales
     *
     * @access public
     *
     * @param array $items array with items
     *
     * @return array $items with description
     * @since  unknown
     */
    private function extendItemDescription($items, $prefLocale = null)
    {
        if (!empty($items)) {
            if (null === $prefLocale) {
                $prefLocale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
            }
            $itemIds = array_column($items, 'pk_i_id');

            $this->dao->select('fk_i_item_id, fk_c_locale_code, s_title, s_description');
            $this->dao->from(DB_TABLE_PREFIX . 't_item_description');
            $this->dao->whereIn('fk_i_item_id', $itemIds);
            $result = $this->dao->get();
            if ($result === false) {
                return $items;
            }
            $descriptions = $result->result();
            $aDescriptions = array();
            foreach ($descriptions as $d) {
                if ($d['s_title']!='') {
                    $aDescriptions[$d['fk_i_item_id']]['locale'][$d['fk_c_locale_code']]['s_title'] = $d['s_title'];
                }
                if ($d['s_description']!='') {
                    $aDescriptions[$d['fk_i_item_id']]['locale'][$d['fk_c_locale_code']]['s_description'] = $d['s_description'];
                }
            }
            $extendedItems = [];
            foreach ($items as $item) {
                if (isset($item['pk_i_id'], $aDescriptions[$item['pk_i_id']])) {
                    //if $item['locale'] exists, then we have to merge the arrays
                    if (isset($item['locale']) && is_array($item['locale'])) {
                        $item['locale'] = array_merge($item['locale'], $aDescriptions[$item['pk_i_id']]['locale']);
                    } else {
                        $item['locale'] = $aDescriptions[$item['pk_i_id']]['locale'];
                    }
                }
                if (isset($item['locale'][$prefLocale]['s_title'])) {
                    $item['s_title'] = $item['locale'][$prefLocale]['s_title'];
                } else {
                    // check each locale until we find one that has a title
                    $item['s_title'] = '';
                    if (isset($item['locale'])) {
                        foreach ($item['locale'] as $locale => $title) {
                            if (isset($title['s_title']) && $title['s_title']  != '') {
                                $item['s_title'] = $title['s_title'];
                                break;
                            }
                        }
                    }
                }
                if (isset($item['locale'][$prefLocale]['s_description'])) {
                    $item['s_description'] = $item['locale'][$prefLocale]['s_description'];
                } else {
                    // check each locale until we find one that has a description
                    $item['s_description'] = '';
                    if (isset($item['locale']) && is_array($item['locale'])) {
                        foreach ($item['locale'] as $locale => $description) {
                            if (isset($description['s_description']) && $description['s_description'] != '') {
                                $item['s_description'] = $description['s_description'];
                                break;
                            }
                        }
                    }
                }
                $extendedItems[] = $item;
            }
            return $extendedItems;
        }
        return $items;
    }
}

/* file end: ./oc-includes/osclass/model/Item.php */