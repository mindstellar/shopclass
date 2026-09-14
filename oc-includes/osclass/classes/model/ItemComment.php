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
 * Model database for ItemComment table
 *
 * @package    Shopclass
 * @subpackage Model
 */
class ItemComment extends DAO
{
    /**
     * It references to self object: ItemComment.
     * It is used as a singleton
     *
     * @var ItemComment
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
     * @return ItemComment
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Searches for comments information, given an item id.
     *
     * @param int $id
     *
     * @return array<int,array<string,string|null>>
     */
    public function findByItemIDAll($id)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->where('fk_i_item_id', $id)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for comments information, given an item id, page and comments per page.
     *
     * @param int             $id
     * @param int|string|null $page            'all' returns every page
     * @param int|null        $commentsPerPage Defaults to the site setting
     *
     * @return array<int,array<string,string|null>>
     */
    public function findByItemID($id, $page = null, $commentsPerPage = null)
    {
        if ($page == null) {
            $page = osc_item_comments_page();
        }
        if ($page == '') {
            $page = 0;
        }

        if ($commentsPerPage == null) {
            $commentsPerPage = osc_comments_per_page();
        }

        $query = osc_db_table($this->getTableName())
            ->where('fk_i_item_id', $id)
            ->where('b_active', 1)
            ->where('b_enabled', 1);

        if ($page !== 'all' && $commentsPerPage > 0) {
            // Legacy dao->limit($page * $commentsPerPage, $commentsPerPage) compiles to
            // "LIMIT offset, count" here (unlike the confusingly-named KeywordBlock pair,
            // these argument names already match the SQL meaning): offset = page * perPage,
            // count = perPage.
            $query = $query->limit((int)$commentsPerPage)->offset((int)($page * $commentsPerPage));
        }

        try {
            $rows = $query->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Return total of comments, given an item id. (active & enabled)
     *
     * @param int $id
     *
     * @return int|string|false The count as a string, int 0 when there are none,
     *                          false on a null id or a query failure
     * @see        ItemComment::totalComments
     * @deprecated since 2.3
     */
    public function total_comments($id)
    {
        return $this->totalComments($id);
    }

    /**
     * Return total of comments, given an item id. (active & enabled)
     *
     * @param int $id
     *
     * @return int|string|false The count as a string, int 0 when there are none,
     *                          false on a null id or a query failure
     * @since  2.3
     */
    public function totalComments($id)
    {
        if ($id === null) {
            // Legacy _where() omits the right-hand side for a null value, producing a
            // malformed WHERE clause that fails the query; the failure branch below
            // returns false, which a bound null (a valid, zero-row match under GROUP BY)
            // does not. The two are not interchangeable, so null keeps its own branch.
            return false;
        }

        // COUNT(pk_i_id) AS total is an aggregate expression the query builder's
        // identifier allowlist rejects, so this stays hand-written SQL with every
        // value bound; the column list, WHERE and GROUP BY match _getSelect() exactly.
        $sql = 'SELECT COUNT(pk_i_id) AS total FROM ' . $this->getTableName()
            . ' WHERE fk_i_item_id = ? AND b_active = ? AND b_enabled = ? GROUP BY fk_i_item_id';

        try {
            $row = osc_db_select_one($sql, array($id, 1, 1));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return 0;
        }

        return (string)$row['total'];
    }

    /**
     * Searches for comments information, given an user id.
     *
     * @param int $id
     *
     * @return array<int,array<string,string|null>>
     */
    public function findByAuthorID($id)
    {
        try {
            $rows = osc_db_table($this->getTableName())
                ->where('fk_i_user_id', $id)
                ->where('b_active', 1)
                ->where('b_enabled', 1)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Searches for comments information, given an item id (or every item).
     *
     * @param int|null $itemId
     *
     * @return array<int,array<string,mixed>> Comment rows extended with their item's locale block
     */
    public function getAllComments($itemId = null)
    {
        // The query builder has no notion of a table alias, and this method's own
        // column list (c.*) plus the implicit two-table join legacy built via a second
        // from() can't be expressed through its identifier allowlist, so this is
        // hand-written SQL: same columns, same CROSS JOIN _getSelect() produces for two
        // FROM entries, same ordering.
        $itemTable = DB_TABLE_PREFIX . 't_item';
        $sql       = 'SELECT c.* FROM ' . $this->getTableName() . ' c CROSS JOIN ' . $itemTable . ' i WHERE ';

        if (null === $itemId) {
            // No caller value here: a compile-time-only join condition, not user input.
            $sql   .= 'c.fk_i_item_id = i.pk_i_id';
            $params = array();
        } else {
            $sql   .= 'i.pk_i_id = ? AND c.fk_i_item_id = ?';
            $params = array($itemId, $itemId);
        }
        $sql .= ' ORDER BY c.dt_pub_date DESC';

        try {
            $comments = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return $this->extendData(osc_db_stringify_rows($comments));
    }

    /**
     * Extends an array of comments with title / description
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function extendData($items)
    {
        $prefLocale = osc_current_user_locale();

        if (count($items) === 0) {
            return array();
        }

        // One lookup for the whole batch instead of one per comment. Comments on
        // a single item all asked for the same rows, so the repeat was pure
        // waste. A failure now empties every comment's locale block rather than
        // just one, which matches what actually happened before: the queries were
        // identical in shape, so anything breaking one broke all of them.
        $itemIds = array();
        foreach ($items as $item) {
            $itemIds[$item['fk_i_item_id']] = true;
        }

        try {
            $descriptions = osc_db_stringify_rows(
                osc_db_table(DB_TABLE_PREFIX . 't_item_description')
                    ->whereIn('fk_i_item_id', array_keys($itemIds))
                    ->get()
            );
        } catch (\mindstellar\database\DbException $e) {
            $descriptions = array();
        }

        // Grouped in the order the rows arrive, so the locale picked by current()
        // below when there is no match for the preferred one is the same one a
        // per-comment query would have put first.
        $byItem = array();
        foreach ($descriptions as $desc) {
            $byItem[$desc['fk_i_item_id']][$desc['fk_c_locale_code']] = $desc;
        }

        $results = array();
        foreach ($items as $item) {
            $item['locale'] = $byItem[$item['fk_i_item_id']] ?? array();
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
     * @param int $num
     *
     * @return array<int,array<string,string|null>>|false False when $num is not a positive int
     */
    public function getLastComments($num)
    {
        if (!(int)$num) {
            return false;
        }

        // The select list re-aliases c.s_title as comment_title and also selects the
        // unaliased d.s_title, which collides in the associative result with c.*'s own
        // s_title (the later column in the list wins) — a hand-written query is the
        // only way to reproduce that column ordering, so this stays raw SQL rather than
        // going through the builder, which cannot express an aliased select list.
        $table            = $this->getTableName();
        $itemTable        = DB_TABLE_PREFIX . 't_item';
        $descriptionTable = DB_TABLE_PREFIX . 't_item_description';

        $sql = 'SELECT c.*,c.s_title as comment_title, d.s_title'
            . ' FROM ' . $table . ' c'
            . ' JOIN ' . $itemTable . ' i ON i.pk_i_id = c.fk_i_item_id'
            . ' JOIN ' . $descriptionTable . ' d ON d.fk_i_item_id = c.fk_i_item_id'
            . ' ORDER BY c.pk_i_id DESC'
            . ' LIMIT ' . (int)$num;

        try {
            $rows = osc_db_select($sql);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Return comments on command
     *
     * @param int|null $itemId   item's ID, or null for every item
     * @param int      $start    offset
     * @param int      $limit    row count
     * @param string   $order_by sort column
     * @param string   $order    order type [DESC|ASC|random]
     * @param bool     $all      true returns all comments, false, returns comments
     *                           which not display at frontend
     *
     * @return array<int,array<string,string|null>>
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
        $itemTable = DB_TABLE_PREFIX . 't_item';
        $sql       = 'SELECT c.* FROM ' . $this->getTableName() . ' c CROSS JOIN ' . $itemTable . ' i WHERE ';

        if (null === $itemId) {
            $sql    .= 'c.fk_i_item_id = i.pk_i_id';
            $params  = array();
        } else {
            $sql    .= 'i.pk_i_id = ? AND c.fk_i_item_id = ?';
            $params  = array($itemId, $itemId);
        }

        if (!$all) {
            // A fixed literal, not caller input.
            $sql .= ' AND ( c.b_enabled = 0 OR c.b_active = 0 OR c.b_spam = 1 )';
        }

        // Validate the sort column against the same identifier allowlist the other
        // paged searches use before it reaches ORDER BY. Every in-repo caller
        // passes a fixed literal, but this is a public method a plugin could call
        // with request input, so an unvalidated column is not reproduced.
        if (!preg_match('/^[A-Za-z0-9_.]+$/', (string)$order_by)) {
            $order_by = 'c.pk_i_id';
        }
        $direction = (string)$order;
        if (strtolower($direction) === 'random') {
            $orderSql = $order_by . ' RAND()';
        } elseif (trim($direction) !== '' && trim($direction) !== '0') {
            $orderSql = $order_by
                . (in_array(strtoupper(trim($direction)), array('ASC', 'DESC'), true) ? ' ' . $direction : ' ASC');
        } else {
            // trim($direction) is falsy for '' AND for the single string '0', so a
            // literal "0" direction falls through unvalidated and is concatenated onto
            // the column, producing an invalid identifier and a failed query.
            $orderSql = $order_by . $direction;
        }
        $sql .= ' ORDER BY ' . $orderSql;

        if (is_numeric($start)) {
            $sql .= ' LIMIT ' . (int)$start;
            if (is_numeric($limit) && (int)$limit > 0) {
                $sql .= ', ' . (int)$limit;
            }
        }

        try {
            $rows = osc_db_select($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * Count the number of comments
     *
     * @param int|null $itemId item's ID, or null for every item
     *
     * @return string|array{}|null The count as a string, an empty array on a query failure
     */
    public function count($itemId = null)
    {
        $itemTable = DB_TABLE_PREFIX . 't_item';
        $sql       = 'SELECT COUNT(*) AS numrows FROM ' . $this->getTableName() . ' c CROSS JOIN ' . $itemTable
            . ' i WHERE ';

        if (null === $itemId) {
            $sql   .= 'c.fk_i_item_id = i.pk_i_id';
            $params = array();
        } else {
            $sql   .= 'i.pk_i_id = ? AND c.fk_i_item_id = ?';
            $params = array($itemId, $itemId);
        }

        try {
            $row = osc_db_select_one($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        // COUNT(*) with no GROUP BY always returns exactly one row, so this branch is
        // unreachable through this method's own signature; kept for parity with the
        // legacy row() call on an empty result set.
        return $row === null ? null : (string)$row['numrows'];
    }

    /**
     * Count comments across every item, narrowed by optional WHERE conditions.
     *
     * @param array<string,mixed>|string|null $aConditions Column => value map, or a raw SQL fragment
     *
     * @return int|string|false The count as a string, int 0 when there are none,
     *                          false on a null condition value or a query failure
     */
    public function countAll($aConditions = null)
    {
        $itemTable = DB_TABLE_PREFIX . 't_item';
        $sql       = 'SELECT COUNT(*) AS total FROM ' . $this->getTableName() . ' c CROSS JOIN ' . $itemTable
            . ' i WHERE c.fk_i_item_id = i.pk_i_id';
        $params = array();

        if (null !== $aConditions) {
            if (is_array($aConditions)) {
                foreach ($aConditions as $k => $v) {
                    if ($v === null) {
                        // Same malformed-clause outcome as totalComments(null): the
                        // failure branch below returns false, which a bound null does
                        // not reproduce.
                        return false;
                    }
                    $k = (string)$k;
                    if (!preg_match('/(\s|<|>|!|=|is null|is not null)/i', $k)) {
                        $k .= ' =';
                    }
                    $sql     .= ' AND ' . $k . ' ?';
                    $params[] = $v;
                }
            } else {
                // Every caller in this codebase passes a fixed literal condition
                // fragment (functions.php, CAdminMain.php, CommentsDataTable.php); legacy
                // passed it straight into the WHERE clause unvalidated and this
                // reproduces that exactly rather than adding validation it never had.
                $sql .= ' AND ' . $aConditions;
            }
        }

        try {
            $row = osc_db_select_one($sql, $params);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        if ($row === null) {
            return 0;
        }

        return (string)$row['total'];
    }
}
/* file end: ./oc-includes/osclass/model/ItemComment.php */
