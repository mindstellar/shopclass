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

namespace mindstellar\moderation;

use mindstellar\utility\DeferredMail;

/**
 * The one rule for changing several status flags of a listing, comment or user at once:
 * which actions run and in what order.
 */
final class StatusFlags
{
    /** Each flag and the column it reads, in the order its action runs. */
    private const COLUMNS = ['blocked' => 'b_enabled', 'active' => 'b_active', 'spam' => 'b_spam', 'premium' => 'b_premium'];

    private function __construct()
    {
    }

    /**
     * The actions that bring $row to $flags. A flag already as asked runs nothing. An unblock
     * runs first and a block last, so approving a blocked object in the same call works.
     *
     * @param array<string,bool>                     $flags   flag => wanted value: blocked, active, spam, premium
     * @param array<string,mixed>                    $row     the object's status columns
     * @param array<string,array{0:string,1:string}> $actions flag => [action for true, action for false]
     *
     * @return string[]
     * @throws \LogicException for a flag $actions does not name
     */
    public static function plan(array $flags, array $row, array $actions): array
    {
        foreach (array_keys($flags) as $flag) {
            if (!isset($actions[$flag], self::COLUMNS[$flag])) {
                throw new \LogicException('Unknown status flag ' . $flag . '.');
            }
        }
        $plan = [];
        $last = [];
        foreach (self::COLUMNS as $flag => $column) {
            if (!array_key_exists($flag, $flags)) {
                continue;
            }
            $want = (bool) $flags[$flag];
            $is   = (int) ($row[$column] ?? 0) === 1;
            if ($flag === 'blocked') {
                $is = !$is;
            }
            if ($is === $want) {
                continue;
            }
            $action = $actions[$flag][$want ? 0 : 1];
            if ($flag === 'blocked' && $want) {
                $last[] = $action;
            } else {
                $plan[] = $action;
            }
        }

        return array_merge($plan, $last);
    }

    /**
     * Plan the actions as plan() does and pass each to $apply, all in one transaction with
     * e-mails held until it commits. Nothing runs, and no transaction opens, for an empty plan.
     *
     * @param array<string,bool>                     $flags
     * @param array<string,mixed>                    $row
     * @param array<string,array{0:string,1:string}> $actions
     * @param callable(string): mixed                $apply runs one action
     *
     * @return string[] the actions that ran
     * @throws \LogicException for a flag $actions does not name
     */
    public static function run(array $flags, array $row, array $actions, callable $apply): array
    {
        $plan = self::plan($flags, $row, $actions);
        if ($plan !== []) {
            DeferredMail::transaction(static function () use ($plan, $apply): void {
                foreach ($plan as $action) {
                    $apply($action);
                }
            });
        }

        return $plan;
    }
}
