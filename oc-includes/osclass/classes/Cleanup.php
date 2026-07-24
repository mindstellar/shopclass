<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Core maintenance engine: finds and removes stale content — expired, unactivated, spam,
 * blocked and reported listings, and unactivated users. Powers the Tools > Cleanup screen
 * and the scheduled (cron) cleanup. The vanilla, first-class replacement for the Butler plugin.
 */
class Cleanup extends DAO
{
    /** All cleanup rules, in the order they run. */
    public const RULES = array(
        'reported',
        'expired',
        'inactive_listings',
        'spam',
        'blocked',
        'inactive_users',
    );

    private static $instance;

    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Whether a rule targets users (vs listings).
     */
    public static function isUserRule($rule)
    {
        return $rule === 'inactive_users';
    }

    /**
     * How many rows a rule currently matches — for the preview counts on the screen.
     *
     * @return int
     */
    public function countFor($rule, $days)
    {
        list($from, $where, $params) = $this->ruleQuery($rule, (int)$days);

        try {
            return (int)osc_db_scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, $params);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * The next batch of rows a rule matches: [{pk_i_id, s_secret}] for listings,
     * [{pk_i_id}] for users.
     *
     * @return array
     */
    public function candidates($rule, $days, $limit)
    {
        list($from, $where, $params, $columns) = $this->ruleQuery($rule, (int)$days);

        // $limit is a caller-supplied batch size, cast rather than bound: MySQL
        // only accepts a placeholder in LIMIT on a prepared statement, and this
        // statement is not always prepared.
        $sql = 'SELECT ' . $columns . ' FROM ' . $from . ' WHERE ' . $where
            . ' LIMIT ' . max(1, (int)$limit);

        try {
            return osc_db_stringify_rows(osc_db_select($sql, $params));
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }
    }

    /**
     * The FROM, WHERE, bound values and candidate columns for one rule.
     *
     * Returned as a description rather than applied to a shared builder so that
     * countFor() and candidates() each compose a complete statement: the count is
     * a plain COUNT(*) instead of a COUNT alongside ungrouped columns, which is
     * rejected outright under ONLY_FULL_GROUP_BY (on by default from MySQL 5.7).
     *
     * @param string $rule
     * @param int    $days
     *
     * @return array{0:string,1:string,2:array,3:string}
     */
    private function ruleQuery($rule, $days)
    {
        $before = date('Y-m-d H:i:s', time() - ($days * 24 * 3600));
        $item   = DB_TABLE_PREFIX . 't_item';
        $cols   = 'pk_i_id, s_secret';

        switch ($rule) {
            case 'expired':
                return array($item, 'dt_expiration < ?', array($before), $cols);
            case 'inactive_listings':
                return array($item, 'b_active = 0 AND dt_pub_date < ?', array($before), $cols);
            case 'spam':
                return array($item, 'b_spam = 1 AND dt_pub_date < ?', array($before), $cols);
            case 'blocked':
                return array($item, 'b_enabled = 0 AND dt_pub_date < ?', array($before), $cols);
            case 'reported':
                // Listings with at least one spam report (t_item_stats is 1:1 with t_item).
                return array(
                    $item . ' AS i INNER JOIN ' . DB_TABLE_PREFIX . 't_item_stats AS s'
                        . ' ON s.fk_i_item_id = i.pk_i_id',
                    's.i_num_spam > 0',
                    array(),
                    'i.pk_i_id AS pk_i_id, i.s_secret AS s_secret'
                );
            case 'inactive_users':
                return array(
                    DB_TABLE_PREFIX . 't_user',
                    'b_active = 0 AND dt_reg_date < ?',
                    array($before),
                    'pk_i_id'
                );
            default:
                // Unknown rule: an impossible condition, so nothing is ever matched/deleted.
                return array($item, '1 = 0', array(), $cols);
        }
    }

    /**
     * Delete up to $limit rows matched by a rule. Returns the number actually deleted.
     *
     * @return int
     */
    public function purge($rule, $days, $limit)
    {
        $rows = $this->candidates($rule, $days, $limit);
        if (!$rows) {
            return 0;
        }
        $deleted = 0;
        if (self::isUserRule($rule)) {
            $users = User::newInstance();
            foreach ($rows as $row) {
                if ($users->deleteUser($row['pk_i_id'])) {
                    $deleted++;
                }
            }
        } else {
            $items = new ItemActions(true);
            foreach ($rows as $row) {
                if ($items->delete($row['s_secret'], $row['pk_i_id'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}

/* file end: ./oc-includes/osclass/classes/Cleanup.php */
