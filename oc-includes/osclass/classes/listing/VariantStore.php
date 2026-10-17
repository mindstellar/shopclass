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

use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Reads and writes of t_item_variant. A listing is buyable when it has at least one row. The
 * default row (no options) holds a plain product; rows with options are for size, colour and
 * the like. Rows go with their listing (ON DELETE CASCADE).
 */
final class VariantStore extends Model
{
    protected const TABLE = 't_item_variant';

    /** Widest value a stock count can hold. */
    public const MAX_STOCK = 2147483647;

    /** Widest value a weight in grams can hold. */
    public const MAX_WEIGHT = 4294967295;

    /** The columns a write may set on the default row. */
    private const FIELDS = array('s_sku', 'i_price', 'i_compare_price', 'i_stock', 'i_weight', 's_shipping_class');

    /**
     * The prefixed table name, for the search compiler's SQL fragments.
     */
    public static function tableName(): string
    {
        return parent::tableName();
    }

    /**
     * Every variant of one listing, default row first.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function ofItem(int $itemId): array
    {
        return self::ofItems(array($itemId))[$itemId] ?? array();
    }

    /**
     * The variants of these listings, by listing id. A listing with none has no entry.
     *
     * @param int[] $itemIds
     *
     * @return array<int,array<int,array<string,mixed>>>
     * @throws \mindstellar\database\DbException
     */
    public static function ofItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === array()) {
            return array();
        }
        $rows = Db::select(
            'SELECT * FROM ' . self::tableName() . ' WHERE fk_i_item_id IN (' . implode(', ', array_fill(0, count($itemIds), '?')) . ')'
            . ' ORDER BY fk_i_item_id, i_position, pk_i_id',
            $itemIds
        );
        $byItem = array();
        foreach ($rows as $row) {
            $byItem[(int) $row['fk_i_item_id']][] = $row;
        }

        return $byItem;
    }

    /**
     * The listing's default row (the one with no options), or null.
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    public static function defaultRow(int $itemId): ?array
    {
        return self::table()->where('fk_i_item_id', $itemId)->whereNull('s_options')->orderBy('pk_i_id')->first();
    }

    /**
     * Whether the listing has at least one variant.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function isBuyable(int $itemId): bool
    {
        return self::table()->where('fk_i_item_id', $itemId)->count() > 0;
    }

    /**
     * Create or change the listing's default row. A key left out of $values keeps its stored
     * value; null clears it.
     *
     * @param array{s_sku?:?string,i_price?:?int,i_compare_price?:?int,i_stock?:?int,i_weight?:?int,s_shipping_class?:?string} $values
     *
     * @return int the row's id
     * @throws \mindstellar\database\DbException
     */
    public static function saveDefault(int $itemId, array $values): int
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));

        return Db::transaction(static function () use ($itemId, $values): int {
            $row = Db::selectOne(
                'SELECT pk_i_id FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND s_options IS NULL ORDER BY pk_i_id LIMIT 1 FOR UPDATE',
                array($itemId)
            );
            if ($row !== null) {
                $id = (int) $row['pk_i_id'];
                if ($values !== array()) {
                    self::table()->where('pk_i_id', $id)->update($values);
                }

                return $id;
            }

            return self::table()->insert(array('fk_i_item_id' => $itemId, 'i_position' => 0) + $values);
        });
    }

    /**
     * Remove the listing's default row, which makes a plain product not buyable.
     *
     * @return int rows deleted
     * @throws \mindstellar\database\DbException
     */
    public static function removeDefault(int $itemId): int
    {
        return self::table()->where('fk_i_item_id', $itemId)->whereNull('s_options')->delete();
    }

    /**
     * Remove every variant of a listing.
     *
     * @return int rows deleted
     * @throws \mindstellar\database\DbException
     */
    public static function deleteForItem(int $itemId): int
    {
        return self::table()->where('fk_i_item_id', $itemId)->delete();
    }

    /**
     * Change a variant's stock by $delta in one statement, so two buyers cannot both take the
     * last unit. Refuses a change that would go below zero or past the column. A variant that
     * does not track stock accepts any change and keeps no count.
     *
     * @return bool false when the variant does not exist or the stock would go out of range
     * @throws \mindstellar\database\DbException
     */
    public static function adjustStock(int $variantId, int $delta): bool
    {
        if ($delta !== 0) {
            $changed = Db::execute(
                'UPDATE ' . self::tableName() . ' SET i_stock = i_stock + ? WHERE pk_i_id = ? AND i_stock IS NOT NULL'
                . ' AND i_stock + ? >= 0 AND i_stock + ? <= ' . self::MAX_STOCK,
                array($delta, $variantId, $delta, $delta)
            );
            if ($changed === 1) {
                return true;
            }
        }
        // Nothing changed: fine for a zero change or an untracked stock, a refusal otherwise.
        $row = self::table()->where('pk_i_id', $variantId)->first();
        if ($row === null) {
            return false;
        }

        return $row['i_stock'] === null || $delta === 0;
    }
}
