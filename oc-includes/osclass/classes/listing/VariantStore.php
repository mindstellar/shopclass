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
use mindstellar\boot\CoreServices;
use mindstellar\database\Db;
use mindstellar\listing\event\VariantPriceChanged;
use mindstellar\listing\event\VariantStockChanged;

/**
 * Reads and writes of t_item_variant. A listing is buyable when it has at least one row. It has
 * either one default row (no options), for a plain product, or option rows (Colour x Size ...),
 * never both. Rows go with their listing (ON DELETE CASCADE).
 *
 * A kept row whose stock or selling price changes dispatches a VariantStockChanged or
 * VariantPriceChanged event once the write commits; a row with no price of its own dispatches
 * the price one when the listing's price changes.
 */
final class VariantStore extends Model
{
    protected const TABLE = 't_item_variant';

    /** Widest value a stock count can hold. */
    public const MAX_STOCK = 2147483647;

    /** Widest value a weight in grams can hold. */
    public const MAX_WEIGHT = 4294967295;

    /** Most option names a listing has. */
    public const MAX_OPTIONS = 3;

    /** Most values one option name has. */
    public const MAX_VALUES = 30;

    /** Most option rows a listing has. */
    public const MAX_ROWS = 100;

    /** Longest option name, in characters. */
    public const MAX_NAME = 30;

    /** Longest option value, in characters. */
    public const MAX_VALUE = 40;

    /**
     * How s_options is encoded. A search that matches `"Colour":"Sunflower"` inside it must
     * json_encode the name and value with the same flags.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** The columns a write may set on the default row. */
    private const FIELDS = array('s_sku', 'i_price', 'i_compare_price', 'i_stock', 'i_weight', 's_shipping_class');

