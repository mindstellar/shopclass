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

use mindstellar\listing\ListingService;
use mindstellar\utility\Clock;
use mindstellar\utility\DeferredMail;
use mindstellar\utility\SystemClock;
use mindstellar\validation\ConflictException;
use mindstellar\validation\NotFoundException;

/**
 * What an admin does to a listing's status, for the listings screen and the API alike: run
 * through ListingService in one transaction, so stats, hooks and e-mails follow. A listing
 * already in the asked state is left alone; a change is logged under the admin who made it.
 */
final class ListingModeration
{
    /** Each action and what it does. */
    public const ACTIONS = [
        'activate'   => 'Activate a listing that waits for moderation',
        'deactivate' => 'Send a listing back to moderation',
        'enable'     => 'Unblock a listing',
        'disable'    => 'Block a listing',
        'spam'       => 'Mark a listing as spam',
        'unspam'     => 'Clear a listing\'s spam mark',
        'premium'    => 'Make a listing premium, with no end date',
        'unpremium'  => 'End a listing\'s premium status',
        'bump'       => 'Move a listing to the top of "newest first"',
    ];

    public function __construct(private Clock $clock)
    {
    }

    public static function make(): self
    {
        return new self(new SystemClock());
    }

    /**
     * Run one of ACTIONS on a listing. Premium has no end date; a bump fires `item_bumped`
     * as a bought bump does and keeps the first publish date.
     *
     * @param string $note what the activity log says the change came through, e.g. `API key #4`
     *
     * @return bool whether the listing changed
     * @throws NotFoundException for no such listing
     * @throws ConflictException when activating a blocked listing
     * @throws \LogicException for an action not in ACTIONS
     * @throws \RuntimeException when the write fails
     */
    public function apply(string $action, int $id, int $adminId, string $note): bool
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new \LogicException('Unknown listing action ' . $action . '.');
        }
        $row = osc_db_table(DB_TABLE_PREFIX . 't_item')
            ->select('pk_i_id', 'b_active', 'b_enabled', 'b_spam', 'b_premium')
            ->where('pk_i_id', $id)
            ->first();
        if ($row === null) {
            throw new NotFoundException(_m('No such listing.'));
        }
        $row     = osc_db_stringify_row($row);
        $at      = date('Y-m-d H:i:s', $this->clock->now());
        $changed = (bool) DeferredMail::transaction(static function () use ($action, $id, $row, $at): bool {
            $result = self::change($action, $id, $row, new ListingService(), $at);
            if ($result === false) {
                throw new \RuntimeException('The listing could not be changed.');
            }

            return $result === true;
        });
        if ($changed) {
            // The change has committed; a failed log line must not turn it into an error.
            try {
                \Log::getInstance()->insertLog('item', $action, $id, $note, 'admin', $adminId);
            } catch (\Throwable $e) {
                error_log('ListingModeration: the ' . $action . ' of listing ' . $id . ' was not logged: ' . $e->getMessage());
            }
        }

        return $changed;
    }

    /**
     * @param array<string,string> $row the listing's status columns
     *
     * @return bool|null null when there is nothing to do, false when the write failed
     * @throws ConflictException when activating a blocked listing
     */
    private static function change(string $action, int $id, array $row, ListingService $listings, string $at): ?bool
    {
        $is = static fn (string $column, int $value): bool => (int) $row[$column] === $value;

        return match ($action) {
            'activate'   => ($is('b_active', 1) ? null : self::activate($id, $row, $listings)),
            'deactivate' => ($is('b_active', 0) ? null : $listings->deactivate($id)),
            'enable'     => ($is('b_enabled', 1) ? null : $listings->enable($id)),
            'disable'    => ($is('b_enabled', 0) ? null : $listings->disable($id)),
            'spam'       => ($is('b_spam', 1) ? null : $listings->spam($id, true)),
            'unspam'     => ($is('b_spam', 0) ? null : $listings->spam($id, false)),
            'premium'    => ($is('b_premium', 1) ? null : $listings->premium($id, true)),
            'unpremium'  => ($is('b_premium', 0) ? null : $listings->premium($id, false)),
            'bump'       => self::bump($id, $at, $listings),
        };
    }

    /**
     * @param array<string,string> $row
     *
     * @throws ConflictException when the listing is blocked
     */
    private static function activate(int $id, array $row, ListingService $listings): bool
    {
        if ((int) $row['b_enabled'] !== 1) {
            throw new ConflictException(_m('The listing is blocked. Enable it first.'));
        }

        return $listings->activate($id) === true;
    }

    private static function bump(int $id, string $at, ListingService $listings): bool
    {
        $listings->bump($id, $at);

        return true;
    }
}
