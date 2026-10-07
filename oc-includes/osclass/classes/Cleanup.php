<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Db;

/**
 * Core maintenance engine: finds and removes stale content — expired, unactivated, spam,
 * blocked and reported listings, unactivated users and avatars left by deleted users.
 * Powers the Tools > Cleanup screen and the scheduled (cron) cleanup. The vanilla, first-class replacement for the Butler plugin.
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
        'orphan_avatars',
    );

    /** Age threshold, in days, when a rule has none saved. */
    public const DEFAULT_DAYS = 30;

    /** Rows per batch when none is saved. */
    public const DEFAULT_BATCH = 250;

    private static $instance;

    /**
     * The shared Cleanup instance, created on first call.
     *
     * @return self
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Each rule's name, translated, in run order.
     *
     * @return array<string,string>
     */
    public static function ruleLabels()
    {
        return array(
            'reported'          => __('Reported listings'),
            'expired'           => __('Expired listings'),
            'inactive_listings' => __('Unactivated listings'),
            'spam'              => __('Spam listings'),
            'blocked'           => __('Blocked listings'),
            'inactive_users'    => __('Unactivated users'),
            'orphan_avatars'    => __('Avatars of deleted users'),
        );
    }

    /**
     * Whether a rule is switched on in Tools > Cleanup.
     *
     * @param string $rule
     *
     * @return bool
     */
    public static function isEnabled($rule)
    {
        return osc_get_preference('enabled_' . $rule, 'osclass') == 1;
    }

    /**
     * The age threshold for a rule, in days.
     *
     * @param string $rule
     *
     * @return int
     */
    public static function days($rule)
    {
        $days = (int)osc_get_preference('days_' . $rule, 'osclass');

        return $days > 0 ? $days : self::DEFAULT_DAYS;
    }

    /**
     * How many rows one batch removes.
     *
     * @return int
     */
    public static function batchLimit()
    {
        $limit = (int)osc_get_preference('batch_limit', 'osclass');

        return $limit > 0 ? $limit : self::DEFAULT_BATCH;
    }

    /**
     * Whether a rule targets users (vs listings).
     *
     * @param string $rule
     *
     * @return bool
     */
    public static function isUserRule($rule)
    {
        return $rule === 'inactive_users';
    }

    /**
     * Whether a rule targets t_resource rows (vs listings or users).
     *
     * @param string $rule
     *
     * @return bool
     */
    public static function isResourceRule($rule)
    {
        return $rule === 'orphan_avatars';
    }

    /**
     * How many rows a rule currently matches — for the preview counts on the screen.
     *
     * @param string $rule
     * @param int    $days
     *
     * @return int
     */
    public function countFor($rule, $days)
    {
        list($from, $where, $params) = $this->ruleQuery($rule, (int)$days);

        try {
            return (int)Db::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, $params);
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * The next batch of rows a rule matches: [{pk_i_id, s_secret}] for listings,
     * [{pk_i_id}] for users, whole t_resource rows for orphan avatars.
     *
     * @param string $rule
     * @param int    $days
     * @param int    $limit Batch size; forced to at least 1
     *
     * @return array<int,array<string,string>>
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
            return Db::stringifyRows(Db::select($sql, $params));
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
                // Listings with at least one spam report. t_item_stats holds one row
                // per listing, so the join cannot multiply a listing out — it could
                // when the table was keyed by date as well, and a listing reported on
                // several days was then counted and offered for deletion once per day.
                // Aged by the listing's last change, so the owner's fix after a report
                // gives it more time. A listing never edited uses its publish date.
                return array(
                    $item . ' AS i INNER JOIN ' . DB_TABLE_PREFIX . 't_item_stats AS s'
                        . ' ON s.fk_i_item_id = i.pk_i_id',
                    's.i_num_spam > 0 AND COALESCE(i.dt_mod_date, i.dt_pub_date) < ?',
                    array($before),
                    'i.pk_i_id AS pk_i_id, i.s_secret AS s_secret'
                );
            case 'inactive_users':
                return array(
                    DB_TABLE_PREFIX . 't_user',
                    'b_active = 0 AND dt_reg_date < ?',
                    array($before),
                    'pk_i_id'
                );
            case 'orphan_avatars':
                // User-owned resources whose user row is gone, aged by upload date.
                return array(
                    DB_TABLE_PREFIX . 't_resource AS r LEFT JOIN ' . DB_TABLE_PREFIX . 't_user AS u'
                        . ' ON u.pk_i_id = r.i_owner_id',
                    'r.s_owner_type = ? AND u.pk_i_id IS NULL AND r.dt_created < ?',
                    array(\mindstellar\model\Resource::OWNER_USER, $before),
                    'r.*'
                );
            default:
                // Unknown rule: an impossible condition, so nothing is ever matched/deleted.
                return array($item, '1 = 0', array(), $cols);
        }
    }

    /**
     * Delete up to $limit rows matched by a rule. Returns the number actually deleted.
     *
     * @param string $rule
     * @param int    $days
     * @param int    $limit
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
        if (self::isResourceRule($rule)) {
            $ids     = array_map('intval', array_column($rows, 'pk_i_id'));
            $deleted = (int)(new \mindstellar\model\Resource())->deleteResourcesIds($ids);
            if ($deleted > 0) {
                try {
                    (new \mindstellar\storage\ResourceUploader())->purgeDeleted($rows);
                } catch (\Throwable $e) {
                    error_log('Cleanup: stored avatar files not removed: ' . $e->getMessage());
                }
            }
        } elseif (self::isUserRule($rule)) {
            $users = User::getInstance();
            foreach ($rows as $row) {
                if ($users->deleteUser((int) $row['pk_i_id'])) {
                    $deleted++;
                }
            }
        } else {
            $items = new ItemActions(true);
            foreach ($rows as $row) {
                if ($items->delete($row['s_secret'], (int) $row['pk_i_id'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}

/* file end: ./oc-includes/osclass/classes/Cleanup.php */
