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
 * LoginAttempt DAO — the failed sign-in ledger behind
 * {@see \mindstellar\security\LoginThrottle}.
 *
 * Append-only. A row is written for every rejected sign-in or password-reset
 * request and read back as a count over a rolling window, per source address
 * and per submitted account name. Rows are removed when an account signs in
 * successfully, and otherwise by the daily prune.
 *
 * Counts are the whole point of the table, so they are issued as bound
 * statements through the query helpers rather than assembled by the DAO's
 * find* methods, which would fetch every matching row to count it.
 */
class LoginAttempt extends DAO
{
    /** @var LoginAttempt */
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_login_attempt');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array(
            'pk_i_id',
            's_context',
            's_account',
            's_ip',
            'dt_date',
        ));
    }

    /**
     * @return LoginAttempt
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Record one rejected attempt.
     *
     * @param string $context 'web' or 'admin'
     * @param string $account identifier as submitted, already normalised
     * @param string $ip
     * @param string $date    'Y-m-d H:i:s'
     *
     * @return int rows written
     */
    public function record($context, $account, $ip, $date)
    {
        return osc_db_execute(
            'INSERT INTO ' . $this->getTableName()
            . ' (s_context, s_account, s_ip, dt_date) VALUES (?, ?, ?, ?)',
            array((string)$context, $this->truncate($account), (string)$ip, $date)
        );
    }

    /**
     * Attempts from one address since a moment, across every account it tried.
     *
     * @param string $ip
     * @param string $since 'Y-m-d H:i:s'
     *
     * @return int
     */
    public function countByIp($ip, $since)
    {
        return (int)osc_db_scalar(
            'SELECT COUNT(*) FROM ' . $this->getTableName() . ' WHERE s_ip = ? AND dt_date > ?',
            array((string)$ip, $since)
        );
    }

    /**
     * Events of one context from one address since a moment. Used by the item-post flood
     * wait, which is a per-address rate limit rather than a per-account one.
     *
     * @param string $context
     * @param string $ip
     * @param string $since 'Y-m-d H:i:s'
     *
     * @return int
     */
    public function countByIpContext($context, $ip, $since)
    {
        return (int)osc_db_scalar(
            'SELECT COUNT(*) FROM ' . $this->getTableName()
            . ' WHERE s_context = ? AND s_ip = ? AND dt_date > ?',
            array((string)$context, (string)$ip, $since)
        );
    }

    /**
     * Attempts against one account since a moment, from every address.
     *
     * @param string $context
     * @param string $account
     * @param string $since 'Y-m-d H:i:s'
     *
     * @return int
     */
    public function countByAccount($context, $account, $since)
    {
        return (int)osc_db_scalar(
            'SELECT COUNT(*) FROM ' . $this->getTableName()
            . ' WHERE s_context = ? AND s_account = ? AND dt_date > ?',
            array((string)$context, $this->truncate($account), $since)
        );
    }

    /**
     * When the earliest still-counted attempt from an address was made, so a
     * caller can say how long the block has left to run. Null when there is
     * none. Only issued once a limit has already been hit.
     *
     * @param string $ip
     * @param string $since 'Y-m-d H:i:s'
     *
     * @return string|null 'Y-m-d H:i:s'
     */
    public function oldestByIp($ip, $since)
    {
        $v = osc_db_scalar(
            'SELECT MIN(dt_date) FROM ' . $this->getTableName() . ' WHERE s_ip = ? AND dt_date > ?',
            array((string)$ip, $since)
        );

        return $v === null || $v === false ? null : (string)$v;
    }

    /**
     * As {@see oldestByIp()}, for one account across every address.
     *
     * @param string $context
     * @param string $account
     * @param string $since 'Y-m-d H:i:s'
     *
     * @return string|null 'Y-m-d H:i:s'
     */
    public function oldestByAccount($context, $account, $since)
    {
        $v = osc_db_scalar(
            'SELECT MIN(dt_date) FROM ' . $this->getTableName()
            . ' WHERE s_context = ? AND s_account = ? AND dt_date > ?',
            array((string)$context, $this->truncate($account), $since)
        );

        return $v === null || $v === false ? null : (string)$v;
    }

    /**
     * Forget one account's attempts. Called when its owner signs in.
     *
     * @param string $context
     * @param string $account
     *
     * @return int rows removed
     */
    public function clearAccount($context, $account)
    {
        return osc_db_execute(
            'DELETE FROM ' . $this->getTableName() . ' WHERE s_context = ? AND s_account = ?',
            array((string)$context, $this->truncate($account))
        );
    }

    /**
     * Forget one address's attempts.
     *
     * @param string $ip
     *
     * @return int rows removed
     */
    public function clearIp($ip)
    {
        return osc_db_execute(
            'DELETE FROM ' . $this->getTableName() . ' WHERE s_ip = ?',
            array((string)$ip)
        );
    }

    /**
     * Drop everything older than a moment.
     *
     * @param string $before 'Y-m-d H:i:s'
     *
     * @return int rows removed
     */
    public function pruneBefore($before)
    {
        return osc_db_execute(
            'DELETE FROM ' . $this->getTableName() . ' WHERE dt_date <= ?',
            array($before)
        );
    }

    /**
     * s_account holds whatever was typed into the form, which is unbounded
     * attacker-controlled text; the column stops at 191 characters. Cutting to
     * the same width here keeps a write and the count that follows it looking
     * for the identical string, which they would not on a database that
     * silently truncates the insert.
     *
     * @param string $account
     *
     * @return string
     */
    private function truncate($account)
    {
        return function_exists('mb_substr')
            ? mb_substr((string)$account, 0, 191)
            : substr((string)$account, 0, 191);
    }
}
