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
 * Model database for ItemComment table
 *
 * @package    Osclass
 * @subpackage Model
 * @since      unknown
 */
class ItemComment extends DAO
{
    /**
     * It references to self object: ItemComment.
     * It is used as a singleton
     *
     * @access private
     * @since  unknown
     * @var Item
     */
    private static $instance;

    /**
     * Set data related to t_item_comment table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_comment');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            'fk_i_item_id',
            'dt_pub_date',
            's_title',
            's_author_name',
            's_author_email',
            's_body',
            'b_enabled',
            'b_active',
            'b_spam',
            'fk_i_user_id'
        );
        $this->setFields($array_fields);
    }

    /**
     * It creates a new ItemComment object class ir if it has been created
     * before, it return the previous object
     *
     * @access public
     * @return ItemComment
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
     * Searches for comments information, given an item id.
     *
     * @access public
     *
     * @param integer $id
     *
     * @return array
     * @since  unknown
     */
    public function findByItemIDAll($id)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_item_id', $id);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Searches for comments information, given an item id, page and comments per page.
     *
     * @access public
     *
     * @param integer $id
     * @param integer $page
     * @param null    $commentsPerPage
     *
     * @return array
     * @since  unknown
     *
     */
    public function findByItemID($id, $page = null, $commentsPerPage = null)
    {
        $result = array();
        if ($page == null) {
            $page = osc_item_comments_page();
        }
        if ($page == '') {
            $page = 0;
        }

        if ($commentsPerPage == null) {
            $commentsPerPage = osc_comments_per_page();
        }

        $this->dao->select();
        $this->dao->from($this->getTableName());
        $conditions = array(
            'fk_i_item_id' => $id,
            'b_active'     => 1,
            'b_enabled'    => 1
        );
        $this->dao->where($conditions);

        if ($page !== 'all' && $commentsPerPage > 0) {
            $this->dao->limit($page * $commentsPerPage, $commentsPerPage);
        }

        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Return total of comments, given an item id. (active & enabled)
     *
     * @access     public
     *
     * @param integer $id
     *
     * @return integer
     * @see        ItemComment::totalComments
     * @since      unknown
     * @deprecated since 2.3
     */
    public function total_comments($id)
    {
        return $this->totalComments($id);
    }

    /**
     * Return total of comments, given an item id. (active & enabled)
     *
     * @access public
     *
     * @param integer $id
     *
     * @return integer
     * @since  2.3
     */
    public function totalComments($id)
    {
        $this->dao->select('count(pk_i_id) as total');
        $this->dao->from($this->getTableName());
        $conditions = array(
            'fk_i_item_id' => $id,
            'b_active'     => 1,
            'b_enabled'    => 1
        );
        $this->dao->where($conditions);
        $this->dao->groupBy('fk_i_item_id');
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() === 0) {
            return 0;
        } else {
            $total = $result->row();

            return $total['total'];
        }
    }

    /**
     * Searches for comments information, given an user id.
     *
     * @access public
     *
     * @param integer $id
     *
     * @return array
     * @since  unknown
     */
    public function findByAuthorID($id)
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $conditions = array(
            'fk_i_user_id' => $id,
            'b_active'     => 1,
            'b_enabled'    => 1
        );
        $this->dao->where($conditions);
        $result = $this->dao->get();

        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Searches for comments information, given an user id.
     *
     * @access public
     *
     * @param integer $itemId
     *
     * @return array
     * @since  unknown
     */
    public function getAllComments($itemId = null)
    {
        $this->dao->select('c.*');
        $this->dao->from($this->getTableName() . ' c');
        $this->dao->from(DB_TABLE_PREFIX . 't_item i');

        $conditions = array();
        $conditions = array(
            'i.pk_i_id'      => $itemId,
            'c.fk_i_item_id' => $itemId
        );
        if (null === $itemId) {
            $conditions = 'c.fk_i_item_id = i.pk_i_id';
        }

        $this->dao->where($conditions);
        $this->dao->orderBy('c.dt_pub_date', 'DESC');
        $aux = $this->dao->get();
        if ($aux == false) {
            return array();
        }
        $comments = $aux->result();

        return $this->extendData($comments);
    }

