<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\search;

use mindstellar\database\Connection;
use mindstellar\database\DbException;

/**
 * t_alerts queries for the stored-search format: converting old rows, and finding the
 * ones that were held.
 */
final class AlertStore
{
    /** Rows converted per batch. */
    public const BATCH = 500;

    /** s_search of a held row starts with this. */
    private const HELD_LIKE = '{"v":2,"held":%';

    /**
     * @return string
     */
    private static function table(): string
    {
        return DB_TABLE_PREFIX . 't_alerts';
    }

    /**
     * Convert the next rows after $after that need it, in primary-key order.
     *
     * Every row is read once; a valid envelope or a held marker is left as it is, and
     * anything else (the old format, a malformed or non-canonical v2 row) is converted
     * or held. Each is written only if its s_search is still what was read, so a row
     * changed meanwhile is counted as missed and left for another pass. A held row is
     * also deactivated.
     *
     * @param Connection    $conn
     * @param int           $after       the last primary key already handled
     * @param int           $limit       rows to read
     * @param float         $deadline    microtime(true) to stop at, between rows
     * @param callable|null $beforeWrite fn(int $pk): void, run between reading a row and
     *                                   writing it; a seam for tests
     *
     * @return array{last:int,converted:int,held:int,missed:int,done:bool} done: nothing is left after last
     * @throws DbException
     */
    public static function convertBatch(
        Connection $conn,
        int $after,
        int $limit = self::BATCH,
        float $deadline = INF,
        ?callable $beforeWrite = null
    ): array {
        $limit = max(1, $limit);
        $rows  = $conn->select(
            'SELECT pk_i_id, s_search FROM ' . self::table() . ' WHERE pk_i_id > ? ORDER BY pk_i_id LIMIT ' . $limit,
            array($after)
        );

        $result = array('last' => $after, 'converted' => 0, 'held' => 0, 'missed' => 0, 'done' => false);
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                return $result;
            }
            $pk  = (int)$row['pk_i_id'];
            $old = $row['s_search'] === null ? null : (string)$row['s_search'];
            if (!self::needsWork($old)) {
                $result['last'] = $pk;
                continue;
            }
            try {
                $new = LegacyAlertParser::convert($old);
            } catch (\Throwable $e) {
                $reason = LegacyAlertParser::HELD_ERROR;
                $new    = array('json' => AlertEnvelope::held($reason), 'held' => $reason);
            }
            if ($beforeWrite !== null) {
                $beforeWrite($pk);
            }
            $written = $conn->execute(
                'UPDATE ' . self::table() . ' SET s_search = ?' . ($new['held'] !== null ? ', b_active = 0' : '')
                . ' WHERE pk_i_id = ? AND s_search <=> ?',
                array($new['json'], $pk, $old)
            );
            if ($written === 0 && $new['json'] !== $old) {
                $result['missed']++;
            } else {
                $result[$new['held'] !== null ? 'held' : 'converted']++;
            }
            $result['last'] = $pk;
        }
        $result['done'] = count($rows) < $limit;

        return $result;
    }

    /**
     * Whether a stored s_search still has to be converted: anything but a valid v2
     * envelope or a held marker.
     *
     * @param string|null $search
     *
     * @return bool
     */
    public static function needsWork(?string $search): bool
    {
        return $search === null
            || (AlertEnvelope::heldReason($search) === null && AlertEnvelope::validate($search) === null);
    }

    /**
     * Which of these alerts are held.
     *
     * @param array<int,int|string> $ids
     *
     * @return array<int,int>
     */
    public static function heldIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === array()) {
            return array();
        }
        try {
            $rows = Connection::instance()->select(
                'SELECT pk_i_id FROM ' . self::table() . ' WHERE pk_i_id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ') AND s_search LIKE ?',
                array_merge($ids, array(self::HELD_LIKE))
            );
        } catch (DbException $e) {
            return array();
        }

        return array_map('intval', array_column($rows, 'pk_i_id'));
    }

    /**
     * A held reason in words, for the admin.
     *
     * @param string $reason a LegacyAlertParser::HELD_* code
     *
     * @return string
     */
    public static function reasonText(string $reason): string
    {
        switch ($reason) {
            case LegacyAlertParser::HELD_CONDITION:
                return __('It filtered on a condition added by a plugin or theme.');
            case LegacyAlertParser::HELD_TABLES:
                return __('It searched tables added by a plugin or theme.');
            case LegacyAlertParser::HELD_FILTER:
                return __('Its location or user filter was in an unknown form.');
            case LegacyAlertParser::HELD_FIELD_TYPE:
                return __('A custom field it filtered on has changed type.');
            case LegacyAlertParser::HELD_KEY:
            case LegacyAlertParser::HELD_VALUE:
                return __('It held a value that could not be read safely.');
            default:
                return __('Its saved search could not be read.');
        }
    }

    /**
     * How many alerts are held.
     *
     * @return int
     */
    public static function countHeld(): int
    {
        try {
            return (int)Connection::instance()->scalar(
                'SELECT COUNT(*) FROM ' . self::table() . ' WHERE s_search LIKE ?',
                array(self::HELD_LIKE)
            );
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * Held alerts, in the shape Alerts::search() returns: `rows` is every alert,
     * `total_results` the held ones matching $email.
     *
     * @param int    $start
     * @param int    $limit
     * @param string $orderColumn a t_alerts column name
     * @param string $direction   ASC or DESC
     * @param string $email       optional s_email filter
     *
     * @return array{rows:int,total_results:int,alerts:array<int,array<string,string|null>>}
     */
    public static function searchHeld(
        int $start,
        int $limit,
        string $orderColumn,
        string $direction,
        string $email = ''
    ): array {
        $out = array('rows' => 0, 'total_results' => 0, 'alerts' => array());
        if (!preg_match('/^[A-Za-z0-9_]+$/', $orderColumn)) {
            $orderColumn = 'dt_date';
        }
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $where  = ' WHERE s_search LIKE ?';
        $params = array(self::HELD_LIKE);
        if ($email !== '') {
            $where   .= ' AND s_email LIKE ?';
            $params[] = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $email) . '%';
        }

        try {
            $conn                 = Connection::instance();
            $out['rows']          = (int)$conn->scalar('SELECT COUNT(*) FROM ' . self::table());
            $out['total_results'] = (int)$conn->scalar('SELECT COUNT(*) FROM ' . self::table() . $where, $params);
            $out['alerts']        = osc_db_stringify_rows($conn->select(
                'SELECT * FROM ' . self::table() . $where . ' ORDER BY ' . $orderColumn . ' ' . $direction
                . ', pk_i_id ' . $direction . ' LIMIT ' . max(0, $start) . ', ' . max(1, $limit),
                $params
            ));
        } catch (DbException $e) {
            return array('rows' => 0, 'total_results' => 0, 'alerts' => array());
        }

        return $out;
    }
}
