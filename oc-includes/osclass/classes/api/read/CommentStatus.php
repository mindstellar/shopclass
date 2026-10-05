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

namespace mindstellar\api\read;

use mindstellar\api\serializer\Format;
use mindstellar\database\QueryBuilder;

/**
 * The status of a comment, from its row or as a query condition: spam first, then blocked,
 * then waiting for approval.
 */
final class CommentStatus
{
    public const ACTIVE   = 'active';
    public const PENDING  = 'pending';
    public const DISABLED = 'disabled';
    public const SPAM     = 'spam';

    public const ALL = [self::ACTIVE, self::PENDING, self::DISABLED, self::SPAM];

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $row a t_item_comment row
     */
    public static function of(array $row): string
    {
        return match (true) {
            Format::bool($row['b_spam'] ?? 0)     => self::SPAM,
            !Format::bool($row['b_enabled'] ?? 1) => self::DISABLED,
            !Format::bool($row['b_active'] ?? 0)  => self::PENDING,
            default                               => self::ACTIVE,
        };
    }

    /**
     * Keep the comments of any of these statuses; all of them when none is given.
     *
     * @param string[] $statuses names from ALL
     */
    public static function condition(QueryBuilder $query, array $statuses): QueryBuilder
    {
        if ($statuses === []) {
            return $query;
        }

        return $query->whereGroup(static function (QueryBuilder $group) use ($statuses): QueryBuilder {
            foreach ($statuses as $status) {
                $group = $group->orWhereRaw(match ($status) {
                    self::SPAM     => 'b_spam = 1',
                    self::DISABLED => 'b_spam = 0 AND b_enabled = 0',
                    self::PENDING  => 'b_spam = 0 AND b_enabled = 1 AND b_active = 0',
                    default        => 'b_spam = 0 AND b_enabled = 1 AND b_active = 1',
                });
            }

            return $group;
        });
    }
}
