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

use mindstellar\billing\event\CartItemAdded;
use mindstellar\billing\event\Line;
use mindstellar\boot\CoreServices;
use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\database\RowHashQuery;
use mindstellar\model\KeyValue;
use mindstellar\privacy\PersonalData;
use mindstellar\user\UserStore;

/**
 * A signed-in shopper's saved cart: one `t_key_value` row per user (group `cart`, key the user
 * id, 90 days), holding variant ids and quantities. Prices are never stored; the quote prices
 * the lines each time. Limits are CartPricer's.
 */
final class CartStore
{
    public const GROUP = 'cart';

    /** Seconds a saved cart is kept after its last change. */
    public const TTL = 7776000;

    /**
     * @return array{lines:array<int,array{variant:int,qty:int}>,updated_at:?string}
     */
    public static function read(int $userId): array
    {
        $row = $userId > 0 ? osc_kv_get(self::GROUP, (string) $userId) : null;
        if (!is_array($row)) {
            return array('lines' => array(), 'updated_at' => null);
        }

        return array(
            'lines'      => self::clean($row['lines'] ?? array()),
            'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
        );
    }

    /**
     * Save these lines as the user's cart. A variant that cannot be bought is dropped; an
     * empty cart removes the row. `cart_item_add_after` fires once for each variant that was
     * not in the saved cart before.
     *
     * @param array<int,mixed> $lines {variant, qty} each
     *
     * @return array{before:array<int,array{variant:int,qty:int}>,after:array{lines:array<int,array{variant:int,qty:int}>,updated_at:?string}}
     * @throws \mindstellar\database\DbException
     */
    public static function replace(int $userId, array $lines): array
    {
        $lines = self::clean($lines);
        $quote = null;
        if ($lines !== array()) {
            $quote = Container::root()->get(CartPricer::class)->quote($lines, array('user_id' => $userId));
            $lines = array();
            foreach ($quote->lines() as $line) {
                $lines[] = array('variant' => (int) $line['variant'], 'qty' => (int) $line['qty']);
            }
        }
        $out = array('before' => array(), 'after' => array('lines' => array(), 'updated_at' => null));
        Db::transaction(static function () use ($userId, $lines, &$out): void {
            UserStore::lock($userId);
            $out['before'] = self::read($userId)['lines'];
            if ($lines === array()) {
                osc_kv_delete(self::GROUP, (string) $userId);

                return;
            }
            $now = gmdate('c');
            osc_kv_set(self::GROUP, (string) $userId, array('lines' => $lines, 'updated_at' => $now), self::TTL);
            $out['after'] = array('lines' => $lines, 'updated_at' => $now);
        });

        if ($quote !== null) {
            $known  = array_column($out['before'], 'variant');
            $events = CoreServices::dispatcher();
            foreach ($quote->lines() as $line) {
                if (!in_array($line['variant'], $known, true)) {
                    $events->dispatch(new CartItemAdded($quote->cart(), new Line(
                        'item.' . $line['variant'],
                        (string) $line['title'],
                        (int) $line['amount'],
                        $quote->currency(),
                        array('variant' => (int) $line['variant'], 'qty' => (int) $line['qty'], 'item_id' => (int) $line['item_id'])
                    )));
                }
            }
        }

        return $out;
    }

    /**
     * The hash of the user's row, for an If-Match on the cart; none when the cart is empty.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public static function rowHashes(int $userId, bool $lock): array
    {
        return $userId > 0 ? RowHashQuery::keyedHashes(KeyValue::TABLE, array(array('s_group' => self::GROUP, 's_key' => (string) $userId)), $lock) : array();
    }

    /**
     * Forget the user's cart.
     */
    public static function clear(int $userId): void
    {
        if ($userId > 0) {
            osc_kv_delete(self::GROUP, (string) $userId);
        }
    }

    /**
     * `personal_data_export`: the saved cart is kept in t_key_value, which has no user column.
     *
     * @param mixed $out
     * @param mixed $userId
     *
     * @return mixed
     */
    public static function export($out, $userId = 0)
    {
        if (is_array($out)) {
            $out['data']['saved_cart'] = array(
                'retention' => PersonalData::ERASE_DELETED,
                'why'       => 'The variants and quantities in their saved cart.',
                'rows'      => array(array('lines' => self::read((int) $userId)['lines'])),
            );
        }

        return $out;
    }

    /**
     * `delete_user`: the saved cart goes with the account.
     *
     * @param mixed $userId
     */
    public static function erase($userId): void
    {
        self::clear((int) $userId);
    }

    /**
     * @param mixed $lines
     *
     * @return array<int,array{variant:int,qty:int}>
     */
    private static function clean($lines): array
    {
        $out = array();
        foreach (CartPricer::normalise(is_array($lines) ? $lines : array())[0] as $variant => [, $qty]) {
            $out[] = array('variant' => (int) $variant, 'qty' => (int) $qty);
        }

        return $out;
    }
}
