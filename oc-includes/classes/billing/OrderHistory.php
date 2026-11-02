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

namespace mindstellar\billing;

/**
 * The `_history` list of an order's status changes: who moved it, and the entry each move appends.
 * Only ids are kept; the admin page looks names up when it shows them.
 */
final class OrderHistory
{
    public const BY_ADMIN  = 'admin';
    public const BY_BUYER  = 'buyer';
    public const BY_SYSTEM = 'system';

    /** The newest entries kept; older ones are dropped. */
    public const CAP = 200;

    /** @var array{by:string,id:int|null}|null */
    private static ?array $actor = null;

    private function __construct()
    {
    }

    /**
     * Record every following move as done by $by (with $id), until called again with null. For
     * tests, and for callers that know the actor better than the session does.
     *
     * @param string|null $by One of the BY_* constants, or null to look it up again
     * @param int|null    $id The admin or user id
     */
    public static function actAs(?string $by, ?int $id = null): void
    {
        if ($by !== null && !in_array($by, array(self::BY_ADMIN, self::BY_BUYER, self::BY_SYSTEM), true)) {
            throw new \InvalidArgumentException('OrderHistory: unknown actor "' . $by . '"');
        }
        self::$actor = $by === null ? null : array('by' => $by, 'id' => $by === self::BY_SYSTEM ? null : $id);
    }

    /**
     * Who is moving an order of $ownerId now: a signed-in admin in the admin panel, the signed-in
     * buyer who owns it, or the system (a webhook, a job, anyone else).
     *
     * @param int $ownerId The order's user id
     *
     * @return array{by:string,id:int|null}
     */
    public static function actor(int $ownerId): array
    {
        if (self::$actor !== null) {
            return self::$actor;
        }
        if (defined('OC_ADMIN') && OC_ADMIN === true && function_exists('osc_logged_admin_id') && osc_logged_admin_id() > 0) {
            return array('by' => self::BY_ADMIN, 'id' => osc_logged_admin_id());
        }
        if ($ownerId > 0 && function_exists('osc_logged_user_id') && osc_logged_user_id() === $ownerId) {
            return array('by' => self::BY_BUYER, 'id' => $ownerId);
        }

        return array('by' => self::BY_SYSTEM, 'id' => null);
    }

    /**
     * $meta with one entry for a move from $from to $to appended, keeping the newest CAP.
     *
     * @param array<string,mixed>         $meta
     * @param array{by:string,id:int|null} $actor From actor()
     *
     * @return array<string,mixed>
     */
    public static function append(array $meta, string $from, string $to, array $actor, ?string $at = null): array
    {
        $list   = is_array($meta[OrderStore::HISTORY] ?? null) ? array_values($meta[OrderStore::HISTORY]) : array();
        $list[] = array(
            'at'   => $at ?? date('Y-m-d H:i:s'),
            'from' => $from,
            'to'   => $to,
            'by'   => $actor['by'],
            'id'   => $actor['id'],
        );
        $meta[OrderStore::HISTORY] = array_slice($list, -self::CAP);

        return $meta;
    }
}
