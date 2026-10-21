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

use mindstellar\listing\VariantStore;

/**
 * Takes a goods order's stock and gives it back, each once per order. Both run under the
 * order's row lock and are keyed on the `_stock_taken` meta flag, so a replayed callback or a
 * second cancel changes nothing. A line that cannot be taken is recorded under `_short`
 * (line id => qty) for the admin; stock never goes below zero and nothing throws. Variants
 * that do not track stock are left alone.
 */
final class OrderStock
{
    /** Meta key: the quantities taken, line id => qty. Present while the order holds stock. */
    public const TAKEN = '_stock_taken';

    /** Meta key: the lines that could not be taken, line id => qty. */
    public const SHORT = '_short';

    /**
     * The lines of an order that could not be taken, line id => qty.
     *
     * @return array<int,int>
     */
    public static function short(Order $order): array
    {
        return self::quantities($order->meta(self::SHORT));
    }

    /**
     * Take the order's stock, unless it already holds it.
     *
     * @return array<int,int> the lines that were short, line id => qty; empty when every line was taken
     * @throws \mindstellar\database\DbException
     */
    public static function take(int $orderId): array
    {
        $short = array();
        OrderStore::withMeta($orderId, static function (array $meta) use ($orderId, &$short): array {
            if (array_key_exists(self::TAKEN, $meta)) {
                $short = self::quantities($meta[self::SHORT] ?? null);

                return $meta;
            }
            $taken = array();
            foreach (self::tracked(OrderLineStore::itemLines($orderId, true)) as $line) {
                if (VariantStore::adjustStock($line['variant_id'], -$line['qty'])) {
                    $taken[$line['id']] = $line['qty'];
                } else {
                    $short[$line['id']] = $line['qty'];
                }
            }
            $meta[self::TAKEN] = $taken;
            if ($short !== array()) {
                $meta[self::SHORT] = $short;
            }

            return $meta;
        });

        return $short;
    }

    /**
     * Give back what take() took, if the order holds it.
     *
     * @return bool whether stock was given back by this call
     * @throws \mindstellar\database\DbException
     */
    public static function release(int $orderId): bool
    {
        $released = false;
        OrderStore::withMeta($orderId, static function (array $meta) use ($orderId, &$released): array {
            if (!array_key_exists(self::TAKEN, $meta)) {
                return $meta;
            }
            $taken = self::quantities($meta[self::TAKEN]);
            if ($taken !== array()) {
                $variants = array();
                foreach (OrderLineStore::itemLines($orderId, true) as $line) {
                    if (isset($taken[$line['id']]) && $line['variant_id'] !== null) {
                        $variants[$line['id']] = $line['variant_id'];
                    }
                }
                asort($variants);
                foreach ($variants as $lineId => $variantId) {
                    VariantStore::adjustStock($variantId, $taken[$lineId]);
                }
            }
            unset($meta[self::TAKEN]);
            $released = true;

            return $meta;
        });

        return $released;
    }

    /**
     * The lines whose variant counts stock (or is gone, so it cannot be taken), in variant order
     * so two orders lock the same rows in the same order.
     *
     * @param array<int,array<string,mixed>> $lines
     *
     * @return array<int,array{id:int,variant_id:int,qty:int}>
     * @throws \mindstellar\database\DbException
     */
    private static function tracked(array $lines): array
    {
        $lines    = array_values(array_filter($lines, static fn (array $line): bool => $line['variant_id'] !== null));
        $variants = VariantStore::byIds(array_column($lines, 'variant_id'), true);
        $out      = array();
        foreach ($lines as $line) {
            $variant = $variants[$line['variant_id']] ?? null;
            if ($variant !== null && $variant['i_stock'] === null) {
                continue;
            }
            $out[] = array('id' => (int) $line['id'], 'variant_id' => (int) $line['variant_id'], 'qty' => (int) $line['qty']);
        }
        usort($out, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);

        return $out;
    }

    /**
     * A stored line id => qty map, with anything malformed left out.
     *
     * @param mixed $value
     *
     * @return array<int,int>
     */
    private static function quantities($value): array
    {
        $out = array();
        foreach (is_array($value) ? $value : array() as $lineId => $qty) {
            if (is_numeric($lineId) && is_int($qty) && $qty > 0) {
                $out[(int) $lineId] = $qty;
            }
        }

        return $out;
    }
}
