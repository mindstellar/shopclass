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

namespace mindstellar\security;

use mindstellar\base\Model;
use mindstellar\cache\CacheGroup;
use mindstellar\database\Db;

/**
 * t_ban_rule reads and writes for the ban list and the report bans. The legacy BanRule
 * model keeps its own methods. The rule list is cached in the `ban_rule` group; every write clears it.
 */
final class BanRuleStore extends Model
{
    protected const TABLE = 't_ban_rule';

    public const CACHE_GROUP = 'ban_rule';

    /**
     * Every rule, from the object cache when it holds them.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function cached(): array
    {
        return CacheGroup::remember(self::CACHE_GROUP, 'all', [self::class, 'all']);
    }

    /**
     * Drop the cached rule list after a write that did not go through this class.
     */
    public static function forget(): void
    {
        CacheGroup::invalidate(self::CACHE_GROUP);
    }

    /**
     * Every rule. SELECT * so the list still loads before the upgrade adds s_scope and dt_expires.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function all(): array
    {
        return Db::select('SELECT * FROM ' . self::tableName());
    }

    /**
     * The id of a rule for this pattern and scope that has not ended at $now, or null.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function activeId(string $pattern, string $scope, string $now): ?int
    {
        $row = Db::selectOne(
            'SELECT pk_i_id FROM ' . self::tableName() . ' WHERE s_email = ? AND s_scope = ?'
            . ' AND (dt_expires IS NULL OR dt_expires > ?)',
            array($pattern, $scope, $now)
        );

        return $row ? (int) $row['pk_i_id'] : null;
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function setExpiry(int $id, ?string $expires): void
    {
        Db::execute('UPDATE ' . self::tableName() . ' SET dt_expires = ? WHERE pk_i_id = ?', array($expires, $id));
        self::forget();
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function add(string $name, string $ip, string $email, string $scope, ?string $expires): void
    {
        Db::execute(
            'INSERT INTO ' . self::tableName() . ' (s_name, s_ip, s_email, s_scope, dt_expires) VALUES (?, ?, ?, ?, ?)',
            array($name, $ip, $email, $scope, $expires)
        );
        self::forget();
    }

    /**
     * @return int rules deleted
     * @throws \mindstellar\database\DbException
     */
    public static function delete(int $id): int
    {
        $deleted = Db::execute('DELETE FROM ' . self::tableName() . ' WHERE pk_i_id = ?', array($id));
        self::forget();

        return $deleted;
    }

    /**
     * Delete rules that ended by $now.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function purgeExpired(string $now): void
    {
        if (Db::execute('DELETE FROM ' . self::tableName() . ' WHERE dt_expires IS NOT NULL AND dt_expires <= ?', array($now)) > 0) {
            self::forget();
        }
    }
}