    /** The columns a write may set on an option row. */
    private const ROW_FIELDS = array('s_sku', 'i_price', 'i_compare_price', 'i_stock', 'i_weight', 's_shipping_class', 'fk_i_resource_id');

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
     * Lock the variant rows of these listings in primary key order.
     *
     * @param int[] $itemIds
     *
     * @throws \mindstellar\database\DbException
     */
    public static function lockByItems(array $itemIds): void
    {
        foreach (array_chunk($itemIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            Db::select('SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_item_variant WHERE fk_i_item_id IN (' . $in . ') ORDER BY pk_i_id FOR UPDATE', $chunk);
        }
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
     * These variants, by id. Unknown ids have no entry. $lock reads them FOR UPDATE in id order,
     * so two writers lock the same rows in the same order and neither opens its snapshot.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function byIds(array $ids, bool $lock = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === array()) {
            return array();
        }
        $sql = 'SELECT * FROM ' . self::tableName() . ' WHERE pk_i_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
        if ($lock) {
            $sql .= ' ORDER BY pk_i_id FOR UPDATE';
        }
        $out = array();
        foreach (Db::select($sql, $ids) as $row) {
            $out[(int) $row['pk_i_id']] = $row;
        }

        return $out;
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
     * Create or change the listing's default row, and remove any option rows. A key left out
     * of $values keeps its stored value; null clears it.
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
            Db::execute('DELETE FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND s_options IS NOT NULL', array($itemId));
            $row = Db::selectOne(
                'SELECT pk_i_id, i_price, i_stock FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND s_options IS NULL ORDER BY pk_i_id LIMIT 1 FOR UPDATE',
                array($itemId)
            );
            if ($row !== null) {
                $id = (int) $row['pk_i_id'];
                if ($values !== array()) {
                    self::table()->where('pk_i_id', $id)->update($values);
                    self::announce($itemId, array(array($id, $row, $values + $row)));
                }

                return $id;
            }

            return self::table()->insert(array('fk_i_item_id' => $itemId, 'i_position' => 0) + $values);
        });
    }

    /**
     * An option map with its names and values trimmed; anything else is an empty map.
     *
     * @param mixed $options
     *
     * @return array<string,string>
     */
    public static function trimOptions($options): array
    {
        $out = array();
        foreach (is_array($options) ? $options : array() as $name => $value) {
            $out[trim((string) $name)] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $out;
    }

    /**
     * Replace the listing's option rows with $rows, in that order, and remove its default row.
     * A row whose options match a stored one keeps that row's id, so what points at a variant
     * survives an edit; the rest are inserted and stored rows not sent are deleted. Each row
     * is written whole: a column left out of its fields is null. Check the rows with
     * ListingValidator::product() first.
     *
     * @param array<int,array{options:array<string,string>,fields?:array<string,mixed>}> $rows
     *
     * @return int[] the rows' ids, in order
     * @throws \mindstellar\database\DbException
     */
    public static function saveSet(int $itemId, array $rows): array
    {
        $rows = array_map(static fn (array $row): array => array('options' => self::trimOptions($row['options'] ?? null)) + $row, array_values($rows));
        $names = $rows === array() ? array() : array_map('strval', array_keys($rows[0]['options']));

        return Db::transaction(static function () use ($itemId, $rows, $names): array {
            $stored = Db::select(
                'SELECT * FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? ORDER BY i_position, pk_i_id FOR UPDATE',
                array($itemId)
            );
            $byKey = array();
            $drop  = array();
            foreach ($stored as $row) {
                $options = is_string($row['s_options'] ?? null) ? json_decode($row['s_options'], true) : null;
                $key     = is_array($options) && $options !== array() ? self::matchKey($options) : null;
                if ($key === null || isset($byKey[$key])) {
                    $drop[] = (int) $row['pk_i_id'];
                } else {
                    $byKey[$key] = $row;
                }
            }

            $ids     = array();
            $changes = array();
            foreach ($rows as $position => $row) {
                $options = array();
                foreach ($names as $name) {
                    $options[$name] = (string) ($row['options'][$name] ?? '');
                }
                $fields = array_fill_keys(self::ROW_FIELDS, null);
                $fields = array_intersect_key((array) ($row['fields'] ?? array()), $fields) + $fields;
                $values = $fields + array('s_options' => self::optionsJson($options), 'i_position' => $position);
                $key    = self::matchKey($options);
                if (isset($byKey[$key])) {
                    $old = $byKey[$key];
                    unset($byKey[$key]);
                    $id = (int) $old['pk_i_id'];
                    self::table()->where('pk_i_id', $id)->update($values);
                    $changes[] = array($id, $old, $values);
                } else {
                    $id = self::table()->insert(array('fk_i_item_id' => $itemId) + $values);
                }
                $ids[] = $id;
            }
            foreach ($byKey as $row) {
                $drop[] = (int) $row['pk_i_id'];
            }
            if ($drop !== array()) {
                Db::execute(
                    'DELETE FROM ' . self::tableName() . ' WHERE pk_i_id IN (' . implode(', ', array_fill(0, count($drop), '?')) . ')',
                    $drop
                );
            }
            self::announce($itemId, $changes);

            return $ids;
        });
    }

    /**
     * The listing's options from its rows, in first-seen order: [{name, values}]. Takes stored
     * rows (s_options) or rows as saveSet() takes them (options). Empty for a plain product.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array{name:string,values:array<int,string>}>
     */
    public static function optionsOf(array $rows): array
    {
        $values = array();
        foreach ($rows as $row) {
            $options = $row['options'] ?? (is_string($row['s_options'] ?? null) ? json_decode($row['s_options'], true) : null);
            if (!is_array($options)) {
                continue;
            }
            foreach ($options as $name => $value) {
                $values[(string) $name][(string) $value] = true;
            }
        }
        $out = array();
        foreach ($values as $name => $seen) {
            $out[] = array('name' => (string) $name, 'values' => array_map('strval', array_keys($seen)));
        }

        return $out;
    }

    /**
     * A row's options as s_options stores them.
     *
     * @param array<string,string> $options name => value, in the listing's option order
     */
    public static function optionsJson(array $options): string
    {
        return (string) json_encode((object) $options, self::JSON_FLAGS);
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
            // The read after the write sees this change alone: the row stays locked until commit.
            // It locks too, so it opens no snapshot for the caller's later writes.
            $after = Db::transaction(static function () use ($variantId, $delta): ?array {
                $changed = Db::execute(
                    'UPDATE ' . self::tableName() . ' SET i_stock = i_stock + ? WHERE pk_i_id = ? AND i_stock IS NOT NULL'
                    . ' AND i_stock + ? >= 0 AND i_stock + ? <= ' . self::MAX_STOCK,
                    array($delta, $variantId, $delta, $delta)
                );
                if ($changed !== 1) {
                    return null;
                }
                $row = Db::selectOne('SELECT fk_i_item_id, i_stock FROM ' . self::tableName() . ' WHERE pk_i_id = ? FOR UPDATE', array($variantId));
                if ($row !== null) {
                    $to = (int) $row['i_stock'];
                    self::announce((int) $row['fk_i_item_id'], array(array($variantId, array('i_stock' => $to - $delta), array('i_stock' => $to))));
                }

                return $row;
            });
            if ($after !== null) {
                return true;
            }
        }
        // Nothing changed: fine for a zero change or an untracked stock, a refusal otherwise.
        // In a transaction the read locks, so it does not open the caller's snapshot.
        $row = Db::selectOne(
            'SELECT i_stock FROM ' . self::tableName() . ' WHERE pk_i_id = ?' . (Db::inTransaction() ? ' FOR UPDATE' : ''),
            array($variantId)
        );
        if ($row === null) {
            return false;
        }

        return $row['i_stock'] === null || $delta === 0;
    }

    /**
     * A key for a row's options that ignores their order, so a renamed order still matches.
     *
     * @param array<string,mixed> $options
     */
    private static function matchKey(array $options): string
    {
        $options = array_map('strval', $options);
        ksort($options, SORT_STRING);

        return self::optionsJson($options);
    }

    /**
     * The ids of a listing's variants with no price of their own, which sell at the listing's.
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    public static function inheriting(int $itemId): array
    {
        return array_map('intval', array_column(Db::select('SELECT pk_i_id FROM ' . self::tableName() . ' WHERE fk_i_item_id = ? AND i_price IS NULL', array($itemId)), 'pk_i_id'));
    }

    /**
     * The listing's own price went from $from to $to: dispatches VariantPriceChanged once the write
     * commits for each of $before that still has no price of its own.
     *
     * @param int[] $before inheriting() read before the edit
     * @throws \mindstellar\database\DbException
     */
    public static function listingPriceChanged(int $itemId, array $before, ?int $from, ?int $to): void
    {
        if ($from === $to || $before === array()) {
            return;
        }
        $ids = array_values(array_intersect($before, self::inheriting($itemId)));
        if ($ids === array()) {
            return;
        }
        $events = CoreServices::dispatcher();
        Db::afterCommit(static function () use ($events, $itemId, $ids, $from, $to): void {
            foreach ($ids as $variantId) {
                $events->dispatch(new VariantPriceChanged($variantId, $itemId, $from, $to));
            }
        });
    }

    /**
     * Dispatch the stock and price events, once the write commits, for each kept row that changed.
     * The price is the one the row sells at: its own, else the listing's.
     *
     * @param array<int,array{0:int,1:array<string,mixed>,2:array<string,mixed>}> $changes id, old row, new values
     * @throws \mindstellar\database\DbException
     */
    private static function announce(int $itemId, array $changes): void
    {
        $stock = array();
        $price = array();
        $whole = static fn ($value): ?int => is_numeric($value) ? (int) $value : null;
        foreach ($changes as [$id, $old, $new]) {
            if (array_key_exists('i_stock', $new) && $whole($old['i_stock'] ?? null) !== $whole($new['i_stock'])) {
                $stock[] = array($id, $whole($old['i_stock'] ?? null), $whole($new['i_stock']));
            }
            if (array_key_exists('i_price', $new) && $whole($old['i_price'] ?? null) !== $whole($new['i_price'])) {
                $price[] = array($id, $whole($old['i_price'] ?? null), $whole($new['i_price']));
            }
        }
        if ($price !== array() && in_array(null, array_merge(array_column($price, 1), array_column($price, 2)), true)) {
            $listing = $whole(Db::selectOne('SELECT i_price FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ?', array($itemId))['i_price'] ?? null);
            foreach ($price as $i => $change) {
                $price[$i] = array($change[0], $change[1] ?? $listing, $change[2] ?? $listing);
            }
            $price = array_values(array_filter($price, static fn (array $change): bool => $change[1] !== $change[2]));
        }
        if ($stock === array() && $price === array()) {
            return;
        }
        $events = CoreServices::dispatcher();
        Db::afterCommit(static function () use ($events, $itemId, $stock, $price): void {
            foreach ($stock as [$variantId, $from, $to]) {
                $events->dispatch(new VariantStockChanged($variantId, $itemId, $from, $to));
            }
            foreach ($price as [$variantId, $from, $to]) {
                $events->dispatch(new VariantPriceChanged($variantId, $itemId, $from, $to));
            }
        });
    }
}
