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

namespace mindstellar\listing;

use mindstellar\database\QueryBuilder;

/**
 * The one rule for whether a listing is live, from its row or as SQL: enabled, active, not
 * spam, and premium or not yet expired. An expiry that is missing (NULL) counts as passed,
 * as it does in SQL. Search, category counts, the API and webhooks all read it from here.
 */
final class ListingStatus
{
    public const ACTIVE   = 'active';
    public const PENDING  = 'pending';
    public const DISABLED = 'disabled';
    public const EXPIRED  = 'expired';
    public const SPAM     = 'spam';

    public const ALL = [self::ACTIVE, self::PENDING, self::DISABLED, self::EXPIRED, self::SPAM];

    private function __construct()
    {
    }

    /**
     * The status of one listing: spam first, then blocked, then waiting, then expired.
     *
     * @param array<string,mixed> $item a t_item row; the status columns are enough
     */
    public static function of(array $item, ?int $now = null): string
    {
        return match (true) {
            self::flag($item['b_spam'] ?? 0)     => self::SPAM,
            !self::flag($item['b_enabled'] ?? 1) => self::DISABLED,
            !self::flag($item['b_active'] ?? 0)  => self::PENDING,
            self::isExpired($item, $now)         => self::EXPIRED,
            default                              => self::ACTIVE,
        };
    }

    /**
     * @param array<string,mixed> $item a t_item row
     */
    public static function isLive(array $item, ?int $now = null): bool
    {
        return self::of($item, $now) === self::ACTIVE;
    }

    /**
     * Past its expiry and not premium. Whether it is also hidden is a separate question.
     *
     * @param array<string,mixed> $item a t_item row
     */
    public static function isExpired(array $item, ?int $now = null): bool
    {
        $expires = (string) ($item['dt_expiration'] ?? '');

        return !self::flag($item['b_premium'] ?? 0)
            && ($expires === '' || $expires < date('Y-m-d H:i:s', $now ?? time()));
    }

    /**
     * The live rule as SQL fragments to join with AND. The expiry bound is a quoted literal,
     * as the search builder takes it.
     *
     * @param string $alias column qualifier ending in a dot (e.g. 'i.'), or '' for none
     *
     * @return string[]
     */
    public static function liveConditions(string $alias = '', ?int $now = null): array
    {
        return [
            $alias . 'b_enabled = 1',
            $alias . 'b_active = 1',
            $alias . 'b_spam = 0',
            sprintf("(%sb_premium = 1 || %sdt_expiration >= '%s')", $alias, $alias, date('Y-m-d H:i:s', $now ?? time())),
        ];
    }

    /**
     * Keep the listings of any of these statuses; all of them when none is given.
     *
     * @param string[] $statuses names from ALL
     */
    public static function condition(QueryBuilder $query, array $statuses, ?int $now = null): QueryBuilder
    {
        if ($statuses === []) {
            return $query;
        }
        $at   = date('Y-m-d H:i:s', $now ?? time());
        $live = 'b_spam = 0 AND b_enabled = 1 AND b_active = 1';

        return $query->whereGroup(static function (QueryBuilder $group) use ($statuses, $at, $live): QueryBuilder {
            foreach ($statuses as $status) {
                $group = match ($status) {
                    self::SPAM     => $group->orWhereRaw('b_spam = 1'),
                    self::DISABLED => $group->orWhereRaw('b_spam = 0 AND b_enabled = 0'),
                    self::PENDING  => $group->orWhereRaw('b_spam = 0 AND b_enabled = 1 AND b_active = 0'),
                    self::EXPIRED  => $group->orWhereRaw($live . ' AND b_premium = 0 AND (dt_expiration IS NULL OR dt_expiration < ?)', [$at]),
                    default        => $group->orWhereRaw($live . ' AND (b_premium = 1 OR dt_expiration >= ?)', [$at]),
                };
            }

            return $group;
        });
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || (string) $value === '1';
    }
}
