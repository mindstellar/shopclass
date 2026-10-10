<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Db;
use mindstellar\model\KeyValue;

/**
 * Pending e-mail changes, in the `email_change` group of t_key_value: one key per user id,
 * holding the new address, dropped after 7 days.
 *
 * @package    Shopclass
 * @subpackage Model
 */
class UserEmailTmp
{
    public const KV_GROUP = 'email_change';

    /** Seconds a pending change waits for its confirmation. */
    public const TTL = 7 * 24 * 3600;

    /**
     * @var \UserEmailTmp
     */
    private static $instance;

    /**
     * Return the shared UserEmailTmp model instance, creating it on first use.
     *
     * @return \UserEmailTmp
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
     * Record a pending email change for a user, replacing any previous one.
     *
     * @param array{fk_i_user_id:int|string,s_new_email:string} $userEmailTmp
     *
     * @return int|false False when there was no pending change, otherwise 1 when one was
     *                   replaced and 0 when it already held this address or the user id is unknown
     */
    public function insertOrUpdate($userEmailTmp)
    {
        $userId = (int) ($userEmailTmp['fk_i_user_id'] ?? 0);
        $email  = (string) ($userEmailTmp['s_new_email'] ?? '');
        if ($userId <= 0 || Db::table(DB_TABLE_PREFIX . 't_user')->select('pk_i_id')->where('pk_i_id', $userId)->first() === null) {
            return 0;
        }
        $before = $this->findByPrimaryKey($userId);
        $now    = time();
        (new KeyValue())->set(self::KV_GROUP, (string) $userId, $email, $now + self::TTL, null, $now);
        if ($before === false) {
            return false;
        }

        return $before['s_new_email'] === $email ? 0 : 1;
    }

    /**
     * The pending change of a user.
     *
     * @param int $userId
     *
     * @return array{fk_i_user_id:string,s_new_email:string,dt_date:string}|false false when there is none
     */
    public function findByPrimaryKey($userId)
    {
        $userId = (int) $userId;
        $row    = $userId > 0 ? (new KeyValue())->get(self::KV_GROUP, (string) $userId) : null;
        if ($row === null || $row['value'] === null) {
            return false;
        }

        return array(
            'fk_i_user_id' => (string) $userId,
            's_new_email'  => $row['value'],
            'dt_date'      => date('Y-m-d H:i:s', $row['updated'] ?? $row['created']),
        );
    }

    /**
     * Drop a user's pending change.
     *
     * @param int $userId
     *
     * @return int rows removed
     */
    public function deleteByUser($userId): int
    {
        return (int) $userId > 0 ? (new KeyValue())->delete(self::KV_GROUP, (string) (int) $userId) : 0;
    }
}

/* file end: ./oc-includes/osclass/model/UserEmailTmp.php */
