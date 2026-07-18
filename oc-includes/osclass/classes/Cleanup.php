<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 *
 * This program is free software: you can redistribute it and/or modify it under the terms
 * of the GNU General Public License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version. Distributed WITHOUT ANY
 * WARRANTY. See the GNU Affero General Public License for more details.
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
        $this->applyRule($rule, (int)$days);
        $this->dao->select('COUNT(*) AS total');
        $result = $this->dao->get();
        if ($result === false) {
            return 0;
        }
        $rows = $result->result();

        return isset($rows[0]['total']) ? (int)$rows[0]['total'] : 0;
    }

    /**
     * The next batch of rows a rule matches: [{pk_i_id, s_secret}] for listings,
     * [{pk_i_id}] for users.
     *
     * @return array
     */
    public function candidates($rule, $days, $limit)
    {
        $this->applyRule($rule, (int)$days);
        $this->dao->select(self::isUserRule($rule) ? 'pk_i_id' : 'pk_i_id, s_secret');
        $this->dao->limit(max(1, (int)$limit));
        $result = $this->dao->get();
        if ($result === false) {
            return array();
        }

        return $result->result();
    }

    /**
     * Build the FROM + WHERE for a rule (the SELECT and LIMIT are added by the caller).
     */
    private function applyRule($rule, $days)
    {
        $before = '"' . date('Y-m-d H:i:s', time() - ($days * 24 * 3600)) . '"';
        switch ($rule) {
            case 'expired':
                $this->dao->from(DB_TABLE_PREFIX . 't_item');
                $this->dao->where('dt_expiration < ' . $before);
                break;
            case 'inactive_listings':
                $this->dao->from(DB_TABLE_PREFIX . 't_item');
                $this->dao->where('b_active', 0);
                $this->dao->where('dt_pub_date < ' . $before);
                break;
            case 'spam':
                $this->dao->from(DB_TABLE_PREFIX . 't_item');
                $this->dao->where('b_spam', 1);
                $this->dao->where('dt_pub_date < ' . $before);
                break;
            case 'blocked':
                $this->dao->from(DB_TABLE_PREFIX . 't_item');
                $this->dao->where('b_enabled', 0);
                $this->dao->where('dt_pub_date < ' . $before);
                break;
            case 'reported':
                // Listings with at least one spam report (t_item_stats is 1:1 with t_item).
                $this->dao->from(DB_TABLE_PREFIX . 't_item AS i');
                $this->dao->join(DB_TABLE_PREFIX . 't_item_stats AS s', 's.fk_i_item_id = i.pk_i_id', 'INNER');
                $this->dao->select('i.pk_i_id AS pk_i_id, i.s_secret AS s_secret');
                $this->dao->where('s.i_num_spam > 0');
                break;
            case 'inactive_users':
                $this->dao->from(DB_TABLE_PREFIX . 't_user');
                $this->dao->where('b_active', 0);
                $this->dao->where('dt_reg_date < ' . $before);
                break;
            default:
                // Unknown rule: an impossible condition, so nothing is ever matched/deleted.
                $this->dao->from(DB_TABLE_PREFIX . 't_item');
                $this->dao->where('1 = 0');
                break;
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
