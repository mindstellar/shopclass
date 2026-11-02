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

use InvalidArgumentException;
use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Persistence for t_billing_order_line: what a goods order holds. Item lines name the listing
 * and variant they sold; shipping, tax, discount and fee lines carry only a title and an
 * amount. Amounts are micros; a discount line's amount is negative. Rows stay when the
 * listing or the account goes, as the order does.
 *
 * A line as this class reads and takes it: {id, order_id, kind, item_id, variant_id, title,
 * options, sku, qty, unit, amount, meta}.
 */
final class OrderLineStore extends Model
{
    protected const TABLE = 't_billing_order_line';

    public const KIND_ITEM     = 'item';
    public const KIND_SHIPPING = 'shipping';
    public const KIND_TAX      = 'tax';
    public const KIND_DISCOUNT = 'discount';
    public const KIND_FEE      = 'fee';

    public const KINDS = array(self::KIND_ITEM, self::KIND_SHIPPING, self::KIND_TAX, self::KIND_DISCOUNT, self::KIND_FEE);

    /**
     * Write an order's lines in one insert.
     *
     * @param array<int,array<string,mixed>> $lines kind, item_id, variant_id, title, options, sku, qty, unit, amount, meta
     *
     * @throws InvalidArgumentException on an unknown kind or an empty title
     * @throws \mindstellar\database\DbException
     */
    public static function add(int $orderId, array $lines): void
    {
        if ($lines === array()) {
            return;
        }
        $rows   = array();
        $params = array();
        foreach ($lines as $line) {
            $kind  = (string) ($line['kind'] ?? self::KIND_ITEM);
            $title = mb_substr(trim((string) ($line['title'] ?? '')), 0, 200, 'UTF-8');
            if (!in_array($kind, self::KINDS, true) || $title === '') {
                throw new InvalidArgumentException('OrderLineStore: a line needs a known kind and a title');
            }
            $options = $line['options'] ?? null;
            $meta    = $line['meta'] ?? null;
            $sku     = isset($line['sku']) && $line['sku'] !== '' ? mb_substr((string) $line['sku'], 0, 64, 'UTF-8') : null;
            $rows[]  = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $orderId,
                $kind,
                isset($line['item_id']) ? (int) $line['item_id'] : null,
                isset($line['variant_id']) ? (int) $line['variant_id'] : null,
                $title,
                is_array($options) && $options !== array() ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $sku,
                max(1, (int) ($line['qty'] ?? 1)),
                (int) ($line['unit'] ?? 0),
                (int) ($line['amount'] ?? 0),
                is_array($meta) && $meta !== array() ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
            );
        }
        Db::execute(
            'INSERT INTO ' . self::tableName()
            . ' (fk_i_order_id, s_kind, fk_i_item_id, fk_i_variant_id, s_title, s_options, s_sku, i_qty, i_unit, i_amount, s_meta)'
            . ' VALUES ' . implode(', ', $rows),
            $params
        );
    }

    /**
     * One order's lines, in the order they were written.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function ofOrder(int $orderId): array
    {
        return self::ofOrders(array($orderId))[$orderId] ?? array();
    }

    /**
     * The lines of several orders, by order id, in one query. An order with none has no entry.
     *
     * @param int[] $orderIds
     *
     * @return array<int,array<int,array<string,mixed>>>
     * @throws \mindstellar\database\DbException
     */
    public static function ofOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === array()) {
            return array();
        }
        $rows = Db::select(
            'SELECT * FROM ' . self::tableName() . ' WHERE fk_i_order_id IN (' . implode(', ', array_fill(0, count($orderIds), '?')) . ')'
            . ' ORDER BY fk_i_order_id, pk_i_id',
            $orderIds
        );
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_order_id']][] = self::fromRow($row);
        }

        return $out;
    }

    /**
     * One order's item lines: the goods, without shipping, tax, discount or fee. $lock reads them
     * FOR UPDATE, so a write transaction can read them without opening its snapshot.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function itemLines(int $orderId, bool $lock = false): array
    {
        if ($lock) {
            $rows = Db::select(
                'SELECT * FROM ' . self::tableName() . ' WHERE fk_i_order_id = ? ORDER BY pk_i_id FOR UPDATE',
                array($orderId)
            );
            $lines = array_map(static fn (array $row): array => self::fromRow($row), $rows);
        } else {
            $lines = self::ofOrder($orderId);
        }

        return array_values(array_filter($lines, static fn (array $line): bool => $line['kind'] === self::KIND_ITEM));
    }

    /**
     * Whether the user has a paid, shipped or delivered order with this listing on it.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function bought(int $userId, int $itemId): bool
    {
        return self::boughtItems($userId, array($itemId)) !== array();
    }

    /**
     * Which of these listings the user has on a paid, shipped or delivered order.
     *
     * @param int[] $itemIds
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    public static function boughtItems(int $userId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        if ($userId <= 0 || $itemIds === array()) {
            return array();
        }
        $rows = Db::select(
            'SELECT DISTINCT l.fk_i_item_id FROM ' . self::tableName() . ' l'
            . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_billing_order o ON o.pk_i_id = l.fk_i_order_id'
            . ' WHERE l.fk_i_item_id IN (' . implode(', ', array_fill(0, count($itemIds), '?')) . ')'
            . ' AND o.fk_i_user_id = ? AND o.s_status IN (' . implode(', ', array_fill(0, count(Order::SETTLED), '?')) . ')'
            . ' AND (o.s_gateway <> ? OR o.dt_paid_date IS NOT NULL)',
            array_merge($itemIds, array($userId), Order::SETTLED, array(gateway\CashOnDeliveryGateway::ID))
        );

        return array_map(static fn (array $row): int => (int) $row['fk_i_item_id'], $rows);
    }

    /**
     * @param array<string,mixed> $row a t_billing_order_line row
     *
     * @return array<string,mixed>
     */
    private static function fromRow(array $row): array
    {
        $decode = static function ($json): ?array {
            $value = is_string($json) && $json !== '' ? json_decode($json, true) : null;

            return is_array($value) ? $value : null;
        };

        return array(
            'id'         => (int) $row['pk_i_id'],
            'order_id'   => (int) $row['fk_i_order_id'],
            'kind'       => (string) $row['s_kind'],
            'item_id'    => isset($row['fk_i_item_id']) ? (int) $row['fk_i_item_id'] : null,
            'variant_id' => isset($row['fk_i_variant_id']) ? (int) $row['fk_i_variant_id'] : null,
            'title'      => (string) $row['s_title'],
            'options'    => $decode($row['s_options'] ?? null),
            'sku'        => isset($row['s_sku']) ? (string) $row['s_sku'] : null,
            'qty'        => (int) $row['i_qty'],
            'unit'       => (int) $row['i_unit'],
            'amount'     => (int) $row['i_amount'],
            'meta'       => $decode($row['s_meta'] ?? null) ?? array(),
        );
    }
}
