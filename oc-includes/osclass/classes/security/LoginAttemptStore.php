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

/**
 * t_login_attempt reads for the sign-in throttle's activity list and limits. The legacy
 * LoginAttempt model keeps the per-attempt writes.
 */
final class LoginAttemptStore extends Model
{
    protected const TABLE = 't_login_attempt';

    /**
     * Failures per address since $since, most first.
     *
     * @param string[] $contexts
     *
     * @return array<int,array<string,mixed>> s_ip, n, oldest
     * @throws \mindstellar\database\DbException
     */
    public static function byIp(array $contexts, string $since, int $limit): array
    {
        return self::table()
            ->select('s_ip')
            ->selectRaw('COUNT(*) AS n, MIN(dt_date) AS oldest')
            ->where('dt_date', '>', $since)
            ->whereIn('s_context', $contexts)
            ->where('s_ip', '!=', '')
            ->groupBy('s_ip')
            ->orderBy('n', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Failures per account since $since, most first.
     *
     * @param string[] $contexts
     *
     * @return array<int,array<string,mixed>> s_context, s_account, n, oldest
     * @throws \mindstellar\database\DbException
     */
    public static function byAccount(array $contexts, string $since, int $limit): array
    {
        return self::table()
            ->select('s_context', 's_account')
            ->selectRaw('COUNT(*) AS n, MIN(dt_date) AS oldest')
            ->where('dt_date', '>', $since)
            ->whereIn('s_context', $contexts)
            ->where('s_account', '!=', '')
            ->groupBy('s_context', 's_account')
            ->orderBy('n', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * One address's failures since $since, and the oldest of them.
     *
     * @param string[] $contexts
     *
     * @return array<string,mixed>|null with n and oldest
     * @throws \mindstellar\database\DbException
     */
    public static function ipWindow(string $ip, array $contexts, string $since): ?array
    {
        return self::table()
            ->selectRaw('COUNT(*) AS n, MIN(dt_date) AS oldest')
            ->where('s_ip', $ip)
            ->whereIn('s_context', $contexts)
            ->where('dt_date', '>', $since)
            ->first();
    }

    /**
     * Forget one address's failures in these contexts.
     *
     * @param string[] $contexts
     *
     * @throws \mindstellar\database\DbException
     */
    public static function clearIp(string $ip, array $contexts): void
    {
        self::table()
            ->where('s_ip', $ip)
            ->whereIn('s_context', $contexts)
            ->delete();
    }

    /**
     * Forget every attempt at or before $before.
     *
     * @return int rows removed
     * @throws \mindstellar\database\DbException
     */
    public static function pruneBefore(string $before): int
    {
        return self::table()->where('dt_date', '<=', $before)->delete();
    }
}
