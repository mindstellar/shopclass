<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin;

use Params;
use Throwable;

/**
 * Applies one admin bulk action to the selected rows and reports how many it changed.
 *
 * Twenty-one of these loops were written out by hand, and they drifted. Two counted the rows
 * submitted rather than the rows changed, so selecting two listings and one stale id reported
 * three activated; two more said "changes have been made" instead of naming what changed; and
 * all five on the comments screen counted nothing at all.
 *
 * @package mindstellar\admin
 */
final class BulkAction
{
    /**
     * Run $action over the selected ids and flash the count.
     *
     * $action is handed one id and returns whether it changed anything. Be careful what
     * counts as yes: `ItemActions::activate()` answers -1 for "nothing to do", which is
     * truthy, so a caller wanting real successes compares against true itself.
     *
     * Anything $action throws is a failure for that row and nothing more — one unusable id
     * must not abandon the rest of a selection halfway through.
     *
     * Ids are read with getParamArray(), so a scalar `?id=5` is an empty selection rather
     * than a TypeError, and each is cast to a positive integer. A screen keyed by something
     * other than a numeric primary key needs its own helper, not a looser one here.
     *
     * @param callable    $action fn(int $id): bool
     * @param string      $one    singular message, taking the count
     * @param string      $many   plural message, taking the count
     * @param string|null $none   message when nothing changed; the plural with 0 if omitted
     * @param string      $param
     *
     * @return int rows changed
     */
    public static function apply(
        callable $action,
        string $one,
        string $many,
        ?string $none = null,
        string $param = 'id'
    ): int {
        $ids = Params::getParamArray($param);
        if ($ids === array()) {
            return 0;
        }

        $changed = 0;
        foreach ($ids as $raw) {
            $id = (int)$raw;
            if ($id <= 0) {
                continue;
            }
            try {
                if ($action($id)) {
                    $changed++;
                }
            } catch (Throwable $e) {
                if (defined('OSC_DEBUG') && OSC_DEBUG) {
                    trigger_error('bulk action failed on id ' . $id . ': ' . $e->getMessage(), E_USER_WARNING);
                }
            }
        }

        osc_add_flash_ok_message(
            ($changed === 0 && $none !== null) ? $none : sprintf(_mn($one, $many, $changed), $changed),
            'admin'
        );

        return $changed;
    }
}