    /**
     * Extends an array of comments with title / description
     *
     * @access private
     *
     * @param array $items
     *
     * @return array
     * @since  unknown
     */
    private function extendData($items)
    {
        $prefLocale = osc_current_user_locale();

        $results = array();
        foreach ($items as $item) {
            $this->dao->select();
            $this->dao->from(DB_TABLE_PREFIX . 't_item_description');
            $this->dao->where('fk_i_item_id', $item['fk_i_item_id']);
            $aux = $this->dao->get();
            if ($aux == false) {
                $descriptions = array();
            } else {
                $descriptions = $aux->result();
            }

            $item['locale'] = array();
            foreach ($descriptions as $desc) {
                $item['locale'][$desc['fk_c_locale_code']] = $desc;
            }
            if (isset($item['locale'][$prefLocale])) {
                $item['s_title']       = $item['locale'][$prefLocale]['s_title'];
                $item['s_description'] = $item['locale'][$prefLocale]['s_description'];
            } else {
                $data                  = current($item['locale']);
                $item['s_title']       = $data['s_title'];
                $item['s_description'] = $data['s_description'];
                unset($data);
            }
            $results[] = $item;
        }

        return $results;
    }

    /**
     * Searches for last comments information, given a limit of comments.
     *
     * @access public
     *
     * @param integer $num
     *
     * @return array|bool
     * @since  unknown
     *
     */
    public function getLastComments($num)
    {
        if (!(int)$num) {
            return false;
        }

        $lang = osc_current_user_locale();

        $this->dao->select('c.*,c.s_title as comment_title, d.s_title');
        $this->dao->from($this->getTableName() . ' c');
        $this->dao->join(DB_TABLE_PREFIX . 't_item i', 'i.pk_i_id = c.fk_i_item_id');
        $this->dao->join(DB_TABLE_PREFIX . 't_item_description d', 'd.fk_i_item_id = c.fk_i_item_id');
        $this->dao->orderBy('c.pk_i_id', 'DESC');
        $this->dao->limit(0, $num);

        $result = $this->dao->get();
        if ($result == false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Return comments on command
     *
     * @access public
     *
     * @param int item's ID or null
     * @param int start
     * @param int limit
     * @param string order by
     * @param string order
     * @param bool $all true returns all comments, false, returns comments
     *                  which not display at frontend
     *
     * @return array
     * @since  2.4
     */
    public function search(
        $itemId = null,
        $start = 0,
        $limit = 10,
        $order_by = 'c.pk_i_id',
        $order = 'DESC',
        $all = true
    ) {
        $this->dao->select('c.*');
        $this->dao->from($this->getTableName() . ' c');
        $this->dao->from(DB_TABLE_PREFIX . 't_item i');

        $conditions = array();
        $conditions = array(
            'i.pk_i_id'      => $itemId,
            'c.fk_i_item_id' => $itemId
        );
        if (null === $itemId) {
            $conditions = 'c.fk_i_item_id = i.pk_i_id';
        }

        $this->dao->where($conditions);

        if (!$all) {
            $auxCond = '( c.b_enabled = 0 OR c.b_active = 0 OR c.b_spam = 1 )';
            $this->dao->where($auxCond);
        }

        $this->dao->orderBy($order_by, $order);
        $this->dao->limit($start, $limit);

        $aux = $this->dao->get();
        if ($aux == false) {
            return array();
        }

        return $aux->result();
    }

    /**
     * Count the number of comments
     *
     * @param int item's ID or null
     *
     * @return array|int
     */
    public function count($itemId = null)
    {
        $this->dao->select('COUNT(*) AS numrows');
        $this->dao->from($this->getTableName() . ' c');
        $this->dao->from(DB_TABLE_PREFIX . 't_item i');

        $conditions = array();
        $conditions = array(
            'i.pk_i_id'      => $itemId,
            'c.fk_i_item_id' => $itemId
        );
        if (null === $itemId) {
            $conditions = 'c.fk_i_item_id = i.pk_i_id';
        }

        $this->dao->where($conditions);
        $aux = $this->dao->get();
        if ($aux == false) {
            return array();
        }
        $row = $aux->row();

        return $row['numrows'];
    }

    /**
     * @param null $aConditions
     *
     * @return bool|int
     */
    public function countAll($aConditions = null)
    {
        $this->dao->select('count(*) as total');
        $this->dao->from($this->getTableName() . ' c');
        $this->dao->from(DB_TABLE_PREFIX . 't_item i');

        $this->dao->where('c.fk_i_item_id = i.pk_i_id');
        if (null !== $aConditions) {
            $this->dao->where($aConditions);
        }
        $result = $this->dao->get();

        if ($result == false) {
            return false;
        }

        if ($result->numRows() === 0) {
            return 0;
        } else {
            $total = $result->row();

            return $total['total'];
        }
    }
}
/* file end: ./oc-includes/osclass/model/ItemComment.php */
