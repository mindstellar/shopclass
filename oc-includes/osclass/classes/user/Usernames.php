<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\user;

use mindstellar\database\Db;
use mindstellar\model\KeyValue;

/**
 * Usernames: which are allowed, and claiming one so no two accounts hold it.
 */
final class Usernames
{
    /** The key-value group of usernames held for a sign-up that made no account. */
    public const HOLD_GROUP = 'core.username_hold';

    /** The longest a username stays held. */
    public const HOLD_MAX_SECONDS = 604800;

    private function __construct()
    {
    }

    /**
     * Hold a username as an unconfirmed account would: until the cleanup of unactivated users
     * would remove that account, and never longer than 7 days (also when there is no cleanup).
     */
    public static function hold(string $username): void
    {
        $ttl = self::HOLD_MAX_SECONDS;
        if (osc_user_validation_enabled() && \Cleanup::isEnabled('inactive_users')) {
            $ttl = min($ttl, \Cleanup::days('inactive_users') * 86400);
        }
        (new KeyValue())->set(self::HOLD_GROUP, self::holdKey($username), '1', time() + $ttl);
    }

    /**
     * Whether a username is held by hold().
     *
     * @throws \mindstellar\database\DbException
     */
    public static function held(string $username): bool
    {
        return $username !== '' && (new KeyValue())->get(self::HOLD_GROUP, self::holdKey($username)) !== null;
    }

    /**
     * Usernames compare without case, as the column does.
     */
    private static function holdKey(string $username): string
    {
        return hash('sha256', mb_strtolower($username, 'UTF-8'));
    }

    /**
     * The error for a username made only of digits, or '' when it has a letter or symbol.
     * Digit-only names are kept for the id-based name a blank registration gets.
     *
     * @param string $username already sanitised
     */
    public static function numericError(string $username): string
    {
        if ($username === '' || !ctype_digit($username)) {
            return '';
        }

        return _m('The username cannot be only numbers. Please add at least one letter.');
    }

    /**
     * Give a user a username unless another account already holds it. The check and the
     * write run under a named lock; without the lock within 5 seconds nothing is written. A
     * duplicate-key error from the unique index on s_username counts as taken. An admin
     * ($ignoreHolds) may take a name a hidden sign-up holds.
     *
     * @return string 'ok', 'taken' or 'failed'
     */
    public static function claim(int $userId, string $username, bool $ignoreHolds = false): string
    {
        $lock = UserStore::usernameLock();
        try {
            $locked = (int) Db::scalar('SELECT GET_LOCK(?, 5)', [$lock]) === 1;
        } catch (\mindstellar\database\DbException $e) {
            $locked = false;
        }
        if (!$locked) {
            return 'failed';
        }

        try {
            if (UserStore::usernameTaken($username, $userId, $ignoreHolds)) {
                return 'taken';
            }
            UserStore::setUsername($userId, $username);
        } catch (\mindstellar\database\DbException $e) {
            return (int) $e->getCode() === 1062 ? 'taken' : 'failed';
        } finally {
            try {
                Db::scalar('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (\mindstellar\database\DbException $e) {
                // The lock is dropped with the connection anyway.
            }
            if (function_exists('osc_invalidate_user_cache')) {
                osc_invalidate_user_cache($userId);
            }
        }

        return 'ok';
    }

    /**
     * Set the username a registration without one gets: the user id, or the id with a
     * suffix (_2, _3, ...) when an older account already holds it.
     *
     * @return string the name set, or '' when none could be written
     */
    public static function assignDefault(int $userId): string
    {
        for ($n = 1; $n <= 20; $n++) {
            $name   = $n === 1 ? (string) $userId : $userId . '_' . $n;
            $result = self::claim($userId, $name);
            if ($result === 'ok') {
                return $name;
            }
            if ($result === 'failed') {
                break;
            }
        }
        trigger_error('No default username could be set for user ' . $userId . '.', E_USER_WARNING);

        return '';
    }
}
